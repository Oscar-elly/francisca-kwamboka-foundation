<?php
declare(strict_types=1);

/**
 * Full HTTP smoke and regression suite for the public site and CMS.
 *
 * It creates a uniquely named disposable database, seeds it, starts PHP's
 * built-in server, exercises public and authenticated routes, and removes the
 * database when finished. It never connects to or mutates the configured site
 * database.
 *
 * Run: php tests/full_site_test.php
 * Keep the disposable database after a failure: set FKF_TEST_KEEP_DB=1
 */

final class HttpClient
{
    private array $cookies = [];

    public function __construct(private readonly string $origin)
    {
    }

    /** @return array{status:int, headers:list<string>, body:string} */
    public function request(string $method, string $path, array $form = []): array
    {
        $headers = ['Accept: text/html,application/xhtml+xml'];
        if ($this->cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($this->cookies),
                $this->cookies
            ));
        }
        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 10,
        ];
        if ($form !== []) {
            $options['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
            $options['content'] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        }
        $body = file_get_contents($this->origin . $path, false, stream_context_create(['http' => $options]));
        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
                $this->cookies[$match[1]] = $match[2];
            }
        }
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $statusMatch);
        return ['status' => (int) ($statusMatch[1] ?? 0), 'headers' => $responseHeaders, 'body' => $body === false ? '' : $body];
    }

    public function csrf(string $path): string
    {
        $response = $this->request('GET', $path);
        if ($response['status'] !== 200 || !preg_match('/name="csrf" value="([a-f0-9]{64})"/', $response['body'], $match)) {
            throw new RuntimeException('Could not obtain a CSRF token from ' . $path);
        }
        return $match[1];
    }
}

function assertion(bool $condition, string $description): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo "PASS  {$description}\n";
        return;
    }
    $failures[] = $description;
    echo "FAIL  {$description}\n";
}

function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), strtolower($name) . ':')) {
            return trim(substr($header, strlen($name) + 1));
        }
    }
    return null;
}

function run_php(string $script, array $environment): void
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start ' . $script);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(basename($script) . " failed:\n" . $stdout . $stderr);
    }
}

function start_server(int $port, array $environment): array
{
    $root = dirname(__DIR__);
    $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root . '/public') . ' ' . escapeshellarg($root . '/public/router.php');
    $logPath = tempnam(sys_get_temp_dir(), 'fkf-server-');
    if ($logPath === false) {
        throw new RuntimeException('Could not create a temporary PHP server log.');
    }
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['file', $logPath, 'a'], ['file', $logPath, 'a']], $pipes, $root, $environment);
    if (!is_resource($process)) {
        unlink($logPath);
        throw new RuntimeException('Could not start the PHP test server.');
    }
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        usleep(100000);
        $probe = @file_get_contents('http://127.0.0.1:' . $port . '/');
        if ($probe !== false) {
            return [$process, $pipes, $logPath];
        }
    }
    proc_terminate($process);
    $log = (string) file_get_contents($logPath);
    unlink($logPath);
    throw new RuntimeException('The PHP test server did not become available.' . ($log === '' ? '' : "\n" . $log));
}

$root = dirname(__DIR__);
$config = require $root . '/config.php';
$db = $config['db'];
$testDb = 'fkf_test_' . bin2hex(random_bytes(5));
$port = random_int(18000, 23999);
$origin = 'http://127.0.0.1:' . $port;
$environment = array_merge(is_array(getenv()) ? getenv() : $_ENV, [
    'FKF_DB_HOST' => (string) $db['host'],
    'FKF_DB_NAME' => $testDb,
    'FKF_DB_USER' => (string) $db['user'],
    'FKF_DB_PASSWORD' => (string) $db['password'],
    'FKF_PUBLIC_URL' => $origin,
]);
$fails = [];
$warnings = [];
$passes = 0;
$failures = [];
$server = null;
$pdo = null;

try {
    $adminPdo = new PDO(
        sprintf('mysql:host=%s;charset=%s', $db['host'], $db['charset']),
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $adminPdo->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $testDb, $db['charset']),
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    run_php($root . '/scripts/migrate.php', $environment);
    assertion((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 5, 'fresh database applies all versioned migrations');
    assertion((int) $pdo->query("SELECT COUNT(*) FROM page_sections")->fetchColumn() > 0, 'initial migration seeds editable starter sections');
    run_php($root . '/scripts/migrate.php', $environment);
    assertion((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 5, 'repeated migration run skips applied migrations');
    $password = 'FunctionalTestPassword!2026';
    $insertUser = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $insertUser->execute(['Test Administrator', 'admin.test@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'admin']);
    $insertUser->execute(['Test Editor', 'editor.test@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'editor']);

    $server = start_server($port, $environment);
    $visitor = new HttpClient($origin);
    $admin = new HttpClient($origin);
    $editor = new HttpClient($origin);

    foreach (['/', '/causes', '/news', '/blog', '/articles', '/gallery', '/contact', '/donate', '/page/our-journey', '/page/youth-women-empowerment', '/page/sexual-gender-based-violence'] as $path) {
        $response = $visitor->request('GET', $path);
        assertion($response['status'] === 200, 'public route ' . $path . ' returns 200');
    }

    $home = $visitor->request('GET', '/');
    preg_match_all('/(?:href|src)="(\/[^"#?]*)"/', $home['body'], $resourceMatches);
    $resources = array_values(array_unique(array_filter($resourceMatches[1] ?? [], static fn(string $url): bool => !str_starts_with($url, '/uploads/'))));
    foreach (array_slice($resources, 0, 30) as $resource) {
        $response = $visitor->request('GET', $resource);
        assertion($response['status'] === 200, 'home page resource ' . $resource . ' is reachable');
    }

    $robots = $visitor->request('GET', '/robots.txt');
    assertion($robots['status'] === 200 && str_contains($robots['body'], 'Disallow: /admin/'), 'robots.txt blocks admin crawling');
    assertion(str_contains($robots['body'], 'Sitemap: ' . $origin . '/sitemap.xml'), 'robots.txt advertises the canonical sitemap');
    $sitemap = $visitor->request('GET', '/sitemap.xml');
    assertion($sitemap['status'] === 200 && str_contains((string) header_value($sitemap['headers'], 'Content-Type'), 'application/xml'), 'sitemap is served as XML');
    assertion(str_contains($sitemap['body'], '<loc>' . $origin . '/contact</loc>'), 'sitemap includes public routes');
    assertion(str_contains($home['body'], '<link rel="canonical" href="' . $origin . '/">'), 'home page emits a canonical URL');
    assertion(str_contains($home['body'], 'application/ld+json') && str_contains($home['body'], 'https://schema.org'), 'home page emits JSON-LD');
    assertion(str_contains($home['body'], 'property="og:title"') && str_contains($home['body'], 'name="twitter:card"'), 'home page emits social metadata');

    $contactNoCsrf = $visitor->request('POST', '/contact', ['name' => 'Test', 'email' => 'test@example.invalid', 'subject' => 'Test', 'message' => 'Test']);
    assertion($contactNoCsrf['status'] === 419, 'contact form rejects a request without CSRF protection');
    $contact = $visitor->request('POST', '/contact', [
        'csrf' => $visitor->csrf('/contact'),
        'name' => 'Functional Test',
        'email' => 'functional@example.invalid',
        'phone' => '+254700000000',
        'subject' => 'Functional test inquiry',
        'message' => 'This disposable inquiry verifies the contact flow.',
    ]);
    assertion($contact['status'] === 303 && header_value($contact['headers'], 'Location') === '/contact?sent=1', 'contact form accepts valid input and redirects');
    $inquiryId = (int) $pdo->query("SELECT id FROM inquiries WHERE subject = 'Functional test inquiry'")->fetchColumn();
    assertion($inquiryId > 0, 'contact submission is stored');

    $guestAdmin = $visitor->request('GET', '/admin');
    assertion($guestAdmin['status'] === 303 && header_value($guestAdmin['headers'], 'Location') === '/admin/login', 'admin dashboard requires authentication');
    $invalidLogin = $admin->request('POST', '/admin/login', ['csrf' => $admin->csrf('/admin/login'), 'email' => 'admin.test@example.invalid', 'password' => 'wrong password']);
    assertion($invalidLogin['status'] === 200 && str_contains($invalidLogin['body'], 'Email or password is incorrect.'), 'invalid admin credentials are rejected');
    $login = $admin->request('POST', '/admin/login', ['csrf' => $admin->csrf('/admin/login'), 'email' => 'admin.test@example.invalid', 'password' => $password]);
    assertion($login['status'] === 303 && header_value($login['headers'], 'Location') === '/admin', 'administrator can sign in');
    assertion($admin->request('GET', '/admin')['status'] === 200, 'administrator dashboard loads');

    $editorLogin = $editor->request('POST', '/admin/login', ['csrf' => $editor->csrf('/admin/login'), 'email' => 'editor.test@example.invalid', 'password' => $password]);
    assertion($editorLogin['status'] === 303, 'editor can sign in');
    assertion($editor->request('GET', '/admin/users')['status'] === 403, 'editor cannot access user administration');

    $faqAdd = $admin->request('POST', '/admin/faqs', [
        'csrf' => $admin->csrf('/admin/faqs'), 'faq_action' => 'add', 'question' => 'Functional suite question?',
        'answer' => 'Functional suite answer.', 'sort_order' => '500', 'is_published' => '1',
    ]);
    assertion($faqAdd['status'] === 303, 'administrator can create an FAQ');
    $faqId = (int) $pdo->query("SELECT id FROM faqs WHERE question = 'Functional suite question?'")->fetchColumn();
    assertion($faqId > 0 && str_contains($visitor->request('GET', '/page/faqs')['body'], 'Functional suite answer.'), 'published FAQ appears publicly');
    $faqUpdate = $admin->request('POST', '/admin/faqs', [
        'csrf' => $admin->csrf('/admin/faqs'), 'faq_action' => 'save', 'faq_id' => (string) $faqId,
        'question' => 'Functional suite question updated?', 'answer' => 'Updated answer.', 'sort_order' => '501', 'is_published' => '1',
    ]);
    assertion($faqUpdate['status'] === 303 && $pdo->query('SELECT question FROM faqs WHERE id = ' . $faqId)->fetchColumn() === 'Functional suite question updated?', 'administrator can update an FAQ');
    $faqDelete = $admin->request('POST', '/admin/faqs', ['csrf' => $admin->csrf('/admin/faqs'), 'faq_action' => 'delete', 'faq_id' => (string) $faqId]);
    assertion($faqDelete['status'] === 303 && (int) $pdo->query('SELECT COUNT(*) FROM faqs WHERE id = ' . $faqId)->fetchColumn() === 0, 'administrator can delete an FAQ');

    $postSave = $admin->request('POST', '/admin/posts/save', [
        'csrf' => $admin->csrf('/admin/posts/new'), 'type' => 'news', 'title' => 'Functional suite post',
        'slug' => 'functional-suite-post', 'excerpt' => 'Disposable test post.', 'body' => 'Disposable test body.',
        'status' => 'published', 'existing_image' => '',
    ]);
    assertion($postSave['status'] === 303, 'administrator can create a published news post');
    $postId = (int) $pdo->query("SELECT id FROM posts WHERE slug = 'functional-suite-post'")->fetchColumn();
    assertion($postId > 0 && $visitor->request('GET', '/news/functional-suite-post')['status'] === 200, 'published news post is publicly reachable');
    $postDelete = $admin->request('POST', '/admin/posts/delete/' . $postId, ['csrf' => $admin->csrf('/admin/posts')]);
    assertion($postDelete['status'] === 303 && (int) $pdo->query('SELECT COUNT(*) FROM posts WHERE id = ' . $postId)->fetchColumn() === 0, 'administrator can delete a news post');

    $homePageId = (int) $pdo->query("SELECT id FROM pages WHERE slug = 'home'")->fetchColumn();
    $sectionAdd = $admin->request('POST', '/admin/page-sections/' . $homePageId, ['csrf' => $admin->csrf('/admin/page-sections/' . $homePageId), 'section_action' => 'add', 'section_type' => 'rich_text']);
    assertion($sectionAdd['status'] === 303, 'administrator can add a page section');
    $sectionId = (int) $pdo->query('SELECT MAX(id) FROM page_sections WHERE page_id = ' . $homePageId)->fetchColumn();
    $sectionDelete = $admin->request('POST', '/admin/page-sections/' . $homePageId, ['csrf' => $admin->csrf('/admin/page-sections/' . $homePageId), 'section_action' => 'delete', 'section_id' => (string) $sectionId]);
    assertion($sectionDelete['status'] === 303, 'administrator can delete a page section');

    $inquiryUpdate = $admin->request('POST', '/admin/inquiries/status/' . $inquiryId, ['csrf' => $admin->csrf('/admin/inquiries'), 'status' => 'read']);
    assertion($inquiryUpdate['status'] === 303 && $pdo->query('SELECT status FROM inquiries WHERE id = ' . $inquiryId)->fetchColumn() === 'read', 'administrator can update an inquiry status');

    $adminCsrf = $admin->request('POST', '/admin/settings', ['site_name' => 'Blocked without CSRF']);
    assertion($adminCsrf['status'] === 419, 'admin settings rejects a request without CSRF protection');
    $logout = $admin->request('POST', '/admin/logout', ['csrf' => $admin->csrf('/admin')]);
    assertion($logout['status'] === 303 && header_value($logout['headers'], 'Location') === '/admin/login', 'administrator can sign out');

    foreach (['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy'] as $securityHeader) {
        if (header_value($home['headers'], $securityHeader) === null) {
            $warnings[] = 'Missing ' . $securityHeader . ' response header.';
        }
    }
    if (!str_contains((string) file_get_contents($root . '/public/index.php'), 'login_attempt')) {
        $warnings[] = 'No login rate limiting or lockout mechanism was found.';
    }
} catch (Throwable $error) {
    $failures[] = 'Suite setup or execution: ' . $error->getMessage();
    echo 'FAIL  ' . end($failures) . "\n";
} finally {
    if (is_array($server)) {
        proc_terminate($server[0]);
        foreach ([$server[1][1] ?? null, $server[1][2] ?? null] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($server[0]);
        $serverLog = (string) file_get_contents($server[2]);
        if ($failures !== [] && $serverLog !== '') {
            echo "\nPHP server log:\n{$serverLog}";
        }
        unlink($server[2]);
    }
    if (getenv('FKF_TEST_KEEP_DB') !== '1') {
        try {
            $pdo = null;
            $cleanup = new PDO(sprintf('mysql:host=%s;charset=%s', $db['host'], $db['charset']), $db['user'], $db['password']);
            $cleanup->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        } catch (Throwable $cleanupError) {
            $failures[] = 'Disposable test database cleanup failed: ' . $cleanupError->getMessage();
        }
    }
}

echo "\n{$passes} passed, " . count($failures) . " failed\n";
if ($warnings !== []) {
    echo "\nSecurity and SEO warnings:\n";
    foreach ($warnings as $warning) {
        echo "- {$warning}\n";
    }
}
if ($failures !== []) {
    exit(1);
}
