<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Copy config.example.php to config.php and configure the database first.\n");
    exit(1);
}

$config = require $configPath;
$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['name'],
    $db['charset']
);
$pdo = new PDO($dsn, $db['user'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$read = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    return trim($value === false ? '' : $value);
};

$name = $read('Administrator name: ');
$email = strtolower($read('Administrator email: '));
fwrite(STDOUT, 'Password (minimum 12 characters): ');
$password = fgets(STDIN);
$password = $password === false ? '' : trim($password);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "A name, valid email, and password of at least 12 characters are required.\n");
    exit(1);
}

$statement = $pdo->prepare(
    'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password, "admin")'
);
$statement->execute([
    'name' => $name,
    'email' => $email,
    'password' => password_hash($password, PASSWORD_DEFAULT),
]);

fwrite(STDOUT, "Administrator account created.\n");
