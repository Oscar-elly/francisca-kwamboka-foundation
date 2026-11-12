<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Configuration is missing. Create config.php with the cPanel database credentials first.\n");
    exit(1);
}

try {
    $config = require $configPath;
    $db = $config['db'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset']),
        $db['user'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $lockName = 'fkf_migrate_' . substr(hash('sha256', (string) $db['name']), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the database migration lock within 30 seconds.');
    }

    try {
        $migrationDirectory = dirname(__DIR__) . '/database/migrations';
        $files = glob($migrationDirectory . '/[0-9][0-9][0-9]_*.{sql,php}', GLOB_BRACE) ?: [];
        sort($files, SORT_STRING);
        $findApplied = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
        $recordApplied = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');

        foreach ($files as $file) {
            $name = basename($file);
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException("Could not read migration {$name}.");
            }
            $checksum = hash('sha256', $contents);
            $findApplied->execute([$name]);
            $appliedChecksum = $findApplied->fetchColumn();
            if ($appliedChecksum !== false) {
                if (!hash_equals((string) $appliedChecksum, $checksum)) {
                    throw new RuntimeException("Applied migration {$name} was changed. Add a new migration instead of editing it.");
                }
                fwrite(STDOUT, "SKIP  {$name}\n");
                continue;
            }

            if (str_ends_with($name, '.sql')) {
                $statements = preg_split('/;\s*(?:\R|$)/', trim($contents)) ?: [];
                foreach ($statements as $statement) {
                    if (trim($statement) !== '') {
                        $pdo->exec($statement);
                    }
                }
            } else {
                $migration = require $file;
                if (!is_callable($migration)) {
                    throw new RuntimeException("PHP migration {$name} must return a callable.");
                }
                $pdo->beginTransaction();
                try {
                    $migration($pdo);
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }
            }

            $recordApplied->execute([$name, $checksum]);
            fwrite(STDOUT, "APPLY {$name}\n");
        }
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: {$exception->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "Database migrations are up to date.\n");
