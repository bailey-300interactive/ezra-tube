<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

const KID_GATE_COOKIE = 'kidtube_gate';
const KID_GATE_COOKIE_DAYS = 90;
const KID_GATE_MAX_ATTEMPTS = 6;
const KID_GATE_LOCKOUT_SECONDS = 60;

/**
 * A signed token tied to the current password - if the password is ever
 * changed, every previously "remembered" device is automatically signed
 * out, since old cookies won't match a token computed from the new one.
 */
function kid_gate_token(): string {
    return hash_hmac('sha256', 'kidtube-gate-v1', KID_GATE_PASSWORD);
}

function kid_gate_is_unlocked(): bool {
    if (!KID_GATE_ENABLED) {
        return true;
    }
    if (!empty($_SESSION['kid_gate_unlocked'])) {
        return true;
    }
    if (isset($_COOKIE[KID_GATE_COOKIE]) && hash_equals(kid_gate_token(), $_COOKIE[KID_GATE_COOKIE])) {
        $_SESSION['kid_gate_unlocked'] = true;
        return true;
    }
    return false;
}

/** Call at the top of any kid-facing page. */
function require_kid_gate(): void {
    if (kid_gate_is_unlocked()) {
        return;
    }
    $redirect = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: ' . kid_gate_base_path() . 'gate.php?redirect=' . urlencode($redirect));
    exit;
}

/** Figures out how many directories deep the current script is, so gate.php links work from any depth. */
function kid_gate_base_path(): string {
    // Every kid-facing page currently lives at the site root, so this is
    // simple - kept as a function in case that ever changes.
    return '';
}

function kid_gate_too_many_attempts(): bool {
    $attempts = $_SESSION['kid_gate_attempts'] ?? 0;
    $locked_until = $_SESSION['kid_gate_locked_until'] ?? 0;
    return $attempts >= KID_GATE_MAX_ATTEMPTS && time() < $locked_until;
}

function kid_gate_record_failure(): void {
    $_SESSION['kid_gate_attempts'] = ($_SESSION['kid_gate_attempts'] ?? 0) + 1;
    if ($_SESSION['kid_gate_attempts'] >= KID_GATE_MAX_ATTEMPTS) {
        $_SESSION['kid_gate_locked_until'] = time() + KID_GATE_LOCKOUT_SECONDS;
    }
}

function kid_gate_record_success(bool $remember): void {
    $_SESSION['kid_gate_unlocked'] = true;
    $_SESSION['kid_gate_attempts'] = 0;
    if ($remember) {
        setcookie(KID_GATE_COOKIE, kid_gate_token(), [
            'expires' => time() + KID_GATE_COOKIE_DAYS * 86400,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
