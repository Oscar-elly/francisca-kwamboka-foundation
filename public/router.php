<?php
declare(strict_types=1);

$requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requested = is_string($requested) ? $requested : '/';
$assetRequest = preg_match('#^/(assets|uploads)/#', $requested) === 1;
$file = $assetRequest
    ? realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($requested, '/')))
    : false;
$publicRoot = realpath(__DIR__);

if ($file !== false && $publicRoot !== false && str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
