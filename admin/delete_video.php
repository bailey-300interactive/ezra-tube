<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo = get_db();

    $stmt = $pdo->prepare('SELECT * FROM media WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if ($item) {
        delete_media_files($item);
        $stmt = $pdo->prepare('DELETE FROM media WHERE id = ?');
        $stmt->execute([$id]);
    }
}

header('Location: dashboard.php?msg=deleted');
exit;
