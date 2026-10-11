<?php
// dash_theme_api.php — serves data_dash/themes.json (kept as PLAIN JSON,
// deliberately not run through dash_crypto.php's AES-256 layer like every
// other table) so the site owner can open it directly via FTP/file manager
// and edit it, or drop in new theme entries + wallpaper images, with zero
// app code changes. get_themes() always reads fresh from disk — no
// caching — so a server-side edit shows up for every visitor on their very
// next page load, satisfying "auto add when it's updated on the server."
//
// Reading the registry needs no login (themes aren't sensitive, and the
// login pages themselves need to be themed before anyone's authenticated).
// Only the small set of write actions (set default, upload a wallpaper)
// require the master admin — this is a site-wide setting, not a per-tenant
// one, so it's deliberately not exposed to tenant admins.
require_once 'dash_firewall.php';
header('Content-Type: application/json');

$themes_file = __DIR__ . '/data_dash/themes.json';
$wallpapers_dir = __DIR__ . '/files_dash/wallpapers';
if (!is_dir($wallpapers_dir)) @mkdir($wallpapers_dir, 0755, true);
$action = $_GET['action'] ?? '';

function read_themes_file($path) {
    if (!file_exists($path)) return null;
    $data = json_decode(@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}
function write_themes_file($path, $data) {
    $data['updatedAt'] = gmdate('Y-m-d\TH:i:s.000\Z');
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function require_master_admin() {
    if (!isset($_SESSION['dash_admin']) || ($_SESSION['dash_tenant'] ?? '') !== 'main') {
        http_response_code(403); die(json_encode(["error" => "Only the default super admin can change site-wide theme settings."]));
    }
}

switch ($action) {

    case 'get_themes':
        $data = read_themes_file($themes_file);
        if (!$data) { http_response_code(404); die(json_encode(["error" => "Theme registry not found."])); }
        // Resolve any image-type wallpapers to a fetchable URL for the client.
        foreach ($data['themes'] as &$t) {
            if (($t['wallpaper']['type'] ?? '') === 'image') {
                $t['wallpaper']['url'] = 'dash_theme_api.php?action=get_wallpaper&file=' . urlencode($t['wallpaper']['value']);
            }
        }
        echo json_encode($data);
        break;

    case 'get_wallpaper':
        // Public image proxy — wallpapers aren't sensitive, but this still
        // only ever serves a file that's actually referenced by a real
        // theme entry, from a fixed directory, nothing path-traversable.
        $file = basename($_GET['file'] ?? '');
        $data = read_themes_file($themes_file);
        $known = array_column(array_column($data['themes'] ?? [], 'wallpaper'), 'value');
        if (!in_array($file, $known, true) || !file_exists($wallpapers_dir . '/' . $file)) { http_response_code(404); exit; }
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $wallpapers_dir . '/' . $file); finfo_close($finfo);
        header('Content-Type: ' . $mime); header('Cache-Control: public, max-age=86400');
        readfile($wallpapers_dir . '/' . $file); exit;

    case 'set_default_theme':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_master_admin();
            if (!rate_limit_allow('theme_admin_write', get_client_ip(), 20, 600)) { http_response_code(429); die(json_encode(["error" => "Too many requests."])); }
            $id = trim($_POST['theme_id'] ?? '');
            $data = read_themes_file($themes_file);
            if (!$data || !in_array($id, array_column($data['themes'], 'id'), true)) die(json_encode(["error" => "Unknown theme id."]));
            $data['defaultTheme'] = $id;
            write_themes_file($themes_file, $data);
            require_once 'dash_audit.php'; log_activity(__DIR__ . '/data_dash', $_SESSION['dash_admin'], 'theme_default_changed', "Set site default theme to \"$id\".");
            echo json_encode(["status" => "success"]);
        }
        break;

    case 'upload_wallpaper':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_master_admin();
            if (empty($_FILES['wallpaper'])) die(json_encode(["error" => "No file received."]));
            if ($_FILES['wallpaper']['size'] > 4 * 1024 * 1024) die(json_encode(["error" => "Wallpaper image must be 4MB or smaller."]));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['wallpaper']['tmp_name']); finfo_close($finfo);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) die(json_encode(["error" => "Choose a JPG, PNG, or WebP image."]));
            $stored_name = 'wp_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($_FILES['wallpaper']['tmp_name'], $wallpapers_dir . '/' . $stored_name)) die(json_encode(["error" => "Upload failed."]));
            echo json_encode(["status" => "success", "filename" => $stored_name]);
        }
        break;

    case 'save_theme':
        // Add or update a theme entry (e.g. pairing an uploaded wallpaper
        // with a new theme id/name/colors) from inside the app, as a
        // convenience alternative to hand-editing themes.json over FTP.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_master_admin();
            $body = json_decode(file_get_contents('php://input'), true);
            $theme = $body['theme'] ?? null;
            if (!$theme || empty($theme['id']) || empty($theme['name']) || empty($theme['colors'])) die(json_encode(["error" => "Missing theme id, name, or colors."]));
            if (!preg_match('/^[a-z0-9_\-]+$/', $theme['id'])) die(json_encode(["error" => "Theme id can only contain lowercase letters, numbers, - and _."]));
            $data = read_themes_file($themes_file) ?: ['version' => 1, 'defaultTheme' => 'nocturne', 'themes' => []];
            $found = false;
            foreach ($data['themes'] as &$t) { if ($t['id'] === $theme['id']) { $t = $theme; $found = true; break; } }
            unset($t);
            if (!$found) $data['themes'][] = $theme;
            write_themes_file($themes_file, $data);
            require_once 'dash_audit.php'; log_activity(__DIR__ . '/data_dash', $_SESSION['dash_admin'], 'theme_saved', "Saved theme \"{$theme['name']}\".");
            echo json_encode(["status" => "success"]);
        }
        break;

    case 'delete_theme':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_master_admin();
            $id = trim($_POST['theme_id'] ?? '');
            $data = read_themes_file($themes_file);
            if (!$data) die(json_encode(["error" => "Theme registry not found."]));
            if (count($data['themes']) <= 1) die(json_encode(["error" => "At least one theme must remain."]));
            $data['themes'] = array_values(array_filter($data['themes'], function ($t) use ($id) { return $t['id'] !== $id; }));
            if ($data['defaultTheme'] === $id) $data['defaultTheme'] = $data['themes'][0]['id'];
            write_themes_file($themes_file, $data);
            require_once 'dash_audit.php'; log_activity(__DIR__ . '/data_dash', $_SESSION['dash_admin'], 'theme_deleted', "Deleted theme \"$id\".");
            echo json_encode(["status" => "success"]);
        }
        break;

    default: http_response_code(400); echo json_encode(["error" => "Unknown action."]); break;
}
?>
