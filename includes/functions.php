<?php

/** Shorthand for htmlspecialchars(). */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Turn a stored media path (thumbnail_url or file_path) into something safe
 * to drop into an <img>/<video> src from ANY page, regardless of how deep
 * that page lives (site root like index.php, or one level down like
 * admin/dashboard.php). Stored paths are root-relative like "uploads/...",
 * which only resolves correctly from a page at the site root - from
 * /admin/ it would incorrectly look for /admin/uploads/... This makes it
 * an absolute path from the domain root instead, which works everywhere,
 * as long as the site is deployed at the root of its (sub)domain rather
 * than in a subfolder like example.com/kidtube/.
 */
function media_src(?string $path): string {
    if ($path === null || $path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

/**
 * Pull an 11-character YouTube video ID out of pretty much any format a
 * parent might paste in: a full watch URL, a youtu.be short link, an
 * embed URL, a Shorts URL, or the bare ID itself.
 */
function extract_youtube_id(string $input): ?string {
    $input = trim($input);

    // Already looks like a bare video ID.
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
        return $input;
    }

    $patterns = [
        '~youtu\.be/([a-zA-Z0-9_-]{11})~',
        '~youtube\.com/watch\?v=([a-zA-Z0-9_-]{11})~',
        '~youtube\.com/embed/([a-zA-Z0-9_-]{11})~',
        '~youtube\.com/shorts/([a-zA-Z0-9_-]{11})~',
        '~[?&]v=([a-zA-Z0-9_-]{11})~',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $m)) {
            return $m[1];
        }
    }

    return null;
}

/**
 * Ask YouTube's public oEmbed endpoint for a video's title and thumbnail.
 * No API key needed. Returns null if the video can't be found (private,
 * deleted, age-restricted in a way that blocks oEmbed, etc.).
 */
function fetch_youtube_oembed(string $youtube_id): ?array {
    $url = 'https://www.youtube.com/oembed?format=json&url=' .
        urlencode('https://www.youtube.com/watch?v=' . $youtube_id);

    $context = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['title'])) {
        return null;
    }

    return [
        'title' => $data['title'],
        'thumbnail_url' => $data['thumbnail_url'] ?? ('https://i.ytimg.com/vi/' . $youtube_id . '/hqdefault.jpg'),
    ];
}

// --- Uploaded video/image handling -----------------------------------

/** Extension => allowed real MIME types, checked against the file's actual bytes (not just its name). */
function allowed_video_types(): array {
    return [
        'mp4'  => ['video/mp4'],
        'm4v'  => ['video/x-m4v', 'video/mp4'],
        'mov'  => ['video/quicktime'],
        'webm' => ['video/webm'],
        'ogv'  => ['video/ogg'],
    ];
}

function allowed_image_types(): array {
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];
}

function human_filesize(int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    return round($bytes / 1024, 1) . ' KB';
}

/** A short random filename - never trust or reuse the name the browser sent us. */
function random_filename(string $extension): string {
    return bin2hex(random_bytes(10)) . '.' . strtolower($extension);
}

/**
 * Shared validation for any uploaded file: checks upload errors, size limit,
 * extension whitelist, and real file content (not just the extension the
 * browser reported). Returns ['ok'=>true,'ext'=>...,'mime'=>...] or
 * ['ok'=>false,'error'=>...]. Doesn't touch the filesystem yet.
 */
function validate_upload_common(array $file, array $allowed, int $max_mb): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'That file is larger than this server allows (check upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'That file is larger than this form allows.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted partway through. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a file to upload.',
        ];
        return ['ok' => false, 'error' => $messages[$file['error'] ?? -1] ?? 'The upload failed. Please try again.'];
    }

    if ($file['size'] > $max_mb * 1024 * 1024) {
        return ['ok' => false, 'error' => "That file is bigger than the {$max_mb}MB limit."];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'error' => 'That file type is not supported. Allowed: ' . implode(', ', array_keys($allowed))];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $real_mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($real_mime, $allowed[$ext], true)) {
        return ['ok' => false, 'error' => "That file doesn't look like a valid .{$ext} file."];
    }

    return ['ok' => true, 'ext' => $ext, 'mime' => $real_mime];
}

/**
 * Validate and store an uploaded video or image from $_FILES.
 * Returns ['ok' => true, 'file_path' => ..., 'thumbnail_url' => ...] on
 * success, or ['ok' => false, 'error' => '...'] on failure. Every path
 * returned is relative to the site root, ready to drop into media_src().
 */
function handle_media_upload(array $file, string $kind): array {
    $max_mb = $kind === 'video' ? UPLOAD_MAX_VIDEO_MB : UPLOAD_MAX_IMAGE_MB;
    $allowed = $kind === 'video' ? allowed_video_types() : allowed_image_types();

    $check = validate_upload_common($file, $allowed, $max_mb);
    if (!$check['ok']) {
        return $check;
    }

    $subdir = $kind === 'video' ? 'videos' : 'images';
    $dest_dir = UPLOADS_DIR . '/' . $subdir;
    if (!is_dir($dest_dir) && !mkdir($dest_dir, 0755, true) && !is_dir($dest_dir)) {
        return ['ok' => false, 'error' => 'Could not create the uploads folder. Check folder permissions.'];
    }

    $filename = random_filename($check['ext']);
    $dest_path = $dest_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['ok' => false, 'error' => 'Could not save the uploaded file. Check that the uploads/ folder is writable.'];
    }

    $file_path = UPLOADS_URL . '/' . $subdir . '/' . $filename;
    $thumbnail_url = $kind === 'video'
        ? generate_video_thumbnail($dest_path, $filename)
        : generate_image_thumbnail($dest_path, $filename, $check['mime']);

    return ['ok' => true, 'file_path' => $file_path, 'thumbnail_url' => $thumbnail_url];
}

/**
 * Replace just a media item's thumbnail with a manually uploaded image.
 * Used by the "edit thumbnail" admin page for any content type.
 */
function handle_thumbnail_replacement(array $file): array {
    $check = validate_upload_common($file, allowed_image_types(), UPLOAD_MAX_IMAGE_MB);
    if (!$check['ok']) {
        return $check;
    }

    $thumb_dir = UPLOADS_DIR . '/thumbnails';
    if (!is_dir($thumb_dir) && !mkdir($thumb_dir, 0755, true) && !is_dir($thumb_dir)) {
        return ['ok' => false, 'error' => 'Could not create the thumbnails folder. Check folder permissions.'];
    }

    $filename = random_filename('jpg');
    $dest_path = $thumb_dir . '/' . $filename;

    if (function_exists('imagecreatetruecolor')) {
        $tmp_source = match ($check['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png' => @imagecreatefrompng($file['tmp_name']),
            'image/gif' => @imagecreatefromgif($file['tmp_name']),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
            default => false,
        };
        if ($tmp_source) {
            $orig_w = imagesx($tmp_source);
            $orig_h = imagesy($tmp_source);
            $target_w = 480;
            $target_h = (int) round($orig_h * ($target_w / $orig_w));
            $thumb = imagecreatetruecolor($target_w, $target_h);
            imagecopyresampled($thumb, $tmp_source, 0, 0, 0, 0, $target_w, $target_h, $orig_w, $orig_h);
            imagejpeg($thumb, $dest_path, 85);
            imagedestroy($tmp_source);
            imagedestroy($thumb);
            return ['ok' => true, 'thumbnail_url' => UPLOADS_URL . '/thumbnails/' . $filename];
        }
    }

    // GD unavailable or failed to decode - just store the file as-is.
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['ok' => false, 'error' => 'Could not save the thumbnail. Check that the uploads/ folder is writable.'];
    }
    return ['ok' => true, 'thumbnail_url' => UPLOADS_URL . '/thumbnails/' . $filename];
}

/**
 * Re-extract a thumbnail from an already-uploaded video at a chosen
 * timestamp. Used by the "edit thumbnail" admin page's regenerate button.
 * Returns the new relative thumbnail path, or null if ffmpeg isn't
 * available or extraction fails at that timestamp.
 */
function regenerate_video_thumbnail_at(string $file_path_relative, float $seconds): ?string {
    $video_path = dirname(UPLOADS_DIR) . '/' . ltrim($file_path_relative, '/');
    if (!is_file($video_path)) {
        return null;
    }

    $ffmpeg = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($ffmpeg === '') {
        return null;
    }

    $thumb_dir = UPLOADS_DIR . '/thumbnails';
    if (!is_dir($thumb_dir)) {
        @mkdir($thumb_dir, 0755, true);
    }
    $filename = random_filename('jpg');
    $thumb_path = $thumb_dir . '/' . $filename;

    $seconds = max(0, $seconds);
    $timestamp = gmdate('H:i:s', (int) $seconds);

    $cmd = escapeshellcmd($ffmpeg)
        . ' -y -ss ' . escapeshellarg($timestamp)
        . ' -i ' . escapeshellarg($video_path)
        . ' -frames:v 1 -vf ' . escapeshellarg('scale=480:-1')
        . ' ' . escapeshellarg($thumb_path) . ' 2>&1';
    @exec($cmd, $out, $code);

    if ($code === 0 && file_exists($thumb_path) && filesize($thumb_path) > 0) {
        return UPLOADS_URL . '/thumbnails/' . $filename;
    }

    return null;
}

/**
 * Try to grab a frame from an uploaded video with ffmpeg, if it's installed
 * on this server. Falls back to a generic placeholder thumbnail if ffmpeg
 * isn't available or the extraction fails - upload still succeeds either way.
 */
function generate_video_thumbnail(string $video_path, string $source_filename): string {
    $thumb_name = pathinfo($source_filename, PATHINFO_FILENAME) . '.jpg';
    $thumb_dir = UPLOADS_DIR . '/thumbnails';
    if (!is_dir($thumb_dir)) {
        @mkdir($thumb_dir, 0755, true);
    }
    $thumb_path = $thumb_dir . '/' . $thumb_name;

    if (function_exists('exec')) {
        $ffmpeg = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg !== '') {
            $cmd = escapeshellcmd($ffmpeg)
                . ' -y -ss 00:00:01 -i ' . escapeshellarg($video_path)
                . ' -frames:v 1 -vf ' . escapeshellarg('scale=480:-1')
                . ' ' . escapeshellarg($thumb_path) . ' 2>&1';
            @exec($cmd, $out, $code);
            if ($code === 0 && file_exists($thumb_path) && filesize($thumb_path) > 0) {
                return UPLOADS_URL . '/thumbnails/' . $thumb_name;
            }
        }
    }

    // Fallback: a shared placeholder graphic, no per-video file needed.
    return 'assets/img/video-placeholder.jpg';
}

/**
 * Make a small thumbnail copy of an uploaded photo using GD, so the home
 * grid doesn't have to load full-resolution images. Falls back to using
 * the original image directly if GD isn't available on this server.
 */
function generate_image_thumbnail(string $image_path, string $source_filename, string $mime): string {
    if (!function_exists('imagecreatetruecolor')) {
        return UPLOADS_URL . '/images/' . $source_filename;
    }

    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($image_path),
        'image/png' => @imagecreatefrompng($image_path),
        'image/gif' => @imagecreatefromgif($image_path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($image_path) : false,
        default => false,
    };

    if (!$source) {
        return UPLOADS_URL . '/images/' . $source_filename;
    }

    $orig_w = imagesx($source);
    $orig_h = imagesy($source);
    $target_w = 480;
    $target_h = (int) round($orig_h * ($target_w / $orig_w));

    $thumb = imagecreatetruecolor($target_w, $target_h);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $target_w, $target_h, $orig_w, $orig_h);

    $thumb_dir = UPLOADS_DIR . '/thumbnails';
    if (!is_dir($thumb_dir)) {
        @mkdir($thumb_dir, 0755, true);
    }
    $thumb_name = pathinfo($source_filename, PATHINFO_FILENAME) . '.jpg';
    $thumb_path = $thumb_dir . '/' . $thumb_name;
    imagejpeg($thumb, $thumb_path, 85);

    imagedestroy($source);
    imagedestroy($thumb);

    return UPLOADS_URL . '/thumbnails/' . $thumb_name;
}

/** Delete a media item's files from disk. Safe to call even if a path is a remote URL or missing. */
function delete_media_files(array $item): void {
    $paths = [];
    if (!empty($item['file_path'])) {
        $paths[] = $item['file_path'];
    }
    $thumb = $item['thumbnail_url'] ?? '';
    $is_placeholder = ltrim($thumb, '/') === 'assets/img/video-placeholder.jpg';
    if ($thumb !== '' && !preg_match('~^https?://~i', $thumb) && !$is_placeholder) {
        $paths[] = $thumb;
    }
    foreach ($paths as $relative_path) {
        $full_path = dirname(UPLOADS_DIR) . '/' . ltrim($relative_path, '/');
        if (is_file($full_path)) {
            @unlink($full_path);
        }
    }
}
