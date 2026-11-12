<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/scripts/migrate_page_sections.php';
    seed_page_sections($pdo);
};
