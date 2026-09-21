<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = get_db();
$items = $pdo->query('SELECT * FROM media ORDER BY added_at DESC')->fetchAll();

$message = $_GET['msg'] ?? '';

$type_labels = ['youtube' => 'YouTube', 'video' => 'Video', 'image' => 'Photo'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="apple-touch-icon" href="assets/img/header-avatar.jpg">
<title>Dashboard · <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <h1><?= h(SITE_NAME) ?> · Approved media</h1>
        <div class="row-actions">
            <a href="../index.php">View Ezra Tube</a>
            <a href="logout.php">Log out</a>
        </div>
    </div>

    <?php if ($message === 'added'): ?>
        <div class="alert success">Added.</div>
    <?php elseif ($message === 'deleted'): ?>
        <div class="alert success">Removed.</div>
    <?php elseif ($message === 'thumb_updated'): ?>
        <div class="alert success">Thumbnail updated.</div>
    <?php endif; ?>

    <div class="card">
        <a class="btn" href="add_video.php">+ Add media</a>
    </div>

    <?php if (empty($items)): ?>
        <div class="card"><p>Nothing added yet.</p></div>
    <?php else: ?>
    <div class="media-grid">
        <?php foreach ($items as $v): ?>
        <div class="media-item">
            <img class="media-thumb" src="<?= h(media_src($v['thumbnail_url'])) ?>" alt="">
            <div class="media-info">
                <div class="media-title"><?= h($v['title']) ?></div>
                <div class="media-tags">
                    <span class="tag"><?= h($type_labels[$v['content_type']] ?? $v['content_type']) ?></span>
                    <span class="tag"><?= h($v['category']) ?></span>
                    <?php if ($v['is_active']): ?>
                        <span class="status-on">Visible</span>
                    <?php else: ?>
                        <span class="status-off">Hidden</span>
                    <?php endif; ?>
                </div>
                <div class="media-actions">
                    <a class="btn secondary" href="edit_thumbnail.php?id=<?= (int) $v['id'] ?>">Edit thumbnail</a>
                    <form method="post" action="toggle_video.php">
                        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <button class="btn secondary" type="submit">
                            <?= $v['is_active'] ? 'Hide' : 'Show' ?>
                        </button>
                    </form>
                    <form method="post" action="delete_video.php" onsubmit="return confirm('Remove this for good? This also deletes any uploaded file.');">
                        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <button class="btn danger" type="submit">Delete</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
