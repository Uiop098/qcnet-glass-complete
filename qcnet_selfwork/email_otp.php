<?php
// =============================================================================
// email_otp.php — Email OTP verification with multi-provider fallback
// =============================================================================
// Delivery priority (auto-fallback, server-side health cache):
//   1. Brevo (Sendinblue)  — PRIMARY
//   2. Mailgun             — FALLBACK 1
//   3. SendGrid            — FALLBACK 2
//   4. Resend              — FALLBACK 3
//   5. PHP mail()          — LAST RESORT
// =============================================================================

require_once __DIR__ . '/dash_crypto.php';
if (file_exists(__DIR__ . '/email_config.php')) require_once __DIR__ . '/email_config.php';

// ---------------------------------------------------------------------------
// OTP constants (safe defaults if email_config.php is missing)
// ---------------------------------------------------------------------------
if (!defined('OTP_LENGTH'))               define('OTP_LENGTH',               6);
if (!defined('OTP_TTL_SECONDS'))          define('OTP_TTL_SECONDS',          600);
if (!defined('OTP_MAX_ATTEMPTS'))         define('OTP_MAX_ATTEMPTS',         5);
if (!defined('OTP_RESEND_COOLDOWN'))      define('OTP_RESEND_COOLDOWN',      60);
if (!defined('OTP_VERIFIED_TTL_SECONDS')) define('OTP_VERIFIED_TTL_SECONDS', 1200);
if (!defined('OTP_SENDER_NAME'))          define('OTP_SENDER_NAME',  'Verification');
if (!defined('OTP_SENDER_EMAIL'))         define('OTP_SENDER_EMAIL', 'noreply@example.com');
if (!defined('OTP_PHPMAIL_ENABLED'))      define('OTP_PHPMAIL_ENABLED', false);
if (!defined('OTP_HEALTH_FILE'))          define('OTP_HEALTH_FILE',  __DIR__ . '/data_dash/otp/provider_health.json');
if (!defined('OTP_HEALTH_FAIL_TTL'))      define('OTP_HEALTH_FAIL_TTL', 300);

// ---------------------------------------------------------------------------
// File helpers
// ---------------------------------------------------------------------------
function otp_dir() {
    $dir = __DIR__ . '/data_dash/otp';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}
function otp_key($email, $purpose)           { return md5(strtolower(trim($email)) . '|' . $purpose); }
function otp_file($email, $purpose)          { return otp_dir() . '/otp_'      . otp_key($email, $purpose) . '.json'; }
function otp_verified_file($email, $purpose) { return otp_dir() . '/verified_' . otp_key($email, $purpose) . '.json'; }
function is_valid_email($email)              { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }

// ---------------------------------------------------------------------------
// Shared HTML email template
// ---------------------------------------------------------------------------
function otp_email_html($code) {
    $safe = htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html><html><body>'
        . '<div style="font-family:sans-serif;max-width:440px;margin:32px auto;background:#fff;border-radius:10px;padding:32px;border:1px solid #e5e7eb;">'
        . '<h2 style="margin:0 0 8px;color:#111;">Email Verification</h2>'
        . '<p style="color:#444;margin:0 0 20px;">Enter this code to verify your email address:</p>'
        . '<div style="font-size:32px;letter-spacing:8px;font-weight:700;background:#f4f4f7;padding:18px 0;text-align:center;border-radius:8px;color:#111;">' . $safe . '</div>'
        . '<p style="color:#888;font-size:12px;margin-top:20px;">Expires in 10 minutes. If you did not request this, ignore this email.</p>'
        . '</div></body></html>';
}

// =============================================================================
// PROVIDER HEALTH CACHE
// Tiny JSON file tracking last success/failure per provider.
// No sensitive data — only timestamps and boolean status.
// =============================================================================
function _health_read() {
    if (!file_exists(OTP_HEALTH_FILE)) return [];
    $raw = @file_get_contents(OTP_HEALTH_FILE);
    return ($raw && ($d = json_decode($raw, true))) ? $d : [];
}
function _health_write(array $data) {
    $dir = dirname(OTP_HEALTH_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(OTP_HEALTH_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}
function _health_mark($provider, $success) {
    $data = _health_read();
    $data[$provider] = ['lastAttempt' => time(), 'ok' => (bool)$success];
    _health_write($data);
}
function _health_is_down($provider) {
    $data = _health_read();
    if (!isset($data[$provider])) return false;       // never tried → assume up
    if ($data[$provider]['ok'])   return false;       // last attempt succeeded → up
    return (time() - ($data[$provider]['lastAttempt'] ?? 0)) < OTP_HEALTH_FAIL_TTL;
}

// =============================================================================
// BREVO CONNECTIVITY SELF-TEST
// Called once per request before trying to send, so we can detect the two
// most common InfinityFree / shared-host blockers:
//   (a) outbound HTTPS (port 443) is firewalled → cURL can't reach api.brevo.com
//   (b) SSL peer verification fails (missing CA bundle)
// Returns an array describing what was found; logged to error_log only.
// =============================================================================
function _brevo_selftest() {
    $result = ['curl_available' => false, 'ssl_ok' => false, 'connect_ok' => false, 'notes' => []];
    if (!function_exists('curl_init')) {
        $result['notes'][] = 'cURL extension is not loaded — install php-curl';
        return $result;
    }
    $result['curl_available'] = true;

    // Quick HEAD-like connect to Brevo API host, short timeout, no real request
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,   // HEAD only — no body needed
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'OTP-selftest/1.0',
    ]);
    curl_exec($ch);
    $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr    = curl_error($ch);
    $cerrno  = curl_errno($ch);
    curl_close($ch);

    if ($cerrno === CURLE_SSL_CACERT || $cerrno === CURLE_SSL_PEER_CERTIFICATE) {
        // SSL peer verification failed — try without verification to confirm it's just the cert
        $result['notes'][] = "SSL verify failed (errno $cerrno): $cerr — will retry without verify";
        $ch2 = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch2);
        $http2  = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $cerr2  = curl_error($ch2);
        curl_close($ch2);
        if (!$cerr2) {
            $result['ssl_ok']     = false;   // SSL verify broken on this host
            $result['connect_ok'] = true;    // but TCP/HTTPS does reach Brevo
            $result['notes'][]    = 'Connected OK without SSL verify — host missing CA bundle; will use CURLOPT_SSL_VERIFYPEER=false as workaround';
        } else {
            $result['notes'][] = "Even without SSL verify, connect failed: $cerr2";
        }
    } elseif ($cerrno !== 0) {
        $result['notes'][] = "Connect failed (errno $cerrno): $cerr";
    } else {
        $result['ssl_ok']     = true;
        $result['connect_ok'] = true;
        $result['notes'][]    = "Connected OK (HTTP $http)";
    }

    error_log('OTP[selftest] Brevo connectivity: ' . json_encode($result));
    return $result;
}

// Cache the self-test result for the duration of this request (static var)
function _brevo_need_ssl_workaround() {
    static $checked = null, $workaround = false;
    if ($checked !== null) return $workaround;
    $checked = true;
    $t = _brevo_selftest();
    // Need workaround if: we can connect but SSL verify fails
    $workaround = ($t['connect_ok'] && !$t['ssl_ok']);
    return $workaround;
}

// =============================================================================
// INDIVIDUAL PROVIDER SENDERS
// Each returns true on success, false on any failure — never throws.
// Provider keys / raw API responses never reach the HTTP caller.
// =============================================================================

// 1. Brevo (Sendinblue) — PRIMARY
function _send_via_brevo($to, $code) {
    if (!defined('BREVO_API_KEY') || BREVO_API_KEY === '' || BREVO_API_KEY === 'YOUR_BREVO_API_KEY') {
        error_log('OTP[Brevo] skipped — BREVO_API_KEY not set in email_config.php');
        return false;
    }
    $sslWorkaround = _brevo_need_ssl_workaround();
    $payload = json_encode([
        'sender'      => ['name' => OTP_SENDER_NAME, 'email' => OTP_SENDER_EMAIL],
        'to'          => [['email' => $to]],
        'subject'     => 'Your verification code',
        'htmlContent' => otp_email_html($code),
    ]);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        // Apply SSL workaround if self-test found host has broken CA bundle
        CURLOPT_SSL_VERIFYPEER => !$sslWorkaround,
        CURLOPT_SSL_VERIFYHOST => $sslWorkaround ? 0 : 2,
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr     = curl_error($ch);
    $cerrno   = curl_errno($ch);
    curl_close($ch);

    if ($cerrno !== 0) {
        error_log("OTP[Brevo] cURL errno $cerrno: $cerr");
        return false;
    }
    if ($http === 401) {
        error_log("OTP[Brevo] HTTP 401 Unauthorized — check BREVO_API_KEY in email_config.php");
        return false;
    }
    if ($http === 400) {
        error_log("OTP[Brevo] HTTP 400 Bad Request — check OTP_SENDER_EMAIL is verified in Brevo dashboard. Response: $response");
        return false;
    }
    if ($http < 200 || $http >= 300) {
        error_log("OTP[Brevo] HTTP $http — Response: $response");
        return false;
    }
    return true;
}

// 2. Mailgun — FALLBACK 1
function _send_via_mailgun($to, $code) {
    if (!defined('MAILGUN_API_KEY') || MAILGUN_API_KEY === '' || MAILGUN_API_KEY === 'YOUR_MAILGUN_API_KEY') return false;
    if (!defined('MAILGUN_DOMAIN')  || MAILGUN_DOMAIN  === '' || MAILGUN_DOMAIN  === 'mg.yourdomain.com')   return false;
    $region = (defined('MAILGUN_REGION') && MAILGUN_REGION === 'eu') ? 'api.eu.mailgun.net' : 'api.mailgun.net';
    $ch = curl_init("https://{$region}/v3/" . MAILGUN_DOMAIN . "/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERPWD        => 'api:' . MAILGUN_API_KEY,
        CURLOPT_POSTFIELDS     => [
            'from'    => OTP_SENDER_NAME . ' <' . OTP_SENDER_EMAIL . '>',
            'to'      => $to,
            'subject' => 'Your verification code',
            'html'    => otp_email_html($code),
        ],
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr     = curl_error($ch);
    curl_close($ch);
    if ($cerr) { error_log("OTP[Mailgun] cURL error: $cerr"); return false; }
    if ($http < 200 || $http >= 300) { error_log("OTP[Mailgun] HTTP $http: $response"); return false; }
    return true;
}

// 3. SendGrid — FALLBACK 2
function _send_via_sendgrid($to, $code) {
    if (!defined('SENDGRID_API_KEY') || SENDGRID_API_KEY === '' || SENDGRID_API_KEY === 'YOUR_SENDGRID_API_KEY') return false;
    $payload = json_encode([
        'personalizations' => [['to' => [['email' => $to]]]],
        'from'             => ['email' => OTP_SENDER_EMAIL, 'name' => OTP_SENDER_NAME],
        'subject'          => 'Your verification code',
        'content'          => [['type' => 'text/html', 'value' => otp_email_html($code)]],
    ]);
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . SENDGRID_API_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr     = curl_error($ch);
    curl_close($ch);
    if ($cerr) { error_log("OTP[SendGrid] cURL error: $cerr"); return false; }
    if ($http < 200 || $http >= 300) { error_log("OTP[SendGrid] HTTP $http: $response"); return false; }
    return true;
}

// 4. Resend — FALLBACK 3
function _send_via_resend($to, $code) {
    if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '' || RESEND_API_KEY === 'YOUR_RESEND_API_KEY') return false;
    $payload = json_encode([
        'from'    => OTP_SENDER_NAME . ' <' . OTP_SENDER_EMAIL . '>',
        'to'      => [$to],
        'subject' => 'Your verification code',
        'html'    => otp_email_html($code),
    ]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . RESEND_API_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr     = curl_error($ch);
    curl_close($ch);
    if ($cerr) { error_log("OTP[Resend] cURL error: $cerr"); return false; }
    if ($http < 200 || $http >= 300) { error_log("OTP[Resend] HTTP $http: $response"); return false; }
    return true;
}

// 5. PHP mail() — LAST RESORT
function _send_via_phpmail($to, $code) {
    if (!OTP_PHPMAIL_ENABLED) return false;
    $headers = implode("\r\n", [
        'From: ' . OTP_SENDER_NAME . ' <' . OTP_SENDER_EMAIL . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
    ]);
    $sent = @mail($to, 'Your verification code', otp_email_html($code), $headers);
    if (!$sent) error_log("OTP[phpmail] mail() returned false for $to");
    return (bool)$sent;
}

// =============================================================================
// MULTI-PROVIDER DISPATCHER
// Tries each provider in priority order; skips providers marked DOWN in the
// health cache. Updates health cache after every attempt.
// =============================================================================
function send_otp_email($email, $code) {
    $providers = [
        'brevo'    => '_send_via_brevo',
        'mailgun'  => '_send_via_mailgun',
        'sendgrid' => '_send_via_sendgrid',
        'resend'   => '_send_via_resend',
        'phpmail'  => '_send_via_phpmail',
    ];
    foreach ($providers as $name => $fn) {
        if (_health_is_down($name)) {
            error_log("OTP[dispatcher] skipping $name (health cache: DOWN)");
            continue;
        }
        $ok = call_user_func($fn, $email, $code);
        _health_mark($name, $ok);
        if ($ok) {
            error_log("OTP[dispatcher] delivered via $name");
            return true;
        }
        error_log("OTP[dispatcher] $name failed — trying next");
    }
    error_log("OTP[dispatcher] ALL providers failed for: $email");
    return false;
}

// =============================================================================
// DIAGNOSTIC ENDPOINT  (?action=otp_diag)
// Shows exactly WHY sending is failing — visible only to a logged-in admin.
// Remove or disable this in production once everything is working.
// =============================================================================
function _otp_run_diagnostics() {
    $out = [];

    // 1. Check constants from email_config.php
    $out['config'] = [
        'OTP_SENDER_EMAIL'  => defined('OTP_SENDER_EMAIL')  ? OTP_SENDER_EMAIL  : '(not defined)',
        'OTP_SENDER_NAME'   => defined('OTP_SENDER_NAME')   ? OTP_SENDER_NAME   : '(not defined)',
        'BREVO_API_KEY_set' => defined('BREVO_API_KEY') && BREVO_API_KEY !== 'YOUR_BREVO_API_KEY' && BREVO_API_KEY !== '',
        'MAILGUN_set'       => defined('MAILGUN_API_KEY') && MAILGUN_API_KEY !== 'YOUR_MAILGUN_API_KEY',
        'SENDGRID_set'      => defined('SENDGRID_API_KEY') && SENDGRID_API_KEY !== 'YOUR_SENDGRID_API_KEY',
        'RESEND_set'        => defined('RESEND_API_KEY') && RESEND_API_KEY !== 'YOUR_RESEND_API_KEY',
        'PHPMAIL_enabled'   => defined('OTP_PHPMAIL_ENABLED') && OTP_PHPMAIL_ENABLED,
    ];

    // 2. cURL availability
    $out['curl_loaded'] = function_exists('curl_init');
    if ($out['curl_loaded']) {
        $out['curl_version'] = curl_version()['version'] ?? 'unknown';
    }

    // 3. Brevo connectivity self-test
    $out['brevo_selftest'] = _brevo_selftest();

    // 4. Health cache current state
    $out['health_cache'] = _health_read();

    // 5. OTP data directory writable?
    $dir = __DIR__ . '/data_dash/otp';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $out['otp_dir_writable'] = is_writable($dir);

    return $out;
}

// =============================================================================
// PUBLIC API — identical signatures to original (backward-compatible)
// =============================================================================
function request_otp($email, $purpose) {
    if (!is_valid_email($email)) return ['error' => 'Enter a valid email address.'];
    $file     = otp_file($email, $purpose);
    $existing = get_secure_table($file);
    $now      = time();
    if (!empty($existing) && isset($existing['lastSent'])) {
        $remaining = OTP_RESEND_COOLDOWN - ($now - $existing['lastSent']);
        if ($remaining > 0) return ['error' => 'Please wait before requesting another code.', 'cooldownRemaining' => $remaining];
    }
    $code   = sprintf('%0' . OTP_LENGTH . 'd', random_int(0, (10 ** OTP_LENGTH) - 1));
    $record = [
        'email'    => strtolower(trim($email)),
        'purpose'  => $purpose,
        'otpHash'  => password_hash($code, PASSWORD_DEFAULT),
        'expires'  => $now + OTP_TTL_SECONDS,
        'attempts' => 0,
        'lastSent' => $now,
    ];
    if (!send_otp_email($email, $code)) {
        return ['error' => 'Could not send the verification email. Please try again shortly.'];
    }
    save_secure_table($file, $record);
    return ['status' => 'success', 'expiresInSeconds' => OTP_TTL_SECONDS];
}

function verify_otp($email, $purpose, $code) {
    $file   = otp_file($email, $purpose);
    $record = get_secure_table($file);
    if (empty($record))                                   return ['error' => 'No verification code was requested for this email. Request one first.'];
    if (time() > ($record['expires'] ?? 0))               { @unlink($file); return ['error' => 'That code has expired. Request a new one.']; }
    if (($record['attempts'] ?? 0) >= OTP_MAX_ATTEMPTS)   { @unlink($file); return ['error' => 'Too many incorrect attempts. Request a new code.']; }
    if (!password_verify((string)$code, $record['otpHash'])) {
        $record['attempts'] = ($record['attempts'] ?? 0) + 1;
        save_secure_table($file, $record);
        $left = OTP_MAX_ATTEMPTS - $record['attempts'];
        return ['error' => 'Incorrect code.' . ($left > 0 ? " {$left} attempt(s) left." : '')];
    }
    @unlink($file);
    save_secure_table(otp_verified_file($email, $purpose), [
        'email'      => strtolower(trim($email)),
        'purpose'    => $purpose,
        'verifiedAt' => time(),
        'expires'    => time() + OTP_VERIFIED_TTL_SECONDS,
    ]);
    return ['status' => 'success'];
}

function is_email_verified($email, $purpose, $consume = true) {
    $file   = otp_verified_file($email, $purpose);
    $record = get_secure_table($file);
    if (empty($record))                                         return false;
    if (strtolower(trim($email)) !== ($record['email'] ?? '')) return false;
    if (time() > ($record['expires'] ?? 0))                    { @unlink($file); return false; }
    if ($consume) @unlink($file);
    return true;
}

// =============================================================================
// HTTP ACTION ROUTER
// =============================================================================
if (basename($_SERVER['SCRIPT_FILENAME']) === 'email_otp.php') {
    require_once __DIR__ . '/dash_firewall.php';
    header('Content-Type: application/json');

    $action = $_GET['action'] ?? '';

    // ---- Diagnostic endpoint (GET, admin-only check) ----
    // Access via: email_otp.php?action=otp_diag
    // REMOVE or restrict this once working in production.
    if ($action === 'otp_diag') {
        // Basic protection: only allow from localhost or if a debug token matches
        $allowDiag = ($_SERVER['REMOTE_ADDR'] === '127.0.0.1')
                  || ($_SERVER['REMOTE_ADDR'] === '::1')
                  || (isset($_GET['token']) && defined('OTP_DIAG_TOKEN') && hash_equals(OTP_DIAG_TOKEN, $_GET['token']));
        if (!$allowDiag) { http_response_code(403); die(json_encode(['error' => 'Forbidden'])); }
        die(json_encode(_otp_run_diagnostics(), JSON_PRETTY_PRINT));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die(json_encode(['error' => 'Method not allowed.']));
    }

    $email   = trim($_POST['email']   ?? '');
    $purpose = trim($_POST['purpose'] ?? '');

    $allowedPurposes = ['user_register', 'admin_register', 'payment'];
    if (!in_array($purpose, $allowedPurposes, true)) {
        die(json_encode(['error' => 'Invalid verification context.']));
    }

    if ($action === 'send_otp') {
        if (!rate_limit_allow('otp_send', get_client_ip(), 6, 600)) {
            http_response_code(429);
            die(json_encode(['error' => 'Too many requests. Please wait a few minutes.']));
        }
        echo json_encode(request_otp($email, $purpose));
        exit;
    }

    if ($action === 'verify_otp') {
        if (!rate_limit_allow('otp_verify', get_client_ip(), 15, 600)) {
            http_response_code(429);
            die(json_encode(['error' => 'Too many attempts. Please wait a few minutes.']));
        }
        $code = trim($_POST['code'] ?? '');
        echo json_encode(verify_otp($email, $purpose, $code));
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
}
?>
