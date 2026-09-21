<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo = get_db();
    $stmt = $pdo->prepare('UPDATE media SET is_active = NOT is_active WHERE id = ?');
    $stmt->execute([$id]);
}

header('Location: dashboard.php');
exit;
