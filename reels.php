<?php
/**
 * Reels mode: a full-screen, swipe-up feed like Instagram Reels / TikTok.
 * One item fills the screen; swipe up for the next, down for the previous.
 * Videos play when they're on screen and pause when they leave it.
 * It's one page, so fullscreen and sound stay on while he swipes.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/kid_gate.php';
require_kid_gate();

$pdo = get_db();

$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$start_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$categories = $pdo->query(
    "SELECT DISTINCT category FROM media WHERE is_active = 1 ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// Same order as the home grid, so "next" means the same thing everywhere.
if ($category !== '' && in_array($category, $categories, true)) {
    $stmt = $pdo->prepare('SELECT * FROM media WHERE is_active = 1 AND category = ? ORDER BY added_at DESC');
    $stmt->execute([$category]);
} else {
    $category = '';
    $stmt = $pdo->query('SELECT * FROM media WHERE is_active = 1 ORDER BY added_at DESC');
}

$items = [];
$start_index = 0;
foreach ($stmt->fetchAll() as $row) {
    if ($row['content_type'] === 'youtube' && !ENABLE_YOUTUBE) {
        continue; // YouTube was switched off after this was added
    }
    if ((int) $row['id'] === $start_id) {
        $start_index = count($items);
    }
    $items[] = [
        'id' => (int) $row['id'],
        'type' => $row['content_type'],
        'title' => $row['title'],
        'src' => $row['content_type'] === 'youtube' ? '' : media_src($row['file_path']),
        'poster' => media_src($row['thumbnail_url']),
        'youtube' => $row['content_type'] === 'youtube' ? $row['youtube_id'] : '',
    ];
}

$home_url = 'index.php' . ($category !== '' ? '?category=' . urlencode($category) : '');

if (empty($items)) {
    header('Location: ' . $home_url);
    exit;
}

$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#000000">
<link rel="apple-touch-icon" href="assets/img/header-avatar.jpg">
<title><?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/kid.css">
<link rel="stylesheet" href="assets/css/reels.css">
<script src="assets/js/fullscreen.js" defer></script>
<script src="assets/js/reels.js" defer></script>
</head>
<body class="reels-body">

<main class="reels" id="reels" aria-label="<?= h(SITE_NAME) ?>"></main>

<a class="reels-btn reels-home" href="<?= h($home_url) ?>" aria-label="Back to all videos">
    <svg viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6.5 9.5V20h4v-5.5h3V20h4V9.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
</a>

<button type="button" class="reels-btn reels-mute" id="reels-mute" aria-label="Sound off">
    <svg viewBox="0 0 24 24" class="icon-sound"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor"/><path d="M15.5 9a4.5 4.5 0 0 1 0 6M18 6.5a8 8 0 0 1 0 11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
    <svg viewBox="0 0 24 24" class="icon-muted"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor"/><path d="M16 9.5l5 5M21 9.5l-5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
</button>

<div class="reels-start" id="reels-start">
    <img class="reels-start-avatar" src="assets/img/header-avatar.jpg" alt="" width="96" height="96">
    <h1><?= h(SITE_NAME) ?></h1>
    <button type="button" class="reels-start-btn" id="reels-start-btn" aria-label="Start watching">
        <svg viewBox="0 0 64 64"><path d="M24 16 L50 32 L24 48 Z" fill="currentColor" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/></svg>
    </button>
    <p>Tap to watch!</p>
</div>

<div class="reels-hint" id="reels-hint" aria-hidden="true">
    <span class="reels-hint-hand">👆</span>
    <span>Swipe up for more!</span>
</div>

<script>
window.EZRA_REELS = {
    items: <?= json_encode($items, $json_flags) ?>,
    start: <?= (int) $start_index ?>,
    category: <?= json_encode($category, $json_flags) ?>
};
</script>
</body>
</html>
