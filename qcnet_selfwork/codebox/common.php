<?php
/**
 * common.php — shared helpers for QuickChat Code Box's PHP backend.
 * No external dependencies; plain file-based storage under data/projects/<token>/.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
header('X-Content-Type-Options: nosniff');

define('CB_DATA_DIR', __DIR__ . '/data/projects');
define('CB_TOKEN_LEN', 22);
define('CB_MAX_STATE_BYTES', 4 * 1024 * 1024); // 4MB safety cap per saved project
define('CB_PROJECT_RETENTION_DAYS', 45);       // used by cleanup.php

/* ---------------------------- JSON responses ---------------------------- */
function cb_json($data, int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function cb_ok(array $extra = [], int $httpCode = 200): void {
    cb_json(array_merge(['ok' => true], $extra), $httpCode);
}
function cb_error(string $message, int $httpCode = 400): void {
    cb_json(['ok' => false, 'error' => $message], $httpCode);
}

/* ---------------------------- Input parsing ---------------------------- */
function cb_input(): array {
    $raw = file_get_contents('php://input');
    $data = [];
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = $decoded;
    }
    // GET/POST fallbacks so simple GET polling calls also work
    return array_merge($_GET, $_POST, $data);
}

/* ---------------------------- Tokens ---------------------------- */
function cb_new_token(): string {
    $bytes = random_bytes(16);
    $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    return substr($token, 0, CB_TOKEN_LEN);
}
function cb_valid_token(?string $token): bool {
    return is_string($token) && preg_match('/^[A-Za-z0-9_\-]{8,32}$/', $token) === 1;
}

/* ---------------------------- Filesystem helpers ---------------------------- */
function cb_ensure_dir(string $path): void {
    if (!is_dir($path)) {
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            cb_error('Server storage is not writable. Check permissions on the data/ folder.', 500);
        }
    }
}
function cb_project_dir(string $token, bool $create = false): string {
    if (!cb_valid_token($token)) cb_error('Invalid project token.', 400);
    $dir = CB_DATA_DIR . '/' . $token;
    if ($create) cb_ensure_dir($dir);
    return $dir;
}
function cb_read_json(string $path, $default = null) {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $default;
    $data = json_decode($raw, true);
    return $data === null && json_last_error() !== JSON_ERROR_NONE ? $default : $data;
}
function cb_write_json_atomic(string $path, $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (strlen($json) > CB_MAX_STATE_BYTES) cb_error('Project is too large to save (over 4MB).', 413);
    cb_ensure_dir(dirname($path));
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $fh = fopen($tmp, 'w');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    fwrite($fh, $json);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return rename($tmp, $path);
}
function cb_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        if (is_dir($p)) cb_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}
