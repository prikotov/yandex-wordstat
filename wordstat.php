<?php

require_once __DIR__ . '/WordstatClient.php';

function parseArgs(array $argv): array
{
    $result = [
        'phrase' => null,
        'geo' => null,
        'type' => 'freq',
        'limit' => null,
        'pages' => 12
    ];
    
    $i = 1;
    while ($i < count($argv)) {
        $arg = $argv[$i];
        
        if (in_array($arg, ['--geo', '-g']) && isset($argv[$i + 1])) {
            $result['geo'] = $argv[++$i];
        } elseif (in_array($arg, ['--type', '-t']) && isset($argv[$i + 1])) {
            $result['type'] = $argv[++$i];
        } elseif (in_array($arg, ['--limit', '-l']) && isset($argv[$i + 1])) {
            $result['limit'] = (int)$argv[++$i];
        } elseif (in_array($arg, ['--pages', '-p']) && isset($argv[$i + 1])) {
            $result['pages'] = (int)$argv[++$i];
        } elseif (!str_starts_with($arg, '-')) {
            $result['phrase'] = $arg;
        }
        $i++;
    }
    
    return $result;
}

$args = parseArgs($argv);

if (!$args['phrase']) {
    echo "\n  Использование:\n";
    echo "    php wordstat.php [опции] <фраза>\n\n";
    echo "  Опции:\n";
    echo "    -g, --geo <id>     Регион (1=Москва, 2=СПб, 225=Россия)\n";
    echo "    -t, --type <type>  Тип: freq, similar, history\n";
    echo "    -l, --limit <n>    Лимит записей\n";
    echo "    -p, --pages <n>    Месяцев для history (по умолчанию 12)\n\n";
    echo "  Примеры:\n";
    echo "    php wordstat.php \"купить ноутбук\"\n";
    echo "    php wordstat.php -t similar -l 20 \"seo\"\n";
    echo "    php wordstat.php -t history -p 24 \"маркетинг\"\n";
    exit(1);
}

WordstatClient::checkGitignore();
$config = WordstatClient::loadConfig();

$client = new WordstatClient(
    $config['client_id'],
    $config['client_secret']
);

echo "\n  Фраза: {$args['phrase']}\n";
echo "  Тип отчёта: {$args['type']}\n";
if ($args['geo']) {
    echo "  Регион: {$args['geo']}\n";
}
echo "\n";

try {
    switch ($args['type']) {
        case 'freq':
            $limit = $args['limit'] ?? 50;
            $data = $client->getTopRequests($args['phrase'], $args['geo'], $limit);
            $title = "Частотность запроса";
            break;
            
        case 'similar':
            $limit = $args['limit'] ?? 50;
            $data = $client->getSimilar($args['phrase'], $args['geo'], $limit);
            $title = "Похожие запросы";
            break;
            
        case 'history':
            $months = $args['pages'] ?? 12;
            $data = $client->getDynamics($args['phrase'], $args['geo'], $months);
            $title = "Динамика запросов";
            break;
            
        default:
            throw new Exception("Неизвестный тип отчёта: {$args['type']}");
    }
    
    $reportPath = WordstatClient::createReportDir();
    $timestamp = WordstatClient::getFileTimestamp();
    
    echo "  Папка отчёта: wordstat_reports/" . basename($reportPath) . "\n";
    
    WordstatClient::saveCsv($data, "$reportPath/wordstat_$timestamp.csv");
    WordstatClient::saveMarkdown($data, "$reportPath/wordstat_$timestamp.md", $title, $args['phrase']);
    
    echo "  Создано файлов:\n";
    echo "    - wordstat_$timestamp.csv\n";
    echo "    - wordstat_$timestamp.md\n";
    echo "\n  Найдено записей: " . count($data) . "\n";
    
} catch (Exception $e) {
    echo "\n  Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
