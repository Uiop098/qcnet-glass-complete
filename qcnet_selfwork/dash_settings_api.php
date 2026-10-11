<?php
// dash_settings_api.php — backs dash_settings.html. Adapted from the Settings
// plan's endpoints to this project's flat-file storage. Admin-only.
//
// Scope note on "Roles and Permissions": the plan's RBAC design assumes a
// business can have multiple staff logins with different permission levels.
// This project currently has exactly two access tiers per tenant — one
// admin account and any number of client accounts — there's no existing
// concept of a third "staff member with partial admin rights" tier. Building
// real enforced permissions would mean introducing that tier from scratch
// (its own login, its own session type, and permission checks added to every
// endpoint), which is a bigger architectural decision than this file should
// make unilaterally. What's implemented here is a lightweight, honest
// version: named role labels an admin can define and assign to client
// accounts (stored separately from the free-text Tags field), useful for
// organizing contacts — not an enforced permission system. Flagged clearly
// in the UI too.
require_once 'dash_firewall.php';
require_once 'dash_crypto.php';
require_once 'dash_audit.php';
header('Content-Type: application/json');

if (!isset($_SESSION['dash_admin'])) die(json_encode(["error" => "Only administrators can access settings."]));

$username = $_SESSION['dash_admin'];
$tenant_id = $_SESSION['dash_tenant'] ?? 'main';
$safe_tenant = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
$dir = ($tenant_id === 'main') ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . $safe_tenant;
$logos_dir = __DIR__ . '/files_dash/' . ($tenant_id === 'main' ? 'main' : $safe_tenant);
if (!is_dir($logos_dir)) @mkdir($logos_dir, 0755, true);
$action = $_GET['action'] ?? '';

$biz_file = $dir . '/business_profile.json';
$notif_file = $dir . '/notification_prefs.json';
$roles_file = $dir . '/roles.json';

switch ($action) {

    case 'get_settings':
        $biz = get_secure_table($biz_file);
        $notif = get_secure_table($notif_file);
        if (empty($notif)) $notif = ['new_user' => true, 'new_payment' => true, 'security_alerts' => true];
        $plan = ['label' => 'Custom Admin Panel', 'purchased_at' => null, 'amount' => null];
        if ($tenant_id !== 'main') {
            $tenants = get_secure_table(__DIR__ . '/data_dash/tenants.json');
            $orders = get_secure_table(__DIR__ . '/data_dash/orders.json');
            foreach ($orders as $oid => $o) {
                if (($o['registered'] ?? false) && strtolower($o['email'] ?? '') === strtolower($tenants[$username]['email'] ?? '___')) {
                    $plan['purchased_at'] = $o['created_at'] ?? null; $plan['amount'] = $o['amount'] ?? null; break;
                }
            }
        }
        echo json_encode([
            "status" => "success",
            "business" => ["name" => $biz['name'] ?? '', "tagline" => $biz['tagline'] ?? '', "logo_url" => !empty($biz['logo_file']) ? ('dash_settings_api.php?action=get_logo&t=' . $safe_tenant) : null],
            "notifications" => $notif,
            "plan" => $plan,
            "roles" => get_secure_table($roles_file),
        ]);
        break;

    case 'save_business_profile':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $biz = get_secure_table($biz_file);
            $biz['name'] = trim($_POST['name'] ?? ''); $biz['tagline'] = trim($_POST['tagline'] ?? '');
            save_secure_table($biz_file, $biz);
            log_activity($dir, $username, 'business_profile_update', 'Updated business profile.');
            echo json_encode(["status" => "success"]);
        }
        break;

    case 'upload_logo':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (empty($_FILES['logo'])) die(json_encode(["error" => "No file received."]));
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) die(json_encode(["error" => "Logo must be 2MB or smaller."]));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['logo']['tmp_name']); finfo_close($finfo);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) die(json_encode(["error" => "Choose a JPG, PNG, or WebP image."]));
            // Random filename, not the uploaded name — avoids path tricks and
            // filename collisions between re-uploads.
            $stored_name = 'logo_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            $dest = $logos_dir . '/' . $stored_name;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) die(json_encode(["error" => "Upload failed."]));
            $biz = get_secure_table($biz_file);
            $old_file = $biz['logo_file'] ?? null;
            $biz['logo_file'] = $stored_name; $biz['logo_mime'] = $mime;
            save_secure_table($biz_file, $biz);
            if ($old_file && $old_file !== $stored_name) @unlink($logos_dir . '/' . $old_file);
            log_activity($dir, $username, 'logo_update', 'Updated business logo.');
            echo json_encode(["status" => "success", "logo_url" => 'dash_settings_api.php?action=get_logo&t=' . $safe_tenant]);
        }
        break;

    case 'get_logo':
        // Public-ish (no auth check) so the logo can be used as a plain <img
        // src=""> — logos aren't sensitive the way member PII is. Still only
        // ever serves the one file this tenant explicitly uploaded, from a
        // random server-generated filename, nothing path-traversable.
        $t = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['t'] ?? '');
        $tdir = ($t === 'main' || $t === '') ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . $t;
        $biz = get_secure_table($tdir . '/business_profile.json');
        $ldir = __DIR__ . '/files_dash/' . ($t === '' ? 'main' : $t);
        if (empty($biz['logo_file']) || !file_exists($ldir . '/' . $biz['logo_file'])) { http_response_code(404); exit; }
        header('Content-Type: ' . ($biz['logo_mime'] ?? 'image/png'));
        header('Cache-Control: public, max-age=3600');
        readfile($ldir . '/' . $biz['logo_file']); exit;

    case 'save_notifications':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $notif = [
                'new_user' => !empty($_POST['new_user']), 'new_payment' => !empty($_POST['new_payment']),
                'security_alerts' => !empty($_POST['security_alerts']),
            ];
            save_secure_table($notif_file, $notif);
            log_activity($dir, $username, 'notifications_update', 'Updated notification preferences.');
            echo json_encode(["status" => "success"]);
        }
        break;

    // ---- Role labels (see scope note at top of file) ----
    case 'save_role':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? ''); $color = trim($_POST['color'] ?? '#8b5cf6');
            if ($name === '') die(json_encode(["error" => "Role name is required."]));
            $roles = get_secure_table($roles_file);
            $role_id = $_POST['role_id'] ?? ('role_' . bin2hex(random_bytes(4)));
            $roles[$role_id] = ['name' => $name, 'color' => $color];
            save_secure_table($roles_file, $roles);
            log_activity($dir, $username, 'role_saved', "Saved role label \"$name\".");
            echo json_encode(["status" => "success", "role_id" => $role_id]);
        }
        break;

    case 'delete_role':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $role_id = $_POST['role_id'] ?? ''; $roles = get_secure_table($roles_file);
            if (isset($roles[$role_id])) { $name = $roles[$role_id]['name']; unset($roles[$role_id]); save_secure_table($roles_file, $roles); log_activity($dir, $username, 'role_deleted', "Deleted role label \"$name\"."); }
            echo json_encode(["status" => "success"]);
        }
        break;

    default: http_response_code(400); echo json_encode(["error" => "Unknown action."]); break;
}
?>
