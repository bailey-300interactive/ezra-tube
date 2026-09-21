<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/kid_gate.php';
require_kid_gate();

$pdo = get_db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

$stmt = $pdo->prepare('SELECT * FROM media WHERE id = ? AND is_active = 1');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    header('Location: index.php');
    exit;
}

// Build the same ordered list index.php would show (optionally scoped to a
// category), so we can figure out what "next" and "previous" mean here -
// lets a kid keep swiping through content without going back to the grid.
if ($category !== '') {
    $stmt = $pdo->prepare('SELECT id FROM media WHERE is_active = 1 AND category = ? ORDER BY added_at DESC');
    $stmt->execute([$category]);
} else {
    $stmt = $pdo->query('SELECT id FROM media WHERE is_active = 1 ORDER BY added_at DESC');
}
$ordered_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$current_index = array_search($item['id'], $ordered_ids, true);
$prev_id = null;
$next_id = null;
if ($current_index !== false && count($ordered_ids) > 1) {
    $prev_id = $ordered_ids[($current_index - 1 + count($ordered_ids)) % count($ordered_ids)];
    $next_id = $ordered_ids[($current_index + 1) % count($ordered_ids)];
}

$category_qs = $category !== '' ? '&category=' . urlencode($category) : '';
$prev_url = $prev_id ? "watch.php?id={$prev_id}{$category_qs}" : null;
$next_url = $next_id ? "watch.php?id={$next_id}{$category_qs}" : null;

$embed_src = null;
if ($item['content_type'] === 'youtube' && ENABLE_YOUTUBE) {
    // youtube-nocookie.com is YouTube's own privacy-enhanced embed domain.
    // rel=0 limits related-video suggestions and modestbranding trims some
    // of YouTube's branding, but the YouTube logo (which links off-site) is
    // required to stay in the controls - see config.php's ENABLE_YOUTUBE note.
    $embed_src = 'https://www.youtube-nocookie.com/embed/' . urlencode($item['youtube_id'])
        . '?rel=0&modestbranding=1&playsinline=1';
} elseif ($item['content_type'] === 'youtube') {
    // YouTube got disabled after this was added - don't try to play it.
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($item['title']) ?> · <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/kid.css">
<script src="assets/js/fullscreen.js" defer></script>
</head>
<body>
<div class="watch-page" id="watch-swipe-area">
    <a class="back-btn" href="index.php<?= $category !== '' ? '?category=' . urlencode($category) : '' ?>">&larr; Back</a>

    <?php if ($item['content_type'] === 'youtube'): ?>
        <div class="player-wrap">
            <iframe src="<?= h($embed_src) ?>"
                    title="<?= h($item['title']) ?>"
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
        </div>

    <?php elseif ($item['content_type'] === 'video'): ?>
        <div class="player-wrap">
            <video autoplay controls loop playsinline poster="<?= h(media_src($item['thumbnail_url'])) ?>">
                <source src="<?= h(media_src($item['file_path'])) ?>">
                Sorry, this video can't be played in your browser.
            </video>
        </div>

    <?php elseif ($item['content_type'] === 'image'): ?>
        <div class="photo-wrap">
            <img src="<?= h(media_src($item['file_path'])) ?>" alt="<?= h($item['title']) ?>">
        </div>
    <?php endif; ?>

    <div class="watch-title"><?= h($item['title']) ?></div>

    <?php if ($prev_url || $next_url): ?>
    <div class="watch-nav-row">
        <?php if ($prev_url): ?>
            <a class="watch-nav-btn" href="<?= h($prev_url) ?>">&larr; Previous</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <?php if ($next_url): ?>
            <a class="watch-nav-btn next" href="<?= h($next_url) ?>">Next &rarr;</a>
        <?php endif; ?>
    </div>
    <p class="swipe-hint">Swipe left or right for more!</p>
    <?php endif; ?>
</div>

<?php if ($prev_url || $next_url): ?>
<script>
(function () {
    var area = document.getElementById('watch-swipe-area');
    var prevUrl = <?= json_encode($prev_url) ?>;
    var nextUrl = <?= json_encode($next_url) ?>;
    var startX = null, startY = null;

    area.addEventListener('touchstart', function (e) {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
    }, { passive: true });

    area.addEventListener('touchend', function (e) {
        if (startX === null) return;
        var dx = e.changedTouches[0].clientX - startX;
        var dy = e.changedTouches[0].clientY - startY;
        // Require a mostly-horizontal swipe of at least 60px so scrolling
        // the page vertically never gets mistaken for a swipe.
        if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            if (dx < 0 && nextUrl) window.location.href = nextUrl;
            if (dx > 0 && prevUrl) window.location.href = prevUrl;
        }
        startX = null; startY = null;
    }, { passive: true });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight' && nextUrl) window.location.href = nextUrl;
        if (e.key === 'ArrowLeft' && prevUrl) window.location.href = prevUrl;
    });
})();
</script>
<?php endif; ?>
</body>
</html>
