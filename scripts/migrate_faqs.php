<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Configuration is missing. Copy config.example.php to config.php and configure the database.\n");
    exit(1);
}

$config = require $configFile;
$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset']),
    $db['user'],
    $db['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/002_faqs.sql');
if ($migration === false) {
    fwrite(STDERR, "FAQ migration file could not be read.\n");
    exit(1);
}

$pdo->exec($migration);
fwrite(STDOUT, "FAQ table is ready.\n");
