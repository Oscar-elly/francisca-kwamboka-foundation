<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration is missing. Copy config.example.php to config.php and configure the database.');
}

$config = require $configFile;
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

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $https,
    'samesite' => 'Lax',
]);
session_start();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_url(string $path = ''): string
{
    global $config;
    $base = rtrim((string) ($config['base_path'] ?? ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function go(string $path): never
{
    header('Location: ' . site_url($path), true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('The form expired. Return to the previous page and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function settings(): array
{
    global $pdo, $config;
    static $values;
    if ($values === null) {
        $values = [];
        foreach ($pdo->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll() as $row) {
            $values[$row['setting_key']] = $row['setting_value'];
        }
    }
    $values['site_name'] ??= $config['site_name'] ?? 'Francisca Kwamboka Foundation';
    $values['contact_email'] ??= 'office@franciscakwambokafoundation.org';
    $values['contact_phone'] ??= '+254 780971932';
    $values['postal_address'] ??= 'P.O.Box 3952 - 40200, Kisii, Kenya';
    $values['office_locations'] ??= 'Kisii, Nyamira and Machakos';
    $values['logo_path'] ??= 'assets/images/fkf-logo.png';
    return $values;
}

function require_user(): array
{
    global $pdo;
    $user = current_user();
    if ($user === null) {
        go('admin/login');
    }
    $statement = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $statement->execute([$user['id']]);
    $freshUser = $statement->fetch();
    if (!$freshUser) {
        unset($_SESSION['user']);
        go('admin/login');
    }
    $_SESSION['user'] = $freshUser;
    return $freshUser;
}

function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access is required.');
    }
    return $user;
}

function page_row(PDO $pdo, string $slug): ?array
{
    $statement = $pdo->prepare("SELECT * FROM pages WHERE slug = ? AND status = 'published' LIMIT 1");
    $statement->execute([$slug]);
    $page = $statement->fetch();
    return $page ?: null;
}

function render_text(string $text): string
{
    $blocks = preg_split('/\R{2,}/u', trim($text)) ?: [];
    return implode('', array_map(
        static fn(string $block): string => '<p>' . nl2br(e($block), false) . '</p>',
        array_filter($blocks, static fn(string $block): bool => trim($block) !== '')
    ));
}

function layout(string $title, string $content, bool $admin = false): void
{
    $siteSettings = settings();
    $siteName = e($siteSettings['site_name']);
    $user = current_user();
    $nav = $admin
        ? '<a href="' . e(site_url('admin')) . '">Dashboard</a><a href="' . e(site_url('admin/pages')) . '">Pages</a><a href="' . e(site_url('admin/posts')) . '">News &amp; articles</a><a href="' . e(site_url('admin/gallery')) . '">Gallery</a>' .
            (($user['role'] ?? '') === 'admin' ? '<a href="' . e(site_url('admin/users')) . '">Users</a><a href="' . e(site_url('admin/settings')) . '">Site settings</a>' : '') .
            '<a href="' . e(site_url('admin/inquiries')) . '">Inquiries</a>'
        : '<a href="' . e(site_url()) . '">Home</a><a href="' . e(site_url('page/our-journey')) . '">Our Journey</a><a href="' . e(site_url('page/fkf-board')) . '">FKF Board</a><a href="' . e(site_url('causes')) . '">Our Causes</a><a href="' . e(site_url('news')) . '">Events &amp; News</a><a href="' . e(site_url('gallery')) . '">Gallery</a><a href="' . e(site_url('page/faqs')) . '">FAQ&apos;s</a><a href="' . e(site_url('contact')) . '">Contacts</a><a class="nav-cta" href="' . e(site_url('donate')) . '">Donate</a>';
    $adminLinks = $admin && $user
        ? '<a class="button button-outline" href="' . e(site_url()) . '">View site</a><form method="post" action="' . e(site_url('admin/logout')) . '">' . csrf_input() . '<button class="button button-light" type="submit">Sign out</button></form>'
        : '';
    $bodyClass = $admin ? 'admin-shell' : 'site-shell';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Francisca Kwamboka Foundation — safe spaces for women and children to thrive."><title>' . e($title) . ' | ' . $siteName . '</title><link rel="stylesheet" href="' . e(site_url('assets/site.css')) . '"></head><body class="' . $bodyClass . '">';
    echo '<header class="site-header"><a class="brand" href="' . e(site_url()) . '"><img src="' . e(site_url($siteSettings['logo_path'])) . '" alt=""><span>' . $siteName . '</span></a><button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">☰</button><nav class="main-nav">' . $nav . '</nav><div class="header-actions">' . $adminLinks . '</div></header>';
    echo '<main>' . $content . '</main>';
    if (!$admin) {
        $email = e($siteSettings['contact_email']);
        $phone = e($siteSettings['contact_phone']);
        echo '<footer class="site-footer"><div><a class="brand brand-footer" href="' . e(site_url()) . '">' . $siteName . '</a><p>is a Nonprofit Organization with offices in ' . e($siteSettings['office_locations']) . '.</p><p>' . e($siteSettings['postal_address']) . '</p></div><div><h3>Contact Us</h3><a href="mailto:' . $email . '">' . $email . '</a><a href="tel:' . e(preg_replace('/[^0-9+]/', '', $siteSettings['contact_phone'])) . '">' . $phone . '</a><a href="' . e(site_url('contact')) . '">Get involved</a></div><div class="footer-bottom">©2026 ' . $siteName . '. All Rights Reserved.</div></footer>';
    }
    echo '<script src="' . e(site_url('assets/site.js')) . '" defer></script></body></html>';
}

function image_upload(string $field): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Choose an image smaller than 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($types[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Only valid JPEG, PNG, and WebP images are accepted.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    $directory = __DIR__ . '/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The image upload folder could not be created.');
    }
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) {
        throw new RuntimeException('The image could not be saved.');
    }
    return 'uploads/' . $name;
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-');
}

function admin_form(string $title, array $fields, array $row, string $action, string $type, string $error = ''): string
{
    $html = '<section class="admin-page"><div class="admin-heading"><div><p class="eyebrow">Content management</p><h1>' . e($title) . '</h1></div><a class="button button-outline" href="' . e(site_url('admin/' . $type)) . '">Back to list</a></div>';
    if ($error !== '') {
        $html .= '<div class="alert alert-error">' . e($error) . '</div>';
    }
    $html .= '<form class="editor-form" method="post" enctype="multipart/form-data" action="' . e(site_url('admin/' . $type . '/' . $action)) . '">' . csrf_input();
    if (isset($row['id'])) {
        $html .= '<input type="hidden" name="id" value="' . e($row['id']) . '">';
    }
    foreach ($fields as $name => $spec) {
        $value = (string) ($row[$name] ?? ($spec['default'] ?? ''));
        $html .= '<label>' . e($spec['label']) . '</label>';
        if ($spec['type'] === 'textarea') {
            $html .= '<textarea name="' . e($name) . '" rows="' . e($spec['rows'] ?? 5) . '"' . (!empty($spec['required']) ? ' required' : '') . '>' . e($value) . '</textarea>';
        } elseif ($spec['type'] === 'select') {
            $html .= '<select name="' . e($name) . '">';
            foreach ($spec['options'] as $option => $label) {
                $html .= '<option value="' . e($option) . '"' . ($value === (string) $option ? ' selected' : '') . '>' . e($label) . '</option>';
            }
            $html .= '</select>';
        } elseif ($spec['type'] === 'file') {
            if ($value !== '') {
                $html .= '<p class="image-current">Current image: ' . e(basename($value)) . '</p>';
            }
            $html .= '<input type="file" name="image" accept="image/jpeg,image/png,image/webp">';
        } else {
            $html .= '<input type="' . e($spec['type']) . '" name="' . e($name) . '" value="' . e($value) . '"' . (!empty($spec['required']) ? ' required' : '') . (!empty($spec['minlength']) ? ' minlength="' . e($spec['minlength']) . '"' : '') . '>';
        }
        if ($name === 'image_path' && $value !== '') {
            $html .= '<input type="hidden" name="existing_image" value="' . e($value) . '">';
        }
    }
    if ($type === 'gallery') {
        $html .= '<label class="checkbox-label"><input type="checkbox" name="is_published" value="1"' . (!isset($row['is_published']) || $row['is_published'] ? ' checked' : '') . '> Show this image publicly</label>';
    } elseif ($type === 'users') {
        $html .= '<label class="checkbox-label"><input type="checkbox" name="is_active" value="1"' . (!isset($row['is_active']) || $row['is_active'] ? ' checked' : '') . '> Account is active</label>';
    }
    $html .= '<button class="button button-primary" type="submit">Save changes</button></form></section>';
    return $html;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim((string) ($config['base_path'] ?? ''), '/');
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath));
}
$path = trim($requestPath, '/');
$segments = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === 'admin/login') {
    $error = '';
    if ($method === 'POST') {
        verify_csrf();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            go('admin');
        }
        $error = 'Email or password is incorrect.';
    }
    $content = '<section class="login-wrap"><div class="login-card"><p class="eyebrow">Foundation CMS</p><h1>Welcome back</h1><p>Sign in to manage website content.</p>' . ($error !== '' ? '<div class="alert alert-error">' . e($error) . '</div>' : '') . '<form method="post">' . csrf_input() . '<label>Email</label><input type="email" name="email" required autocomplete="username"><label>Password</label><input type="password" name="password" required autocomplete="current-password"><button class="button button-primary button-wide">Sign in</button></form></div></section>';
    layout('Admin sign in', $content, true);
    exit;
}

if ($path === 'admin/logout' && $method === 'POST') {
    verify_csrf();
    $_SESSION = [];
    session_destroy();
    go('admin/login');
}

if ($path === 'admin/settings') {
    require_admin();
    $siteSettings = settings();
    $settingError = '';
    if ($method === 'POST') {
        verify_csrf();
        $newSettings = [
            'site_name' => trim((string) ($_POST['site_name'] ?? '')),
            'contact_email' => strtolower(trim((string) ($_POST['contact_email'] ?? ''))),
            'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
            'postal_address' => trim((string) ($_POST['postal_address'] ?? '')),
            'office_locations' => trim((string) ($_POST['office_locations'] ?? '')),
        ];
        if (
            $newSettings['site_name'] === ''
            || strlen($newSettings['site_name']) > 150
            || !filter_var($newSettings['contact_email'], FILTER_VALIDATE_EMAIL)
            || strlen($newSettings['contact_email']) > 190
            || strlen($newSettings['contact_phone']) > 50
            || strlen($newSettings['postal_address']) > 255
            || strlen($newSettings['office_locations']) > 255
        ) {
            $settingError = 'Enter a site name, valid contact email, and contact details within the stated limits.';
        } else {
            try {
                $logo = image_upload('image');
                $newSettings['logo_path'] = $logo ?? $siteSettings['logo_path'];
                $saveSetting = $pdo->prepare(
                    'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                foreach ($newSettings as $key => $value) {
                    $saveSetting->execute([$key, $value]);
                }
                go('admin/settings');
            } catch (RuntimeException $exception) {
                $settingError = $exception->getMessage();
            } catch (PDOException $exception) {
                error_log('CMS settings save failed: ' . $exception->getMessage());
                $settingError = 'Site settings could not be saved.';
            }
        }
        $siteSettings = array_merge($siteSettings, $newSettings);
    }
    $content = '<section class="admin-page"><div class="admin-heading"><div><p class="eyebrow">Content management</p><h1>Site settings</h1></div></div>' .
        ($settingError !== '' ? '<div class="alert alert-error">' . e($settingError) . '</div>' : '') .
        '<form class="editor-form" method="post" enctype="multipart/form-data">' . csrf_input() .
        '<label>Site name<input name="site_name" required maxlength="150" value="' . e($siteSettings['site_name']) . '"></label>' .
        '<label>Contact email<input type="email" name="contact_email" required maxlength="190" value="' . e($siteSettings['contact_email']) . '"></label>' .
        '<label>Contact phone<input name="contact_phone" maxlength="50" value="' . e($siteSettings['contact_phone']) . '"></label>' .
        '<label>Postal address<input name="postal_address" maxlength="255" value="' . e($siteSettings['postal_address']) . '"></label>' .
        '<label>Office locations<input name="office_locations" maxlength="255" value="' . e($siteSettings['office_locations']) . '"></label>' .
        '<label>Foundation logo<p class="image-current">Current image: ' . e(basename($siteSettings['logo_path'])) . '</p><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>' .
        '<button class="button button-primary">Save settings</button></form></section>';
    layout('Site settings', $content, true);
    exit;
}

if ($path === 'admin' || $path === 'admin/') {
    require_user();
    $counts = [];
    foreach (['pages', 'posts', 'gallery_items', 'inquiries'] as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    $content = '<section class="admin-page"><p class="eyebrow">Foundation CMS</p><h1>Dashboard</h1><p class="lead">Manage your website content and incoming messages.</p><div class="stats-grid">';
    foreach (['pages' => 'Pages', 'posts' => 'News, blogs & articles', 'gallery_items' => 'Gallery images', 'inquiries' => 'Contact inquiries'] as $table => $label) {
        $adminPath = match ($table) {
            'gallery_items' => 'gallery',
            default => $table,
        };
        $content .= '<a class="stat-card" href="' . e(site_url('admin/' . $adminPath)) . '"><span>' . e($label) . '</span><strong>' . $counts[$table] . '</strong></a>';
    }
    $content .= '</div><div class="notice-card"><h2>Getting started</h2><p>The public pages are populated with the foundation copy collected from the current site. Add posts and gallery images as new content is ready.</p></div></section>';
    layout('Dashboard', $content, true);
    exit;
}

if (($segments[0] ?? '') === 'admin' && isset($segments[1])) {
    $type = $segments[1];
    $allowed = ['pages', 'posts', 'gallery', 'users', 'inquiries'];
    if (!in_array($type, $allowed, true)) {
        http_response_code(404);
        exit('Not found.');
    }
    $user = require_user();
    if ($type === 'users') {
        require_admin();
    }

    $tables = ['pages' => 'pages', 'posts' => 'posts', 'gallery' => 'gallery_items', 'users' => 'users', 'inquiries' => 'inquiries'];
    $table = $tables[$type];
    $id = isset($segments[2]) && ctype_digit($segments[2])
        ? (int) $segments[2]
        : (isset($segments[3]) && ctype_digit($segments[3]) ? (int) $segments[3] : 0);
    $action = $segments[2] ?? '';
    if ($method === 'POST' && $action === 'save' && isset($_POST['id']) && ctype_digit((string) $_POST['id'])) {
        $id = (int) $_POST['id'];
    }

    if ($method === 'POST') {
        verify_csrf();
        if ($action === 'delete' && $id > 0) {
            if (in_array($type, ['pages', 'posts', 'gallery', 'inquiries'], true)) {
                $statement = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
                $statement->execute([$id]);
            } else {
                http_response_code(405);
                exit('This item cannot be deleted with that action.');
            }
            go('admin/' . $type);
        }
        if ($type === 'inquiries' && $action === 'status') {
            $status = (string) ($_POST['status'] ?? '');
            if (!in_array($status, ['new', 'read', 'replied'], true)) {
                http_response_code(422);
                exit('Invalid inquiry status.');
            }
            $statement = $pdo->prepare('UPDATE inquiries SET status = ? WHERE id = ?');
            $statement->execute([$status, $id]);
            go('admin/inquiries');
        }
        if ($action !== 'save') {
            http_response_code(405);
            exit('Unsupported action.');
        }

        try {
            if ($type === 'pages' || $type === 'posts') {
                $title = trim((string) ($_POST['title'] ?? ''));
                $slug = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $title);
                $body = trim((string) ($_POST['body'] ?? ''));
                $status = (string) ($_POST['status'] ?? 'draft');
                $image = image_upload('image');
                if ($image === null) {
                    $existingImage = trim((string) ($_POST['existing_image'] ?? ''));
                    $image = $existingImage === '' ? null : $existingImage;
                }
                if ($title === '' || $slug === '' || $body === '' || !in_array($status, ['draft', 'published'], true)) {
                    throw new RuntimeException('Title, slug, body, and a valid publication status are required.');
                }
                $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
                $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
                if ($type === 'pages') {
                    $values = [$title, $slug, $excerpt, $body, $image, $status, $publishedAt];
                    if ($id > 0) {
                        $values[] = $id;
                        $statement = $pdo->prepare('UPDATE pages SET title=?, slug=?, excerpt=?, body=?, image_path=?, status=?, published_at=? WHERE id=?');
                    } else {
                        $values[] = $user['id'];
                        $statement = $pdo->prepare('INSERT INTO pages (title, slug, excerpt, body, image_path, status, published_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    }
                } else {
                    $postType = (string) ($_POST['type'] ?? 'news');
                    if (!in_array($postType, ['news', 'blog', 'article'], true)) {
                        throw new RuntimeException('Choose news, blog, or article.');
                    }
                    $values = [$postType, $title, $slug, $excerpt, $body, $image, $status, $publishedAt];
                    if ($id > 0) {
                        $values[] = $id;
                        $statement = $pdo->prepare('UPDATE posts SET type=?, title=?, slug=?, excerpt=?, body=?, image_path=?, status=?, published_at=? WHERE id=?');
                    } else {
                        $values[] = $user['id'];
                        $statement = $pdo->prepare('INSERT INTO posts (type, title, slug, excerpt, body, image_path, status, published_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    }
                }
                $statement->execute($values);
            } elseif ($type === 'gallery') {
                $title = trim((string) ($_POST['title'] ?? ''));
                $alt = trim((string) ($_POST['alt_text'] ?? ''));
                $image = image_upload('image') ?? trim((string) ($_POST['existing_image'] ?? ''));
                if ($title === '' || $alt === '' || $image === '') {
                    throw new RuntimeException('Title, alternative text, and an image are required.');
                }
                $values = [$title, trim((string) ($_POST['caption'] ?? '')), $alt, $image, (int) ($_POST['sort_order'] ?? 0), isset($_POST['is_published']) ? 1 : 0];
                if ($id > 0) {
                    $values[] = $id;
                    $statement = $pdo->prepare('UPDATE gallery_items SET title=?, caption=?, alt_text=?, image_path=?, sort_order=?, is_published=? WHERE id=?');
                } else {
                    $values[] = $user['id'];
                    $statement = $pdo->prepare('INSERT INTO gallery_items (title, caption, alt_text, image_path, sort_order, is_published, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                }
                $statement->execute($values);
            } elseif ($type === 'users') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $role = (string) ($_POST['role'] ?? 'editor');
                $password = (string) ($_POST['password'] ?? '');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'editor'], true)) {
                    throw new RuntimeException('Enter a name, valid email, and role.');
                }
                if ($id > 0) {
                    if ($id === (int) $user['id'] && !isset($_POST['is_active'])) {
                        throw new RuntimeException('You cannot deactivate your own account.');
                    }
                    $existingStatement = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
                    $existingStatement->execute([$id]);
                    $existingUser = $existingStatement->fetch();
                    if ($existingUser && $existingUser['role'] === 'admin' && $existingUser['is_active'] && ($role !== 'admin' || !isset($_POST['is_active']))) {
                        $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
                        if ($activeAdmins < 2) {
                            throw new RuntimeException('At least one active administrator must remain.');
                        }
                    }
                    if ($password !== '' && strlen($password) < 12) {
                        throw new RuntimeException('New passwords must be at least 12 characters.');
                    }
                    if ($password !== '') {
                        $statement = $pdo->prepare('UPDATE users SET name=?, email=?, role=?, is_active=?, password_hash=? WHERE id=?');
                        $statement->execute([$name, $email, $role, isset($_POST['is_active']) ? 1 : 0, password_hash($password, PASSWORD_DEFAULT), $id]);
                    } else {
                        $statement = $pdo->prepare('UPDATE users SET name=?, email=?, role=?, is_active=? WHERE id=?');
                        $statement->execute([$name, $email, $role, isset($_POST['is_active']) ? 1 : 0, $id]);
                    }
                } else {
                    if (strlen($password) < 12) {
                        throw new RuntimeException('Passwords must be at least 12 characters.');
                    }
                    $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                    $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                }
            }
            go('admin/' . $type);
        } catch (RuntimeException $exception) {
            $formError = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('CMS database save failed: ' . $exception->getMessage());
            $formError = 'The record could not be saved. Check that the email or slug is not already in use.';
        }
    }

    if (
        in_array($action, ['new', 'edit'], true)
        || ($action === 'save' && isset($formError))
        || ($action === '' && $type === 'inquiries' && $id > 0)
    ) {
        if ($type === 'inquiries') {
            http_response_code(405);
            exit('Inquiry records are read-only apart from their status.');
        }
        $row = [];
        if ($action === 'save' && isset($formError)) {
            $row = $_POST;
            if ($id > 0) {
                $row['id'] = $id;
            }
        } elseif ($action === 'edit' && $id > 0) {
            $statement = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
            $statement->execute([$id]);
            $row = $statement->fetch() ?: [];
            if ($row === []) {
                http_response_code(404);
                exit('Record not found.');
            }
        }
        if ($type === 'pages') {
            $fields = [
                'title' => ['label' => 'Title', 'type' => 'text', 'required' => true],
                'slug' => ['label' => 'URL slug', 'type' => 'text', 'required' => true],
                'excerpt' => ['label' => 'Short description', 'type' => 'textarea', 'rows' => 3],
                'body' => ['label' => 'Page content', 'type' => 'textarea', 'rows' => 15, 'required' => true],
                'image_path' => ['label' => 'Featured image (optional)', 'type' => 'file'],
                'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['draft' => 'Draft', 'published' => 'Published']],
            ];
        } elseif ($type === 'posts') {
            $fields = [
                'type' => ['label' => 'Content type', 'type' => 'select', 'default' => 'news', 'options' => ['news' => 'News', 'blog' => 'Blog', 'article' => 'Article']],
                'title' => ['label' => 'Title', 'type' => 'text', 'required' => true],
                'slug' => ['label' => 'URL slug', 'type' => 'text', 'required' => true],
                'excerpt' => ['label' => 'Summary', 'type' => 'textarea', 'rows' => 3],
                'body' => ['label' => 'Content', 'type' => 'textarea', 'rows' => 15, 'required' => true],
                'image_path' => ['label' => 'Cover image (optional)', 'type' => 'file'],
                'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['draft' => 'Draft', 'published' => 'Published']],
            ];
        } elseif ($type === 'gallery') {
            $fields = [
                'title' => ['label' => 'Title', 'type' => 'text', 'required' => true],
                'caption' => ['label' => 'Caption', 'type' => 'textarea', 'rows' => 3],
                'alt_text' => ['label' => 'Image description (alt text)', 'type' => 'text', 'required' => true],
                'image_path' => ['label' => 'Image', 'type' => 'file'],
                'sort_order' => ['label' => 'Display order', 'type' => 'number', 'default' => '0'],
            ];
        } else {
            $fields = [
                'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'email' => ['label' => 'Email', 'type' => 'email', 'required' => true],
                'password' => ['label' => $id > 0 ? 'New password (leave blank to keep current)' : 'Password (minimum 12 characters)', 'type' => 'password', 'minlength' => 12],
                'role' => ['label' => 'Role', 'type' => 'select', 'default' => 'editor', 'options' => ['admin' => 'Administrator', 'editor' => 'Editor']],
            ];
        }
        $formAction = 'save';
        $content = admin_form(($id > 0 ? 'Edit ' : 'Add ') . rtrim($type, 's'), $fields, $row, $formAction, $type, $formError ?? '');
        layout('Manage ' . $type, $content, true);
        exit;
    }

    $labels = [
        'pages' => ['title' => 'Pages', 'new' => 'Add page', 'columns' => ['title' => 'Title', 'slug' => 'Slug', 'status' => 'Status']],
        'posts' => ['title' => 'News, blogs & articles', 'new' => 'Add post', 'columns' => ['title' => 'Title', 'type' => 'Type', 'status' => 'Status']],
        'gallery' => ['title' => 'Gallery', 'new' => 'Add image', 'columns' => ['title' => 'Title', 'alt_text' => 'Image description', 'is_published' => 'Visible']],
        'users' => ['title' => 'Users', 'new' => 'Add user', 'columns' => ['name' => 'Name', 'email' => 'Email', 'role' => 'Role', 'is_active' => 'Active']],
        'inquiries' => ['title' => 'Contact inquiries', 'new' => '', 'columns' => ['name' => 'Name', 'email' => 'Email', 'subject' => 'Subject', 'status' => 'Status', 'created_at' => 'Received']],
    ];
    $meta = $labels[$type];
    $records = $pdo->query("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200")->fetchAll();
    $content = '<section class="admin-page"><div class="admin-heading"><div><p class="eyebrow">Content management</p><h1>' . e($meta['title']) . '</h1></div>' . ($meta['new'] !== '' ? '<a class="button button-primary" href="' . e(site_url('admin/' . $type . '/new')) . '">' . e($meta['new']) . '</a>' : '') . '</div>';
    if ($records === []) {
        $content .= '<div class="empty-state"><h2>No records yet</h2><p>New content will appear here as it is added.</p></div>';
    } else {
        $content .= '<div class="table-wrap"><table><thead><tr>';
        foreach ($meta['columns'] as $label) {
            $content .= '<th>' . e($label) . '</th>';
        }
        $content .= '<th>Actions</th></tr></thead><tbody>';
        foreach ($records as $record) {
            $content .= '<tr>';
            foreach ($meta['columns'] as $key => $_label) {
                $value = $record[$key] ?? '';
                if ($key === 'is_published' || $key === 'is_active') {
                    $value = $value ? 'Yes' : 'No';
                }
                if ($key === 'body' || $key === 'password_hash') {
                    $value = '';
                }
                $content .= '<td>' . e($value) . '</td>';
            }
            if ($type === 'inquiries') {
                $content .= '<td><form method="post" action="' . e(site_url('admin/inquiries/status/' . $record['id'])) . '">' . csrf_input() . '<select name="status"><option value="new"' . ($record['status'] === 'new' ? ' selected' : '') . '>New</option><option value="read"' . ($record['status'] === 'read' ? ' selected' : '') . '>Read</option><option value="replied"' . ($record['status'] === 'replied' ? ' selected' : '') . '>Replied</option></select><button class="button button-small">Update</button><details><summary>Read message</summary><p>' . nl2br(e($record['message']), false) . '</p></details></form></td>';
            } else {
                $content .= '<td class="table-actions"><a class="button button-small button-outline" href="' . e(site_url('admin/' . $type . '/edit/' . $record['id'])) . '">Edit</a>';
                if (in_array($type, ['pages', 'posts', 'gallery'], true)) {
                    $content .= '<form method="post" action="' . e(site_url('admin/' . $type . '/delete/' . $record['id'])) . '" onsubmit="return confirm(\'Delete this content item?\')">' . csrf_input() . '<button class="button button-small button-danger">Delete</button></form>';
                }
                $content .= '</td>';
            }
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '</section>';
    layout($meta['title'], $content, true);
    exit;
}

if ($method === 'POST' && $path === 'contact') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    if (
        $name === ''
        || strlen($name) > 150
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 190
        || strlen($phone) > 50
        || $subject === ''
        || strlen($subject) > 190
        || $message === ''
        || strlen($message) > 5000
    ) {
        http_response_code(422);
        $contactError = 'Please provide your name, a valid email address, subject, and a message under 5,000 characters.';
    } else {
        $statement = $pdo->prepare('INSERT INTO inquiries (name, email, phone, subject, message) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$name, $email, $phone, $subject, $message]);
        go('contact?sent=1');
    }
}

if ($path === '' || $path === 'index.php') {
    $page = page_row($pdo, 'home');
    $posts = $pdo->query("SELECT title, slug, type, excerpt, image_path, published_at FROM posts WHERE status='published' ORDER BY published_at DESC LIMIT 3")->fetchAll();
    $causes = $pdo->query("SELECT title, slug, excerpt FROM pages WHERE status='published' AND slug IN ('health-education','youth-women-empowerment','girlie-camp','sexual-gender-based-violence','mentorship','scholarships') ORDER BY title")->fetchAll();
    $homeTitle = $page['title'] ?? 'Safe Spaces for women and children to thrive';
    $homeCopy = $page['excerpt'] ?? 'EDUCATION AT ALL LEVELS Advocating for safer learning environments';
    $homeImage = !empty($page['image_path']) ? $page['image_path'] : 'assets/images/Slider_1A.jpg';
    $content = '<section class="hero"><div class="hero-copy"><p class="eyebrow">' . e(settings()['site_name']) . '</p><h1>' . e($homeTitle) . '</h1><p class="lead">' . e($homeCopy) . '</p><div class="hero-actions"><a class="button button-light" href="' . e(site_url('causes')) . '">Explore our causes</a><a class="button button-ghost" href="' . e(site_url('donate')) . '">Donate</a></div></div><div class="hero-image"><img src="' . e(site_url($homeImage)) . '" alt="Foundation community work"></div></section>';
    $content .= '<section class="intro-section"><div class="section-copy"><p class="eyebrow">Who we are</p><h2>' . e($homeTitle) . '</h2><div class="prose">' . render_text($page['body'] ?? '') . '</div><a class="text-link" href="' . e(site_url('page/our-journey')) . '">Our journey <span>→</span></a></div><img class="intro-image" src="' . e(site_url(!empty($page['image_path']) ? $page['image_path'] : 'assets/images/about.jpg')) . '" alt="Francisca Kwamboka Foundation"></section>';
    $content .= '<section class="causes-section"><div class="section-heading"><p class="eyebrow">Our causes</p><h2>Every cause is connected to our mission</h2><p>All our causes are connected to our mission and all related programmes are designed to enable the organization to achieve specific goals.</p></div><div class="card-grid">';
    foreach ($causes as $cause) {
        $content .= '<article class="content-card"><div class="card-body"><h3>' . e($cause['title']) . '</h3><p>' . e($cause['excerpt']) . '</p><a class="text-link" href="' . e(site_url('page/' . $cause['slug'])) . '">Read more <span>→</span></a></div></article>';
    }
    $content .= '</div></section>';
    if ($posts !== []) {
        $content .= '<section class="news-section"><div class="section-heading"><p class="eyebrow">Updates</p><h2>Latest news &amp; stories</h2></div><div class="card-grid">';
        foreach ($posts as $post) {
            $content .= '<article class="content-card">' . (!empty($post['image_path']) ? '<img src="' . e(site_url($post['image_path'])) . '" alt="">' : '') . '<div class="card-body"><p class="eyebrow">' . e(ucfirst($post['type'])) . '</p><h3>' . e($post['title']) . '</h3><p>' . e($post['excerpt']) . '</p><a class="text-link" href="' . e(site_url($post['type'] . '/' . $post['slug'])) . '">Read more <span>→</span></a></div></article>';
        }
        $content .= '</div></section>';
    }
    layout('Home', $content);
    exit;
}

if ($path === 'causes') {
    $causePage = page_row($pdo, 'our-causes');
    $causes = $pdo->query("SELECT title, slug, excerpt, image_path FROM pages WHERE status='published' AND slug IN ('health-education','youth-women-empowerment','girlie-camp','sexual-gender-based-violence','mentorship','scholarships') ORDER BY title")->fetchAll();
    $content = '<section class="page-hero"><p class="eyebrow">Our causes</p><h1>' . e($causePage['title'] ?? 'Our Causes') . '</h1><p>' . e($causePage['excerpt'] ?? '') . '</p></section><section class="page-content"><div class="card-grid">';
    foreach ($causes as $cause) {
        $content .= '<article class="content-card">' . (!empty($cause['image_path']) ? '<img src="' . e(site_url($cause['image_path'])) . '" alt="">' : '') . '<div class="card-body"><h2>' . e($cause['title']) . '</h2><p>' . e($cause['excerpt']) . '</p><a class="text-link" href="' . e(site_url('page/' . $cause['slug'])) . '">Read more <span>→</span></a></div></article>';
    }
    $content .= '</div></section>';
    layout('Our Causes', $content);
    exit;
}

if ($path === 'news' || $path === 'blog' || $path === 'articles') {
    $newsPage = $path === 'news' ? page_row($pdo, 'events-news') : null;
    $filter = ['news' => 'news', 'blog' => 'blog', 'articles' => 'article'][$path];
    $statement = $pdo->prepare("SELECT * FROM posts WHERE status='published' AND type=? ORDER BY published_at DESC LIMIT 100");
    $statement->execute([$filter]);
    $posts = $statement->fetchAll();
    $content = '<section class="page-hero"><p class="eyebrow">Stories &amp; updates</p><h1>' . e($newsPage['title'] ?? ucfirst($filter === 'article' ? 'Articles' : $filter)) . '</h1>' . (!empty($newsPage['excerpt']) ? '<p>' . e($newsPage['excerpt']) . '</p>' : '') . '</section><section class="page-content">';
    if ($posts === []) {
        $content .= '<div class="empty-state"><h2>Stories coming soon</h2><p>Check back for updates from the foundation.</p></div>';
    } else {
        $content .= '<div class="card-grid">';
        foreach ($posts as $post) {
            $content .= '<article class="content-card">' . (!empty($post['image_path']) ? '<img src="' . e(site_url($post['image_path'])) . '" alt="">' : '') . '<div class="card-body"><p class="eyebrow">' . e(ucfirst($post['type'])) . '</p><h2>' . e($post['title']) . '</h2><p>' . e($post['excerpt']) . '</p><a class="text-link" href="' . e(site_url($post['type'] . '/' . $post['slug'])) . '">Read more <span>→</span></a></div></article>';
        }
        $content .= '</div>';
    }
    $content .= '</section>';
    layout(ucfirst($filter), $content);
    exit;
}

if ($path === 'gallery') {
    $galleryPage = page_row($pdo, 'gallery');
    $items = $pdo->query('SELECT * FROM gallery_items WHERE is_published=1 ORDER BY sort_order, id DESC')->fetchAll();
    $content = '<section class="page-hero"><p class="eyebrow">Our community</p><h1>' . e($galleryPage['title'] ?? 'Gallery') . '</h1>' . (!empty($galleryPage['excerpt']) ? '<p>' . e($galleryPage['excerpt']) . '</p>' : '') . '</section><section class="page-content">';
    if ($items === []) {
        $content .= '<div class="empty-state"><h2>Gallery coming soon</h2><p>Foundation photos will be added here.</p></div>';
    } else {
        $content .= '<div class="gallery-grid">';
        foreach ($items as $item) {
            $content .= '<figure><img src="' . e(site_url($item['image_path'])) . '" alt="' . e($item['alt_text']) . '"><figcaption><strong>' . e($item['title']) . '</strong>' . (!empty($item['caption']) ? '<span>' . e($item['caption']) . '</span>' : '') . '</figcaption></figure>';
        }
        $content .= '</div>';
    }
    $content .= '</section>';
    layout('Gallery', $content);
    exit;
}

if ($path === 'contact') {
    $contactPage = page_row($pdo, 'contacts');
    $siteSettings = settings();
    $sent = isset($_GET['sent']);
    $contactBody = $contactPage['body'] ?? '';
    $contactBody = str_replace(
        ['office@franciscakwambokafoundation.org', '+254 780971932', 'P.O.Box 3952 - 40200, Kisii, Kenya', 'Kisii, Nyamira and Machakos'],
        [$siteSettings['contact_email'], $siteSettings['contact_phone'], $siteSettings['postal_address'], $siteSettings['office_locations']],
        $contactBody
    );
    $contactCopy = $contactPage ? render_text($contactBody) : '';
    $email = e($siteSettings['contact_email']);
    $phone = e($siteSettings['contact_phone']);
    $phoneLink = e(preg_replace('/[^0-9+]/', '', $siteSettings['contact_phone']));
    $content = '<section class="page-hero"><p class="eyebrow">Get involved</p><h1>' . e($contactPage['title'] ?? 'Contacts') . '</h1>' . (!empty($contactPage['excerpt']) ? '<p>' . e($contactPage['excerpt']) . '</p>' : '') . '</section><section class="contact-layout"><div><div class="prose">' . $contactCopy . '</div><p><a href="mailto:' . $email . '">' . $email . '</a></p><p><a href="tel:' . $phoneLink . '">' . $phone . '</a></p></div><form class="contact-form" method="post">' . csrf_input() . ($sent ? '<div class="alert alert-success">Thank you. Your message has been received.</div>' : '') . (!empty($contactError) ? '<div class="alert alert-error">' . e($contactError) . '</div>' : '') . '<label>Your name<input name="name" required maxlength="150"></label><label>Email address<input name="email" type="email" required maxlength="190"></label><label>Phone (optional)<input name="phone" maxlength="50"></label><label>Subject<input name="subject" required maxlength="190"></label><label>Your message<textarea name="message" rows="6" required maxlength="5000"></textarea></label><button class="button button-primary">Send message</button></form></section>';
    layout('Contact Us', $content);
    exit;
}

if ($path === 'donate') {
    $page = page_row($pdo, 'donate');
    $contactEmail = e(settings()['contact_email']);
    $content = '<section class="page-hero"><p class="eyebrow">Give your share</p><h1>' . e($page['excerpt'] ?? 'Support our cause') . '</h1></section><section class="prose page-content">' . render_text($page['body'] ?? 'Please consider supporting our efforts.') . '<p>For more information, please contact <a href="mailto:' . $contactEmail . '">' . $contactEmail . '</a>.</p></section>';
    layout('Donate', $content);
    exit;
}

if (count($segments) === 2 && in_array($segments[0], ['news', 'blog', 'article'], true)) {
    $statement = $pdo->prepare("SELECT * FROM posts WHERE slug=? AND type=? AND status='published' LIMIT 1");
    $type = $segments[0] === 'article' ? 'article' : $segments[0];
    $statement->execute([$segments[1], $type]);
    $post = $statement->fetch();
    if ($post) {
        $content = '<article class="prose article-page"><p class="eyebrow">' . e(ucfirst($post['type'])) . '</p><h1>' . e($post['title']) . '</h1><p class="article-date">' . e($post['published_at']) . '</p>' . (!empty($post['image_path']) ? '<img class="article-cover" src="' . e(site_url($post['image_path'])) . '" alt="">' : '') . '<p class="lead">' . e($post['excerpt']) . '</p>' . render_text($post['body']) . '</article>';
        layout($post['title'], $content);
        exit;
    }
}

if (count($segments) === 2 && $segments[0] === 'page') {
    $page = page_row($pdo, $segments[1]);
    if ($page) {
        $content = '<section class="page-hero"><p class="eyebrow">Francisca Kwamboka Foundation</p><h1>' . e($page['title']) . '</h1>' . (!empty($page['excerpt']) ? '<p>' . e($page['excerpt']) . '</p>' : '') . '</section><article class="prose page-content">' . (!empty($page['image_path']) ? '<img class="article-cover" src="' . e(site_url($page['image_path'])) . '" alt="">' : '') . render_text($page['body']) . '</article>';
        layout($page['title'], $content);
        exit;
    }
}

http_response_code(404);
layout('Page not found', '<section class="page-hero"><p class="eyebrow">404</p><h1>Page not found</h1><a class="button button-primary" href="' . e(site_url()) . '">Return home</a></section>');
