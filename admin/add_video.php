<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = get_db();
$error = '';
$preview = null;

$existing_categories = $pdo->query(
    'SELECT DISTINCT category FROM media ORDER BY category ASC'
)->fetchAll(PDO::FETCH_COLUMN);

$tab = $_GET['tab'] ?? ($_POST['tab'] ?? (ENABLE_YOUTUBE ? 'youtube' : 'video'));
if (!in_array($tab, ['youtube', 'video', 'image'], true) || ($tab === 'youtube' && !ENABLE_YOUTUBE)) {
    $tab = ENABLE_YOUTUBE ? 'youtube' : 'video';
}

$step = $_POST['step'] ?? 'find';

// --- YouTube: step 1, look up the video ---
if (ENABLE_YOUTUBE && $_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'youtube' && $step === 'find') {
    $link = trim($_POST['link'] ?? '');
    $youtube_id = extract_youtube_id($link);

    if (!$youtube_id) {
        $error = "Couldn't find a YouTube video in that link. Paste the full video URL (or its ID).";
    } else {
        $info = fetch_youtube_oembed($youtube_id);
        if (!$info) {
            $error = "Couldn't find that video on YouTube. It may be private, deleted, or age-restricted.";
        } else {
            $preview = [
                'youtube_id' => $youtube_id,
                'title' => $info['title'],
                'thumbnail_url' => $info['thumbnail_url'],
            ];
        }
    }
}

// --- YouTube: step 2, confirm and save ---
if (ENABLE_YOUTUBE && $_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'youtube' && $step === 'save') {
    $youtube_id = trim($_POST['youtube_id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $thumbnail_url = trim($_POST['thumbnail_url'] ?? '');
    $category = trim($_POST['category'] ?? '') ?: 'General';

    if ($youtube_id === '' || $title === '') {
        $error = 'Missing video details, please try again.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO media (content_type, youtube_id, title, thumbnail_url, category, is_active)
             VALUES ('youtube', ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE title = VALUES(title), thumbnail_url = VALUES(thumbnail_url),
                                     category = VALUES(category), is_active = 1"
        );
        $stmt->execute([$youtube_id, $title, $thumbnail_url, $category]);
        header('Location: dashboard.php?msg=added');
        exit;
    }
}

// --- Uploaded video or image: single-step save ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($tab, ['video', 'image'], true) && $step === 'upload') {
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '') ?: 'General';
    $file = $_FILES['media_file'] ?? null;

    if ($title === '') {
        $error = 'Please give it a title.';
    } elseif (!$file) {
        $error = 'Please choose a file to upload.';
    } else {
        $result = handle_media_upload($file, $tab);
        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO media (content_type, file_path, title, thumbnail_url, category, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$tab, $result['file_path'], $title, $result['thumbnail_url'], $category]);
            header('Location: dashboard.php?msg=added');
            exit;
        }
    }
}

$max_video_mb = UPLOAD_MAX_VIDEO_MB;
$max_image_mb = UPLOAD_MAX_IMAGE_MB;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add media · <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <h1>Add media</h1>
        <a href="dashboard.php">&larr; Back to dashboard</a>
    </div>

    <div class="tab-row">
        <?php if (ENABLE_YOUTUBE): ?>
        <a class="tab <?= $tab === 'youtube' ? 'active' : '' ?>" href="?tab=youtube">YouTube link</a>
        <?php endif; ?>
        <a class="tab <?= $tab === 'video' ? 'active' : '' ?>" href="?tab=video">Upload a video</a>
        <a class="tab <?= $tab === 'image' ? 'active' : '' ?>" href="?tab=image">Upload a photo</a>
    </div>

    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($tab === 'youtube'): ?>

        <?php if (!$preview): ?>
        <div class="card">
            <form method="post">
                <input type="hidden" name="tab" value="youtube">
                <input type="hidden" name="step" value="find">
                <div class="field">
                    <label for="link">YouTube link or video ID</label>
                    <input type="text" id="link" name="link" placeholder="https://www.youtube.com/watch?v=..." required autofocus>
                </div>
                <button class="btn" type="submit">Find video</button>
            </form>
        </div>
        <?php else: ?>
        <div class="card">
            <p style="display:flex; gap:16px; align-items:flex-start;">
                <img src="<?= h($preview['thumbnail_url']) ?>" alt="" style="width:200px; border-radius:8px;">
                <span><strong><?= h($preview['title']) ?></strong><br>
                <span style="color:var(--muted); font-size:0.85rem;">Video ID: <?= h($preview['youtube_id']) ?></span></span>
            </p>
            <form method="post">
                <input type="hidden" name="tab" value="youtube">
                <input type="hidden" name="step" value="save">
                <input type="hidden" name="youtube_id" value="<?= h($preview['youtube_id']) ?>">
                <input type="hidden" name="thumbnail_url" value="<?= h($preview['thumbnail_url']) ?>">
                <div class="field">
                    <label for="title">Title (you can rename it for your kid)</label>
                    <input type="text" id="title" name="title" value="<?= h($preview['title']) ?>" required>
                </div>
                <div class="field">
                    <label for="category">Category</label>
                    <input type="text" id="category" name="category" list="category-list" placeholder="General" value="General">
                    <datalist id="category-list">
                        <?php foreach ($existing_categories as $cat): ?>
                            <option value="<?= h($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <button class="btn" type="submit">Approve &amp; add to KidTube</button>
            </form>
        </div>
        <?php endif; ?>

    <?php elseif ($tab === 'video'): ?>

        <div class="card">
            <p class="hint">MP4, MOV, WebM or M4V, up to <?= (int) $max_video_mb ?>MB. Good for home videos, school projects, or anything that isn't on YouTube.</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="video">
                <input type="hidden" name="step" value="upload">
                <div class="field">
                    <label for="media_file">Video file</label>
                    <input type="file" id="media_file" name="media_file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v,video/ogg" required>
                </div>
                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" placeholder="Grandma's birthday party" required>
                </div>
                <div class="field">
                    <label for="category">Category</label>
                    <input type="text" id="category" name="category" list="category-list" placeholder="General" value="General">
                    <datalist id="category-list">
                        <?php foreach ($existing_categories as $cat): ?>
                            <option value="<?= h($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <button class="btn" type="submit">Upload &amp; add to Ezra Tube</button>
            </form>
        </div>

    <?php elseif ($tab === 'image'): ?>

        <div class="card">
            <p class="hint">JPG, PNG, GIF or WebP, up to <?= (int) $max_image_mb ?>MB. Great for family photos or fun pictures you want your kid to be able to browse to.</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="image">
                <input type="hidden" name="step" value="upload">
                <div class="field">
                    <label for="media_file">Photo file</label>
                    <input type="file" id="media_file" name="media_file" accept="image/jpeg,image/png,image/gif,image/webp" required>
                </div>
                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" placeholder="Our trip to the zoo" required>
                </div>
                <div class="field">
                    <label for="category">Category</label>
                    <input type="text" id="category" name="category" list="category-list" placeholder="General" value="General">
                    <datalist id="category-list">
                        <?php foreach ($existing_categories as $cat): ?>
                            <option value="<?= h($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <button class="btn" type="submit">Upload &amp; add to Ezra Tube</button>
            </form>
        </div>

    <?php endif; ?>
</div>
</body>
</html>
