<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/scripts/seed_content.php';
    seed_foundation_content($pdo);
};
