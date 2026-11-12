<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Copy config.example.php to config.php and configure the database first.\n");
    exit(1);
}

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
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/001_page_sections.sql');
if ($migration === false) {
    fwrite(STDERR, "The page sections migration could not be read.\n");
    exit(1);
}
$pdo->exec($migration);

$findPage = $pdo->prepare('SELECT id, body FROM pages WHERE slug = ? LIMIT 1');
$countSections = $pdo->prepare('SELECT COUNT(*) FROM page_sections WHERE page_id = ?');
$insertSection = $pdo->prepare(
    'INSERT INTO page_sections
        (page_id, section_type, eyebrow, title, body, image_path, link_label, link_url, sort_order, is_enabled)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
);

$saveSections = static function (string $slug, array $sections) use ($findPage, $countSections, $insertSection): int {
    $findPage->execute([$slug]);
    $page = $findPage->fetch();
    if (!$page) {
        return 0;
    }

    $countSections->execute([$page['id']]);
    if ((int) $countSections->fetchColumn() > 0) {
        return 0;
    }

    foreach ($sections as $section) {
        $insertSection->execute([
            $page['id'],
            $section['type'],
            $section['eyebrow'],
            $section['title'],
            $section['body'],
            $section['image'],
            $section['link_label'],
            $section['link_url'],
            $section['order'],
        ]);
    }
    return count($sections);
};

$homeSections = [
    ['type' => 'hero', 'eyebrow' => 'Francisca Kwamboka Foundation', 'title' => 'Safe Spaces for women and children to thrive', 'body' => 'We inspire healthier communities, end violence against women and girls, and support education for all children.', 'image' => 'assets/images/Slider_1A.jpg', 'link_label' => 'Explore our causes', 'link_url' => 'causes', 'order' => 10],
    ['type' => 'image_text', 'eyebrow' => 'Who we are', 'title' => 'Carrying a legacy of care into the future', 'body' => 'Established in memory of community leader Francisca Kwamboka, the Foundation continues her commitment to supporting vulnerable women, young people and children. Our programmes focus on health, education and the rights of the communities we serve.', 'image' => 'assets/images/about.jpg', 'link_label' => 'Discover our journey', 'link_url' => 'page/our-journey', 'order' => 20],
    ['type' => 'feature_cards', 'eyebrow' => 'Our purpose', 'title' => 'What guides our work', 'body' => "Our vision | To alleviate poverty and promote the rights of vulnerable women, girls and youth.\nOur mission | To inspire healthier communities, end violence against women and girls, and provide education for all children.\nOur values | Commitment, social justice, equality and equity, gender mainstreaming, wholeness and inclusiveness.", 'image' => null, 'link_label' => null, 'link_url' => null, 'order' => 30],
    ['type' => 'feature_cards', 'eyebrow' => 'Our causes', 'title' => 'Every cause is connected to our mission', 'body' => "Health & Education | Diabetes awareness, prevention and community health programmes. | page/health-education\nWomen & Youth | Building skills, confidence, livelihoods and participation. | page/youth-women-empowerment\nGirlie Camp | Creating supportive learning and wellbeing opportunities for girls. | page/girlie-camp\nSafe Communities | Working to prevent violence and support the rights of women and girls. | page/sexual-gender-based-violence\nMentorship | Helping young people manage their learning and reach their potential. | page/mentorship\nScholarships | Supporting education for orphans and vulnerable children. | page/scholarships", 'image' => null, 'link_label' => null, 'link_url' => null, 'order' => 40],
    ['type' => 'call_to_action', 'eyebrow' => 'Stand with our community', 'title' => 'Help create a safer, healthier future.', 'body' => 'Your support helps the Foundation continue its community programmes.', 'image' => null, 'link_label' => 'Find ways to give', 'link_url' => 'donate', 'order' => 50],
];

$boardSections = [
    ['type' => 'hero', 'eyebrow' => 'Leadership & governance', 'title' => 'Meet our board', 'body' => 'Guided by the legacy of Francisca Kwamboka, our board brings a shared commitment to serving women, young people and children.', 'image' => null, 'link_label' => null, 'link_url' => null, 'order' => 10],
];
$findPage->execute(['fkf-board']);
$boardPage = $findPage->fetch();
if ($boardPage) {
    $members = [
        ['Rhoda Onyancha', 'Chairperson', 'assets/images/board-rhoda.jpg'],
        ["Martha Mang'are", 'Board member', 'assets/images/board-mangare.jpg'],
        ['Alex Kalonzo', 'Board secretary', 'assets/images/board-kalonzo.jpg'],
        ['Ivy Kwamboka', 'Board member', 'assets/images/board-ivy.jpg'],
        ['Lilian Marwa', 'Treasurer', 'assets/images/board-marwa.jpg'],
    ];
    $sections = preg_split(
        '~(?=^(?:Rhoda Onyancha|Martha Mang\'are|Alex Kalonzo|Ivy Kwamboka|Lilian Marwa)[ \t]+—[ \t]+)~mu',
        trim($boardPage['body'])
    ) ?: [];
    $biographies = [];
    foreach ($sections as $section) {
        if (preg_match('/^([^\r\n]+?)[ \t]+—[ \t]+([^\r\n]+)\R(.*)$/us', trim($section), $match)) {
            $biographies[$match[1]] = trim($match[3]);
        }
    }
    foreach ($members as $index => [$name, $role, $image]) {
        $boardSections[] = [
            'type' => 'profile',
            'eyebrow' => $role,
            'title' => $name,
            'body' => $biographies[$name] ?? '',
            'image' => $image,
            'link_label' => null,
            'link_url' => null,
            'order' => 20 + ($index * 10),
        ];
    }
}
$boardSections[] = ['type' => 'call_to_action', 'eyebrow' => 'Join the work', 'title' => 'Be part of a stronger community.', 'body' => 'Learn how you can support the Foundation and the people it serves.', 'image' => null, 'link_label' => 'Get in touch', 'link_url' => 'contact', 'order' => 80];

$donationSections = [
    ['type' => 'hero', 'eyebrow' => 'Give your share', 'title' => 'Support community-led change', 'body' => 'Your generosity helps us continue programmes that advance health, education and the rights of women, girls and young people.', 'image' => null, 'link_label' => null, 'link_url' => null, 'order' => 10],
    ['type' => 'donation_options', 'eyebrow' => 'Ways to give', 'title' => 'Choose how you would like to help', 'body' => "Bank transfer\nContact us for the current bank details and to let us know how you would like your contribution to support our work.\n\nPayPal\nGet in touch to arrange a one-off or recurring contribution through PayPal.", 'image' => null, 'link_label' => null, 'link_url' => null, 'order' => 20],
    ['type' => 'rich_text', 'eyebrow' => 'Important information', 'title' => 'A note about giving', 'body' => 'The Foundation is currently unable to provide tax-deductible receipts. For help with your donation, please contact the Foundation.', 'image' => null, 'link_label' => null, 'link_url' => null, 'order' => 30],
];

$pdo->beginTransaction();
try {
    $imported = [
        'home' => $saveSections('home', $homeSections),
        'fkf-board' => $saveSections('fkf-board', $boardSections),
        'donate' => $saveSections('donate', $donationSections),
    ];
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Page sections could not be imported: " . $exception->getMessage() . "\n");
    exit(1);
}

foreach ($imported as $slug => $count) {
    fwrite(STDOUT, sprintf("%s: %s\n", $slug, $count > 0 ? "{$count} sections imported." : 'Already configured; left unchanged.'));
}
