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

function public_origin(): ?string
{
    global $config;
    $configuredUrl = trim((string) ($config['public_url'] ?? ''));
    if ($configuredUrl === '') {
        $configuredUrl = trim((string) (getenv('FKF_PUBLIC_URL') ?: ''));
    }
    if ($configuredUrl !== '') {
        $parts = parse_url($configuredUrl);
        if (
            filter_var($configuredUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('The configured public_url must be an HTTP or HTTPS origin without a path, query, or credentials.');
        }
        return rtrim($configuredUrl, '/');
    }

    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $hostParts = parse_url('http://' . $host);
    if (!is_array($hostParts) || !in_array($hostParts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)) {
        return null;
    }
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function request_path(): string
{
    global $config;
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? $requestPath : '/';
    $basePath = rtrim((string) ($config['base_path'] ?? ''), '/');
    if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
        $requestPath = substr($requestPath, strlen($basePath));
    }
    return trim($requestPath, '/');
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
    $html = '';
    foreach ($blocks as $block) {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $block) ?: []),
            static fn(string $line): bool => $line !== ''
        ));
        if ($lines === []) {
            continue;
        }

        $heading = '';
        if (count($lines) > 1 && strlen($lines[0]) <= 80 && !preg_match('/[.!?;:]$/u', $lines[0])) {
            $heading = array_shift($lines);
        }
        if ($heading !== '') {
            $html .= '<h2>' . e($heading) . '</h2>';
        }

        $paragraph = [];
        $list = [];
        foreach ($lines as $line) {
            if (preg_match('/^[-•]\s+(.+)$/u', $line, $match)) {
                if ($paragraph !== []) {
                    $html .= '<p>' . nl2br(e(implode("\n", $paragraph)), false) . '</p>';
                    $paragraph = [];
                }
                $list[] = $match[1];
                continue;
            }
            if ($list !== []) {
                $html .= '<ul>' . implode('', array_map(
                    static fn(string $item): string => '<li>' . e($item) . '</li>',
                    $list
                )) . '</ul>';
                $list = [];
            }
            $paragraph[] = $line;
        }
        if ($paragraph !== []) {
            $html .= '<p>' . nl2br(e(implode("\n", $paragraph)), false) . '</p>';
        }
        if ($list !== []) {
            $html .= '<ul>' . implode('', array_map(
                static fn(string $item): string => '<li>' . e($item) . '</li>',
                $list
            )) . '</ul>';
        }
    }
    return $html;
}

function render_programme_section(string $slug, string $body): string
{
    $renderedBody = render_text($body);
    preg_match_all('~<p>.*?</p>~s', $renderedBody, $matches);
    $paragraphs = $matches[0] ?? [];

    if ($slug === 'youth-women-empowerment' && count($paragraphs) >= 7) {
        $themes = [
            ['id' => 'voice', 'title' => 'A voice in decisions', 'body' => array_slice($paragraphs, 1, 3)],
            ['id' => 'livelihoods', 'title' => 'Skills and livelihoods', 'body' => array_slice($paragraphs, 4, 3)],
            ['id' => 'wellbeing', 'title' => 'Wellbeing and rights', 'body' => array_slice($paragraphs, 7)],
        ];
        return render_programme_themes('From participation to opportunity', '', $themes);
    }

    if ($slug === 'sexual-gender-based-violence' && count($paragraphs) >= 5) {
        $intro = array_shift($paragraphs);
        $themes = [
            ['id' => 'prevention', 'title' => 'Prevention needs sustained action', 'body' => array_slice($paragraphs, 0, 1)],
            ['id' => 'accountability', 'title' => 'Turning commitments into action', 'body' => array_slice($paragraphs, 1, 2)],
            ['id' => 'survivor-support', 'title' => 'Justice and care for survivors', 'body' => array_slice($paragraphs, 3)],
        ];
        return render_programme_themes('A safer future takes all of us', '<div class="programme-story-intro prose">' . $intro . '</div>', $themes);
    }

    if ($slug === 'health-education' && count($paragraphs) >= 3) {
        $intro = '<div class="programme-story-intro prose">' . array_shift($paragraphs) . '</div>';
        return render_programme_themes('Prevention starts with awareness', $intro, [
            ['id' => 'understanding-risk', 'title' => 'Understanding risk in the community', 'body' => [$paragraphs[0]]],
            ['id' => 'local-prevention', 'title' => 'Prevention close to home', 'body' => array_slice($paragraphs, 1)],
        ]);
    }

    if ($slug === 'girlie-camp' && count($paragraphs) >= 6) {
        $intro = '<div class="programme-story-intro prose">' . array_shift($paragraphs) . '</div>';
        return render_programme_themes('A camp that grows into a lifelong circle of support', $intro, [
            ['id' => 'mentors', 'title' => 'Encouragement across generations', 'body' => array_slice($paragraphs, 0, 2)],
            ['id' => 'beyond-camp', 'title' => 'Support that continues beyond camp', 'body' => array_slice($paragraphs, 2, 1)],
            ['id' => 'take-part', 'title' => 'Help a girl take part', 'body' => array_slice($paragraphs, 3)],
        ]);
    }

    if ($slug === 'mentorship' && count($paragraphs) >= 3) {
        $intro = '<div class="programme-story-intro prose">' . array_shift($paragraphs) . '</div>';
        return render_programme_themes('Guidance, skills and room to grow', $intro, [
            ['id' => 'young-leaders', 'title' => 'A relationship that opens possibilities', 'body' => array_slice($paragraphs, 0, 2)],
            ['id' => 'lasting-benefits', 'title' => 'Benefits for mentors and mentees', 'body' => array_slice($paragraphs, 2)],
        ]);
    }

    if ($slug === 'scholarships' && count($paragraphs) === 1 && preg_match('~<p>(.*?)</p>~s', $paragraphs[0], $match)) {
        $sentences = preg_split('~(?<=[.!?])\s+(?=[A-Z])~u', $match[1]) ?: [$match[1]];
        if (count($sentences) >= 3) {
            $intro = '<div class="programme-story-intro prose"><p>' . array_shift($sentences) . '</p></div>';
            return render_programme_themes('A more supported path through school', $intro, [
                ['id' => 'school-support', 'title' => 'Support through the school years', 'body' => ['<p>' . array_shift($sentences) . '</p>']],
                ['id' => 'scholars', 'title' => 'Growing opportunity for scholars', 'body' => ['<p>' . implode(' ', $sentences) . '</p>']],
            ]);
        }
    }

    return '<section class="programme-story"><div class="programme-story-heading"><p class="eyebrow">Programme in focus</p>' .
        '<div class="prose">' . $renderedBody . '</div></div></section>';
}

function render_programme_themes(string $title, string $intro, array $themes): string
{
    $html = '<section class="programme-story"><div class="programme-story-heading"><p class="eyebrow">Programme in focus</p><h2>' . e($title) . '</h2>' . $intro . '</div>' .
        '<nav class="programme-chapter-nav" aria-label="Explore programme themes">';
    foreach ($themes as $index => $theme) {
        $html .= '<a href="#programme-theme-' . e($theme['id']) . '"><span aria-hidden="true">' . sprintf('%02d', $index + 1) . '</span>' . e($theme['title']) . '</a>';
    }
    $html .= '</nav><div class="programme-narrative">';
    foreach ($themes as $index => $theme) {
        $html .= '<section class="programme-chapter" id="programme-theme-' . e($theme['id']) . '"><div class="programme-chapter-title"><span aria-hidden="true">' . sprintf('%02d', $index + 1) . '</span><h3>' . e($theme['title']) . '</h3></div><div class="prose">' . implode('', $theme['body']) . '</div></section>';
    }
    return $html . '</div></section>';
}

function page_sections(PDO $pdo, int $pageId): array
{
    $statement = $pdo->prepare(
        'SELECT id, section_type, eyebrow, title, body, image_path, link_label, link_url, sort_order
         FROM page_sections
         WHERE page_id = ? AND is_enabled = 1
         ORDER BY sort_order, id'
    );
    $statement->execute([$pageId]);
    return $statement->fetchAll();
}

function page_has_sections(PDO $pdo, int $pageId): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM page_sections WHERE page_id = ? LIMIT 1');
    $statement->execute([$pageId]);
    return $statement->fetchColumn() !== false;
}

function render_page_sections(array $sections, string $pageSlug = '', ?PDO $pdo = null): string
{
    $html = '';
    $count = count($sections);
    for ($index = 0; $index < $count; $index++) {
        $section = $sections[$index];
        $eyebrow = trim((string) ($section['eyebrow'] ?? ''));
        $title = trim((string) ($section['title'] ?? ''));
        $body = trim((string) ($section['body'] ?? ''));
        $image = trim((string) ($section['image_path'] ?? ''));
        $linkLabel = trim((string) ($section['link_label'] ?? ''));
        $linkUrl = trim((string) ($section['link_url'] ?? ''));
        $heading = $title !== '' ? '<h2>' . e($title) . '</h2>' : '';
        $kicker = $eyebrow !== '' ? '<p class="eyebrow">' . e($eyebrow) . '</p>' : '';
        $action = $linkLabel !== '' && $linkUrl !== ''
            ? '<a class="text-link" href="' . e(site_url($linkUrl)) . '">' . e($linkLabel) . ' <span>→</span></a>'
            : '';

        if ($section['section_type'] === 'profile') {
            $profiles = [];
            while ($index < $count && $sections[$index]['section_type'] === 'profile') {
                $profile = $sections[$index];
                $profileTitle = trim((string) $profile['title']);
                $profileBody = trim((string) ($profile['body'] ?? ''));
                $profileImage = trim((string) ($profile['image_path'] ?? ''));
                $profileRole = trim((string) ($profile['eyebrow'] ?? ''));
                $profileSummary = $profileBody;
                if ($profileBody !== '' && preg_match('/^.{1,180}(?:\s|$)/us', $profileBody, $match) && strlen($match[0]) < strlen($profileBody)) {
                    $profileSummary = rtrim($match[0]) . '…';
                }
                $profileContent = $profileBody !== ''
                    ? '<p class="board-bio-preview">' . e($profileSummary) . '</p><details class="board-profile"><summary>Read full profile</summary><p>' . e($profileBody) . '</p></details>'
                    : '';
                $profiles[] = '<article class="board-card">' .
                    ($profileImage !== '' ? '<img class="board-portrait" src="' . e(site_url($profileImage)) . '" alt="Portrait of ' . e($profileTitle) . '">' : '') .
                    '<div class="board-card-body">' . ($profileRole !== '' ? '<p class="board-role">' . e($profileRole) . '</p>' : '') .
                    '<h2>' . e($profileTitle) . '</h2>' . $profileContent . '</div></article>';
                $index++;
            }
            $index--;
            $html .= '<section class="page-content board-section"><div class="board-list">' . implode('', $profiles) . '</div></section>';
            continue;
        }

        if ($section['section_type'] === 'hero') {
            if ($pageSlug === 'home') {
                $heroImage = $image !== '' ? $image : 'assets/images/Slider_1A.jpg';
                $html .= '<section class="hero home-hero"><div class="home-hero-copy"><h1>' . e($title) . '</h1>' .
                    ($body !== '' ? '<p class="lead">' . e($body) . '</p>' : '') .
                    '<div class="hero-actions"><a class="button button-primary home-donate-button" href="' . e(site_url('donate')) . '">Help a child thrive</a>' .
                    ($linkLabel !== '' && $linkUrl !== '' ? '<a class="home-text-link" href="' . e(site_url($linkUrl)) . '">' . e($linkLabel) . ' <span>→</span></a>' : '<a class="home-text-link" href="' . e(site_url('page/our-journey')) . '">Meet the foundation <span>→</span></a>') . '</div>' .
                    '<p class="home-hero-promise">Safe spaces. Strong families. Hopeful futures.</p></div>' .
                    '<div class="home-hero-art"><div class="home-photo-shape"><img src="' . e(site_url($heroImage)) . '" alt="Children and community members taking part in Foundation programmes"></div></div></section>';
                continue;
            }
            if ($pageSlug === 'fkf-board') {
                $html .= '<section class="page-hero board-page-hero">' . $kicker . '<h1>' . e($title) . '</h1>' .
                    ($body !== '' ? '<p>' . e($body) . '</p>' : '') . '</section>';
                continue;
            }
            $heroClass = $image !== '' ? 'hero' : 'hero hero-copy-only';
            $html .= '<section class="' . $heroClass . '"><div class="hero-copy">' . $kicker . '<h1>' . e($title) . '</h1>' .
                ($body !== '' ? '<p class="lead">' . e($body) . '</p>' : '') .
                '<div class="hero-actions">' . ($linkLabel !== '' && $linkUrl !== '' ? '<a class="button button-light" href="' . e(site_url($linkUrl)) . '">' . e($linkLabel) . '</a>' : '') .
                '<a class="button button-ghost" href="' . e(site_url('donate')) . '">Donate</a></div></div>' .
                ($image !== '' ? '<div class="hero-image"><img src="' . e(site_url($image)) . '" alt=""></div>' : '') . '</section>';
            continue;
        }

        if ($section['section_type'] === 'image_text') {
            $introClass = $image !== '' ? 'intro-section' : 'intro-section intro-text-only';
            $homeIntroClass = $pageSlug === 'home' ? ' home-intro' : '';
            $html .= '<section class="' . $introClass . $homeIntroClass . '"><div class="section-copy">' . $kicker . $heading .
                ($body !== '' ? '<div class="prose">' . render_text($body) . '</div>' : '') . $action . '</div>' .
                ($image !== '' ? '<img class="intro-image" src="' . e(site_url($image)) . '" alt="' . e($title) . '">' : '') . '</section>';
            continue;
        }

        if ($section['section_type'] === 'feature_cards') {
            $cards = [];
            $featureItems = [];
            foreach (preg_split('/\R/u', $body) ?: [] as $line) {
                $parts = array_map('trim', explode('|', $line, 3));
                if ($parts[0] === '') {
                    continue;
                }
                $cardTitle = $parts[0];
                $cardBody = $parts[1] ?? '';
                $cardUrl = $parts[2] ?? '';
                $featureItems[] = [
                    'title' => $cardTitle,
                    'body' => $cardBody,
                    'url' => $cardUrl,
                ];
                $cards[] = '<article class="editorial-item"><span class="editorial-number">' . sprintf('%02d', count($cards) + 1) . '</span><div class="editorial-item-copy"><h3>' . e($cardTitle) . '</h3>' .
                    ($cardBody !== '' ? '<p>' . e($cardBody) . '</p>' : '') .
                    ($cardUrl !== '' ? '<a class="text-link" href="' . e(site_url($cardUrl)) . '">Explore this work <span>→</span></a>' : '') .
                    '</div></article>';
            }
            if ($pageSlug === 'home' && count($featureItems) <= 3) {
                $missionIndex = 0;
                foreach ($featureItems as $itemIndex => $item) {
                    if (stripos($item['title'], 'mission') !== false) {
                        $missionIndex = $itemIndex;
                        break;
                    }
                }
                $mission = $featureItems[$missionIndex] ?? null;
                $principles = array_values(array_filter(
                    $featureItems,
                    static fn(array $item, int $itemIndex): bool => $itemIndex !== $missionIndex && $item['title'] !== '',
                    ARRAY_FILTER_USE_BOTH
                ));
                $principleMarkup = '';
                foreach ($principles as $principle) {
                    $principleMarkup .= '<article class="purpose-principle"><h3>' . e($principle['title']) . '</h3>' .
                        ($principle['body'] !== '' ? '<p>' . e($principle['body']) . '</p>' : '') . '</article>';
                }
                $html .= '<section class="home-purpose"><div class="purpose-heading"><h2>Our mission</h2></div>' .
                    '<div class="purpose-layout">' .
                    ($mission !== null ? '<article class="purpose-mission"><span class="purpose-quote" aria-hidden="true">“</span><p class="purpose-mission-copy">' . e($mission['body']) . '</p></article>' : '') .
                    ($principleMarkup !== '' ? '<div class="purpose-principles">' . $principleMarkup . '</div>' : '') .
                    '</div></section>';
                continue;
            }

            $hasMoreHomeItems = $pageSlug === 'home' && count($cards) > 3;
            if ($hasMoreHomeItems) {
                $featureItems = array_slice($featureItems, 0, 3);
            }
            $featureSectionClass = $pageSlug === 'home'
                ? ($hasMoreHomeItems ? 'home-programmes' : 'home-purpose')
                : 'page-content';
            if ($hasMoreHomeItems && $pdo !== null) {
                $fallbackImages = [
                    'health-education' => 'assets/images/cause-2a.jpg',
                    'youth-women-empowerment' => 'assets/images/about.jpg',
                    'girlie-camp' => 'assets/images/camp1.jpg',
                    'sexual-gender-based-violence' => 'assets/images/cause-1a.jpg',
                    'mentorship' => 'assets/images/cause-5.jpg',
                    'scholarships' => 'assets/images/cause-6a.jpg',
                ];
                $causeItems = [];
                foreach ($featureItems as $item) {
                    $causeSlug = preg_match('~^page/([a-z0-9-]+)$~', $item['url'], $match) ? $match[1] : '';
                    $causePage = $causeSlug !== '' ? page_row($pdo, $causeSlug) : null;
                    $causeImage = $causePage['image_path'] ?? ($fallbackImages[$causeSlug] ?? '');
                    $causeItems[] = [
                        ...$item,
                        'image' => $causeImage,
                    ];
                }
                $featuredCause = array_shift($causeItems);
                $causeMarkup = '';
                if ($featuredCause !== null) {
                    $causeMarkup .= '<a class="cause-collage-main" href="' . e(site_url($featuredCause['url'])) . '">' .
                        ($featuredCause['image'] !== '' ? '<img src="' . e(site_url($featuredCause['image'])) . '" alt="">' : '') .
                        '<span class="cause-collage-title">' . e($featuredCause['title']) . '</span><span class="cause-collage-arrow" aria-hidden="true">↗</span></a>';
                }
                if ($causeItems !== []) {
                    $causeMarkup .= '<div class="cause-collage-side">';
                    foreach ($causeItems as $causeIndex => $causeItem) {
                        $causeMarkup .= '<a class="cause-collage-small cause-collage-small-' . ($causeIndex + 1) . '" href="' . e(site_url($causeItem['url'])) . '">' .
                            ($causeItem['image'] !== '' ? '<img src="' . e(site_url($causeItem['image'])) . '" alt="">' : '') .
                            '<span class="cause-collage-title">' . e($causeItem['title']) . '</span><span class="cause-collage-arrow" aria-hidden="true">↗</span></a>';
                    }
                    $causeMarkup .= '</div>';
                }
                $html .= '<section class="home-programmes"><h2 class="programmes-title">' . e($title) . '</h2><div class="cause-collage">' . $causeMarkup . '</div>' .
                    '<a class="text-link programmes-more-link" href="' . e(site_url('causes')) . '">Explore all our work <span>→</span></a></section>';
                continue;
            }
            $html .= '<section class="editorial-section ' . $featureSectionClass . '"><div class="section-heading">' . $kicker . $heading . '</div>' .
                ($cards !== [] ? '<div class="editorial-list">' . implode('', array_slice($cards, 0, 3)) . '</div>' : '') .
                ($hasMoreHomeItems ? '<a class="text-link editorial-more-link" href="' . e(site_url('causes')) . '">Explore all our work <span>→</span></a>' : '') .
                '</section>';
            continue;
        }

        if ($section['section_type'] === 'donation_options') {
            $options = [];
            $optionBlocks = preg_split('/\R{2,}/u', $body) ?: [];
            foreach ($optionBlocks as $optionBlock) {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $optionBlock) ?: [])));
                if ($lines === []) {
                    continue;
                }
                $optionTitle = array_shift($lines);
                $optionBody = implode("\n", $lines);
                $subject = rawurlencode($optionTitle . ' donation');
                $options[] = '<article class="donation-card"><span class="donation-icon" aria-hidden="true">' . sprintf('%02d', count($options) + 1) . '</span>' .
                    '<p class="eyebrow">' . e($eyebrow) . '</p><h3>' . e($optionTitle) . '</h3>' .
                    ($optionBody !== '' ? '<p>' . nl2br(e($optionBody), false) . '</p>' : '') .
                    '<a class="text-link" href="mailto:' . e(settings()['contact_email']) . '?subject=' . e($subject) . '">Ask about ' . e($optionTitle) . ' <span>→</span></a></article>';
            }
            $html .= '<section class="page-content donation-section"><div class="section-heading">' . $kicker . $heading .
                ($body !== '' ? '<p>Contact the Foundation and we will guide you through the available options.</p>' : '') .
                '</div><div class="donation-options">' . implode('', $options) . '</div>' .
                '<p class="donation-note">The Foundation is currently unable to provide tax-deductible receipts.</p>' .
                '<div class="donation-contact"><p>Need help or have a question about giving?</p><a class="button button-primary" href="' . e(site_url('contact')) . '">Contact the Foundation</a></div></section>';
            continue;
        }

        if ($section['section_type'] === 'call_to_action') {
            $html .= '<section class="donate-banner"><div>' . $kicker . '<h2>' . e($title) . '</h2>' .
                ($body !== '' ? '<p>' . e($body) . '</p>' : '') . '</div>' .
                ($action !== '' ? '<a class="button button-light" href="' . e(site_url($linkUrl)) . '">' . e($linkLabel) . '</a>' : '') . '</section>';
            continue;
        }

        $html .= '<section class="prose page-content section-story">' . $kicker . $heading . render_text($body) . '</section>';
    }
    return $html;
}

function layout(string $title, string $content, bool $admin = false, array $seo = []): void
{
    $siteSettings = settings();
    $siteName = e($siteSettings['site_name']);
    $user = current_user();
    $description = trim((string) ($seo['description'] ?? ''));
    if ($description === '') {
        $plainContent = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        preg_match('/^.{1,160}(?:\s|$)/us', $plainContent, $descriptionMatch);
        $description = $descriptionMatch[0] ?? 'Support children, women and young people through the community programmes of the Francisca Kwamboka Foundation.';
    }
    if (preg_match('/^.{1,160}(?:\s|$)/us', trim($description), $descriptionMatch)) {
        $description = rtrim($descriptionMatch[0]);
    }
    $canonicalPath = (string) ($seo['path'] ?? request_path());
    $origin = public_origin();
    $canonicalUrl = $origin !== null ? $origin . site_url($canonicalPath) : '';
    $socialImagePath = trim((string) ($seo['image'] ?? $siteSettings['logo_path']));
    $socialImageUrl = $origin !== null && $socialImagePath !== '' ? $origin . site_url($socialImagePath) : '';
    $structuredData = $seo['structured_data'] ?? null;
    $organization = [
        '@type' => 'Organization',
        'name' => $siteSettings['site_name'],
        'description' => $description,
        'logo' => $socialImageUrl !== '' ? $socialImageUrl : null,
        'url' => $origin !== null ? $origin . site_url() : null,
    ];
    $organization = array_filter($organization, static fn(mixed $value): bool => $value !== null);
    $website = [
        '@type' => 'WebSite',
        'name' => $siteSettings['site_name'],
        'url' => $origin !== null ? $origin . site_url() : null,
    ];
    $website = array_filter($website, static fn(mixed $value): bool => $value !== null);
    $graph = array_values(array_filter([$organization, $website]));
    if (is_array($structuredData)) {
        $graph[] = $structuredData;
    }
    $jsonLd = json_encode(
        ['@context' => 'https://schema.org', '@graph' => $graph],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    $nav = $admin
        ? '<a href="' . e(site_url('admin')) . '">Dashboard</a><a href="' . e(site_url('admin/page-sections')) . '">Edit pages</a><a href="' . e(site_url('admin/faqs')) . '">FAQs</a><a href="' . e(site_url('admin/posts')) . '">News &amp; articles</a><a href="' . e(site_url('admin/gallery')) . '">Gallery</a>' .
            (($user['role'] ?? '') === 'admin' ? '<a href="' . e(site_url('admin/users')) . '">Users</a><a href="' . e(site_url('admin/settings')) . '">Site settings</a>' : '') .
            '<a href="' . e(site_url('admin/inquiries')) . '">Inquiries</a>'
        : '<a href="' . e(site_url()) . '">Home</a>' .
            '<details class="nav-group"><summary>About us</summary><div class="nav-menu"><a href="' . e(site_url('page/our-journey')) . '">Our journey</a><a href="' . e(site_url('page/fkf-board')) . '">Our board</a><a href="' . e(site_url('page/faqs')) . '">Frequently asked questions</a></div></details>' .
            '<details class="nav-group"><summary>Our work</summary><div class="nav-menu"><a href="' . e(site_url('causes')) . '">All causes</a><a href="' . e(site_url('page/health-education')) . '">Health &amp; education</a><a href="' . e(site_url('page/youth-women-empowerment')) . '">Women &amp; youth</a><a href="' . e(site_url('page/sexual-gender-based-violence')) . '">Safe communities</a></div></details>' .
            '<a href="' . e(site_url('news')) . '">Stories</a><a href="' . e(site_url('gallery')) . '">Gallery</a>' .
            '<details class="nav-group"><summary>Get involved</summary><div class="nav-menu"><a href="' . e(site_url('donate')) . '">Ways to give</a><a href="' . e(site_url('contact')) . '">Contact us</a></div></details>' .
            '<a class="nav-cta" href="' . e(site_url('donate')) . '">Donate</a>';
    $adminLinks = $admin && $user
        ? '<a class="button button-outline" href="' . e(site_url()) . '">View site</a><form method="post" action="' . e(site_url('admin/logout')) . '">' . csrf_input() . '<button class="button button-light" type="submit">Sign out</button></form>'
        : '';
    $bodyClass = $admin ? 'admin-shell' : 'site-shell';
    $robots = $admin ? 'noindex, nofollow' : (string) ($seo['robots'] ?? 'index, follow');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="' . e($description) . '"><meta name="robots" content="' . e($robots) . '"><meta property="og:site_name" content="' . $siteName . '"><meta property="og:title" content="' . e($title) . '"><meta property="og:description" content="' . e($description) . '"><meta property="og:type" content="' . e($seo['type'] ?? 'website') . '"><meta name="twitter:card" content="' . ($socialImageUrl !== '' ? 'summary_large_image' : 'summary') . '"><meta name="twitter:title" content="' . e($title) . '"><meta name="twitter:description" content="' . e($description) . '">' . ($canonicalUrl !== '' ? '<link rel="canonical" href="' . e($canonicalUrl) . '"><meta property="og:url" content="' . e($canonicalUrl) . '">' : '') . ($socialImageUrl !== '' ? '<meta property="og:image" content="' . e($socialImageUrl) . '"><meta name="twitter:image" content="' . e($socialImageUrl) . '">' : '') . '<title>' . e($title) . ' | ' . $siteName . '</title><link rel="stylesheet" href="' . e(site_url('assets/site.css')) . '"><script type="application/ld+json">' . $jsonLd . '</script></head><body class="' . $bodyClass . '">';
    echo '<header class="site-header"><a class="brand" href="' . e(site_url()) . '"><img src="' . e(site_url($siteSettings['logo_path'])) . '" alt="' . $siteName . '"></a><button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">☰</button><nav class="main-nav" aria-label="Main navigation">' . $nav . '</nav><div class="header-actions">' . $adminLinks . '</div></header>';
    echo '<main>' . $content . '</main>';
    if (!$admin) {
        $email = e($siteSettings['contact_email']);
        $phone = e($siteSettings['contact_phone']);
        echo '<footer class="site-footer"><div class="footer-about"><a class="brand brand-footer" href="' . e(site_url()) . '"><img src="' . e(site_url($siteSettings['logo_path'])) . '" alt="' . $siteName . '"></a><p>A nonprofit organization serving communities in ' . e($siteSettings['office_locations']) . '.</p><p>' . e($siteSettings['postal_address']) . '</p></div><div><h2>Explore</h2><a href="' . e(site_url('page/our-journey')) . '">Our journey</a><a href="' . e(site_url('page/fkf-board')) . '">Our board</a><a href="' . e(site_url('causes')) . '">Our work</a><a href="' . e(site_url('news')) . '">Stories</a><a href="' . e(site_url('gallery')) . '">Gallery</a></div><div><h2>Get involved</h2><a href="' . e(site_url('donate')) . '">Ways to give</a><a href="' . e(site_url('contact')) . '">Contact us</a><a href="mailto:' . $email . '">' . $email . '</a><a href="tel:' . e(preg_replace('/[^0-9+]/', '', $siteSettings['contact_phone'])) . '">' . $phone . '</a></div><div class="footer-bottom">©' . date('Y') . ' ' . $siteName . '. All Rights Reserved.</div></footer>';
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

if ($path === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: " . site_url() . "\nDisallow: " . site_url('admin/') . "\n";
    $origin = public_origin();
    if ($origin !== null) {
        echo 'Sitemap: ' . $origin . site_url('sitemap.xml') . "\n";
    }
    exit;
}

if ($path === 'sitemap.xml') {
    $origin = public_origin();
    if ($origin === null) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Configure public_url to publish the XML sitemap.');
    }
    $urls = [
        ['path' => '', 'updated' => null],
        ...array_map(
            static fn(string $route): array => ['path' => $route, 'updated' => null],
            ['causes', 'news', 'blog', 'articles', 'gallery', 'contact', 'donate']
        ),
    ];
    foreach ($pdo->query("SELECT slug, updated_at FROM pages WHERE status = 'published' AND slug <> 'home' ORDER BY slug")->fetchAll() as $row) {
        $urls[] = ['path' => 'page/' . $row['slug'], 'updated' => $row['updated_at']];
    }
    foreach ($pdo->query("SELECT type, slug, updated_at FROM posts WHERE status = 'published' ORDER BY updated_at DESC")->fetchAll() as $row) {
        $route = $row['type'] === 'article' ? 'article' : $row['type'];
        $urls[] = ['path' => $route . '/' . $row['slug'], 'updated' => $row['updated_at']];
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $url) {
        $location = $origin . site_url($url['path']);
        echo '<url><loc>' . e($location) . '</loc>';
        if (!empty($url['updated'])) {
            echo '<lastmod>' . e(date(DATE_W3C, strtotime((string) $url['updated']))) . '</lastmod>';
        }
        echo '</url>';
    }
    echo '</urlset>';
    exit;
}

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

if ($path === 'admin/faqs') {
    require_user();
    $faqError = '';
    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['faq_action'] ?? '');
        $faqId = filter_var($_POST['faq_id'] ?? 0, FILTER_VALIDATE_INT);
        try {
            if ($action === 'delete' && $faqId > 0) {
                $delete = $pdo->prepare('DELETE FROM faqs WHERE id = ?');
                $delete->execute([$faqId]);
                go('admin/faqs?saved=deleted');
            }
            if (!in_array($action, ['add', 'save'], true)) {
                http_response_code(405);
                exit('Unsupported FAQ action.');
            }
            $question = trim((string) ($_POST['question'] ?? ''));
            $answer = trim((string) ($_POST['answer'] ?? ''));
            $sortOrder = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
            if ($question === '' || strlen($question) > 255 || $answer === '' || strlen($answer) > 10000 || $sortOrder === false) {
                throw new RuntimeException('Enter a question, an answer under 10,000 characters, and a whole-number display order.');
            }
            $isPublished = (($_POST['is_published'] ?? null) === '1') ? 1 : 0;
            if ($action === 'add') {
                $save = $pdo->prepare('INSERT INTO faqs (question, answer, sort_order, is_published) VALUES (?, ?, ?, ?)');
                $save->execute([$question, $answer, $sortOrder, $isPublished]);
            } else {
                if ($faqId < 1) {
                    throw new RuntimeException('The selected FAQ is not valid.');
                }
                $save = $pdo->prepare('UPDATE faqs SET question = ?, answer = ?, sort_order = ?, is_published = ? WHERE id = ?');
                $save->execute([$question, $answer, $sortOrder, $isPublished, $faqId]);
            }
            go('admin/faqs?saved=1');
        } catch (RuntimeException $exception) {
            $faqError = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('CMS FAQ save failed: ' . $exception->getMessage());
            $faqError = 'The FAQ could not be saved. Please try again.';
        }
    }

    $faqs = $pdo->query('SELECT id, question, answer, sort_order, is_published FROM faqs ORDER BY sort_order, id')->fetchAll();
    $content = '<section class="admin-page"><div class="admin-heading"><div><p class="eyebrow">Help visitors find answers</p><h1>Frequently asked questions</h1></div><a class="button button-outline" target="_blank" rel="noopener" href="' . e(site_url('page/faqs')) . '">View FAQ page</a></div>' .
        '<p class="section-editor-intro">Add clear answers to common questions. Published answers appear on the public FAQ page; drafts remain visible only here.</p>' .
        (!empty($faqError) ? '<div class="alert alert-error">' . e($faqError) . '</div>' : '') .
        (isset($_GET['saved']) ? '<div class="alert alert-success">' . ($_GET['saved'] === 'deleted' ? 'FAQ deleted.' : 'FAQ saved.') . '</div>' : '') .
        '<section class="faq-add-panel"><h2>Add a question</h2><form class="editor-form faq-form" method="post">' . csrf_input() .
        '<input type="hidden" name="faq_action" value="add"><label>Question<input name="question" maxlength="255" required></label><label>Answer<textarea name="answer" rows="5" maxlength="10000" required></textarea></label>' .
        '<div class="faq-form-options"><label>Display order<input type="number" name="sort_order" value="0" required></label><label class="checkbox-label"><input type="checkbox" name="is_published" value="1" checked> Publish this answer</label></div>' .
        '<button class="button button-primary">Add FAQ</button></form></section><h2 class="faq-list-title">Saved questions</h2><div class="faq-admin-list">';
    if ($faqs === []) {
        $content .= '<div class="empty-state"><h3>No FAQs yet</h3><p>Add your first answer above.</p></div>';
    }
    foreach ($faqs as $faq) {
        $faqId = (int) $faq['id'];
        $content .= '<article class="faq-admin-item"><details><summary><span>' . e($faq['question']) . '</span><span class="status-badge">' . ((int) $faq['is_published'] === 1 ? 'Published' : 'Draft') . '</span></summary>' .
            '<form class="editor-form faq-form" method="post">' . csrf_input() . '<input type="hidden" name="faq_action" value="save"><input type="hidden" name="faq_id" value="' . $faqId . '">' .
            '<label>Question<input name="question" maxlength="255" required value="' . e($faq['question']) . '"></label><label>Answer<textarea name="answer" rows="5" maxlength="10000" required>' . e($faq['answer']) . '</textarea></label>' .
            '<div class="faq-form-options"><label>Display order<input type="number" name="sort_order" value="' . (int) $faq['sort_order'] . '" required></label><label class="checkbox-label"><input type="hidden" name="is_published" value="0"><input type="checkbox" name="is_published" value="1"' . ((int) $faq['is_published'] === 1 ? ' checked' : '') . '> Publish this answer</label></div>' .
            '<div class="faq-admin-actions"><button class="button button-primary">Save changes</button></form><form method="post" onsubmit="return confirm(&quot;Delete this FAQ? This cannot be undone.&quot;)">' . csrf_input() .
            '<input type="hidden" name="faq_action" value="delete"><input type="hidden" name="faq_id" value="' . $faqId . '"><button class="button button-danger">Delete</button></form></div></details></article>';
    }
    $content .= '</div></section>';
    layout('Manage FAQs', $content, true, ['description' => 'Manage frequently asked questions for the Foundation website.']);
    exit;
}

if (($segments[0] ?? '') === 'admin' && ($segments[1] ?? '') === 'page-sections') {
    require_user();
    $pageId = isset($segments[2]) && ctype_digit($segments[2]) ? (int) $segments[2] : 0;
    $pageStatement = $pdo->prepare('SELECT id, title, slug, status FROM pages WHERE id = ? LIMIT 1');
    $sectionTypes = [
        'hero' => 'Large page introduction',
        'rich_text' => 'Text section',
        'image_text' => 'Photo and text',
        'feature_cards' => 'Cards',
        'call_to_action' => 'Highlight banner',
        'profile' => 'Board member',
        'donation_options' => 'Ways to donate',
    ];

    if ($pageId === 0) {
        $pages = $pdo->query('SELECT id, title, slug, status FROM pages ORDER BY title')->fetchAll();
        $sectionCounts = $pdo->query('SELECT page_id, COUNT(*) AS section_count FROM page_sections GROUP BY page_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $content = '<section class="admin-page"><div class="admin-heading"><div><p class="eyebrow">Website content</p><h1>Edit a page</h1></div></div><p class="lead">Choose a page, open the part you want to change, then save. Your original page content is kept.</p><div class="section-page-list">';
        foreach ($pages as $page) {
            $sectionCount = (int) ($sectionCounts[$page['id']] ?? 0);
            $content .= '<article class="section-page-item"><div><h2>' . e($page['title']) . '</h2><p><span class="status-badge">' . e(ucfirst($page['status'])) . '</span> ' . ($sectionCount > 0 ? $sectionCount . ' page parts' : 'Original content') . '</p></div><a class="button button-primary" href="' . e(site_url('admin/page-sections/' . $page['id'])) . '">Edit page</a></article>';
        }
        $content .= '</div></section>';
        layout('Page sections', $content, true);
        exit;
    }

    $pageStatement->execute([$pageId]);
    $page = $pageStatement->fetch();
    if (!$page) {
        http_response_code(404);
        exit('Page not found.');
    }

    if ($method === 'POST') {
        verify_csrf();
        $operation = (string) ($_POST['section_action'] ?? '');
        try {
            if ($operation === 'add') {
                $type = (string) ($_POST['section_type'] ?? '');
                if (!isset($sectionTypes[$type])) {
                    throw new RuntimeException('Choose a supported section type.');
                }
                $existingSectionCount = $pdo->prepare('SELECT COUNT(*) FROM page_sections WHERE page_id = ?');
                $existingSectionCount->execute([$pageId]);
                if ((int) $existingSectionCount->fetchColumn() === 0) {
                    $legacyPageStatement = $pdo->prepare('SELECT title, excerpt, body, image_path FROM pages WHERE id = ?');
                    $legacyPageStatement->execute([$pageId]);
                    $legacyPage = $legacyPageStatement->fetch();
                    if ($legacyPage) {
                        $legacyInsert = $pdo->prepare(
                            'INSERT INTO page_sections (page_id, section_type, eyebrow, title, body, image_path, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $legacyInsert->execute([
                            $pageId,
                            'hero',
                            'Francisca Kwamboka Foundation',
                            $legacyPage['title'],
                            $legacyPage['excerpt'],
                            $legacyPage['image_path'],
                            10,
                        ]);
                        if (trim((string) $legacyPage['body']) !== '') {
                            $legacyInsert->execute([
                                $pageId,
                                'rich_text',
                                null,
                                'Page content',
                                $legacyPage['body'],
                                null,
                                20,
                            ]);
                        }
                    }
                }
                $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM page_sections WHERE page_id = ?');
                $maxOrder->execute([$pageId]);
                $insert = $pdo->prepare('INSERT INTO page_sections (page_id, section_type, title, sort_order) VALUES (?, ?, ?, ?)');
                $insert->execute([$pageId, $type, 'New ' . $sectionTypes[$type], ((int) $maxOrder->fetchColumn()) + 10]);
                $newSectionId = (int) $pdo->lastInsertId();
                go('admin/page-sections/' . $pageId . '?section=' . $newSectionId . '#section-' . $newSectionId);
            }

            $sectionId = isset($_POST['section_id']) && ctype_digit((string) $_POST['section_id'])
                ? (int) $_POST['section_id']
                : 0;
            $sectionStatement = $pdo->prepare('SELECT id, sort_order FROM page_sections WHERE id = ? AND page_id = ? LIMIT 1');
            $sectionStatement->execute([$sectionId, $pageId]);
            $currentSection = $sectionStatement->fetch();
            if (!$currentSection) {
                throw new RuntimeException('The selected section no longer exists on this page.');
            }

            if ($operation === 'delete') {
                $delete = $pdo->prepare('DELETE FROM page_sections WHERE id = ? AND page_id = ?');
                $delete->execute([$sectionId, $pageId]);
                go('admin/page-sections/' . $pageId . '?saved=deleted');
            }

            if (in_array($operation, ['move_up', 'move_down'], true)) {
                $orderedIds = $pdo->prepare('SELECT id FROM page_sections WHERE page_id = ? ORDER BY sort_order, id');
                $orderedIds->execute([$pageId]);
                $ids = array_map('intval', $orderedIds->fetchAll(PDO::FETCH_COLUMN));
                $position = array_search($sectionId, $ids, true);
                $neighbor = $operation === 'move_up' ? $position - 1 : $position + 1;
                if ($position !== false && isset($ids[$neighbor])) {
                    [$ids[$position], $ids[$neighbor]] = [$ids[$neighbor], $ids[$position]];
                    $pdo->beginTransaction();
                    $updateOrder = $pdo->prepare('UPDATE page_sections SET sort_order = ? WHERE page_id = ? AND id = ?');
                    foreach ($ids as $index => $orderedId) {
                        $updateOrder->execute([($index + 1) * 10, $pageId, $orderedId]);
                    }
                    $pdo->commit();
                }
                go('admin/page-sections/' . $pageId . '#section-' . $sectionId);
            }

            if ($operation !== 'save') {
                http_response_code(405);
                exit('Unsupported section action.');
            }

            $type = (string) ($_POST['section_type'] ?? '');
            $title = trim((string) ($_POST['title'] ?? ''));
            $eyebrow = trim((string) ($_POST['eyebrow'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            $linkLabel = trim((string) ($_POST['link_label'] ?? ''));
            $linkUrl = trim((string) ($_POST['link_url'] ?? ''));
            $imagePath = trim((string) ($_POST['image_path'] ?? ''));
            $sortOrder = filter_var($_POST['sort_order'] ?? null, FILTER_VALIDATE_INT);
            if (!isset($sectionTypes[$type]) || $title === '' || strlen($title) > 190) {
                throw new RuntimeException('Choose a section type and enter a title of 190 characters or fewer.');
            }
            if (strlen($eyebrow) > 190 || strlen($linkLabel) > 100 || strlen($imagePath) > 255 || strlen($linkUrl) > 255 || strlen($body) > 60000) {
                throw new RuntimeException('One or more section fields exceed the allowed length.');
            }
            if ($sortOrder === false) {
                throw new RuntimeException('Section order must be a whole number.');
            }
            if ($linkUrl !== '' && !preg_match('~^(?:[a-zA-Z0-9][a-zA-Z0-9/_-]*)(?:\?[a-zA-Z0-9%&=_-]*)?$~', $linkUrl)) {
                throw new RuntimeException('Use a relative site URL for section links, such as donate or page/our-journey.');
            }
            $uploadedImage = image_upload('section_image_' . $sectionId);
            if ($uploadedImage !== null) {
                $imagePath = $uploadedImage;
            }
            $update = $pdo->prepare(
                'UPDATE page_sections
                 SET section_type = ?, eyebrow = ?, title = ?, body = ?, image_path = ?, link_label = ?, link_url = ?, sort_order = ?, is_enabled = ?
                 WHERE id = ? AND page_id = ?'
            );
            $update->execute([
                $type,
                $eyebrow !== '' ? $eyebrow : null,
                $title,
                $body !== '' ? $body : null,
                $imagePath !== '' ? $imagePath : null,
                $linkLabel !== '' ? $linkLabel : null,
                $linkUrl !== '' ? $linkUrl : null,
                $sortOrder,
                (($_POST['is_enabled'] ?? null) === '1') ? 1 : 0,
                $sectionId,
                $pageId,
            ]);
            go('admin/page-sections/' . $pageId . '?saved=1&section=' . $sectionId . '#section-' . $sectionId);
        } catch (RuntimeException $exception) {
            $sectionError = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('CMS section save failed: ' . $exception->getMessage());
            $sectionError = 'The section could not be saved. Please try again.';
        }
    }

    $sectionQuery = $pdo->prepare('SELECT * FROM page_sections WHERE page_id = ? ORDER BY sort_order, id');
    $sectionQuery->execute([$pageId]);
    $sections = $sectionQuery->fetchAll();
    $previewPath = match ($page['slug']) {
        'home' => '',
        'donate' => 'donate',
        default => 'page/' . $page['slug'],
    };
    $content = '<section class="admin-page"><div class="admin-heading"><div><p class="eyebrow">Edit website page</p><h1>' . e($page['title']) . '</h1></div><div class="section-page-actions"><a class="button button-outline" href="' . e(site_url('admin/page-sections')) . '">All pages</a><a class="button button-outline" target="_blank" rel="noopener" href="' . e(site_url($previewPath)) . '">Preview</a></div></div>' .
        '<p class="section-editor-intro">Open a part to edit it. Remember to save your changes. Hidden parts stay here and can be shown again later.</p>' .
        (!empty($sectionError) ? '<div class="alert alert-error">' . e($sectionError) . '</div>' : '') .
        (isset($_GET['saved']) ? '<div class="alert alert-success">' . ($_GET['saved'] === 'deleted' ? 'Section deleted.' : 'Section changes saved.') . '</div>' : '');

    if ($sections === []) {
        $content .= '<div class="empty-state"><h2>This page has no editable parts yet</h2><p>Add a part below to start editing. Your original page content is kept when you do.</p></div>';
    }

    foreach ($sections as $index => $section) {
        $sectionType = $section['section_type'];
        $typeLabel = $sectionTypes[$sectionType] ?? 'Section';
        $sectionId = (int) $section['id'];
        $content .= '<article class="section-editor-card" id="section-' . $sectionId . '"><details class="section-editor-details"' . ((int) ($_GET['section'] ?? 0) === $sectionId ? ' open' : '') . '><summary class="section-editor-heading"><span><span class="eyebrow">Part ' . ($index + 1) . '</span><strong>' . e($section['title'] ?: $typeLabel) . '</strong></span><span class="status-badge">' . e($typeLabel) . '</span></summary>' .
            '<form class="editor-form section-edit-form" method="post" enctype="multipart/form-data">' . csrf_input() .
            '<input type="hidden" name="section_id" value="' . (int) $section['id'] . '">' .
            '<input type="hidden" name="sort_order" value="' . (int) $section['sort_order'] . '">' .
            '<label>Heading<input name="title" maxlength="190" required value="' . e($section['title']) . '"></label>' .
            '<label>Role or small heading<input name="eyebrow" maxlength="190" value="' . e($section['eyebrow']) . '"><span class="field-help">For a board member, this is the role shown beneath their name.</span></label>' .
            '<label>Text<textarea name="body" rows="6">' . e($section['body']) . '</textarea>' .
            ($sectionType === 'feature_cards' ? '<span class="field-help">For cards, use one per line: Title | Description | optional page link</span>' : '') . '</label>' .
            '<input type="hidden" name="image_path" value="' . e($section['image_path']) . '">' .
            '<label>Photo (optional)<input type="file" name="section_image_' . $sectionId . '" accept="image/jpeg,image/png,image/webp"><span class="field-help">Choose a new photo to replace the current one. JPG, PNG, or WebP up to 5 MB.</span></label>' .
            '<div class="section-editor-actions"><button class="button button-primary" name="section_action" value="save">Save changes</button></div>' .
            '<details class="section-advanced"><summary>More options</summary><div class="section-advanced-fields"><label>Part style<select name="section_type">';
        foreach ($sectionTypes as $type => $label) {
            $content .= '<option value="' . e($type) . '"' . ($sectionType === $type ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $content .= '</select></label>' .
            '<div class="section-fields-grid"><label>Button text<input name="link_label" maxlength="100" value="' . e($section['link_label']) . '"></label><label>Button link<input name="link_url" maxlength="255" value="' . e($section['link_url']) . '" placeholder="donate or page/our-journey"></label></div>' .
            '<label class="checkbox-label"><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1"' . ((int) $section['is_enabled'] === 1 ? ' checked' : '') . '> Show this part to visitors</label></div></details></form>' .
            '<div class="section-editor-actions section-order-actions">';
        if ($index > 0) {
            $content .= '<form method="post">' . csrf_input() . '<input type="hidden" name="section_id" value="' . $sectionId . '"><button class="button button-outline button-small" name="section_action" value="move_up" aria-label="Move part up">Move up</button></form>';
        }
        if ($index < count($sections) - 1) {
            $content .= '<form method="post">' . csrf_input() . '<input type="hidden" name="section_id" value="' . $sectionId . '"><button class="button button-outline button-small" name="section_action" value="move_down" aria-label="Move part down">Move down</button></form>';
        }
        $content .= '<form method="post" class="section-delete-form" onsubmit="return confirm(\'Delete this part? This cannot be undone.\')">' . csrf_input() .
            '<input type="hidden" name="section_action" value="delete"><input type="hidden" name="section_id" value="' . $sectionId . '">' .
            '<button class="button button-danger button-small">Delete</button></form></div></article>';
    }

    $content .= '<section class="add-section-panel"><h2>Add a part to this page</h2><p>Choose what you want to add. You can change its text and photo afterward.</p><form method="post">' . csrf_input() .
        '<input type="hidden" name="section_action" value="add"><label>Part type<select name="section_type">';
    foreach ($sectionTypes as $type => $label) {
        $content .= '<option value="' . e($type) . '">' . e($label) . '</option>';
    }
    $content .= '</select></label><button class="button button-primary">Add to page</button></form></section></section>';
    layout('Edit ' . $page['title'], $content, true);
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
                if ($type === 'pages') {
                    $content .= '<a class="button button-small button-outline" href="' . e(site_url('admin/page-sections/' . $record['id'])) . '">Sections</a>';
                }
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
    if ($page) {
        if (page_has_sections($pdo, (int) $page['id'])) {
            $sections = page_sections($pdo, (int) $page['id']);
            $homeSections = [];
            $homeCtaSections = [];
            foreach ($sections as $section) {
                if ($section['section_type'] === 'call_to_action') {
                    $homeCtaSections[] = $section;
                } else {
                    $homeSections[] = $section;
                }
            }
            $content = render_page_sections($homeSections, 'home', $pdo);
            if ($posts !== []) {
                $content .= '<section class="news-section"><div class="section-heading"><p class="eyebrow">Updates</p><h2>Latest news &amp; stories</h2></div><div class="editorial-list">';
                foreach ($posts as $post) {
                    $content .= '<article class="editorial-item editorial-item-with-image">' . (!empty($post['image_path']) ? '<img src="' . e(site_url($post['image_path'])) . '" alt="">' : '') . '<div class="editorial-item-copy"><p class="eyebrow">' . e(ucfirst($post['type'])) . '</p><h3>' . e($post['title']) . '</h3><p>' . e($post['excerpt']) . '</p><a class="text-link" href="' . e(site_url($post['type'] . '/' . $post['slug'])) . '">Read more <span>→</span></a></div></article>';
                }
                $content .= '</div></section>';
            }
            $content .= render_page_sections($homeCtaSections, 'home');
            layout($page['title'], $content, false, [
                'description' => $page['excerpt'] ?: 'Francisca Kwamboka Foundation supports children, women, and young people through community-led health, education, and safety programmes.',
                'path' => '',
                'image' => $page['image_path'] ?: 'assets/images/Slider_1A.jpg',
            ]);
            exit;
        }
    }
    $causes = $pdo->query("SELECT title, slug, excerpt, image_path FROM pages WHERE status='published' AND slug IN ('health-education','youth-women-empowerment','girlie-camp','sexual-gender-based-violence','mentorship','scholarships') ORDER BY title LIMIT 3")->fetchAll();
    $homeTitle = $page['title'] ?? 'Safe Spaces for women and children to thrive';
    $homeCopy = 'We inspire healthier communities, end violence against women and girls, and support education for all children.';
    $homeImage = !empty($page['image_path']) ? $page['image_path'] : 'assets/images/Slider_1A.jpg';
    $content = '<section class="hero home-hero"><div class="home-hero-copy"><h1>' . e($homeTitle) . '</h1><p class="lead">' . e($homeCopy) . '</p><div class="hero-actions"><a class="button button-primary home-donate-button" href="' . e(site_url('donate')) . '">Help a child thrive</a><a class="home-text-link" href="' . e(site_url('causes')) . '">Explore our work <span>→</span></a></div><p class="home-hero-promise">Safe spaces. Strong families. Hopeful futures.</p></div><div class="home-hero-art"><div class="home-photo-shape"><img src="' . e(site_url($homeImage)) . '" alt="Children and community members taking part in Foundation programmes"></div></div></section>';
    $content .= '<section class="intro-section home-intro"><div class="section-copy"><p class="eyebrow">Who we are</p><h2>Carrying a legacy of care into the future</h2><p>Established in memory of community leader Francisca Kwamboka, the Foundation continues her commitment to supporting vulnerable women, young people and children. Our programmes focus on health, education and the rights of the communities we serve.</p><a class="text-link" href="' . e(site_url('page/our-journey')) . '">Discover our journey <span>→</span></a></div><img class="intro-image" src="' . e(site_url('assets/images/about.jpg')) . '" alt="Community work by the Francisca Kwamboka Foundation"></section>';
    $content .= '<section class="home-purpose"><div class="purpose-heading"><h2>Our mission</h2></div><div class="purpose-layout">' .
        '<article class="purpose-mission"><span class="purpose-quote" aria-hidden="true">“</span><p class="purpose-mission-copy">To inspire healthier communities, end violence against women and girls, and provide education for all children.</p></article>' .
        '<div class="purpose-principles"><article class="purpose-principle"><h3>Our vision</h3><p>To alleviate poverty and promote the rights of vulnerable women, girls and youth.</p></article><article class="purpose-principle"><h3>Our values</h3><p>Commitment, social justice, equality and equity, gender mainstreaming, wholeness and inclusiveness.</p></article></div>' .
        '</div></section>';
    $content .= '<section class="editorial-section home-programmes"><div class="section-heading"><p class="eyebrow">Our causes</p><h2>Every cause is connected to our mission</h2><p>Our programmes respond to local needs and help build healthier, safer futures.</p></div><div class="editorial-list">';
    foreach ($causes as $causeIndex => $cause) {
        $content .= '<article class="editorial-item"><span class="editorial-number">' . sprintf('%02d', $causeIndex + 1) . '</span><div class="editorial-item-copy"><h3>' . e($cause['title']) . '</h3><p>' . e($cause['excerpt']) . '</p><a class="text-link" href="' . e(site_url('page/' . $cause['slug'])) . '">Explore this work <span>→</span></a></div></article>';
    }
    $content .= '</div></section>';
    if ($posts !== []) {
        $content .= '<section class="news-section"><div class="section-heading"><p class="eyebrow">Updates</p><h2>Latest news &amp; stories</h2></div><div class="editorial-list">';
        foreach ($posts as $post) {
            $content .= '<article class="editorial-item editorial-item-with-image">' . (!empty($post['image_path']) ? '<img src="' . e(site_url($post['image_path'])) . '" alt="">' : '') . '<div class="editorial-item-copy"><p class="eyebrow">' . e(ucfirst($post['type'])) . '</p><h3>' . e($post['title']) . '</h3><p>' . e($post['excerpt']) . '</p><a class="text-link" href="' . e(site_url($post['type'] . '/' . $post['slug'])) . '">Read more <span>→</span></a></div></article>';
        }
        $content .= '</div></section>';
    }
    $content .= '<section class="donate-banner home-donate-banner"><div><p class="eyebrow">Stand with our community</p><h2>Help create a safer, healthier future.</h2><p>Your support helps the Foundation continue its community programmes.</p></div><a class="button button-light" href="' . e(site_url('donate')) . '">Find ways to give</a></section>';
    layout('Home', $content, false, [
        'description' => 'Francisca Kwamboka Foundation supports children, women, and young people through community-led health, education, and safety programmes.',
        'path' => '',
        'image' => $homeImage,
    ]);
    exit;
}

if ($path === 'causes') {
    $causePage = page_row($pdo, 'our-causes');
    $causes = $pdo->query("SELECT title, slug, excerpt, image_path FROM pages WHERE status='published' AND slug IN ('health-education','youth-women-empowerment','girlie-camp','sexual-gender-based-violence','mentorship','scholarships') ORDER BY title")->fetchAll();
    $causeImageFallbacks = [
        'health-education' => 'assets/images/cause-2a.jpg',
        'youth-women-empowerment' => 'assets/images/about.jpg',
        'girlie-camp' => 'assets/images/camp1.jpg',
        'sexual-gender-based-violence' => 'assets/images/cause-1a.jpg',
        'mentorship' => 'assets/images/cause-5.jpg',
        'scholarships' => 'assets/images/cause-6a.jpg',
    ];
    $hasCauseSections = $causePage && page_has_sections($pdo, (int) $causePage['id']);
    $content = $hasCauseSections
        ? render_page_sections(page_sections($pdo, (int) $causePage['id'])) . '<section class="page-content"><div class="editorial-list">'
        : '<section class="page-hero"><p class="eyebrow">Our causes</p><h1>' . e($causePage['title'] ?? 'Our Causes') . '</h1><p>' . e($causePage['excerpt'] ?? '') . '</p></section><section class="page-content"><div class="editorial-list">';
    foreach ($causes as $cause) {
        $causeImage = $cause['image_path'] ?: ($causeImageFallbacks[$cause['slug']] ?? 'assets/images/about.jpg');
        $content .= '<article class="editorial-item editorial-item-with-image"><img src="' . e(site_url($causeImage)) . '" alt=""><div class="editorial-item-copy"><h2>' . e($cause['title']) . '</h2><p>' . e($cause['excerpt']) . '</p><a class="text-link" href="' . e(site_url('page/' . $cause['slug'])) . '">Read more <span>→</span></a></div></article>';
    }
    $content .= '</div></section>';
    layout('Our Causes', $content, false, [
        'description' => $causePage['excerpt'] ?? 'Explore community-led programmes supporting health, education, women, girls, and young people.',
        'path' => 'causes',
    ]);
    exit;
}

if ($path === 'news' || $path === 'blog' || $path === 'articles') {
    $newsPage = $path === 'news' ? page_row($pdo, 'events-news') : null;
    $filter = ['news' => 'news', 'blog' => 'blog', 'articles' => 'article'][$path];
    $statement = $pdo->prepare("SELECT * FROM posts WHERE status='published' AND type=? ORDER BY published_at DESC LIMIT 100");
    $statement->execute([$filter]);
    $posts = $statement->fetchAll();
    $hasNewsSections = $newsPage && page_has_sections($pdo, (int) $newsPage['id']);
    $content = $hasNewsSections
        ? render_page_sections(page_sections($pdo, (int) $newsPage['id'])) . '<section class="page-content">'
        : '<section class="page-hero"><p class="eyebrow">Stories &amp; updates</p><h1>' . e($newsPage['title'] ?? ucfirst($filter === 'article' ? 'Articles' : $filter)) . '</h1>' . (!empty($newsPage['excerpt']) ? '<p>' . e($newsPage['excerpt']) . '</p>' : '') . '</section><section class="page-content">';
    if ($posts === []) {
        $content .= '<div class="empty-state"><h2>Stories coming soon</h2><p>Check back for updates from the foundation.</p></div>';
    } else {
        $content .= '<div class="editorial-list">';
        foreach ($posts as $post) {
            $content .= '<article class="editorial-item editorial-item-with-image">' . (!empty($post['image_path']) ? '<img src="' . e(site_url($post['image_path'])) . '" alt="">' : '') . '<div class="editorial-item-copy"><p class="eyebrow">' . e(ucfirst($post['type'])) . '</p><h2>' . e($post['title']) . '</h2><p>' . e($post['excerpt']) . '</p><a class="text-link" href="' . e(site_url($post['type'] . '/' . $post['slug'])) . '">Read more <span>→</span></a></div></article>';
        }
        $content .= '</div>';
    }
    $content .= '</section>';
    layout(ucfirst($filter), $content, false, [
        'description' => $newsPage['excerpt'] ?? 'Stories, news, and updates from Francisca Kwamboka Foundation programmes and communities.',
        'path' => $path,
    ]);
    exit;
}

if ($path === 'gallery') {
    $galleryPage = page_row($pdo, 'gallery');
    $items = $pdo->query('SELECT * FROM gallery_items WHERE is_published=1 ORDER BY sort_order, id DESC')->fetchAll();
    $hasGallerySections = $galleryPage && page_has_sections($pdo, (int) $galleryPage['id']);
    $content = $hasGallerySections
        ? render_page_sections(page_sections($pdo, (int) $galleryPage['id'])) . '<section class="page-content">'
        : '<section class="page-hero"><p class="eyebrow">Our community</p><h1>' . e($galleryPage['title'] ?? 'Gallery') . '</h1>' . (!empty($galleryPage['excerpt']) ? '<p>' . e($galleryPage['excerpt']) . '</p>' : '') . '</section><section class="page-content">';
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
    layout('Gallery', $content, false, [
        'description' => $galleryPage['excerpt'] ?? 'Photos and stories from children, families, and communities involved in the Foundation’s work.',
        'path' => 'gallery',
    ]);
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
    $hasContactSections = $contactPage && page_has_sections($pdo, (int) $contactPage['id']);
    $email = e($siteSettings['contact_email']);
    $phone = e($siteSettings['contact_phone']);
    $phoneLink = e(preg_replace('/[^0-9+]/', '', $siteSettings['contact_phone']));
    $contactIntro = $hasContactSections
        ? render_page_sections(page_sections($pdo, (int) $contactPage['id']))
        : '<section class="page-hero"><p class="eyebrow">Get involved</p><h1>' . e($contactPage['title'] ?? 'Contacts') . '</h1>' . (!empty($contactPage['excerpt']) ? '<p>' . e($contactPage['excerpt']) . '</p>' : '') . '</section>';
    $content = $contactIntro . '<section class="contact-layout"><div>' . (!$hasContactSections ? '<div class="prose">' . $contactCopy . '</div>' : '') . '<p><a href="mailto:' . $email . '">' . $email . '</a></p><p><a href="tel:' . $phoneLink . '">' . $phone . '</a></p></div><form class="contact-form" method="post">' . csrf_input() . ($sent ? '<div class="alert alert-success">Thank you. Your message has been received.</div>' : '') . (!empty($contactError) ? '<div class="alert alert-error">' . e($contactError) . '</div>' : '') . '<label>Your name<input name="name" required maxlength="150"></label><label>Email address<input name="email" type="email" required maxlength="190"></label><label>Phone (optional)<input name="phone" maxlength="50"></label><label>Subject<input name="subject" required maxlength="190"></label><label>Your message<textarea name="message" rows="6" required maxlength="5000"></textarea></label><button class="button button-primary">Send message</button></form></section>';
    layout('Contact Us', $content, false, [
        'description' => $contactPage['excerpt'] ?? 'Contact Francisca Kwamboka Foundation to learn about programmes, volunteering, and ways to support the community.',
        'path' => 'contact',
    ]);
    exit;
}

if (count($segments) === 2 && $segments[0] === 'page' && $segments[1] === 'fkf-board') {
    $boardPage = page_row($pdo, 'fkf-board');
    if ($boardPage) {
        if (page_has_sections($pdo, (int) $boardPage['id'])) {
            layout($boardPage['title'], render_page_sections(page_sections($pdo, (int) $boardPage['id']), 'fkf-board'), false, [
                'description' => $boardPage['excerpt'] ?: 'Meet the board members guiding Francisca Kwamboka Foundation and its work with children and families.',
                'path' => 'page/fkf-board',
            ]);
            exit;
        }
        $bioByName = [];
        $roleByName = [];
        $boardSections = preg_split(
            '~(?=^(?:Rhoda Onyancha|Martha Mang\'are|Alex Kalonzo|Ivy Kwamboka|Lilian Marwa)[ \t]+—[ \t]+)~mu',
            trim($boardPage['body'])
        ) ?: [];
        foreach ($boardSections as $section) {
            if (preg_match('/^([^\r\n]+?)[ \t]+—[ \t]+([^\r\n]+)\R(.*)$/us', trim($section), $match)) {
                $bioByName[$match[1]] = trim($match[3]);
                $roleByName[$match[1]] = trim($match[2]);
            }
        }

        $members = [
            ['name' => 'Rhoda Onyancha', 'role' => 'Chairperson', 'image' => 'assets/images/board-rhoda.jpg'],
            ['name' => 'Martha Mang\'are', 'role' => 'Board member', 'image' => 'assets/images/board-mangare.jpg'],
            ['name' => 'Alex Kalonzo', 'role' => 'Board secretary', 'image' => 'assets/images/board-kalonzo.jpg'],
            ['name' => 'Ivy Kwamboka', 'role' => 'Board member', 'image' => 'assets/images/board-ivy.jpg'],
            ['name' => 'Lilian Marwa', 'role' => 'Treasurer', 'image' => 'assets/images/board-marwa.jpg'],
        ];
        $content = '<section class="page-hero board-page-hero"><p class="eyebrow">Leadership &amp; governance</p><h1>Meet our board</h1><p>Guided by the legacy of Francisca Kwamboka, our board brings a shared commitment to serving women, young people and children.</p></section><section class="page-content board-section"><div class="board-list">';
        foreach ($members as $member) {
            $bio = $bioByName[$member['name']] ?? '';
            $snippet = $bio;
            if ($bio !== '' && preg_match('/^.{1,180}(?:\s|$)/us', $bio, $match)) {
                $snippet = rtrim($match[0]) . '…';
            }
            $role = $roleByName[$member['name']] ?? $member['role'];
            $content .= '<article class="board-card"><img class="board-portrait" src="' . e(site_url($member['image'])) . '" alt="Portrait of ' . e($member['name']) . '"><div class="board-card-body"><p class="board-role">' . e($role) . '</p><h2>' . e($member['name']) . '</h2>' . ($snippet !== '' ? '<p class="board-bio-preview">' . e($snippet) . '</p>' : '') . ($bio !== '' ? '<details class="board-profile"><summary>Read full profile</summary><p>' . e($bio) . '</p></details>' : '') . '</div></article>';
        }
        $content .= '</div></section><section class="donate-banner board-cta"><div><p class="eyebrow">Join the work</p><h2>Be part of a stronger community.</h2><p>Learn how you can support the Foundation and the people it serves.</p></div><a class="button button-light" href="' . e(site_url('contact')) . '">Get in touch</a></section>';
        layout('Our Board', $content, false, [
            'description' => 'Meet the board members guiding Francisca Kwamboka Foundation and its work with children and families.',
            'path' => 'page/fkf-board',
        ]);
        exit;
    }
}

if ($path === 'donate') {
    $page = page_row($pdo, 'donate');
    if ($page && page_has_sections($pdo, (int) $page['id'])) {
        layout($page['title'], render_page_sections(page_sections($pdo, (int) $page['id']), 'donate'), false, [
            'description' => $page['excerpt'] ?: 'Learn how to support community-led programmes for children, women, and young people.',
            'path' => 'donate',
        ]);
        exit;
    }
    $contactEmail = e(settings()['contact_email']);
    $content = '<section class="page-hero donate-hero"><p class="eyebrow">Give your share</p><h1>Support community-led change</h1><p>Your generosity helps us continue programmes that advance health, education and the rights of women, girls and young people.</p></section><section class="page-content donation-section"><div class="section-heading"><p class="eyebrow">Ways to give</p><h2>Choose how you would like to help</h2><p>Contact the Foundation and we will guide you through the available options.</p></div><div class="donation-options"><article class="donation-card"><span class="donation-icon" aria-hidden="true">01</span><p class="eyebrow">Direct giving</p><h3>Bank transfer</h3><p>Contact us for the current bank details and to let us know how you would like your contribution to support our work.</p><a class="text-link" href="mailto:' . $contactEmail . '?subject=Bank%20transfer%20donation">Request bank details <span>→</span></a></article><article class="donation-card"><span class="donation-icon" aria-hidden="true">02</span><p class="eyebrow">One-off or recurring</p><h3>PayPal</h3><p>Get in touch to arrange a one-off or recurring contribution through PayPal.</p><a class="text-link" href="mailto:' . $contactEmail . '?subject=PayPal%20donation">Ask about PayPal giving <span>→</span></a></article></div><p class="donation-note">Please note: the Foundation is currently unable to provide tax-deductible receipts.</p><div class="donation-contact"><p>Need help or have a question about giving?</p><a class="button button-primary" href="' . e(site_url('contact')) . '">Contact the Foundation</a></div></section>';
    layout('Donate', $content, false, [
        'description' => 'Support community-led health, education, and safety programmes for children, women, and young people.',
        'path' => 'donate',
    ]);
    exit;
}

if (count($segments) === 2 && in_array($segments[0], ['news', 'blog', 'article'], true)) {
    $statement = $pdo->prepare("SELECT * FROM posts WHERE slug=? AND type=? AND status='published' LIMIT 1");
    $type = $segments[0] === 'article' ? 'article' : $segments[0];
    $statement->execute([$segments[1], $type]);
    $post = $statement->fetch();
    if ($post) {
        $content = '<article class="prose article-page"><p class="eyebrow">' . e(ucfirst($post['type'])) . '</p><h1>' . e($post['title']) . '</h1><p class="article-date">' . e($post['published_at']) . '</p>' . (!empty($post['image_path']) ? '<img class="article-cover" src="' . e(site_url($post['image_path'])) . '" alt="">' : '') . '<p class="lead">' . e($post['excerpt']) . '</p>' . render_text($post['body']) . '</article>';
        layout($post['title'], $content, false, [
            'description' => $post['excerpt'] ?: $post['title'],
            'image' => $post['image_path'] ?: null,
            'type' => 'article',
        ]);
        exit;
    }
}

if (count($segments) === 2 && $segments[0] === 'page') {
    $page = page_row($pdo, $segments[1]);
    if ($page) {
        if ($page['slug'] === 'faqs') {
            $faqPageTitle = 'Frequently asked questions';
            $faqs = $pdo->query('SELECT question, answer FROM faqs WHERE is_published = 1 ORDER BY sort_order, id')->fetchAll();
            $faqItems = [];
            $faqSchemaItems = [];
            foreach ($faqs as $index => $faq) {
                $question = trim((string) $faq['question']);
                $answer = trim((string) $faq['answer']);
                $faqItems[] = '<details class="faq-item"' . ($index === 0 ? ' open' : '') . '><summary><span class="faq-question-number">' . sprintf('%02d', $index + 1) . '</span><span>' . e($question) . '</span></summary><div class="faq-answer"><p>' . nl2br(e($answer), false) . '</p></div></details>';
                $faqSchemaItems[] = [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
                ];
            }
            $faqBody = $faqItems !== []
                ? '<div class="faq-list">' . implode('', $faqItems) . '</div>'
                : '<div class="faq-empty"><h2>We are here to help</h2><p>Our team can answer questions about our programmes, volunteering, and ways to support children and families.</p><a class="button button-primary" href="' . e(site_url('contact')) . '">Ask us a question</a></div>';
            $content = '<section class="page-hero faq-page-hero"><p class="eyebrow">Good questions are welcome</p><h1>' . e($faqPageTitle) . '</h1><p>' . e($page['excerpt'] ?: 'Find out more about our work, the communities we serve, and how you can help.') . '</p></section><section class="faq-page-content">' . $faqBody . '<aside class="faq-contact-note"><div><h2>Still curious?</h2><p>We would be happy to talk with you.</p></div><a class="button button-light" href="' . e(site_url('contact')) . '">Contact us</a></aside></section>';
            $faqSchema = $faqSchemaItems !== [] ? ['@type' => 'FAQPage', 'mainEntity' => $faqSchemaItems] : null;
            layout($faqPageTitle, $content, false, [
                'description' => $page['excerpt'] ?: 'Answers to common questions about Francisca Kwamboka Foundation programmes, volunteering, and donations.',
                'path' => 'page/faqs',
                'structured_data' => $faqSchema,
            ]);
            exit;
        }
        if (page_has_sections($pdo, (int) $page['id'])) {
            layout($page['title'], render_page_sections(page_sections($pdo, (int) $page['id']), $page['slug']), false, [
                'description' => $page['excerpt'] ?: null,
                'path' => 'page/' . $page['slug'],
                'image' => $page['image_path'] ?: null,
            ]);
            exit;
        }
        $programmeSlugs = [
            'health-education', 'youth-women-empowerment', 'girlie-camp',
            'sexual-gender-based-violence', 'mentorship', 'scholarships',
        ];
        $programmeImages = [
            'health-education' => 'assets/images/cause-2a.jpg',
            'youth-women-empowerment' => 'assets/images/about.jpg',
            'girlie-camp' => 'assets/images/camp1.jpg',
            'sexual-gender-based-violence' => 'assets/images/cause-1a.jpg',
            'mentorship' => 'assets/images/cause-5.jpg',
            'scholarships' => 'assets/images/cause-6a.jpg',
        ];
        $programmeHeroImages = [
            'youth-women-empowerment' => 'assets/images/slide-sliders-2.jpg',
            'sexual-gender-based-violence' => 'assets/images/cause-1a.jpg',
        ];
        $storyImagePath = trim((string) ($page['image_path'] ?? '')) ?: ($programmeImages[$page['slug']] ?? '');
        $programmeHeroPath = $programmeHeroImages[$page['slug']] ?? $storyImagePath;
        $storyImage = $storyImagePath !== ''
            ? '<figure class="story-image"><img src="' . e(site_url($storyImagePath)) . '" alt="' . e($page['title']) . '"></figure>'
            : '';
        $isProgrammePage = in_array($page['slug'], $programmeSlugs, true);
        $programmeHero = $isProgrammePage
            ? '<section class="programme-hero programme-hero--' . e($page['slug']) . '"><div class="programme-hero-media">' .
                ($programmeHeroPath !== '' ? '<img src="' . e(site_url($programmeHeroPath)) . '" alt="' . e($page['title']) . '">' : '') .
                '</div><div class="programme-hero-copy"><p class="eyebrow">Our programmes</p><h1>' . e($page['title']) . '</h1>' .
                (!empty($page['excerpt']) ? '<p>' . e($page['excerpt']) . '</p>' : '') . '</div></section>'
            : '';
        $storyBody = $isProgrammePage
            ? render_programme_section($page['slug'], (string) $page['body'])
            : '<div class="story-body prose">' . render_text($page['body']) . '</div>';
        $storyClass = $isProgrammePage ? 'story-page-content programme-page-content' : 'story-page-content';
        $pageHeading = $isProgrammePage ? '' : '<section class="page-hero story-page-hero"><p class="eyebrow">Our work, together</p><h1>' . e($page['title']) . '</h1>' . (!empty($page['excerpt']) ? '<p>' . e($page['excerpt']) . '</p>' : '') . '</section>';
        $content = $programmeHero . $pageHeading . '<article class="' . $storyClass . '">' . ($isProgrammePage ? '' : $storyImage) . $storyBody . '<aside class="story-support"><div><p class="eyebrow">Be part of the change</p><h2>Every child deserves the chance to thrive.</h2><p>Your support can help strengthen community-led care.</p></div><a class="button button-primary" href="' . e(site_url('donate')) . '">Ways to give</a></aside></article>';
        layout($page['title'], $content, false, [
            'description' => $page['excerpt'] ?: $page['title'] . ' — learn about the work of Francisca Kwamboka Foundation.',
            'path' => 'page/' . $page['slug'],
            'image' => $page['image_path'] ?: null,
        ]);
        exit;
    }
}

http_response_code(404);
layout('Page not found', '<section class="page-hero"><p class="eyebrow">404</p><h1>Page not found</h1><a class="button button-primary" href="' . e(site_url()) . '">Return home</a></section>', false, ['robots' => 'noindex, follow']);
