<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('FKF_DB_HOST') ?: '127.0.0.1',
        'name' => getenv('FKF_DB_NAME') ?: 'fkf_foundation',
        'user' => getenv('FKF_DB_USER') ?: 'root',
        'password' => getenv('FKF_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'site_name' => 'Francisca Kwamboka Foundation',
    'base_path' => '',
    'public_url' => getenv('FKF_PUBLIC_URL') ?: '',
];
