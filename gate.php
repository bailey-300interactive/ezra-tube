<?php
require_once __DIR__ . '/includes/kid_gate.php';
require_once __DIR__ . '/includes/functions.php';

$redirect = $_GET['redirect'] ?? 'index.php';
// Only allow redirecting to a local path, never an external URL.
if (!preg_match('~^/[A-Za-z0-9_\-./?=&%]*$~', $redirect)) {
    $redirect = 'index.php';
}

$error = '';

if (kid_gate_is_unlocked()) {
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (kid_gate_too_many_attempts()) {
        $error = 'Too many tries - please wait a minute and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        if (hash_equals(KID_GATE_PASSWORD, $password)) {
            kid_gate_record_success(!empty($_POST['remember']));
            header('Location: ' . $redirect);
            exit;
        }
        kid_gate_record_failure();
        $error = 'That password isn\'t right - ask a grown-up for help.';
    }
}
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
<div class="gate-wrap">
    <div class="kid-header" style="justify-content:center;">
        <h1><?= h(SITE_NAME) ?></h1>
    </div>
    <div class="gate-card">
        <p class="gate-hint">Ask a grown-up to unlock this! 🔒</p>
        <?php if ($error): ?><div class="gate-error"><?= h($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="password" name="password" placeholder="Grown-up password" required autofocus>
            <label class="gate-remember">
                <input type="checkbox" name="remember" value="1" checked>
                Remember this device
            </label>
            <button class="unlock-btn" type="submit">Unlock</button>
        </form>
    </div>
</div>
</body>
</html>
