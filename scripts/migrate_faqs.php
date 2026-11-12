<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require __DIR__ . '/migrate.php';
