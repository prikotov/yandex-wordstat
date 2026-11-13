<?php

declare(strict_types=1);

/**
 * Клиент Яндекс.Wordstat через Yandex Search API v2.
 *
 * Старое API https://api.wordstat.yandex.net/v1 (OAuth oauth.yandex.ru) закрыто Яндексом в 2026;
 * статистика Wordstat доступна через сервис Yandex Search API:
 *   - база:  https://searchapi.api.cloud.yandex.net/v2/wordstat
 *   - auth:  заголовок Authorization: Api-Key <ключ>, folderId — в JSON-теле каждого запроса.
 *
 * Конфиг: wordstat_config.json в текущем каталоге (getcwd), поля api_key и folder_id.
 * Секреты не логируются и не выводятся.
 *
 * Wire-формат v2: POST + JSON; Authorization: Api-Key <ключ>; folderId — в JSON-теле
 * каждого запроса. Числовые поля ответа (count, totalCount, ID региона) приходят
 * строками (int64) — при парсинге приводятся к int.
 *
 * Документация: https://yandex.cloud/ru/docs/search-api/
 */
final class WordstatClient
{
    private const API_BASE = 'https://searchapi.api.cloud.yandex.net/v2/wordstat';
    private const CONFIG_FILE = 'wordstat_config.json';
    private const GITIGNORE_ENTRIES = ['wordstat_config.json', 'wordstat_reports/'];

    /** Ограничение длины фразы (getTop / getDynamics / getRegionsDistribution). */
    public const PHRASE_MAX_LENGTH = 400;
    /** Допустимый диапазон лимита записей getTop (freq/similar). */
    public const TOP_LIMIT_RANGE = [1, 2000];
    /** Допустимый диапазон лимита записей getRegionsDistribution (regions). */
    public const REGIONS_LIMIT_RANGE = [1, 50];
    /**
     * Глубина динамики daily: API хранит последние 60 дней, ровно 60 дней назад — уже отказ,
     * поэтому максимум 59 дней.
     */
    public const DAILY_MAX_COUNT = 59;

    /** Имена period в wire-формате /dynamics: CLI-период → enum API. */
    private const PERIOD_MAP = [
        'daily' => 'PERIOD_DAILY',
        'weekly' => 'PERIOD_WEEKLY',
        'monthly' => 'PERIOD_MONTHLY',
    ];

    private const MAX_RETRIES = 3;
    private const MAX_BACKOFF_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const REQUEST_TIMEOUT_SECONDS = 60;

    private string $apiKey;
    private string $folderId;

    /** @var array<int, array{label: string, parentId: ?int}>|null кэш плоского индекса регионов из /getRegionsTree */
    private ?array $regionIndex = null;

    public function __construct(string $apiKey, string $folderId)
    {
        $this->apiKey = $apiKey;
        $this->folderId = $folderId;
    }

    /**
     * Следит, чтобы секреты (конфиг с ключом) и отчёты не попали в git.
     * Защищает каталог скилла и корень репозитория (раскладка TasK); текущий каталог —
     * только если он вне этих корней (не плодим .gitignore в поддиректориях).
     */
    public static function checkGitignore(): void
    {
        $skillDir = __DIR__;
        $dirs = [$skillDir];
        $outer = dirname(__DIR__, 4); // раскладка TasK: docs/agents/skills/yandex-wordstat
        $outerIsRepo = is_dir($outer . '/.git');
        if ($outerIsRepo && $outer !== $skillDir) {
            $dirs[] = $outer;
        }
        $cwd = getcwd();
        $insideSkill = $cwd === $skillDir || str_starts_with($cwd . '/', $skillDir . '/');
        $insideOuter = $outerIsRepo && ($cwd === $outer || str_starts_with($cwd . '/', $outer . '/'));
        if (!$insideSkill && !$insideOuter) {
            $dirs[] = $cwd;
        }
        foreach (array_unique($dirs) as $dir) {
            self::ensureGitignore($dir);
        }
    }

    /**
     * Каталоги поиска конфига: текущий каталог → каталог скилла (общий ключ для всех
     * агентов, использующих эту установку) → корень репозитория при раскладке TasK.
     *
     * @return list<string>
     */
    public static function configDirs(): array
    {
        $dirs = [getcwd(), __DIR__];
        $outer = dirname(__DIR__, 4);
        if (is_dir($outer . '/.git')) {
            $dirs[] = $outer;
        }
        return array_values(array_unique($dirs));
    }

    private static function ensureGitignore(string $dir): void
    {
        $gitignoreFile = $dir . '/.gitignore';

        if (!file_exists($gitignoreFile)) {
            file_put_contents($gitignoreFile, implode("\n", self::GITIGNORE_ENTRIES) . "\n");
            echo "  ⚠️  Создан .gitignore с защитой секретных файлов: $gitignoreFile\n\n";
            return;
        }

        $lines = array_map('trim', file($gitignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        $missing = [];
        foreach (self::GITIGNORE_ENTRIES as $entry) {
            if (!in_array($entry, $lines, true)) {
                $missing[] = $entry;
            }
        }

        if (!empty($missing)) {
            $content = rtrim((string)file_get_contents($gitignoreFile)) . "\n";
            foreach ($missing as $entry) {
                $content .= $entry . "\n";
            }
            file_put_contents($gitignoreFile, $content);
            echo "  ⚠️  Добавлено в .gitignore ($gitignoreFile): " . implode(', ', $missing) . "\n\n";
        }
    }

    /**
     * Загружает конфиг: переменные окружения YANDEX_WORDSTAT_API_KEY/FOLDER_ID →
     * wordstat_config.json в текущем каталоге → в каталоге скилла (общий ключ для всех
     * агентов этой установки) → в корне репозитория при раскладке TasK.
     * При отсутствии файла создаёт шаблон в текущем каталоге и выводит инструкцию
     * получения ключа Yandex Cloud, затем завершает работу (fail-fast, без запросов к API).
     *
     * @return array{api_key: string, folder_id: string}
     */
    public static function loadConfig(): array
    {
        $envKey = getenv('YANDEX_WORDSTAT_API_KEY');
        $envFolder = getenv('YANDEX_WORDSTAT_FOLDER_ID');
        if (is_string($envKey) && $envKey !== '' && is_string($envFolder) && $envFolder !== '') {
            return ['api_key' => trim($envKey), 'folder_id' => trim($envFolder)];
        }

        $candidates = array_map(static fn (string $dir): string => $dir . '/' . self::CONFIG_FILE, self::configDirs());

        $configFile = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $configFile = $candidate;
                break;
            }
        }

        if ($configFile === null) {
            $configFile = $candidates[0];
            file_put_contents($configFile, json_encode([
                'api_key' => 'ВАШ_API_KEY',
                'folder_id' => 'ВАШ_FOLDER_ID',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            echo "\n  Создан файл конфигурации: $configFile\n";
            echo "  Заполните в нём:\n";
            echo "    - api_key: ключ API Яндекс.Облака (начинается с AQVN...)\n";
            echo "    - folder_id: ID каталога Yandex Cloud (начинается с b1g...)\n\n";
            echo "  Как получить ключ (Wordstat теперь работает через Yandex Search API v2):\n";
            echo "  1. Откройте https://console.yandex.cloud/ и выберите или создайте каталог;\n";
            echo "     к облаку должен быть привязан платёжный аккаунт (billing).\n";
            echo "  2. В каталоге создайте сервисный аккаунт с ролью search-api.webSearch.user.\n";
            echo "  3. У сервисного аккаунта: «Создать новый ключ» → «API-ключ»,\n";
            echo "     со scope yc.search-api.execute.\n";
            echo "  4. Скопируйте секрет ключа (AQVN...) в api_key, ID каталога (b1g...) в folder_id.\n\n";
            echo "  Старое OAuth-подключение (client_id/client_secret) больше не работает:\n";
            echo "  API Вордстата переведён в Yandex Search API.\n";
            echo "  Документация: https://yandex.cloud/ru/docs/search-api/\n\n";
            exit(1);
        }

        $config = json_decode((string)file_get_contents($configFile), true);

        if (!is_array($config)) {
            echo "\n  Не удалось прочитать конфигурацию: $configFile\n";
            echo "  Ожидается корректный JSON с полями api_key и folder_id.\n";
            exit(1);
        }

        if (self::isPlaceholder($config['api_key'] ?? null) || self::isPlaceholder($config['folder_id'] ?? null)) {
            echo "\n  Заполните конфигурацию в файле: $configFile\n";
            echo "  Поля: api_key (ключ AQVN... со scope yc.search-api.execute)\n";
            echo "        и folder_id (ID каталога b1g...).\n";
            exit(1);
        }

        return [
            'api_key' => trim((string)$config['api_key']),
            'folder_id' => trim((string)$config['folder_id']),
        ];
    }

    /**
     * Частотность: популярные запросы, содержащие фразу, за последние 30 дней (/topRequests).
     * Ответ: { results: [{phrase, count}], associations, totalCount }; count/totalCount
     * приходят строками (int64). Частотность точной фразы (без учёта регистра)
     * ищется в results — если найдена, возвращается одна строка, иначе весь список топа.
     *
     * @return list<array{phrase: string, shows: int}>
     */
    public function getTopRequests(string $phrase, ?int $geo = null, int $limit = 50): array
    {
        self::assertPhrase($phrase);
        self::assertLimit($limit, self::TOP_LIMIT_RANGE, 'freq/similar');

        $result = $this->apiRequest('/topRequests', self::topBody($phrase, $geo, $limit));
        $rows = self::rowsFromCounts($result['results'] ?? null, 'results');

        $needle = mb_strtolower(trim($phrase));
        foreach ($rows as $row) {
            if (mb_strtolower(trim($row['phrase'])) === $needle) {
                return [$row];
            }
        }

        return $rows;
    }

    /**
     * Похожие запросы: ассоциации к фразе (/topRequests, поле associations);
     * count приходит строкой (int64) и приводится к int.
     *
     * @return list<array{phrase: string, shows: int}>
     */
    public function getSimilar(string $phrase, ?int $geo = null, int $limit = 50): array
    {
        self::assertPhrase($phrase);
        self::assertLimit($limit, self::TOP_LIMIT_RANGE, 'freq/similar');

        $result = $this->apiRequest('/topRequests', self::topBody($phrase, $geo, $limit));

        return self::rowsFromCounts($result['associations'] ?? null, 'associations');
    }

    /**
     * Динамика запросов (/dynamics). Окно дат строится по правилам API (даты в UTC):
     *   daily   — глубина до 59 дней (доступно 60, ровно 60 — отказ);
     *   weekly  — полные завершённые недели (понедельник … воскресенье);
     *   monthly — полные завершённые месяцы (1-е число … последнее число).
     * Если API отклоняет окно (HTTP 400) — окно снапается к ближайшему валидному
     * и выполняется один повтор. В запросе period передаётся enum-ом API
     * (PERIOD_DAILY/PERIOD_WEEKLY/PERIOD_MONTHLY), даты — RFC3339, полночь UTC;
     * count в ответе приходит строкой (int64) и приводится к int.
     *
     * @return list<array{date: string, shows: int, share: float}> share — доля в процентах
     */
    public function getDynamics(string $phrase, ?int $geo = null, string $period = 'monthly', int $count = 12): array
    {
        self::assertPhrase($phrase);
        [$from, $to, $notice] = self::buildWindow($period, $count);
        if ($notice !== null) {
            echo "  ⚠️  $notice\n";
        }

        try {
            $data = $this->apiRequest('/dynamics', self::dynamicsBody($phrase, $geo, $period, $from, $to));
        } catch (WordstatApiException $exception) {
            if ($exception->httpStatus !== 400 || !self::isWindowError($exception->getMessage())) {
                throw $exception;
            }
            [$snappedFrom, $snappedTo] = self::snapWindow($period, $from, $to);
            if ($snappedFrom === $from && $snappedTo === $to) {
                throw $exception;
            }
            echo "  ⚠️  Окно дат скорректировано под правила API: $from…$to → $snappedFrom…$snappedTo\n";
            $data = $this->apiRequest('/dynamics', self::dynamicsBody($phrase, $geo, $period, $snappedFrom, $snappedTo));
        }

        // Ответ: { results: [{date, count, share}] }; date — строка/timestamp (берём часть до "T"),
        // count — строка (int64).
        $results = $data['results'] ?? null;
        if ($results === null) {
            return [];
        }
        if (!array_is_list($results)) {
            throw new Exception('Неожиданный формат ответа /dynamics (ожидался список results с точками динамики): ' . self::jsonSnippet($results));
        }

        $rows = [];
        foreach ($results as $item) {
            $rows[] = [
                'date' => explode('T', (string)($item['date'] ?? ''), 2)[0],
                'shows' => self::toInt($item['count'] ?? null),
                'share' => round(self::toFloat($item['share'] ?? null) * 100, 2),
            ];
        }

        return $rows;
    }

    /**
     * Географическое распределение запросов по регионам (/regions).
     * Сервер принимает только phrase (поля limit и regions в этом методе не поддерживаются):
     * лимит (1–50) проверяется здесь, а geo-фильтр выполняется клиентски — остаются
     * указанный регион и все его потомки (дерево /getRegionsTree); затем сортировка
     * по убыванию count и срез до лимита. Имена регионов берутся из дерева.
     *
     * @return list<array{regionId: int, regionName: string, count: int, share: float, affinityIndex: float}> share — доля в процентах
     */
    public function getRegionsDistribution(string $phrase, ?int $geo = null, int $limit = 20): array
    {
        self::assertPhrase($phrase);
        self::assertLimit($limit, self::REGIONS_LIMIT_RANGE, 'regions');

        $data = $this->apiRequest('/regions', ['phrase' => $phrase]);

        // Ответ: { results: [{region, count, share, affinityIndex}] }; region — ID
        // (строка/число), count — строка (int64), affinityIndex — строка или число.
        $results = $data['results'] ?? null;
        if ($results === null) {
            return [];
        }
        if (!array_is_list($results)) {
            throw new Exception('Неожиданный формат ответа /regions (ожидался список results с регионами): ' . self::jsonSnippet($results));
        }

        $rows = [];
        foreach ($results as $item) {
            $regionId = self::toInt($item['region'] ?? null);
            $rows[] = [
                'regionId' => $regionId,
                'regionName' => '',
                'count' => self::toInt($item['count'] ?? null),
                'share' => round(self::toFloat($item['share'] ?? null) * 100, 2),
                'affinityIndex' => self::toFloat($item['affinityIndex'] ?? null),
            ];
        }

        if ($geo !== null) {
            $allowed = $this->descendantIds($geo);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($allowed[$row['regionId']]),
            ));
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $rows = array_slice($rows, 0, $limit);

        $index = $this->regionIndex();
        foreach ($rows as &$row) {
            $row['regionName'] = $index[$row['regionId']]['label'] ?? ('Регион ' . $row['regionId']);
        }
        unset($row);

        return $rows;
    }

    public static function saveCsv(array $data, string $filename): void
    {
        $fp = fopen($filename, 'w');
        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if (!empty($data)) {
            fputcsv($fp, array_keys($data[0]), ';', '"', '"');
            foreach ($data as $row) {
                fputcsv($fp, $row, ';', '"', '"');
            }
        }

        fclose($fp);
    }

    public static function saveMarkdown(array $data, string $filename, string $title, string $phrase): void
    {
        $md = "# $title\n\n";
        $md .= "Фраза: $phrase\n\n";

        if (!empty($data)) {
            $headers = array_keys($data[0]);
            $md .= '| ' . implode(' | ', $headers) . " |\n";
            $md .= '| ' . implode(' | ', array_fill(0, count($headers), '---')) . " |\n";

            foreach ($data as $row) {
                $md .= '| ' . implode(' | ', $row) . " |\n";
            }
        } else {
            $md .= "_Нет данных_\n";
        }

        file_put_contents($filename, $md);
    }

    public static function createReportDir(): string
    {
        $reportDir = getcwd() . '/wordstat_reports';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }

        $dateDir = $reportDir . '/' . date('Y-m-d');
        if (!is_dir($dateDir)) {
            mkdir($dateDir, 0755);
        }

        return $dateDir;
    }

    public static function getFileTimestamp(): string
    {
        return date('Y-m-d_H-i-s');
    }

    // --- Внутренние методы -------------------------------------------------

    /**
     * POST-запрос к Wordstat API. При сетевых ошибках, HTTP 429 и 5xx —
     * exponential backoff с учётом Retry-After, до MAX_RETRIES повторов.
     *
     * @param array<string, mixed> $body тело запроса, folderId добавляется здесь
     * @return array<string, mixed> декодированный JSON-ответ
     */
    private function apiRequest(string $endpoint, array $body): array
    {
        $body['folderId'] = $this->folderId;
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Не удалось закодировать JSON-тело запроса');
        }

        $attempt = 0;
        $response = false;
        $httpCode = 0;

        while (true) {
            $ch = curl_init(self::API_BASE . $endpoint);
            if ($ch === false) {
                throw new RuntimeException('Не удалось инициализировать cURL');
            }

            /** @var array<string, string> $responseHeaders */
            $responseHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Api-Key ' . $this->apiKey,
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                $errorCode = curl_errno($ch);
                $errorMessage = curl_error($ch);
                curl_close($ch);
                if ($attempt >= self::MAX_RETRIES) {
                    throw new Exception(sprintf(
                        'Сетевая ошибка при обращении к %s (проверьте доступность сети), после %d повторов: [%d] %s',
                        self::API_BASE,
                        self::MAX_RETRIES,
                        $errorCode,
                        $errorMessage,
                    ));
                }
                self::sleepBackoff($attempt);
                $attempt++;
                continue;
            }

            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 429 || $httpCode >= 500) {
                $retryAfter = $responseHeaders['retry-after'] ?? null;
                curl_close($ch);
                if ($attempt >= self::MAX_RETRIES) {
                    throw new Exception(sprintf(
                        'API недоступен [HTTP %d] после %d повторов: %s',
                        $httpCode,
                        self::MAX_RETRIES,
                        self::textSnippet((string)$response),
                    ));
                }
                sleep(self::retryDelaySeconds($attempt, $retryAfter));
                $attempt++;
                continue;
            }

            curl_close($ch);
            break;
        }

        $data = json_decode((string)$response, true);

        if (!is_array($data)) {
            throw new Exception('Некорректный JSON в ответе API: ' . self::textSnippet((string)$response));
        }

        if ($httpCode !== 200) {
            throw new WordstatApiException($httpCode, self::describeHttpError($httpCode, $data, (string)$response));
        }

        return $data;
    }

    /**
     * Плоский индекс регионов: id → {label, parentId}. Строится из /getRegionsTree
     * (тело запроса — только folderId: параметр depth на живом API даёт 403)
     * и кэшируется на время жизни клиента.
     *
     * @return array<int, array{label: string, parentId: ?int}>
     */
    private function regionIndex(): array
    {
        if ($this->regionIndex !== null) {
            return $this->regionIndex;
        }

        $data = $this->apiRequest('/getRegionsTree', []);

        $index = [];
        $walk = static function (array $nodes, ?int $parentId) use (&$walk, &$index): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = self::toInt($node['id'] ?? null);
                if ($id <= 0) {
                    continue;
                }
                $index[$id] = [
                    'label' => isset($node['label']) && is_string($node['label']) && $node['label'] !== ''
                        ? $node['label']
                        : ('Регион ' . $id),
                    'parentId' => $parentId,
                ];
                $children = $node['children'] ?? null;
                if (is_array($children)) {
                    $walk($children, $id);
                }
            }
        };
        $walk(is_array($data['regions'] ?? null) ? $data['regions'] : [], null);

        $this->regionIndex = $index;

        return $index;
    }

    /**
     * Множество ID: указанный регион и все его потомки по дереву регионов
     * (для клиентского geo-фильтра /regions).
     *
     * @return array<int, true>
     */
    private function descendantIds(int $regionId): array
    {
        $index = $this->regionIndex();

        $ids = [$regionId => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($index as $id => $info) {
                if (!isset($ids[$id]) && isset($ids[$info['parentId']])) {
                    $ids[$id] = true;
                    $changed = true;
                }
            }
        }

        return $ids;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [fromDate, toDate, предупреждение о сдвиге окна]
     */
    private static function buildWindow(string $period, int $count): array
    {
        $now = self::utcNow();

        switch ($period) {
            case 'daily':
                if ($count < 1) {
                    throw new Exception('--count для daily должен быть >= 1');
                }
                $effective = min($count, self::DAILY_MAX_COUNT);
                $to = $now->modify('-1 day');
                $from = $to->modify('-' . ($effective - 1) . ' days');
                $notice = $effective === $count ? null : sprintf(
                    'daily: API хранит динамику только за последние 60 дней, запрошено %d дней — окно сокращено до %d дней (%s … %s)',
                    $count,
                    $effective,
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                );
                return [$from->format('Y-m-d'), $to->format('Y-m-d'), $notice];

            case 'weekly':
                if ($count < 1) {
                    throw new Exception('--count для weekly должен быть >= 1');
                }
                $to = $now->modify('-' . (int)$now->format('N') . ' days'); // последнее завершённое воскресенье
                $from = $to->modify('-' . (7 * $count - 1) . ' days');      // понедельник
                return [$from->format('Y-m-d'), $to->format('Y-m-d'), null];

            case 'monthly':
                if ($count < 1) {
                    throw new Exception('--count для monthly должен быть >= 1');
                }
                $firstOfCurrentMonth = new DateTimeImmutable($now->format('Y-m-01'), new DateTimeZone('UTC'));
                $to = $firstOfCurrentMonth->modify('-1 day');                    // последний день завершённого месяца
                $from = $firstOfCurrentMonth->modify('-' . $count . ' months');  // 1-е число, count месяцев назад
                return [$from->format('Y-m-d'), $to->format('Y-m-d'), null];

            default:
                throw new Exception("Неизвестный период: $period. Используйте: daily, weekly, monthly");
        }
    }

    /**
     * Снап произвольного окна дат к ближайшему валидному по правилам API.
     * Диапазон целиком внутри текущей незавершённой недели/месяца восстановить нельзя —
     * исключение с подсказкой о последней доступной дате окончания.
     *
     * @return array{0: string, 1: string} [fromDate, toDate]
     */
    private static function snapWindow(string $period, string $fromDate, string $toDate): array
    {
        $utc = new DateTimeZone('UTC');
        $now = self::utcNow();

        switch ($period) {
            case 'daily':
                $from = new DateTimeImmutable($fromDate, $utc);
                $to = new DateTimeImmutable($toDate, $utc);
                if ($to < $from) {
                    throw new Exception("Некорректный диапазон daily: $fromDate…$toDate (дата окончания раньше даты начала)");
                }
                $oldestAllowed = $now->modify('-' . self::DAILY_MAX_COUNT . ' days');
                $lastCompleteDay = $now->modify('-1 day');
                // Окно сдвигается целиком с сохранением длины: к ближайшему валидному месту.
                $span = (int)$from->diff($to)->days + 1;
                $to = $to > $lastCompleteDay ? $lastCompleteDay : $to;
                $from = $to->modify('-' . ($span - 1) . ' days');
                if ($from < $oldestAllowed) {
                    $from = $oldestAllowed;
                    $to = $from->modify('+' . ($span - 1) . ' days');
                    if ($to > $lastCompleteDay) {
                        $to = $lastCompleteDay; // запрошенная глубина больше доступной — отдаём максимум
                    }
                }
                return [$from->format('Y-m-d'), $to->format('Y-m-d')];

            case 'weekly':
                $from = (new DateTimeImmutable($fromDate, $utc))->modify('monday this week');
                $to = (new DateTimeImmutable($toDate, $utc))->modify('sunday this week');
                $lastSunday = $now->modify('-' . (int)$now->format('N') . ' days');
                if ($to > $lastSunday) {
                    $to = $lastSunday;
                }
                if ($from > $to) {
                    throw new Exception(sprintf(
                        'Диапазон %s…%s целиком внутри текущей незавершённой недели — API её не отдаёт. Последняя доступная дата окончания: %s',
                        $fromDate,
                        $toDate,
                        $lastSunday->format('Y-m-d'),
                    ));
                }
                return [$from->format('Y-m-d'), $to->format('Y-m-d')];

            case 'monthly':
                $from = self::firstOfMonth(new DateTimeImmutable($fromDate, $utc));
                $to = self::lastDayOfMonth(new DateTimeImmutable($toDate, $utc));
                $lastCompletedMonthEnd = self::lastDayOfMonth(
                    self::firstOfMonth($now)->modify('-1 day'),
                );
                if ($to > $lastCompletedMonthEnd) {
                    $to = $lastCompletedMonthEnd;
                }
                if ($from > $to) {
                    throw new Exception(sprintf(
                        'Диапазон %s…%s целиком внутри текущего незавершённого месяца — API его не отдаёт. Последняя доступная дата окончания: %s',
                        $fromDate,
                        $toDate,
                        $lastCompletedMonthEnd->format('Y-m-d'),
                    ));
                }
                return [$from->format('Y-m-d'), $to->format('Y-m-d')];

            default:
                throw new Exception("Неизвестный период: $period. Используйте: daily, weekly, monthly");
        }
    }

    /**
     * Похожа ли ошибка HTTP 400 на отказ из-за окна дат getDynamics
     * (правила окон API возвращает как текстовые ошибки gRPC).
     */
    private static function isWindowError(string $message): bool
    {
        return (bool)preg_match('/(fromdate|todate|from date|to date|period|window|date|дата|датой|диапазон|интервал)/ui', $message);
    }

    /**
     * Тело /topRequests: numPhrases — лимит СТРОКОЙ (1–2000), regions — ID регионов строками.
     *
     * @return array<string, mixed>
     */
    private static function topBody(string $phrase, ?int $geo, int $limit): array
    {
        $body = [
            'phrase' => $phrase,
            'numPhrases' => (string)$limit,
        ];
        if ($geo !== null) {
            $body['regions'] = [(string)$geo];
        }
        return $body;
    }

    /**
     * Тело /dynamics: period — enum PERIOD_*; даты — RFC3339 (google.protobuf.Timestamp),
     * полночь UTC; regions — ID регионов строками.
     *
     * @return array<string, mixed>
     */
    private static function dynamicsBody(string $phrase, ?int $geo, string $period, string $from, string $to): array
    {
        $periodValue = self::PERIOD_MAP[$period]
            ?? throw new Exception("Неизвестный период: $period. Используйте: daily, weekly, monthly");

        $body = [
            'phrase' => $phrase,
            'period' => $periodValue,
            'fromDate' => $from . 'T00:00:00Z',
            'toDate' => $to . 'T00:00:00Z',
        ];
        if ($geo !== null) {
            $body['regions'] = [(string)$geo];
        }
        return $body;
    }

    /**
     * @return list<array{phrase: string, shows: int}>
     */
    private static function rowsFromCounts(mixed $list, string $field): array
    {
        if ($list === null) {
            return [];
        }
        if (!array_is_list($list)) {
            throw new Exception("Неожиданный формат поля $field в ответе /topRequests (ожидался список фраз): " . self::jsonSnippet($list));
        }

        $rows = [];
        foreach ($list as $item) {
            $rows[] = [
                'phrase' => (string)($item['phrase'] ?? ''),
                'shows' => self::toInt($item['count'] ?? null),
            ];
        }
        return $rows;
    }

    /**
     * Пауза перед повтором: exponential backoff (1с, 2с, 4с…).
     */
    private static function sleepBackoff(int $attempt): void
    {
        sleep(min(2 ** $attempt, self::MAX_BACKOFF_SECONDS));
    }

    /**
     * Пауза перед повтором для 429/5xx: exponential backoff, при наличии
     * Retry-After — большее из двух значений, но не дольше MAX_BACKOFF_SECONDS.
     */
    private static function retryDelaySeconds(int $attempt, ?string $retryAfter): int
    {
        $delay = min(2 ** $attempt, self::MAX_BACKOFF_SECONDS);

        if ($retryAfter !== null) {
            if (ctype_digit($retryAfter)) {
                $delay = max($delay, min((int)$retryAfter, self::MAX_BACKOFF_SECONDS));
            } else {
                $timestamp = strtotime($retryAfter);
                if ($timestamp !== false) {
                    $delay = max($delay, min(max($timestamp - time(), 0), self::MAX_BACKOFF_SECONDS));
                }
            }
        }

        return $delay;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function describeHttpError(int $httpCode, array $data, string $rawResponse): string
    {
        $apiMessage = isset($data['message']) && is_string($data['message']) && $data['message'] !== ''
            ? $data['message']
            : self::textSnippet($rawResponse);

        if ($httpCode === 401 || $httpCode === 403) {
            return sprintf(
                'Доступ запрещён [HTTP %d]. Проверьте: 1) API-ключ действителен и создан со scope yc.search-api.execute;'
                . ' 2) сервисный аккаунт имеет роль search-api.webSearch.user;'
                . ' 3) к облаку привязан платёжный аккаунт (billing). Ответ API: %s',
                $httpCode,
                $apiMessage,
            );
        }

        return "Ошибка API [HTTP $httpCode]: $apiMessage";
    }

    private static function assertPhrase(string $phrase): void
    {
        $length = mb_strlen(trim($phrase));
        if ($length < 1) {
            throw new Exception('Фраза не должна быть пустой');
        }
        if ($length > self::PHRASE_MAX_LENGTH) {
            throw new Exception('Длина фразы не должна превышать ' . self::PHRASE_MAX_LENGTH . ' символов, получено: ' . $length);
        }
    }

    /**
     * @param array{0: int, 1: int} $range
     */
    private static function assertLimit(int $limit, array $range, string $context): void
    {
        [$min, $max] = $range;
        if ($limit < $min || $limit > $max) {
            throw new Exception("Лимит для $context должен быть в диапазоне $min–$max, получено: $limit");
        }
    }

    private static function isPlaceholder(mixed $value): bool
    {
        if (!is_string($value)) {
            return true;
        }
        $trimmed = trim($value);

        return $trimmed === '' || str_starts_with($trimmed, 'ВАШ_') || str_starts_with($trimmed, 'YOUR_');
    }

    private static function firstOfMonth(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-01'), $date->getTimezone());
    }

    private static function lastDayOfMonth(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-t'), $date->getTimezone());
    }

    private static function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function textSnippet(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
    }

    private static function jsonSnippet(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json === false ? '(ответ не кодируется в JSON)' : self::textSnippet($json);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}

/**
 * Ошибка HTTP-ответа Wordstat API, несущая код ответа
 * (нужна для ретрая снапом при 400 из-за окна дат).
 */
final class WordstatApiException extends Exception
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
