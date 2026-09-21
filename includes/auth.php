<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

function is_logged_in(): bool {
    return !empty($_SESSION['admin_id']);
}

/** Call at the top of any admin page that requires a logged-in parent. */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}
