<?php
// common.php — shared helpers: message storage, room config (PIN, host
// password, bans, mutes, presence, theme, title), and session/token
// management (including the super-user "master" session used to browse
// rooms before joining one).

$GLOBALS['dataDir'] = __DIR__ . '/data';
$GLOBALS['filesDir'] = __DIR__ . '/files';
$GLOBALS['maxMessages'] = 300;
$GLOBALS['messageExpirySeconds'] = 24 * 60 * 60; // 24 hours
$GLOBALS['sessionTtlSeconds'] = 6 * 60 * 60; // 6 hours, sliding
$GLOBALS['presenceWindowMs'] = 10000; // "online" = seen in last 10s
$GLOBALS['MASTER_ROOM'] = '__super_master__'; // sentinel room for super pre-join sessions
$GLOBALS['linkPreviewCacheSeconds'] = 6 * 60 * 60; // 6 hours
$GLOBALS['messageEditWindowSeconds'] = null; // null = editable any time (owner-only)

// SUPER_MASTER_SECRET — used ONLY to let a verified super-user session
// recover a room's encryption passphrase automatically (no manual key
// entry). CHANGE THIS to your own long random string before deploying.
// Anyone who has this string (i.e. server file access) can recover every
// room's encryption passphrase, same as any super user can — this is an
// intentional bypass, not a bug. If you don't want this bypass to exist
// at all, don't use the "auto-recover for super" feature (see README).
if (!defined('SUPER_MASTER_SECRET')) {
    define('SUPER_MASTER_SECRET', 'CHANGE-ME-a8f3k2m9x7q1w5e0r4t6y8u2i0o3p9-CHANGE-ME');
}

// ---------------------------------------------------------------------
// Firewall — application-level IP blocklist + rate limiting. Required
// here (rather than in each file individually) so every entry point
// that already does `require_once common.php` gets the blocklist check
// for free. See FIREWALL_HOW_IT_WORKS.txt for the full explanation.
// ---------------------------------------------------------------------
require_once __DIR__ . '/firewall.php';

if (is_ip_blocked(get_client_ip())) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'blocked', 'message' => 'Your connection has been blocked from this site.']);
    exit;
}

if (!is_dir($GLOBALS['dataDir'])) {
    mkdir($GLOBALS['dataDir'], 0755, true);
}
if (!is_dir($GLOBALS['filesDir'])) {
    mkdir($GLOBALS['filesDir'], 0755, true);
}

$dataHtaccess = $GLOBALS['dataDir'] . '/.htaccess';
if (!file_exists($dataHtaccess)) {
    @file_put_contents($dataHtaccess, "Require all denied\n");
}
// files/ used to be merely un-listable (Options -Indexes) — anyone who
// had a URL could still download directly, with no session check, no
// room-membership check, and no re-verification of what the file
// actually is. It's now fully closed off; every chat file must go
// through download.php's checks instead (see DOWNLOAD SYSTEM in
// README.txt). This rewrites the file on every request rather than
// only creating it once, so existing installs upgrading from an older
// version actually get the fix instead of keeping their old, looser
// .htaccess forever.
// v35 security fix: force ALL uploaded files to download instead of
// render inline, as defense-in-depth on top of the 'Require all denied'
// lockdown below (download.php is the only sanctioned way to fetch a
// file). Even if a dangerous type ever slipped through the extension
// checks, the browser would save it to disk instead of executing it.
$filesHtaccessExpected = "Require all denied\nHeader set Content-Disposition \"attachment\"\n";
$filesHtaccess = $GLOBALS['filesDir'] . '/.htaccess';
if (!file_exists($filesHtaccess) || file_get_contents($filesHtaccess) !== $filesHtaccessExpected) {
    @file_put_contents($filesHtaccess, $filesHtaccessExpected);
}

function now_ms() {
    return round(microtime(true) * 1000);
}

// ---------------------------------------------------------------------
// Basic helpers
// ---------------------------------------------------------------------

function safe_room_name($room) {
    $room = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$room);
    return substr($room, 0, 64) ?: 'default';
}

function room_file($room) {
    return $GLOBALS['dataDir'] . '/room_' . safe_room_name($room) . '.json';
}

function room_config_file($room) {
    return $GLOBALS['dataDir'] . '/config_' . safe_room_name($room) . '.json';
}

function sessions_file() {
    return $GLOBALS['dataDir'] . '/sessions.json';
}

function remember_tokens_file() {
    return $GLOBALS['dataDir'] . '/remember_tokens.json';
}

function room_files_dir($room) {
    $dir = $GLOBALS['filesDir'] . '/' . safe_room_name($room);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

// ---------------------------------------------------------------------
// File format verification — the extension a file is uploaded with is
// just a label the uploader typed; it proves nothing about what's
// actually inside. Per the OWASP File Upload Cheat Sheet, extension
// allowlisting (upload.php already does this) should be paired with a
// check of the file's actual content ("magic bytes" / signature),
// which is what this does. Used both at upload time (reject a
// mismatch outright) and by download.php (pick the Content-Type we
// send from OUR OWN map, never from anything the uploader or a stored
// value claims).
// ---------------------------------------------------------------------

// extension => list of real MIME types finfo may report for a
// genuine file of that kind. Several formats legitimately detect as
// more than one string across PHP/OS fileinfo-database versions
// (notably the OOXML Office formats, which are ZIP containers, and
// legacy OLE-based .doc/.xls/.ppt, which share one binary format) —
// listing the known variants avoids false rejections of real files
// while still catching a script/executable renamed to a safe-looking
// extension, which is the actual attack this defends against.
function ext_mime_candidates($ext) {
    static $map = null;
    if ($map === null) {
        $ole = ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-corrupt'];
        $ooxml = ['application/zip', 'application/x-zip', 'application/octet-stream'];
        $map = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp'],
            // svg is handled separately (text/XML signature, see verify_file_format)
            'pdf' => ['application/pdf'],
            'doc' => $ole, 'xls' => $ole, 'ppt' => $ole,
            'docx' => array_merge($ooxml, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
            'xlsx' => array_merge($ooxml, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
            'pptx' => array_merge($ooxml, ['application/vnd.openxmlformats-officedocument.presentationml.presentation']),
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv'],
            'rtf' => ['text/rtf', 'application/rtf', 'text/plain'],
            'mp3' => ['audio/mpeg'],
            'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
            'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
            'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
            'aac' => ['audio/aac', 'audio/x-aac', 'audio/mp4', 'audio/mpeg'],
            'mp4' => ['video/mp4', 'application/mp4'],
            'webm' => ['video/webm', 'audio/webm'],
            'mov' => ['video/quicktime'],
            'mkv' => ['video/x-matroska'],
            'avi' => ['video/x-msvideo', 'video/avi', 'video/msvideo'],
            'zip' => ['application/zip', 'application/x-zip'],
            'rar' => ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'],
            '7z' => ['application/x-7z-compressed'],
        ];
    }
    return isset($map[$ext]) ? $map[$ext] : null;
}

// The Content-Type download.php actually sends. Deliberately NOT the
// same lookup as ext_mime_candidates() above — that list is
// intentionally loose (several acceptable variants, for verification).
// This one is a single canonical value per extension, so what we tell
// the browser a file is never depends on anything the uploader or the
// stored data claims.
function safe_download_mime($ext) {
    static $canonical = null;
    if ($canonical === null) {
        $canonical = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
            'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'rtf' => 'application/rtf',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
        ];
        // Every "viewable as code/text" extension is always served as
        // text/plain — deliberately NOT its native type (text/html,
        // application/json, text/javascript, ...). That native type is
        // only ever used inside the app's own code viewer (which just
        // displays the fetched text, it never lets the browser render
        // or execute it); at the HTTP response level these must stay
        // inert no matter how the URL is opened.
        foreach (code_and_text_exts() as $textExt) {
            $canonical[$textExt] = 'text/plain';
        }
    }
    return isset($canonical[$ext]) ? $canonical[$ext] : 'application/octet-stream';
}

// Returns true if $path's actual content looks like a genuine file of
// type $ext. SVG is checked textually (it's XML, not a fixed binary
// signature, so finfo is unreliable for it) rather than via the MIME
// map above. Any extension with no entry in either path is treated as
// unverifiable and passed through (upload.php's extension allowlist
// already restricts what reaches here).
function verify_file_format($path, $ext) {
    $ext = strtolower($ext);
    if ($ext === 'svg') {
        $head = @file_get_contents($path, false, null, 0, 4096);
        if ($head === false) return false;
        return (stripos($head, '<svg') !== false) && (stripos($head, '<?php') === false);
    }
    if (in_array($ext, code_and_text_exts(), true)) {
        return looks_like_safe_text($path);
    }
    $candidates = ext_mime_candidates($ext);
    if ($candidates === null) return true; // not in our map — nothing to check against
    if (!function_exists('finfo_open')) return true; // fileinfo not installed — can't check, don't block uploads over it
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) return true;
    $detected = finfo_file($finfo, $path);
    finfo_close($finfo);
    return in_array($detected, $candidates, true);
}

// Extensions viewable in the in-chat code viewer (see index.html's
// "View" button on file cards) — plain text and common source-code
// formats. Deliberately excludes anything already on upload.php's
// $dangerousExtensions blocklist (.py, .sh, .pl, etc. stay blocked
// there; this list doesn't reopen that door) and is never used to
// decide a file's Content-Type at download time — code files are
// always served as text/plain regardless of which of these they are
// (see safe_download_mime()), so one can never be executed by opening
// it directly, only ever displayed as text inside the app's own
// viewer.
function code_and_text_exts() {
    return [
        'txt', 'csv', 'md', 'log', 'json', 'xml', 'yml', 'yaml',
        'js', 'ts', 'jsx', 'tsx', 'css', 'scss', 'html', 'htm',
        'java', 'c', 'cpp', 'cc', 'h', 'hpp', 'cs', 'go', 'rb', 'rs',
        'swift', 'kt', 'sql', 'vue',
    ];
}

// finfo's exact-string MIME matching (used above for fixed-signature
// binary formats) isn't a good fit for open-ended text — a genuine,
// harmless .txt or .js file can legitimately detect as all sorts of
// different strings (an empty file, a file that happens to look like
// shell/PHP source, one with a BOM, etc.), which made the old check
// reject real text files. What actually matters for text is different:
// is this plausibly text at all, and does it try to disguise a known
// binary format? That's what this checks instead: no embedded NUL
// bytes (real text essentially never has one) and no leading magic
// bytes matching a well-known binary signature.
function looks_like_safe_text($path) {
    $head = @file_get_contents($path, false, null, 0, 8192);
    if ($head === false) return false;
    if ($head === '') return true; // empty file — nothing dangerous in nothing
    if (strpos($head, "\0") !== false) return false;
    $binarySignatures = [
        "MZ",              // Windows EXE/DLL
        "\x7fELF",         // Linux/Unix ELF binary
        "%PDF",             // PDF
        "PK\x03\x04",       // ZIP-based (docx/xlsx/pptx/zip/jar...)
        "\x89PNG",          // PNG
        "GIF8",             // GIF
        "\xFF\xD8\xFF",     // JPEG
        "\x1f\x8b",         // gzip
        "Rar!",             // RAR
        "7z\xBC\xAF\x27\x1C", // 7z
    ];
    foreach ($binarySignatures as $sig) {
        if (strncmp($head, $sig, strlen($sig)) === 0) return false;
    }
    return true;
}

function room_wallpapers_dir($room) {
    $dir = room_files_dir($room) . '/wallpapers';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    // Room wallpapers are deliberately room-wide and applied straight
    // from a stored CSS background: url(...) value, not fetched by our
    // own JS — so unlike chat files (see download.php), there's no
    // practical way to route them through a per-session-token gate
    // without leaking one user's token into every other member's
    // network requests. They're re-opened here against the files/
    // lockdown above; format is still verified at upload time
    // (wallpaper_upload.php) and filenames are still random/unguessable.
    $wallpaperHtaccessExpected = "Require all granted\n";
    $wallpaperHtaccess = $dir . '/.htaccess';
    if (!file_exists($wallpaperHtaccess) || file_get_contents($wallpaperHtaccess) !== $wallpaperHtaccessExpected) {
        @file_put_contents($wallpaperHtaccess, $wallpaperHtaccessExpected);
    }
    return $dir;
}

function read_json_file($path) {
    if (!file_exists($path)) return null;
    $fp = fopen($path, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true);
}

function write_json_file($path, $data) {
    $fp = fopen($path, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// ---------------------------------------------------------------------
// Super user credentials — mutable, so a super can change their own
// password from the app instead of needing server file access. The
// static super_users.php is used ONLY to seed this store the first
// time it's read; after that, this JSON file is authoritative. Both
// store the password the same way (SHA-256 hash, never plaintext).
// ---------------------------------------------------------------------
function super_credentials_file() {
    return $GLOBALS['dataDir'] . '/super_credentials.json';
}

function read_super_credentials() {
    $data = read_json_file(super_credentials_file());
    if (!is_array($data)) {
        // First run: seed from the static file, carrying over its hashes
        // as-is (they're already SHA-256, same format this store uses).
        $seed = require __DIR__ . '/super_users.php';
        $data = [];
        foreach ($seed as $username => $entry) {
            $data[$username] = ['passwordHash' => $entry[0], 'displayName' => $entry[1]];
        }
        write_json_file(super_credentials_file(), $data);
    }
    // Every entry not explicitly tagged below is legacy super_users.php-style
    // (or manually added to super_credentials.json) and is treated as full
    // "master" tier — this only narrows tenant-sourced accounts, added below.
    foreach ($data as $u => $entry) { if (!isset($data[$u]['tier'])) $data[$u]['tier'] = 'master'; }

    // Business Dashboard bridge: dash_crypto.php provides
    // get_secure_table()/save_secure_table() for the AES-256-at-rest
    // encrypted master.json (the single default super admin) and
    // tenants.json (every paying tenant admin's account).
    require_once __DIR__ . '/dash_crypto.php';
    $master_file = __DIR__ . '/data_dash/master.json';
    if (file_exists($master_file)) {
        $masters = get_secure_table($master_file);
        if (is_array($masters)) { foreach ($masters as $u => $m) { $data[$u] = ['passwordHash' => $m['password_hash'], 'displayName' => 'Master Admin', 'tier' => 'master', 'username' => $u, 'twofaEnabled' => !empty($m['twofa_enabled']), 'twofaSecret' => $m['twofa_secret'] ?? null, 'twofaRecoveryCodes' => $m['twofa_recovery_codes'] ?? []]; } }
    }
    $tenants_file = __DIR__ . '/data_dash/tenants.json';
    if (file_exists($tenants_file)) {
        $tenants = get_secure_table($tenants_file);
        // Tenant admins (added via a Business Dashboard purchase) are bridged
        // into QuickChat's Super User login, but tagged 'tenant' rather than
        // 'master'. See super_join() in auth.php: only 'master'-tier accounts
        // (the single default super admin) get unrestricted access to every
        // room. A 'tenant' account still has to supply that specific room's
        // actual password/PIN to enter it — otherwise every paying tenant
        // admin would have silent host-level control over every OTHER
        // tenant's rooms too, which is a real cross-tenant privilege issue,
        // not just an inconvenience.
        if (is_array($tenants)) { foreach ($tenants as $u => $t) { if (($t['status'] ?? 'active') === 'deactivated') continue; $data[$u] = ['passwordHash' => $t['password_hash'], 'displayName' => $t['name'] ?? $u, 'tier' => 'tenant', 'username' => $u, 'twofaEnabled' => !empty($t['twofa_enabled']), 'twofaSecret' => $t['twofa_secret'] ?? null, 'twofaRecoveryCodes' => $t['twofa_recovery_codes'] ?? []]; } }
    }
    return $data;
}

function write_super_credentials($store) {
    return write_json_file(super_credentials_file(), $store);
}

// ---------------------------------------------------------------------
// Room config: PIN, host password, bans, mutes, presence, theme, title
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// Streams a file that has already been fully validated (existence,
// containment, format) by the caller. Supports HTTP Range requests
// (206 Partial Content) so <audio>/<video> can seek and larger
// downloads can resume — the same mechanism static web servers use
// for file serving, reimplemented here since download.php, not the
// web server, is now the thing deciding whether a request may see
// this file at all.
//
//   $mimeType      sent as-is; caller must derive it from
//                   safe_download_mime(), never from user input
//   $downloadName  filename offered in Content-Disposition (cosmetic
//                   only — has no bearing on which file is read)
//   $inline        true = "inline" (render in <img>/<audio>/<video>),
//                   false = "attachment" (browser download prompt)
function stream_file_with_range($path, $mimeType, $downloadName, $inline) {
    $size = filesize($path);
    if ($size === false) {
        http_response_code(404);
        exit;
    }

    $safeName = preg_replace('/[\r\n"]/', '_', $downloadName);
    $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"';

    header('Content-Type: ' . $mimeType);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disposition);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');

    $start = 0;
    $end = $size - 1;
    $status = 200;

    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $reqStart = $m[1] === '' ? null : (int)$m[1];
        $reqEnd = $m[2] === '' ? null : (int)$m[2];
        if ($reqStart === null && $reqEnd !== null) {
            // suffix range: last N bytes
            $start = max(0, $size - $reqEnd);
            $end = $size - 1;
        } else {
            $start = $reqStart ?? 0;
            $end = $reqEnd ?? ($size - 1);
        }
        $start = max(0, min($start, $size - 1));
        $end = max($start, min($end, $size - 1));
        $status = 206;
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    http_response_code($status);
    header('Content-Length: ' . ($end - $start + 1));

    $fp = fopen($path, 'rb');
    if (!$fp) {
        http_response_code(500);
        exit;
    }
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    $chunkSize = 8192;
    while ($remaining > 0 && !feof($fp)) {
        $read = min($chunkSize, $remaining);
        echo fread($fp, $read);
        $remaining -= $read;
        @ob_flush();
        @flush();
    }
    fclose($fp);
    exit;
}

function default_config() {
    return [
        'pinHash' => null,
        'hostPasswordHash' => null,
        'bannedUsers' => [],   // [{user: lowercase, ts: ms}]
        'bannedIps' => [],     // [{ip, ts}] — room-scoped, separate from the site-wide firewall block list
        'userIps' => [],       // usernameLower => last-seen IP, captured at join
        'mutedUsers' => [],    // usernameLower => muteUntilMs
        'presence' => [],      // usernameLower => {name, role, ts, typingUntil}
        'title' => '',
        'createdAt' => null,
        'lastActivity' => null,
        'roomTheme' => ['background' => '', 'glowColor' => '', 'uiSkin' => '', 'forcePalette' => '', 'forceMsgAnimation' => ''], // super-controlled, room-wide
        'pinnedMessageId' => null,
        'encEnabled' => false, // client-side end-to-end encryption toggle
        'encSalt' => null,     // random, non-secret — used to derive the key from the room's encryption passphrase (never stored here)
        'encSuperRecovery' => null, // passphrase, recoverable ONLY for verified super sessions — see SUPER_MASTER_SECRET below
        'requireFileApproval' => false, // host/super toggle — members' file uploads become pending requests instead of posting immediately
        'fileRequests' => [],   // pending member uploads awaiting host/super approval — see FILE APPROVAL REQUESTS in README
        'extraUploads' => [     // super-only toggle — see EXTENDED FILE UPLOADS in README
            'enabled' => false,
            'expiresAt' => 0,   // 0 = no auto-expiry (stays on until a super user turns it off)
            'allowAll' => false,// true = every extra_upload_catalog() extension is allowed
            'extensions' => [], // subset of extra_upload_catalog() allowed when allowAll is false
        ],

        // ---------------- v35 additions ----------------
        'bannedWords' => [],        // host-editable list of banned words/patterns (case-insensitive substrings)
        'slowModeSeconds' => 0,     // 0 = off; min seconds between a member's messages
        'lastMessageAtByUser' => [],// usernameLower => ms of their last accepted message (for slow mode + auto-kick spam check)
        'lastMessageTextByUser' => [], // usernameLower => [last few message texts] (repeat-spam auto-mute)
        'reports' => [],            // [{id, messageId, reportedUser, reporterUser, reason, ts, status}]
        'coHosts' => [],            // usernames (lowercase) granted host-level actions without the host password
        'coSupers' => [],           // usernames (lowercase) granted LIMITED theme access (preset skins/wallpapers only — no custom colors or uploads) without super credentials
        'hostNotifyEmail' => '',    // optional — email digest destination for reports while host is away
        'hostLastDigestAt' => 0,    // ms — throttles the email digest to at most one per cooldown window
        'scheduledMessages' => [],  // [{id, text, user, role, postAt}] — host-scheduled future posts
        'polls' => [],              // pollId => {id, question, options:[{id,text}], votes:{usernameLower:optionId}, createdBy, ts, closed}
        'autoCleanupDays' => 0,     // 0 = disabled; auto-delete this room after N days of no activity (checked from list_rooms())

        // ---------------- Premium / Super rooms ----------------
        'isPremium' => false,       // marks a room created via the Premium Room flow (auth.php action=create_premium_room)
        'premiumOwner' => null,     // username (lowercase) of the super user who created it — used to enforce the tenant "one room" limit
        'premiumTier' => null,      // 'tenant' or 'master' — which creation path made this room, controls which perks apply
    ];
}

// Curated list of "premium" visual/behavioral perks available on rooms
// created through the Premium Room flow. Tenant supers get the same perks
// as the default super admin — the difference between them is entirely
// about HOW MANY premium rooms they're allowed (1 vs unlimited), not which
// features are available once one exists. See auth.php action=create_premium_room.
function premium_room_perks() {
    return [
        'confetti'        => 'Confetti burst animation on new messages',
        'goldenGlow'       => 'Animated golden border glow (like the super-user glow, but room-wide for everyone)',
        'customEmojiBurst' => 'Bigger emoji reactions with a pop animation',
        'vipBadge'         => 'A "VIP Room" badge shown next to the room title',
        'rainbowNames'     => 'Usernames render in a subtle rainbow gradient',
        'extraStickers'    => 'Unlocks a bonus sticker pack beyond the default set',
    ];
}

// Curated list of extra, non-dangerous file extensions a super user can
// choose to unlock for hosts/members via the "Extended file uploads"
// toggle (see EXTENDED FILE UPLOADS in README). Deliberately excludes
// anything already in upload.php's $memberAllowedExtensions (no point
// listing what's already allowed) and anything in $dangerousExtensions
// (server-executable types stay blocked no matter what this is set to).
function extra_upload_catalog() {
    return [
        'psd', 'ai', 'eps', 'indd',
        'apk', 'ipa', 'dmg', 'iso', 'deb', 'rpm',
        'epub', 'mobi',
        'ttf', 'otf', 'woff', 'woff2',
        'torrent', 'sqlite', 'dwg', 'blend',
    ];
}

// Checks (and, on expiry, clears) a room's extraUploads toggle. Mutates
// $config when an auto-expiry has passed so callers should write_config()
// afterwards if they care about persisting the cleared state (upload.php
// and admin.php both do). Returns true only while the toggle is currently
// in effect.
function extra_uploads_is_active(&$config) {
    if (empty($config['extraUploads']['enabled'])) return false;
    $expiresAt = isset($config['extraUploads']['expiresAt']) ? $config['extraUploads']['expiresAt'] : 0;
    if ($expiresAt > 0 && $expiresAt <= now_ms()) {
        $config['extraUploads']['enabled'] = false;
        $config['extraUploads']['expiresAt'] = 0;
        return false;
    }
    return true;
}

// Whether $ext is currently allowed for a host/member under the
// extraUploads toggle. Does NOT check $dangerousExtensions itself —
// upload.php's existing blocklist check always runs first regardless of
// this toggle's state.
function extra_upload_extension_allowed(&$config, $ext) {
    if (!extra_uploads_is_active($config)) return false;
    if (!empty($config['extraUploads']['allowAll'])) return true;
    $extensions = isset($config['extraUploads']['extensions']) ? $config['extraUploads']['extensions'] : [];
    return in_array($ext, $extensions, true);
}

function read_config($room) {
    $data = read_json_file(room_config_file($room));
    if (!is_array($data)) return null;
    return array_merge(default_config(), $data);
}

function write_config($room, $config) {
    return write_json_file(room_config_file($room), $config);
}

// Encrypts a room's encryption passphrase so it can be recovered later
// ONLY by code that knows SUPER_MASTER_SECRET (i.e. this server, for a
// verified super session). Uses AES-256-CBC with a random IV per call.
function super_encrypt_passphrase($plaintext) {
    $key = hash('sha256', SUPER_MASTER_SECRET, true); // 32 raw bytes
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) return null;
    return base64_encode($iv . $ciphertext);
}

function super_decrypt_passphrase($blob) {
    if (!$blob) return null;
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 17) return null;
    $iv = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $key = hash('sha256', SUPER_MASTER_SECRET, true);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plaintext === false ? null : $plaintext;
}

// End-to-end encryption toggle. The server stores a random, non-secret
// salt plus (optionally) a copy of the passphrase that only a verified
// super session can ever recover — see super_encrypt_passphrase() above.
// Enabling (or rotating) always issues a fresh salt, since a new key means
// old ciphertext can no longer be derived-and-checked against it anyway.
function enable_encryption(&$config, $passphraseForSuperRecovery = null) {
    $config['encEnabled'] = true;
    $config['encSalt'] = base64_encode(random_bytes(16));
    $config['encSuperRecovery'] = $passphraseForSuperRecovery !== null
        ? super_encrypt_passphrase($passphraseForSuperRecovery)
        : null;
}

function disable_encryption(&$config) {
    $config['encEnabled'] = false;
    $config['encSalt'] = null;
    $config['encSuperRecovery'] = null;
}

function is_banned($config, $username) {
    $uname = strtolower(trim($username));
    foreach ($config['bannedUsers'] as $b) {
        if (isset($b['user']) && $b['user'] === $uname) return true;
    }
    return false;
}

function ban_user(&$config, $username) {
    $uname = strtolower(trim($username));
    if ($uname === '') return;
    foreach ($config['bannedUsers'] as $b) {
        if (isset($b['user']) && $b['user'] === $uname) return;
    }
    $config['bannedUsers'][] = ['user' => $uname, 'ts' => now_ms()];
}

function unban_user(&$config, $username) {
    $uname = strtolower(trim($username));
    $config['bannedUsers'] = array_values(array_filter($config['bannedUsers'], function ($b) use ($uname) {
        return !(isset($b['user']) && $b['user'] === $uname);
    }));
}

// ---------------------------------------------------------------------
// Room-scoped IP ban — a lighter-weight companion to username bans,
// separate from the site-wide firewall blocklist in firewall.php.
// Kicking a user auto-bans their last-known IP from THIS room only
// (get_client_ip() itself lives in firewall.php, required by common.php).
// ---------------------------------------------------------------------

function record_user_ip(&$config, $username, $ip) {
    $uname = strtolower(trim($username));
    if ($uname === '' || !$ip) return;
    $config['userIps'][$uname] = $ip;
}

function is_ip_banned($config, $ip) {
    if (!$ip) return false;
    foreach ($config['bannedIps'] as $b) {
        if (isset($b['ip']) && $b['ip'] === $ip) return true;
    }
    return false;
}

function ban_ip(&$config, $ip) {
    $ip = trim((string)$ip);
    if ($ip === '') return;
    foreach ($config['bannedIps'] as $b) {
        if (isset($b['ip']) && $b['ip'] === $ip) return;
    }
    $config['bannedIps'][] = ['ip' => $ip, 'ts' => now_ms()];
}

function unban_ip(&$config, $ip) {
    $ip = trim((string)$ip);
    $config['bannedIps'] = array_values(array_filter($config['bannedIps'], function ($b) use ($ip) {
        return !(isset($b['ip']) && $b['ip'] === $ip);
    }));
}

// ---------------------------------------------------------------------
// File approval requests — when a room has requireFileApproval on, a
// member's upload lands here instead of posting immediately; a host
// or super then approves (finalizes it as a real message) or denies
// (discards the file) it from the Manage room panel. See FILE
// APPROVAL REQUESTS in README.
// ---------------------------------------------------------------------

function add_file_request(&$config, $request) {
    $request['id'] = 'freq_' . now_ms() . '_' . substr(md5(uniqid('', true)), 0, 6);
    $request['ts'] = now_ms();
    $config['fileRequests'][] = $request;
    return $request;
}

function find_file_request($config, $id) {
    foreach ($config['fileRequests'] as $r) {
        if (isset($r['id']) && $r['id'] === $id) return $r;
    }
    return null;
}

function remove_file_request(&$config, $id) {
    $config['fileRequests'] = array_values(array_filter($config['fileRequests'], function ($r) use ($id) {
        return !(isset($r['id']) && $r['id'] === $id);
    }));
}

// Permanently deletes a room: its config, message history, uploaded
// files, any active sessions bound to it, and any remember-me tokens
// bound to it. Used by the super room browser's "Delete room" action.
// Irreversible — callers must confirm with the user first.
function delete_room($room) {
    @unlink(room_config_file($room));
    @unlink(room_file($room));

    $slug = safe_room_name($room);

    $dir = $GLOBALS['filesDir'] . '/' . $slug;
    if (is_dir($dir)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    $sessions = read_sessions();
    foreach ($sessions as $t => $s) {
        if (isset($s['room']) && $s['room'] === $slug) unset($sessions[$t]);
    }
    write_sessions($sessions);

    $tokens = read_remember_tokens();
    foreach ($tokens as $t => $tok) {
        if (isset($tok['room']) && $tok['room'] === $slug) unset($tokens[$t]);
    }
    write_remember_tokens($tokens);
}

// Returns remaining mute milliseconds (>0) if currently muted, else 0.
function mute_remaining_ms($config, $username) {
    $uname = strtolower(trim($username));
    if (!isset($config['mutedUsers'][$uname])) return 0;
    $remaining = $config['mutedUsers'][$uname] - now_ms();
    return $remaining > 0 ? $remaining : 0;
}

function mute_user(&$config, $username, $minutes) {
    $uname = strtolower(trim($username));
    if ($uname === '') return;
    if ($minutes <= 0) {
        unset($config['mutedUsers'][$uname]);
    } else {
        $config['mutedUsers'][$uname] = now_ms() + ($minutes * 60 * 1000);
    }
}

function touch_presence(&$config, $username, $role) {
    $existing = isset($config['presence'][strtolower($username)]) ? $config['presence'][strtolower($username)] : [];
    $config['presence'][strtolower($username)] = [
        'name' => $username,
        'role' => $role,
        'ts' => now_ms(),
        'typingUntil' => isset($existing['typingUntil']) ? $existing['typingUntil'] : 0,
        'ip' => function_exists('get_client_ip') ? get_client_ip() : ($existing['ip'] ?? null),
    ];
}

function set_typing(&$config, $username, $role) {
    touch_presence($config, $username, $role);
    $config['presence'][strtolower($username)]['typingUntil'] = now_ms() + 4000;
}

// Names currently typing, excluding $exceptUser, visible to everyone (not admin-only).
function typing_users($config, $exceptUser) {
    $now = now_ms();
    $exceptLower = strtolower($exceptUser);
    $out = [];
    foreach ($config['presence'] as $key => $p) {
        if ($key === $exceptLower) continue;
        if (!empty($p['typingUntil']) && $p['typingUntil'] > $now) {
            $out[] = $p['name'];
        }
    }
    return $out;
}

function active_presence($config) {
    $now = now_ms();
    $out = [];
    foreach ($config['presence'] as $p) {
        if (($now - $p['ts']) <= $GLOBALS['presenceWindowMs']) {
            $out[] = $p;
        }
    }
    return $out;
}

// List every room that has a config file, with summary info for the
// super-user room browser (no room-name typing needed).
function list_rooms() {
    $rooms = [];
    $pattern = $GLOBALS['dataDir'] . '/config_*.json';
    foreach (glob($pattern) as $path) {
        $base = basename($path, '.json');
        $slug = substr($base, strlen('config_'));
        if ($slug === '') continue;
        $config = read_config($slug);
        if (!$config) continue;

        // v35: auto-cleanup — deletes the room outright if its own
        // opt-in autoCleanupDays threshold has passed, skipping it from
        // this listing entirely.
        if (room_auto_cleanup_if_due($slug, $config)) continue;

        $msgs = read_json_file(room_file($slug));
        $msgCount = is_array($msgs) ? count($msgs) : 0;

        $rooms[] = [
            'room' => $slug,
            'title' => $config['title'] !== '' ? $config['title'] : $slug,
            'createdAt' => $config['createdAt'],
            'lastActivity' => $config['lastActivity'],
            'hostSet' => !empty($config['hostPasswordHash']),
            'pinSet' => !empty($config['pinHash']),
            'encEnabled' => !empty($config['encEnabled']),
            'activeUsers' => active_presence($config),
            'messageCount' => $msgCount,
            'bannedUserCount' => count($config['bannedUsers']),
            'bannedIpCount' => count($config['bannedIps']),
            // v35: pending-delete state, so the room browser can show a
            // "deleting in Xs — Undo" banner instead of the room just
            // vanishing with no recourse.
            'pendingDeleteAt' => $config['pendingDeleteAt'] ?? null,
        ];
    }
    usort($rooms, function ($a, $b) {
        $at = $a['lastActivity'] ?? $a['createdAt'] ?? 0;
        $bt = $b['lastActivity'] ?? $b['createdAt'] ?? 0;
        return $bt <=> $at;
    });
    return $rooms;
}

// ---------------------------------------------------------------------
// Sessions / tokens
// ---------------------------------------------------------------------

function read_sessions() {
    $data = read_json_file(sessions_file());
    return is_array($data) ? $data : [];
}

function write_sessions($sessions) {
    write_json_file(sessions_file(), $sessions);
}

function create_session($room, $user, $role, $extra = []) {
    $sessions = read_sessions();
    $now = time();
    foreach ($sessions as $t => $s) {
        if (!isset($s['expires']) || $s['expires'] < $now) unset($sessions[$t]);
    }
    $token = bin2hex(random_bytes(16));
    $roomSlug = ($room === $GLOBALS['MASTER_ROOM']) ? $GLOBALS['MASTER_ROOM'] : safe_room_name($room);
    $sessions[$token] = array_merge([
        'room' => $roomSlug,
        'user' => $user,
        'role' => $role,
        'expires' => $now + $GLOBALS['sessionTtlSeconds'],
    ], $extra);
    write_sessions($sessions);
    return $token;
}

function get_session($token) {
    if (!$token) return null;
    $sessions = read_sessions();
    if (!isset($sessions[$token])) return null;
    $s = $sessions[$token];
    if (!isset($s['expires']) || $s['expires'] < time()) {
        unset($sessions[$token]);
        write_sessions($sessions);
        return null;
    }
    // Guest sessions created via a "scan to join" QR code (see
    // qr_join.php) carry a hard, non-sliding cutoff tied to when that
    // QR code itself expired — unlike the 'expires' field just above,
    // this one is NEVER pushed forward by continued activity. Once it
    // passes, the session simply stops validating on the very next
    // request, which api.php's normal invalid_session handling already
    // turns into an automatic sign-out on the client — the same
    // experience as being kicked, with no extra plumbing needed.
    if (isset($s['qrJoinExpires']) && $s['qrJoinExpires'] < time()) {
        unset($sessions[$token]);
        write_sessions($sessions);
        return null;
    }
    $sessions[$token]['expires'] = time() + $GLOBALS['sessionTtlSeconds'];
    write_sessions($sessions);
    return $sessions[$token];
}

function remove_sessions_for_user($room, $username) {
    $sessions = read_sessions();
    $room = safe_room_name($room);
    $uname = strtolower(trim($username));
    $changed = false;
    foreach ($sessions as $t => $s) {
        if ($s['room'] === $room && strtolower($s['user']) === $uname) {
            unset($sessions[$t]);
            $changed = true;
        }
    }
    if ($changed) write_sessions($sessions);
}

function remove_host_sessions($room) {
    $sessions = read_sessions();
    $room = safe_room_name($room);
    $changed = false;
    foreach ($sessions as $t => $s) {
        if ($s['room'] === $room && $s['role'] === 'host') {
            unset($sessions[$t]);
            $changed = true;
        }
    }
    if ($changed) write_sessions($sessions);
}

// ---------------------------------------------------------------------
// Remember-me tokens (long-lived auto-login, separate from short-lived
// session tokens above). Uses a selector/validator split so the raw
// token is never stored — only a hash of the validator half — same
// principle as the session tokens but with a much longer TTL and its
// own file, so clearing sessions.json (e.g. on server restart) doesn't
// silently log everyone's "remember me" out too, and vice versa.
// ---------------------------------------------------------------------

$GLOBALS['rememberTtlSeconds'] = 30 * 24 * 60 * 60; // 30 days

function read_remember_tokens() {
    $data = read_json_file(remember_tokens_file());
    return is_array($data) ? $data : [];
}

function write_remember_tokens($tokens) {
    write_json_file(remember_tokens_file(), $tokens);
}

// Creates a remember-me token for a given room/user/role. Returns the
// string to hand to the client (selector:validator) — this exact
// string is never stored server-side, only its hash.
function create_remember_token($room, $user, $role) {
    $tokens = read_remember_tokens();
    $now = time();
    foreach ($tokens as $sel => $t) {
        if (!isset($t['expires']) || $t['expires'] < $now) unset($tokens[$sel]);
    }

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(33));
    $roomSlug = ($room === $GLOBALS['MASTER_ROOM']) ? $GLOBALS['MASTER_ROOM'] : safe_room_name($room);

    $tokens[$selector] = [
        'hash' => hash('sha256', $validator),
        'room' => $roomSlug,
        'user' => $user,
        'role' => $role,
        'expires' => $now + $GLOBALS['rememberTtlSeconds'],
    ];
    write_remember_tokens($tokens);
    return $selector . ':' . $validator;
}

// Validates a "selector:validator" string. On success, rotates the
// token (issues a new selector/validator pair and invalidates the old
// one) so a stolen-but-unused cookie/localStorage value only works
// once before rotating out from under an attacker, and returns
// ['room' => ..., 'user' => ..., 'role' => ..., 'newToken' => 'selector:validator'].
// Returns null on any failure (bad format, unknown selector, expired,
// or validator mismatch).
function consume_remember_token($raw) {
    if (!is_string($raw) || strpos($raw, ':') === false) return null;
    [$selector, $validator] = explode(':', $raw, 2);

    $tokens = read_remember_tokens();
    if (!isset($tokens[$selector])) return null;
    $t = $tokens[$selector];

    if (!isset($t['expires']) || $t['expires'] < time()) {
        unset($tokens[$selector]);
        write_remember_tokens($tokens);
        return null;
    }

    // Constant-time comparison — avoids leaking timing info about the hash.
    if (!hash_equals($t['hash'], hash('sha256', $validator))) {
        return null;
    }

    unset($tokens[$selector]);
    $newRaw = create_remember_token($t['room'], $t['user'], $t['role']); // re-reads/writes the file
    return [
        'room' => $t['room'],
        'user' => $t['user'],
        'role' => $t['role'],
        'newToken' => $newRaw,
    ];
}

// Revokes a single remember-me token (used on explicit logout).
function revoke_remember_token($raw) {
    if (!is_string($raw) || strpos($raw, ':') === false) return;
    [$selector] = explode(':', $raw, 2);
    $tokens = read_remember_tokens();
    if (isset($tokens[$selector])) {
        unset($tokens[$selector]);
        write_remember_tokens($tokens);
    }
}

// Mirrors remove_sessions_for_user() — call this alongside it whenever
// a user is kicked/banned, so a stale remember-me token can't silently
// let them back in.
function remove_remember_tokens_for_user($room, $username) {
    $tokens = read_remember_tokens();
    $room = safe_room_name($room);
    $uname = strtolower(trim($username));
    $changed = false;
    foreach ($tokens as $sel => $t) {
        if ($t['room'] === $room && strtolower($t['user']) === $uname) {
            unset($tokens[$sel]);
            $changed = true;
        }
    }
    if ($changed) write_remember_tokens($tokens);
}

// Mirrors remove_host_sessions() — call this alongside it whenever a
// room's host password is reset, so old "remember me as Host" tokens
// die with the old password instead of granting host access forever.
function remove_host_remember_tokens($room) {
    $tokens = read_remember_tokens();
    $room = safe_room_name($room);
    $changed = false;
    foreach ($tokens as $sel => $t) {
        if ($t['room'] === $room && $t['role'] === 'host') {
            unset($tokens[$sel]);
            $changed = true;
        }
    }
    if ($changed) write_remember_tokens($tokens);
}

// ---------------------------------------------------------------------
// QR "scan to send a file" tokens — a short-lived (60s), single-use
// bearer token that lets a phone camera scan of a QR code open
// qr_upload.php and push exactly one file into the chat, without that
// device ever logging in / joining the room. The token is the ONLY
// thing that page trusts: no cookie, no session, nothing else. See
// qr_send.php (issues the token) and qr_upload.php (redeems it).
// ---------------------------------------------------------------------

$GLOBALS['qrTokenTtlSeconds'] = 100; // hard expiry for a file-transfer QR code, per spec

function qr_tokens_file() {
    return $GLOBALS['dataDir'] . '/qr_tokens.json';
}

function read_qr_tokens() {
    $data = read_json_file(qr_tokens_file());
    return is_array($data) ? $data : [];
}

function write_qr_tokens($tokens) {
    write_json_file(qr_tokens_file(), $tokens);
}

// Drops anything expired or already used — called on every read/write
// so this file never grows unbounded (tokens live at most 60s anyway).
function qr_tokens_gc($tokens) {
    $now = time();
    foreach ($tokens as $t => $rec) {
        if (!isset($rec['expires']) || $rec['expires'] < $now || !empty($rec['used'])) {
            unset($tokens[$t]);
        }
    }
    return $tokens;
}

// Issues a new QR upload token for $room, attributed to the chat user
// who tapped the QR button ($user/$role) — that's who the eventual
// file message will be posted as, since the phone that scans it never
// authenticates on its own. $replyToId optionally threads the upload.
function create_qr_upload_token($room, $user, $role, $replyToId = 0) {
    $tokens = qr_tokens_gc(read_qr_tokens());
    $token = bin2hex(random_bytes(32)); // 256 bits — unguessable within the 60s window
    $now = time();
    $tokens[$token] = [
        'room' => safe_room_name($room),
        'user' => $user,
        'role' => $role,
        'replyTo' => $replyToId > 0 ? $replyToId : 0,
        'createdAt' => $now,
        'expires' => $now + $GLOBALS['qrTokenTtlSeconds'],
        'used' => false,
    ];
    write_qr_tokens($tokens);
    return ['token' => $token, 'expiresAt' => $tokens[$token]['expires']];
}

// Read-only lookup — does NOT consume the token. Used to decide whether
// qr_upload.php may render the upload form at all (GET) vs. bounce the
// visitor straight to login.html. Returns null if missing/expired/used.
function peek_qr_upload_token($token) {
    if (!is_string($token) || $token === '') return null;
    $tokens = read_qr_tokens();
    if (!isset($tokens[$token])) return null;
    $rec = $tokens[$token];
    if (!isset($rec['expires']) || $rec['expires'] < time() || !empty($rec['used'])) return null;
    return $rec;
}

// Atomically redeems the token for its one allowed upload: returns the
// token record on success, or null if it was missing/expired/already
// used. Marks it used immediately so a second concurrent POST (double
// tap, retry, replay of the request) can never ride the same token in.
// Call this right before actually saving the file — NOT on page GET —
// so someone can look at the page without burning their only upload.
function consume_qr_upload_token($token) {
    if (!is_string($token) || $token === '') return null;
    $tokens = read_qr_tokens();
    if (!isset($tokens[$token])) return null;
    $rec = $tokens[$token];
    if (!isset($rec['expires']) || $rec['expires'] < time() || !empty($rec['used'])) {
        return null;
    }
    $tokens[$token]['used'] = true;
    write_qr_tokens($tokens);
    return $rec;
}

// Safe subset for QR uploads specifically — images and everyday
// documents only (per spec), deliberately narrower than the full
// member upload whitelist in upload.php since this is an unauthenticated
// hand-off page reachable by anyone who can see the QR code for the
// short window it's valid.
function qr_allowed_extensions() {
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf',
    ];
}

// ---------------------------------------------------------------------
// QR "scan to join" tokens — a longer-lived (30 min), MULTI-use code a
// host/co-host/super can generate and post at the top of the room so
// people can join without the room PIN, just a display name. Unlike
// the file-upload token above, this one isn't consumed by use — anyone
// who scans it within the 30-minute window gets in. What IS enforced
// is a hard cutoff: every session created from a given code carries
// that same code's expiry as a non-sliding 'qrJoinExpires' (set on
// create_session, checked in get_session), so the whole batch of
// guests who joined via that code are signed out together the moment
// it lapses — they don't get to keep extending their stay by staying
// active, the way a normal session would.
$GLOBALS['joinTokenTtlSeconds'] = 30 * 60;

function join_tokens_file() {
    return $GLOBALS['dataDir'] . '/join_tokens.json';
}

function read_join_tokens() {
    $data = read_json_file(join_tokens_file());
    return is_array($data) ? $data : [];
}

function write_join_tokens($tokens) {
    write_json_file(join_tokens_file(), $tokens);
}

function join_tokens_gc($tokens) {
    $now = time();
    foreach ($tokens as $t => $rec) {
        if (!isset($rec['expires']) || $rec['expires'] < $now) unset($tokens[$t]);
    }
    return $tokens;
}

function create_join_token($room, $issuerUser) {
    $tokens = join_tokens_gc(read_join_tokens());
    $token = bin2hex(random_bytes(32));
    $now = time();
    $tokens[$token] = [
        'room' => safe_room_name($room),
        'issuedBy' => $issuerUser,
        'createdAt' => $now,
        'expires' => $now + $GLOBALS['joinTokenTtlSeconds'],
    ];
    write_join_tokens($tokens);
    return ['token' => $token, 'expiresAt' => $tokens[$token]['expires']];
}

// Read-only, non-consuming lookup — any number of people can join
// through the same code while it's valid. Returns null once expired.
function peek_join_token($token) {
    if (!is_string($token) || $token === '') return null;
    $tokens = read_join_tokens();
    if (!isset($tokens[$token])) return null;
    $rec = $tokens[$token];
    if (!isset($rec['expires']) || $rec['expires'] < time()) return null;
    return $rec;
}

// ---------------------------------------------------------------------
// Messages (with 24h auto-expiry)
// ---------------------------------------------------------------------

function purge_expired($room, $messages) {
    $expiryMs = $GLOBALS['messageExpirySeconds'] * 1000;
    $nowMs = now_ms();
    $changed = false;
    $kept = [];

    foreach ($messages as $m) {
        $age = $nowMs - (isset($m['ts']) ? $m['ts'] : 0);
        if ($age > $expiryMs) {
            $changed = true;
            if (isset($m['type']) && $m['type'] === 'file' && !empty($m['fileUrl'])) {
                $path = __DIR__ . '/' . ltrim($m['fileUrl'], '/');
                if (is_file($path)) @unlink($path);
            }
        } else {
            $kept[] = $m;
        }
    }

    if ($changed) {
        write_messages($room, $kept);
    }

    return $kept;
}

function read_messages($room) {
    $data = read_json_file(room_file($room));
    $messages = is_array($data) ? $data : [];
    return purge_expired($room, $messages);
}

function write_messages($room, $messages) {
    if (count($messages) > $GLOBALS['maxMessages']) {
        $messages = array_slice($messages, -1 * $GLOBALS['maxMessages']);
    }
    return write_json_file(room_file($room), $messages);
}

function find_message($room, $id) {
    $messages = read_messages($room);
    foreach ($messages as $m) {
        if (isset($m['id']) && $m['id'] == $id) return $m;
    }
    return null;
}

// Builds a short quoted snippet of a message for use as a reply-to preview.
function build_reply_snippet($msg) {
    if (!$msg) return null;
    $text = isset($msg['text']) ? $msg['text'] : '';
    if ($text === '' && isset($msg['type']) && $msg['type'] === 'file') {
        $text = '📎 ' . (isset($msg['fileName']) ? $msg['fileName'] : 'file');
    }
    // Encrypted envelopes must stay intact — truncating a base64 ciphertext
    // blob makes it undecryptable client-side. Plain text still gets trimmed.
    if (strpos($text, 'encv1:') !== 0) {
        $text = mb_substr(str_replace("\n", ' ', $text), 0, 120);
    }
    return [
        'id' => $msg['id'],
        'user' => $msg['user'],
        'text' => $text,
    ];
}

// Toggles $username's reaction with $emoji on a message. A user can only
// have one active reaction per message — picking a new emoji replaces
// their old one on that message.
function toggle_reaction($room, $messageId, $username, $emoji) {
    $messages = read_messages($room);
    $found = false;
    $userLower = strtolower($username);
    foreach ($messages as &$m) {
        if (!isset($m['id']) || $m['id'] != $messageId) continue;
        $found = true;
        if (!isset($m['reactions']) || !is_array($m['reactions'])) $m['reactions'] = [];

        $hadThisEmoji = isset($m['reactions'][$emoji]) && in_array($username, $m['reactions'][$emoji], true);

        // Remove this user from every emoji bucket first.
        foreach ($m['reactions'] as $e => $users) {
            $m['reactions'][$e] = array_values(array_filter($users, function ($u) use ($username) {
                return strtolower($u) !== $userLower;
            }));
            if (empty($m['reactions'][$e])) unset($m['reactions'][$e]);
        }

        // Re-add only if they weren't already reacting with this same emoji (i.e. toggle off).
        if (!$hadThisEmoji) {
            if (!isset($m['reactions'][$emoji])) $m['reactions'][$emoji] = [];
            $m['reactions'][$emoji][] = $username;
        }
        break;
    }
    unset($m);
    if ($found) write_messages($room, $messages);
    return $found ? find_message($room, $messageId) : null;
}

function next_message_id($messages) {
    $nextId = 1;
    foreach ($messages as $m) {
        if (isset($m['id']) && $m['id'] >= $nextId) {
            $nextId = $m['id'] + 1;
        }
    }
    return $nextId;
}

function append_message($room, $msg) {
    $messages = read_messages($room);
    $msg['id'] = next_message_id($messages);
    $msg['ts'] = now_ms();
    $messages[] = $msg;
    write_messages($room, $messages);

    // bump last-activity on the room config too
    $config = read_config($room);
    if ($config) {
        $config['lastActivity'] = $msg['ts'];
        write_config($room, $config);
    }

    return $msg;
}

// ---------------------------------------------------------------------
// Message editing
// ---------------------------------------------------------------------

// Edits a message's text in place, but only if $username is the original
// sender (case-insensitive) — host/super do NOT get a bypass here, this
// is purely "edit your own message" like Discord/Slack. Returns the
// updated message on success, or null if not found / not the owner.
function edit_message($room, $id, $username, $newText) {
    $messages = read_messages($room);
    $updated = null;
    $tooLate = false;
    foreach ($messages as &$m) {
        if (!isset($m['id']) || $m['id'] != $id) continue;
        if (!isset($m['user']) || strtolower($m['user']) !== strtolower($username)) {
            return null; // not the owner — stop, don't leak which id existed
        }
        if (isset($m['type']) && $m['type'] !== 'text') {
            return null; // only plain text messages are editable, not files
        }
        $ageMs = now_ms() - (isset($m['ts']) ? $m['ts'] : 0);
        if ($ageMs > 30000) {
            $tooLate = true;
            break;
        }
        $m['text'] = $newText;
        $m['edited'] = true;
        $m['editedAt'] = now_ms();
        $updated = $m;
        break;
    }
    unset($m);
    if ($updated) write_messages($room, $messages);
    if ($tooLate) return 'too_late';
    return $updated;
}

// ---------------------------------------------------------------------
// Read receipts — piggybacks on the existing presence heartbeat, just
// stores the highest message id each person has seen.
// ---------------------------------------------------------------------

function mark_read(&$config, $username, $role, $messageId) {
    touch_presence($config, $username, $role); // keeps ts/typingUntil semantics intact
    $key = strtolower($username);
    $current = isset($config['presence'][$key]['lastReadId']) ? $config['presence'][$key]['lastReadId'] : 0;
    if ($messageId > $current) {
        $config['presence'][$key]['lastReadId'] = $messageId;
    }
}

// Read receipts visible to everyone (not just admins) — every active
// user's name + their highest read message id, excluding $exceptUser.
function read_receipts($config, $exceptUser) {
    $now = now_ms();
    $exceptLower = strtolower($exceptUser);
    $out = [];
    foreach ($config['presence'] as $key => $p) {
        if ($key === $exceptLower) continue;
        if (($now - $p['ts']) > $GLOBALS['presenceWindowMs']) continue; // only "online" people
        $out[] = ['user' => $p['name'], 'lastReadId' => isset($p['lastReadId']) ? $p['lastReadId'] : 0];
    }
    return $out;
}

// ---------------------------------------------------------------------
// Link previews — server-side fetch (browsers can't read cross-origin
// pages directly) with SSRF protections: only http/https, only ports
// 80/443, and every resolved IP for the host must be a public address
// (no loopback/private/link-local/reserved ranges). Results are cached
// to disk for a while so repeated views of the same link are cheap and
// so we don't hammer other people's servers.
// ---------------------------------------------------------------------

function link_preview_cache_file($url) {
    return $GLOBALS['dataDir'] . '/linkpreview_' . hash('sha256', $url) . '.json';
}

function is_public_ip($ip) {
    // Rejects loopback, private, link-local, reserved, and multicast
    // ranges for both IPv4 and IPv6.
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function url_is_safe_to_fetch($url) {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return false;
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    if (!in_array((int)$port, [80, 443], true)) return false;

    $host = $parts['host'];
    // If the host is already a literal IP, check it directly; otherwise
    // resolve it and check every A/AAAA record (DNS can return multiple,
    // and an attacker-controlled domain could point at an internal IP).
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return is_public_ip($host);
    }
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (!$records) return false;
    foreach ($records as $r) {
        $ip = $r['ip'] ?? ($r['ipv6'] ?? null);
        if ($ip && !is_public_ip($ip)) return false;
    }
    return true;
}

// Fetches a URL and extracts a small preview (title, description, image,
// site name). Returns null on any failure. Caches successes (and
// failures, briefly) to disk.
function fetch_link_preview($url) {
    $cacheFile = link_preview_cache_file($url);
    $cached = read_json_file($cacheFile);
    if (is_array($cached) && isset($cached['fetchedAt']) && (now_ms() - $cached['fetchedAt']) < ($GLOBALS['linkPreviewCacheSeconds'] * 1000)) {
        return $cached['preview']; // may itself be null (cached "failed to preview")
    }

    $preview = null;
    if (url_is_safe_to_fetch($url)) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'follow_location' => 0, // no redirects — a redirect could point at an internal address
                'header' => "User-Agent: QuickChatLinkPreview/1.0\r\n",
            ],
            'https' => [
                'timeout' => 4,
                'follow_location' => 0,
            ],
        ]);
        $html = @file_get_contents($url, false, $ctx, 0, 300000); // cap read size
        if ($html !== false && $html !== '') {
            $preview = parse_preview_html($html, $url);
        }
    }

    write_json_file($cacheFile, ['fetchedAt' => now_ms(), 'preview' => $preview]);
    return $preview;
}

function parse_preview_html($html, $url) {
    $title = null; $description = null; $image = null; $siteName = null;

    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
    }
    $meta = function ($property) use ($html) {
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/is', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES));
        }
        return null;
    };
    $ogTitle = $meta('og:title');
    if ($ogTitle) $title = $ogTitle;
    $description = $meta('og:description') ?: $meta('description');
    $image = $meta('og:image');
    $siteName = $meta('og:site_name') ?: parse_url($url, PHP_URL_HOST);

    if ($title) $title = mb_substr($title, 0, 200);
    if ($description) $description = mb_substr($description, 0, 300);

    if (!$title && !$description && !$image) return null;

    // Resolve a relative og:image against the page URL, if needed.
    if ($image && !preg_match('#^https?://#i', $image)) {
        $base = parse_url($url);
        $image = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . '/' . ltrim($image, '/');
    }
    // Only allow the preview image to be embedded if it's itself a safe,
    // public http(s) URL — same SSRF reasoning as the page fetch above.
    if ($image && !url_is_safe_to_fetch($image)) $image = null;

    return [
        'title' => $title,
        'description' => $description,
        'image' => $image,
        'siteName' => $siteName,
        'url' => $url,
    ];
}

// Finds the first http(s) URL in a plain-text message, if any.
function first_url_in_text($text) {
    if (!is_string($text)) return null;
    if (preg_match('#https?://[^\s<>"]+#i', $text, $m)) {
        return rtrim($m[0], '.,)]!?');
    }
    return null;
}

// =======================================================================
// v35 ADDITIONS — see QuickChat_Improvement_Plan.txt PART 2 / PART 3
// =======================================================================

// ---------------------------------------------------------------------
// Word / link filter (PART 2 - Safety/Moderation)
// ---------------------------------------------------------------------

function find_banned_word($config, $text) {
    if (empty($config['bannedWords']) || !is_string($text) || $text === '') return null;
    $lower = mb_strtolower($text);
    foreach ($config['bannedWords'] as $word) {
        $w = mb_strtolower(trim((string)$word));
        if ($w === '') continue;
        if (mb_strpos($lower, $w) !== false) return $word;
    }
    return null;
}

function add_banned_word(&$config, $word) {
    $word = trim((string)$word);
    if ($word === '' || mb_strlen($word) > 100) return false;
    $lower = mb_strtolower($word);
    foreach ($config['bannedWords'] as $w) {
        if (mb_strtolower($w) === $lower) return false;
    }
    if (count($config['bannedWords']) >= 200) return false;
    $config['bannedWords'][] = $word;
    return true;
}

function remove_banned_word(&$config, $word) {
    $lower = mb_strtolower(trim((string)$word));
    $config['bannedWords'] = array_values(array_filter($config['bannedWords'], function ($w) use ($lower) {
        return mb_strtolower($w) !== $lower;
    }));
}

// ---------------------------------------------------------------------
// Slow mode (PART 2 / PART 3)
// ---------------------------------------------------------------------

function slow_mode_remaining_seconds($config, $username) {
    $seconds = isset($config['slowModeSeconds']) ? (int)$config['slowModeSeconds'] : 0;
    if ($seconds <= 0) return 0;
    $uname = strtolower(trim($username));
    $last = isset($config['lastMessageAtByUser'][$uname]) ? $config['lastMessageAtByUser'][$uname] : 0;
    if ($last <= 0) return 0;
    $elapsedMs = now_ms() - $last;
    $remainingMs = ($seconds * 1000) - $elapsedMs;
    return $remainingMs > 0 ? (int)ceil($remainingMs / 1000) : 0;
}

function record_message_for_spam_tracking(&$config, $username, $text) {
    $uname = strtolower(trim($username));
    if ($uname === '') return false;
    $config['lastMessageAtByUser'][$uname] = now_ms();

    if (!isset($config['lastMessageTextByUser'][$uname]) || !is_array($config['lastMessageTextByUser'][$uname])) {
        $config['lastMessageTextByUser'][$uname] = [];
    }
    $history = $config['lastMessageTextByUser'][$uname];
    $isRepeat = count($history) >= 2 && $history[count($history) - 1] === $text && $history[count($history) - 2] === $text;

    $history[] = $text;
    if (count($history) > 3) $history = array_slice($history, -3);
    $config['lastMessageTextByUser'][$uname] = $history;

    return $isRepeat && $text !== '';
}

// ---------------------------------------------------------------------
// Report message (PART 2)
// ---------------------------------------------------------------------

function add_report(&$config, $messageId, $reportedUser, $reporterUser, $reason) {
    $report = [
        'id' => 'rpt_' . now_ms() . '_' . substr(md5(uniqid('', true)), 0, 6),
        'messageId' => $messageId,
        'reportedUser' => $reportedUser,
        'reporterUser' => $reporterUser,
        'reason' => mb_substr(trim((string)$reason), 0, 300),
        'ts' => now_ms(),
        'status' => 'open',
    ];
    $config['reports'][] = $report;
    return $report;
}

function resolve_report(&$config, $reportId) {
    foreach ($config['reports'] as &$r) {
        if (isset($r['id']) && $r['id'] === $reportId) {
            $r['status'] = 'resolved';
            return true;
        }
    }
    unset($r);
    return false;
}

function open_report_count($config) {
    $n = 0;
    foreach ($config['reports'] as $r) {
        if (($r['status'] ?? 'open') === 'open') $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------
// Co-hosts (PART 2 - Room management)
// ---------------------------------------------------------------------

function is_co_host($config, $username) {
    $uname = strtolower(trim($username));
    return in_array($uname, $config['coHosts'], true);
}

function is_effective_host($config, $session) {
    if (in_array($session['role'], ['host', 'super'], true)) return true;
    return $session['role'] === 'member' && is_co_host($config, $session['user']);
}

function add_co_host(&$config, $username) {
    $uname = strtolower(trim($username));
    if ($uname === '' || in_array($uname, $config['coHosts'], true)) return;
    $config['coHosts'][] = $uname;
}

function remove_co_host(&$config, $username) {
    $uname = strtolower(trim($username));
    $config['coHosts'] = array_values(array_filter($config['coHosts'], function ($u) use ($uname) {
        return $u !== $uname;
    }));
}

function is_co_super($config, $username) {
    $uname = strtolower(trim($username));
    return in_array($uname, $config['coSupers'], true);
}

// Co-supers get PRESET-ONLY theme access (see theme.php) — not the
// unrestricted color/URL/upload access a real super has.
function has_limited_theme_access($config, $session) {
    return $session['role'] === 'member' && is_co_super($config, $session['user']);
}

function add_co_super(&$config, $username) {
    $uname = strtolower(trim($username));
    if ($uname === '' || in_array($uname, $config['coSupers'], true)) return;
    $config['coSupers'][] = $uname;
}

function remove_co_super(&$config, $username) {
    $uname = strtolower(trim($username));
    $config['coSupers'] = array_values(array_filter($config['coSupers'], function ($u) use ($uname) {
        return $u !== $uname;
    }));
}

// ---------------------------------------------------------------------
// Email digest to host (PART 2 - Notifications)
// ---------------------------------------------------------------------

$GLOBALS['digestHostInactiveMs'] = 15 * 60 * 1000;
$GLOBALS['digestCooldownMs'] = 60 * 60 * 1000;

function host_is_active($config) {
    foreach ($config['presence'] as $p) {
        if (($p['role'] ?? '') === 'host' && (now_ms() - $p['ts']) <= $GLOBALS['presenceWindowMs']) return true;
    }
    return false;
}

function maybe_send_report_digest(&$config, $room) {
    if (empty($config['hostNotifyEmail'])) return false;
    $openCount = open_report_count($config);
    if ($openCount <= 0) return false;
    if (host_is_active($config)) return false;

    $now = now_ms();
    if (($now - ($config['hostLastDigestAt'] ?? 0)) < $GLOBALS['digestCooldownMs']) return false;

    $roomLabel = $config['title'] !== '' ? $config['title'] : $room;
    $subject = 'QuickChat: ' . $openCount . ' open report(s) in "' . $roomLabel . '"';
    $body = "There are $openCount unresolved report(s) waiting in your room \"$roomLabel\" while you have been away.\n\n"
          . "Open the room's Manage panel to review and act on them.\n";
    $config['hostLastDigestAt'] = $now;
    @mail($config['hostNotifyEmail'], $subject, $body, "From: no-reply@quickchat.local\r\n");
    return true;
}

// ---------------------------------------------------------------------
// Scheduled messages (PART 2 - Content)
// ---------------------------------------------------------------------

function add_scheduled_message(&$config, $text, $user, $role, $postAtMs) {
    $item = [
        'id' => 'sch_' . now_ms() . '_' . substr(md5(uniqid('', true)), 0, 6),
        'text' => $text,
        'user' => $user,
        'role' => $role,
        'postAt' => $postAtMs,
    ];
    $config['scheduledMessages'][] = $item;
    return $item;
}

function remove_scheduled_message(&$config, $id) {
    $config['scheduledMessages'] = array_values(array_filter($config['scheduledMessages'], function ($s) use ($id) {
        return !(isset($s['id']) && $s['id'] === $id);
    }));
}

function process_due_scheduled_messages($room, &$config) {
    if (empty($config['scheduledMessages'])) return [];
    $now = now_ms();
    $due = [];
    $remaining = [];
    foreach ($config['scheduledMessages'] as $s) {
        if (($s['postAt'] ?? PHP_INT_MAX) <= $now) {
            $due[] = $s;
        } else {
            $remaining[] = $s;
        }
    }
    if (empty($due)) return [];
    $config['scheduledMessages'] = $remaining;

    $posted = [];
    foreach ($due as $s) {
        $posted[] = append_message($room, [
            'type' => 'text',
            'user' => $s['user'],
            'role' => $s['role'],
            'text' => $s['text'],
            'replyTo' => null,
            'reactions' => [],
            'wasScheduled' => true,
        ]);
    }
    return $posted;
}

// ---------------------------------------------------------------------
// Polls (PART 2 - Content)
// ---------------------------------------------------------------------

function create_poll(&$config, $question, $options, $createdBy) {
    $id = 'poll_' . now_ms() . '_' . substr(md5(uniqid('', true)), 0, 6);
    $opts = [];
    foreach (array_values($options) as $i => $text) {
        $text = trim((string)$text);
        if ($text === '') continue;
        $opts[] = ['id' => 'o' . $i, 'text' => mb_substr($text, 0, 120)];
    }
    $poll = [
        'id' => $id,
        'question' => mb_substr(trim((string)$question), 0, 200),
        'options' => $opts,
        'votes' => [],
        'createdBy' => $createdBy,
        'ts' => now_ms(),
        'closed' => false,
    ];
    $config['polls'][$id] = $poll;
    return $poll;
}

function cast_poll_vote(&$config, $pollId, $username, $optionId) {
    if (!isset($config['polls'][$pollId])) return null;
    $poll = &$config['polls'][$pollId];
    if (!empty($poll['closed'])) return null;
    $validOption = false;
    foreach ($poll['options'] as $o) {
        if ($o['id'] === $optionId) { $validOption = true; break; }
    }
    if (!$validOption) return null;
    $poll['votes'][strtolower(trim($username))] = $optionId;
    return $poll;
}

function close_poll(&$config, $pollId) {
    if (!isset($config['polls'][$pollId])) return false;
    $config['polls'][$pollId]['closed'] = true;
    return true;
}

function poll_results($poll) {
    $counts = [];
    foreach ($poll['options'] as $o) $counts[$o['id']] = 0;
    foreach ($poll['votes'] as $optId) {
        if (isset($counts[$optId])) $counts[$optId]++;
    }
    return $counts;
}

// ---------------------------------------------------------------------
// Room expiry / auto-cleanup (PART 2 - Room management)
// ---------------------------------------------------------------------

function room_auto_cleanup_if_due($slug, $config) {
    $days = isset($config['autoCleanupDays']) ? (int)$config['autoCleanupDays'] : 0;
    if ($days <= 0) return false;
    $last = $config['lastActivity'] ?? $config['createdAt'] ?? 0;
    if ($last <= 0) return false;
    $thresholdMs = $days * 24 * 60 * 60 * 1000;
    if ((now_ms() - $last) < $thresholdMs) return false;
    delete_room($slug);
    return true;
}

// ---------------------------------------------------------------------
// Room templates (PART 2 - Room management)
// ---------------------------------------------------------------------

function templates_file() {
    return $GLOBALS['dataDir'] . '/templates.json';
}

function read_templates() {
    $data = read_json_file(templates_file());
    return is_array($data) ? $data : [];
}

function save_template($name, $config) {
    $name = trim((string)$name);
    if ($name === '') return false;
    $templates = read_templates();
    $templates[$name] = [
        'name' => $name,
        'roomTheme' => $config['roomTheme'],
        'slowModeSeconds' => $config['slowModeSeconds'] ?? 0,
        'bannedWords' => $config['bannedWords'] ?? [],
        'requireFileApproval' => $config['requireFileApproval'] ?? false,
        'savedAt' => now_ms(),
    ];
    write_json_file(templates_file(), $templates);
    return true;
}

function delete_template($name) {
    $templates = read_templates();
    unset($templates[$name]);
    write_json_file(templates_file(), $templates);
}

function apply_template(&$config, $name) {
    $templates = read_templates();
    if (!isset($templates[$name])) return false;
    $t = $templates[$name];
    if (isset($t['roomTheme'])) $config['roomTheme'] = $t['roomTheme'];
    if (isset($t['slowModeSeconds'])) $config['slowModeSeconds'] = $t['slowModeSeconds'];
    if (isset($t['bannedWords'])) $config['bannedWords'] = $t['bannedWords'];
    if (isset($t['requireFileApproval'])) $config['requireFileApproval'] = $t['requireFileApproval'];
    return true;
}

// ---------------------------------------------------------------------
// Message search (PART 2 - Content)
// ---------------------------------------------------------------------

function search_messages($room, $query, $limit = 50) {
    $query = trim((string)$query);
    if ($query === '') return [];
    $messages = read_messages($room);
    $lowerQuery = mb_strtolower($query);
    $results = [];
    for ($i = count($messages) - 1; $i >= 0 && count($results) < $limit; $i--) {
        $m = $messages[$i];
        $haystack = '';
        if (isset($m['text'])) $haystack .= $m['text'] . ' ';
        if (isset($m['fileName'])) $haystack .= $m['fileName'];
        if (strpos($haystack, 'encv1:') === 0) continue;
        if (mb_strpos(mb_strtolower($haystack), $lowerQuery) !== false) {
            $results[] = $m;
        }
    }
    return $results;
}

// ---------------------------------------------------------------------
// "Clear my messages" (PART 3)
// ---------------------------------------------------------------------

function clear_users_messages($room, $username) {
    $messages = read_messages($room);
    $uname = strtolower(trim($username));
    $kept = [];
    $removedCount = 0;
    foreach ($messages as $m) {
        if (isset($m['user']) && strtolower($m['user']) === $uname) {
            $removedCount++;
            if (isset($m['type']) && $m['type'] === 'file' && !empty($m['fileUrl'])) {
                $path = __DIR__ . '/' . ltrim($m['fileUrl'], '/');
                if (is_file($path)) @unlink($path);
            }
        } else {
            $kept[] = $m;
        }
    }
    if ($removedCount > 0) write_messages($room, $kept);
    return $removedCount;
}

// ---------------------------------------------------------------------
// Undo-delete grace period (PART 3 - Data safety), applied to room
// deletion (the only "delete" action this app has).
// ---------------------------------------------------------------------

$GLOBALS['undoDeleteGraceMs'] = 10 * 1000;

function mark_room_pending_delete($room) {
    $config = read_config($room);
    if (!$config) return false;
    $config['pendingDeleteAt'] = now_ms();
    write_config($room, $config);
    return true;
}

function undo_room_delete($room) {
    $config = read_config($room);
    if (!$config || empty($config['pendingDeleteAt'])) return false;
    unset($config['pendingDeleteAt']);
    write_config($room, $config);
    return true;
}

function sweep_pending_deletes() {
    $pattern = $GLOBALS['dataDir'] . '/config_*.json';
    foreach (glob($pattern) as $path) {
        $base = basename($path, '.json');
        $slug = substr($base, strlen('config_'));
        if ($slug === '') continue;
        $config = read_config($slug);
        if (!$config || empty($config['pendingDeleteAt'])) continue;
        if ((now_ms() - $config['pendingDeleteAt']) >= $GLOBALS['undoDeleteGraceMs']) {
            delete_room($slug);
        }
    }
}

// ---------------------------------------------------------------------
// Simple analytics dashboard (PART 2)
// ---------------------------------------------------------------------

function site_dashboard_stats() {
    $rooms = list_rooms();
    $totalRooms = count($rooms);
    $totalActiveUsers = 0;
    $totalMessages = 0;
    $totalBanned = 0;
    $messagesToday = 0;
    $todayStartMs = strtotime('today') * 1000;
    foreach ($rooms as $r) {
        $totalActiveUsers += count($r['activeUsers']);
        $totalMessages += $r['messageCount'];
        $totalBanned += $r['bannedUserCount'];
        $msgs = read_json_file(room_file($r['room']));
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                if (($m['ts'] ?? 0) >= $todayStartMs) $messagesToday++;
            }
        }
    }
    return [
        'totalRooms' => $totalRooms,
        'totalActiveUsers' => $totalActiveUsers,
        'totalMessages' => $totalMessages,
        'messagesToday' => $messagesToday,
        'totalBannedUsers' => $totalBanned,
        'blockedIps' => count(list_blocked_ips()),
        'generatedAt' => now_ms(),
    ];
}

// ---------------------------------------------------------------------
// Free external API integrations - translation + GIF search (PART 2)
// ---------------------------------------------------------------------

function translate_text($text, $targetLang) {
    $text = mb_substr(trim((string)$text), 0, 2000);
    if ($text === '') return null;
    $targetLang = preg_replace('/[^a-z\-]/i', '', (string)$targetLang);
    if ($targetLang === '') $targetLang = 'en';

    $payload = json_encode(['q' => $text, 'source' => 'auto', 'target' => $targetLang, 'format' => 'text']);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 6,
        ],
    ]);
    $result = @file_get_contents('https://libretranslate.com/translate', false, $ctx);
    if ($result === false) return null;
    $data = json_decode($result, true);
    return isset($data['translatedText']) ? $data['translatedText'] : null;
}

if (!defined('TENOR_API_KEY')) {
    define('TENOR_API_KEY', 'LIVDSRZULELA');
}

// v35.1 fix: the original code called the V2 endpoint
// (tenor.googleapis.com) with a V1-only public demo key. Google's V2
// API has no public demo key — every V2 call needs a real, registered
// key, so every search was silently failing (400/403) and always
// looked like "no results" in the UI. The classic demo key
// "LIVDSRZULELA" is only valid against the older V1 endpoint
// (api.tenor.com/v1), so that's what this uses now.
//
// V1 is officially deprecated by Google (no removal date announced,
// but no guarantee either) — for a production deployment, get your
// own free V2 key at https://developers.google.com/tenor/guides/quickstart
// and set TENOR_API_KEY + switch this back to the V2 URL/response
// shape (media_formats instead of media[0]) for a fully supported setup.
function search_gifs($query, $limit = 12, &$errorOut = null) {
    $query = trim((string)$query);
    if ($query === '') return [];
    $limit = max(1, min(24, (int)$limit));
    $url = 'https://api.tenor.com/v1/search?' . http_build_query([
        'q' => $query,
        'key' => TENOR_API_KEY,
        'limit' => $limit,
        'media_filter' => 'minimal',
        'contentfilter' => 'medium',
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) {
        $errorOut = 'Could not reach Tenor (network error or blocked outbound HTTPS on this host).';
        return [];
    }
    $data = json_decode($result, true);
    if (!is_array($data) || isset($data['error'])) {
        $errorOut = isset($data['error']) ? ('Tenor error: ' . $data['error']) : 'Tenor returned an unexpected response.';
        return [];
    }
    if (!isset($data['results']) || !is_array($data['results'])) {
        $errorOut = 'Tenor returned an unexpected response.';
        return [];
    }
    $out = [];
    foreach ($data['results'] as $g) {
        $media = isset($g['media'][0]) ? $g['media'][0] : null;
        $gifUrl = $media['gif']['url'] ?? null;
        $previewUrl = $media['tinygif']['url'] ?? $gifUrl;
        if (!$gifUrl) continue;
        $out[] = ['url' => $gifUrl, 'preview' => $previewUrl, 'description' => $g['content_description'] ?? ''];
    }
    return $out;
}

// ---------------------------------------------------------------------
// Sticker packs (PART 2) - curated emoji "stickers", no binary assets.
// ---------------------------------------------------------------------

function sticker_catalog($includePremium = false) {
    $base = [
        ['id' => 'wave', 'emoji' => '👋', 'label' => 'Wave'],
        ['id' => 'thumbsup', 'emoji' => '👍', 'label' => 'Thumbs up'],
        ['id' => 'fire', 'emoji' => '🔥', 'label' => 'Fire'],
        ['id' => 'heart', 'emoji' => '❤️', 'label' => 'Heart'],
        ['id' => 'laugh', 'emoji' => '🤣', 'label' => 'Laughing'],
        ['id' => 'clap', 'emoji' => '👏', 'label' => 'Clap'],
        ['id' => 'party', 'emoji' => '🎉', 'label' => 'Party'],
        ['id' => 'think', 'emoji' => '🤔', 'label' => 'Thinking'],
        ['id' => 'cool', 'emoji' => '😎', 'label' => 'Cool'],
        ['id' => 'cry', 'emoji' => '😭', 'label' => 'Crying'],
        ['id' => 'shock', 'emoji' => '😱', 'label' => 'Shocked'],
        ['id' => 'sleep', 'emoji' => '😴', 'label' => 'Sleepy'],
        ['id' => 'rocket', 'emoji' => '🚀', 'label' => 'Rocket'],
        ['id' => 'star', 'emoji' => '⭐', 'label' => 'Star'],
        ['id' => 'ok', 'emoji' => '👌', 'label' => 'OK'],
        ['id' => 'wave-bye', 'emoji' => '🙌', 'label' => 'Celebration'],
    ];
    if (!$includePremium) return $base;
    // extraStickers perk — bonus pack only returned when the caller has
    // confirmed the room is premium and has that perk active (see
    // sticker.php GET handler, which passes $includePremium accordingly).
    return array_merge($base, [
        ['id' => 'crown', 'emoji' => '👑', 'label' => 'Crown'],
        ['id' => 'gem', 'emoji' => '💎', 'label' => 'Gem'],
        ['id' => 'sparkles', 'emoji' => '✨', 'label' => 'Sparkles'],
        ['id' => 'trophy', 'emoji' => '🏆', 'label' => 'Trophy'],
        ['id' => 'moneybag', 'emoji' => '💰', 'label' => 'Money bag'],
        ['id' => 'unicorn', 'emoji' => '🦄', 'label' => 'Unicorn'],
        ['id' => 'rainbow', 'emoji' => '🌈', 'label' => 'Rainbow'],
        ['id' => 'confettiball', 'emoji' => '🎊', 'label' => 'Confetti ball'],
    ]);
}

function sticker_by_id($id, $includePremium = false) {
    foreach (sticker_catalog($includePremium) as $s) {
        if ($s['id'] === $id) return $s;
    }
    return null;
}

// ---------------------------------------------------------------------
// Auto-backup (PART 3 - Data safety)
// ---------------------------------------------------------------------

function build_data_backup_zip() {
    if (!class_exists('ZipArchive')) return null;
    $tmpPath = tempnam(sys_get_temp_dir(), 'qcbackup_');
    if ($tmpPath === false) return null;
    @unlink($tmpPath);
    $tmpPath .= '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::CREATE) !== true) return null;

    foreach (glob($GLOBALS['dataDir'] . '/*.json') as $file) {
        $zip->addFile($file, basename($file));
    }
    $zip->close();
    return $tmpPath;
}
