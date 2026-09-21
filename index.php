<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/kid_gate.php';
require_kid_gate();

$pdo = get_db();

$category = isset($_GET['category']) ? trim($_GET['category']) : '';

$categories = $pdo->query(
    "SELECT DISTINCT category FROM media WHERE is_active = 1 ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

if ($category !== '' && in_array($category, $categories, true)) {
    $stmt = $pdo->prepare('SELECT * FROM media WHERE is_active = 1 AND category = ? ORDER BY added_at DESC');
    $stmt->execute([$category]);
} else {
    $category = '';
    $stmt = $pdo->query('SELECT * FROM media WHERE is_active = 1 ORDER BY added_at DESC');
}
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="apple-touch-icon" href="assets/img/header-avatar.jpg">

<title><?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/kid.css">
<script src="assets/js/fullscreen.js" defer></script>
</head>
<body>

<div class="kid-header">
    <img class="header-avatar" src="assets/img/header-avatar.jpg" alt="" width="64" height="64">
    <h1><?= h(SITE_NAME) ?></h1>
</div>

<?php if (count($categories) > 1): ?>
<div class="category-row">
    <a class="category-pill <?= $category === '' ? 'active' : '' ?>" href="index.php">All</a>
    <?php foreach ($categories as $cat): ?>
        <a class="category-pill <?= $category === $cat ? 'active' : '' ?>"
           href="index.php?category=<?= urlencode($cat) ?>"><?= h($cat) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($items)): ?>
    <div class="empty-state">Nothing here yet! Ask a grown-up to add something. 🎬</div>
<?php else: ?>
<div class="video-grid">
    <?php foreach ($items as $item): ?>
        <a class="video-card" href="watch.php?id=<?= (int) $item['id'] ?><?= $category !== '' ? '&category=' . urlencode($category) : '' ?>">
            <div class="thumb-wrap">
                <img src="<?= h(media_src($item['thumbnail_url'])) ?>" alt="" loading="lazy">
                <?php if ($item['content_type'] === 'image'): ?>
                <div class="play-badge">
                    <svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="30" fill="white" fill-opacity="0.85"/>
                        <path d="M20 40 L27 31 L33 37 L41 26 L46 40 Z" fill="#3FBFE0"/>
                        <circle cx="24" cy="26" r="4" fill="#FFC93C"/>
                    </svg>
                </div>
                <?php else: ?>
                <div class="play-badge">
                    <svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="30" fill="white" fill-opacity="0.85"/><path d="M26 20 L46 32 L26 44 Z" fill="#FF6F5E"/></svg>
                </div>
                <?php endif; ?>
            </div>
            <div class="video-title"><?= h($item['title']) ?></div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="parent-gate">
    <a href="admin/login.php">Parent login</a>
</div>

</body>
</html>
