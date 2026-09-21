<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = get_db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM media WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$ffmpeg_available = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null')) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_custom' && isset($_FILES['thumbnail_file'])) {
        $result = handle_thumbnail_replacement($_FILES['thumbnail_file']);
        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            $old_thumb = $item['thumbnail_url'];
            $stmt = $pdo->prepare('UPDATE media SET thumbnail_url = ? WHERE id = ?');
            $stmt->execute([$result['thumbnail_url'], $id]);
            cleanup_old_thumbnail($old_thumb);
            header('Location: dashboard.php?msg=thumb_updated');
            exit;
        }
    } elseif ($action === 'regenerate' && $item['content_type'] === 'video') {
        $seconds = (float) ($_POST['seconds'] ?? 1);
        $new_thumb = regenerate_video_thumbnail_at($item['file_path'], $seconds);
        if ($new_thumb === null) {
            $error = $ffmpeg_available
                ? "Couldn't grab a frame at that time - try an earlier timestamp (maybe the video is shorter than that)."
                : 'ffmpeg is not installed on this server, so an exact frame can\'t be extracted. Upload a custom thumbnail image instead.';
        } else {
            $old_thumb = $item['thumbnail_url'];
            $stmt = $pdo->prepare('UPDATE media SET thumbnail_url = ? WHERE id = ?');
            $stmt->execute([$new_thumb, $id]);
            cleanup_old_thumbnail($old_thumb);
            header('Location: dashboard.php?msg=thumb_updated');
            exit;
        }
    }
}

/** Deletes the previous thumbnail file, unless it's the shared placeholder graphic. */
function cleanup_old_thumbnail(?string $old_thumb): void {
    if (!$old_thumb || preg_match('~^https?://~i', $old_thumb)) {
        return;
    }
    if (ltrim($old_thumb, '/') === 'assets/img/video-placeholder.jpg') {
        return;
    }
    $full_path = dirname(UPLOADS_DIR) . '/' . ltrim($old_thumb, '/');
    if (is_file($full_path)) {
        @unlink($full_path);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit thumbnail · <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <h1>Edit thumbnail</h1>
        <a href="dashboard.php">&larr; Back to dashboard</a>
    </div>

    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

    <div class="card">
        <p style="font-weight:700; margin-top:0;"><?= h($item['title']) ?></p>
        <img src="<?= h(media_src($item['thumbnail_url'])) ?>" alt="" style="width:280px; max-width:100%; border-radius:8px; display:block;">
    </div>

    <div class="card">
        <h3 style="margin-top:0; font-size:1rem;">Upload a custom thumbnail</h3>
        <p class="hint">JPG, PNG, GIF or WebP. Works for any media type.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <input type="hidden" name="action" value="upload_custom">
            <div class="field">
                <input type="file" name="thumbnail_file" accept="image/jpeg,image/png,image/gif,image/webp" required>
            </div>
            <button class="btn" type="submit">Use this image</button>
        </form>
    </div>

    <?php if ($item['content_type'] === 'video'): ?>
    <div class="card">
        <h3 style="margin-top:0; font-size:1rem;">Or grab a frame from the video</h3>
        <?php if ($ffmpeg_available): ?>
            <p class="hint">Pick a timestamp (in seconds) and we'll pull that exact frame from the video file.</p>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="action" value="regenerate">
                <div class="field">
                    <label for="seconds">Timestamp (seconds)</label>
                    <input type="number" id="seconds" name="seconds" min="0" step="0.5" value="1" required>
                </div>
                <button class="btn secondary" type="submit">Grab this frame</button>
            </form>
        <?php else: ?>
            <p class="hint">This server doesn't have <code>ffmpeg</code> installed, so frames can't be pulled automatically - upload a custom thumbnail above instead. (On Rocky/RHEL, ffmpeg needs the RPM Fusion repo since it's not in the base repos - see the README.)</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
