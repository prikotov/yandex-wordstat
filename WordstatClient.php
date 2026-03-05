<?php

class WordstatClient
{
    private const OAUTH_URL = 'https://oauth.yandex.ru/token';
    private const WORDSTAT_URL = 'https://api.wordstat.yandex.net/v1';
    private const REDIRECT_URI = 'https://oauth.yandex.ru/verification_code';
    
    private string $clientId;
    private string $clientSecret;
    private string $tokenFile;
    
    public function __construct(string $clientId, string $clientSecret, ?string $tokenFile = null)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->tokenFile = $tokenFile ?? getcwd() . '/wordstat_token.json';
    }
    
    public static function checkGitignore(): void
    {
        $gitignoreFile = getcwd() . '/.gitignore';
        $requiredEntries = [
            'wordstat_config.json',
            'wordstat_token.json',
            'wordstat_reports/'
        ];
        
        if (!file_exists($gitignoreFile)) {
            file_put_contents($gitignoreFile, implode("\n", $requiredEntries) . "\n");
            echo "  ⚠️  Создан .gitignore с защитой секретных файлов\n\n";
            return;
        }
        
        $lines = array_map('trim', file($gitignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        
        $missing = [];
        foreach ($requiredEntries as $entry) {
            if (!in_array($entry, $lines)) {
                $missing[] = $entry;
            }
        }
        
        if (!empty($missing)) {
            $content = rtrim(file_get_contents($gitignoreFile)) . "\n";
            foreach ($missing as $entry) {
                $content .= $entry . "\n";
            }
            file_put_contents($gitignoreFile, $content);
            echo "  ⚠️  Добавлено в .gitignore: " . implode(', ', $missing) . "\n\n";
        }
    }
    
    public static function loadConfig(): array
    {
        $configFile = getcwd() . '/wordstat_config.json';
        
        if (!file_exists($configFile)) {
            file_put_contents($configFile, json_encode([
                'client_id' => 'ВАШ_CLIENT_ID',
                'client_secret' => 'ВАШ_CLIENT_SECRET'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            echo "\n  Создан файл конфигурации: $configFile\n";
            echo "  Заполните в нём:\n";
            echo "    - client_id: ID приложения Яндекс.OAuth\n";
            echo "    - client_secret: Пароль приложения\n\n";
            echo "  Как создать OAuth-приложение:\n";
            echo "  1. https://oauth.yandex.ru/client/new\n";
            echo "  2. Выберите: Для доступа к API или отладки\n";
            echo "  3. В доступах выберите: Использование API Вордстата\n\n";
            exit(1);
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        
        if ($config['client_id'] === 'ВАШ_CLIENT_ID') {
            echo "\n  Заполните конфигурацию в файле: $configFile\n";
            exit(1);
        }
        
        return $config;
    }
    
    private function getAuthUrl(): string
    {
        return 'https://oauth.yandex.ru/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => self::REDIRECT_URI
        ]);
    }
    
    private function saveToken(array $data): void
    {
        $data['created_at'] = time();
        file_put_contents($this->tokenFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    private function loadToken(): ?array
    {
        if (!file_exists($this->tokenFile)) {
            return null;
        }
        return json_decode(file_get_contents($this->tokenFile), true);
    }
    
    private function exchangeCodeForToken(string $code): array
    {
        $ch = curl_init(self::OAUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => self::REDIRECT_URI
            ])
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            throw new Exception("Ошибка авторизации: " . ($data['error_description'] ?? $data['error']));
        }
        
        $this->saveToken($data);
        $data['created_at'] = time();
        return $data;
    }
    
    private function refreshToken(string $refreshToken): array
    {
        $ch = curl_init(self::OAUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret
            ])
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            throw new Exception("Ошибка обновления токена: " . ($data['error_description'] ?? $data['error']));
        }
        
        if (!isset($data['refresh_token'])) {
            $data['refresh_token'] = $refreshToken;
        }
        $this->saveToken($data);
        $data['created_at'] = time();
        return $data;
    }
    
    private function getAccessToken(): string
    {
        $token = $this->loadToken();
        
        if (!$token) {
            echo "\n  Авторизация в Яндекс.Wordstat\n";
            echo "  =============================\n\n";
            echo "  1. Откройте ссылку в браузере:\n\n";
            echo "  " . $this->getAuthUrl() . "\n\n";
            echo "  2. Разрешите доступ приложению\n";
            echo "  3. Скопируйте CODE из адресной строки (параметр code=...)\n\n";
            echo "  Введите CODE: ";
            
            $code = trim(fgets(STDIN));
            $token = $this->exchangeCodeForToken($code);
            echo "\n  Авторизация успешна!\n\n";
        }
        
        $expiresAt = ($token['created_at'] ?? 0) + ($token['expires_in'] ?? 31536000) - 300;
        
        if (time() > $expiresAt) {
            echo "  Обновление токена...\n";
            $token = $this->refreshToken($token['refresh_token']);
        }
        
        return $token['access_token'];
    }
    
    private function apiRequest(string $endpoint, array $params = []): array
    {
        $token = $this->getAccessToken();
        
        $ch = curl_init(self::WORDSTAT_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200 || isset($data['error'])) {
            $error = $data['error'] ?? $data['message'] ?? $response;
            throw new Exception("API Error [$httpCode]: $error");
        }
        
        return $data;
    }
    
    public function getTopRequests(string $phrase, ?string $geo = null, int $limit = 50): array
    {
        $params = [
            'phrase' => $phrase,
            'numPhrases' => $limit
        ];
        
        if ($geo) {
            $params['regions'] = [(int)$geo];
        }
        
        $result = $this->apiRequest('/topRequests', $params);
        
        $data = [];
        
        if (isset($result['topRequests'])) {
            foreach ($result['topRequests'] as $item) {
                $data[] = [
                    'phrase' => $item['phrase'],
                    'shows' => (int)$item['count']
                ];
            }
        }
        
        return $data;
    }
    
    public function getSimilar(string $phrase, ?string $geo = null, int $limit = 50): array
    {
        $params = [
            'phrase' => $phrase,
            'numPhrases' => $limit
        ];
        
        if ($geo) {
            $params['regions'] = [(int)$geo];
        }
        
        $result = $this->apiRequest('/topRequests', $params);
        
        $data = [];
        
        if (isset($result['associations'])) {
            foreach ($result['associations'] as $item) {
                $data[] = [
                    'phrase' => $item['phrase'],
                    'shows' => (int)$item['count']
                ];
            }
        }
        
        return $data;
    }
    
    public function getDynamics(string $phrase, ?string $geo = null, int $months = 12): array
    {
        $fromDate = date('Y-m-01', strtotime("-$months months"));
        $toDate = date('Y-m-t', strtotime('last month'));
        
        $params = [
            'phrase' => $phrase,
            'period' => 'monthly',
            'fromDate' => $fromDate,
            'toDate' => $toDate
        ];
        
        if ($geo) {
            $params['regions'] = [(int)$geo];
        }
        
        $result = $this->apiRequest('/dynamics', $params);
        
        $data = [];
        
        if (isset($result['dynamics'])) {
            foreach ($result['dynamics'] as $item) {
                $data[] = [
                    'month' => substr($item['date'], 0, 7),
                    'shows' => (int)$item['count'],
                    'share' => round(($item['share'] ?? 0) * 100, 2)
                ];
            }
        }
        
        return $data;
    }
    
    public function getUserInfo(): array
    {
        return $this->apiRequest('/userInfo');
    }
    
    public static function saveCsv(array $data, string $filename): void
    {
        $fp = fopen($filename, 'w');
        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        if (!empty($data)) {
            fputcsv($fp, array_keys($data[0]), ';');
            foreach ($data as $row) {
                fputcsv($fp, $row, ';');
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
}
