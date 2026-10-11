<?php
// =============================================================================
// email_config.php — Multi-provider OTP email configuration
// =============================================================================
// Priority order (server checks each in order, falls back automatically):
//   1. Brevo (Sendinblue) — PRIMARY   → 300 free/day, no domain needed
//   2. Mailgun             — FALLBACK1 → 100 free/day, domain needed
//   3. SendGrid            — FALLBACK2 → 100 free/day, domain needed
//   4. Resend              — FALLBACK3 → 100 free/day, domain needed
//   5. PHP mail()          — LAST RESORT → hosting mail server, no API key
//
// HOW TO CONFIGURE:
//   — Fill in only the providers you have signed up for.
//   — Leave unused providers set to 'YOUR_...' — they will be skipped.
//   — At minimum, fill in Brevo (PRIMARY). The more you fill in, the more
//     resilient your OTP delivery becomes.
//   — SENDER_EMAIL must be verified / whitelisted on each provider's dashboard.
//   — Never commit real API keys to version control.
// =============================================================================

// ---------------------------------------------------------------------------
// SHARED — sender identity used by all providers
// ---------------------------------------------------------------------------
define('OTP_SENDER_NAME',  'Your Business Dashboard');
define('OTP_SENDER_EMAIL', 'noreply@yourdomain.com');   // must be verified on each provider you use

// ---------------------------------------------------------------------------
// 1. BREVO (Sendinblue) — PRIMARY  [https://app.brevo.com → SMTP & API → API Keys]
//    Free: 300 emails/day. No domain verification required on free plan.
// ---------------------------------------------------------------------------
define('BREVO_API_KEY', 'YOUR_BREVO_API_KEY');          // xkeysib-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

// ---------------------------------------------------------------------------
// 2. MAILGUN — FALLBACK 1  [https://app.mailgun.com → API Keys]
//    Free: 100 emails/day. Requires domain verification.
//    MAILGUN_DOMAIN: your sending domain registered in Mailgun (e.g. mg.yourdomain.com)
//    MAILGUN_REGION: 'us' (default) or 'eu' (if you signed up on EU region)
// ---------------------------------------------------------------------------
define('MAILGUN_API_KEY', 'YOUR_MAILGUN_API_KEY');      // key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
define('MAILGUN_DOMAIN',  'mg.yourdomain.com');
define('MAILGUN_REGION',  'us');                        // 'us' or 'eu'

// ---------------------------------------------------------------------------
// 3. SENDGRID — FALLBACK 2  [https://app.sendgrid.com → Settings → API Keys]
//    Free: 100 emails/day. Requires sender identity verification.
// ---------------------------------------------------------------------------
define('SENDGRID_API_KEY', 'YOUR_SENDGRID_API_KEY');    // SG.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

// ---------------------------------------------------------------------------
// 4. RESEND — FALLBACK 3  [https://resend.com/api-keys]
//    Free: 100 emails/day. Requires domain verification.
// ---------------------------------------------------------------------------
define('RESEND_API_KEY',    'YOUR_RESEND_API_KEY');     // re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

// ---------------------------------------------------------------------------
// 5. PHP mail() — LAST RESORT
//    Uses the server's built-in mail transport. No API key needed.
//    Set to true to enable; false to skip (skip if your host blocks mail()).
//    InfinityFree often has mail() disabled — leave false unless you confirm it works.
// ---------------------------------------------------------------------------
define('OTP_PHPMAIL_ENABLED', false);

// ---------------------------------------------------------------------------
// HEALTH-CHECK CACHE — background provider status tracking
//    The system records each provider's last success/failure so it can skip
//    recently-failed providers faster without waiting for a timeout every time.
//    This file is written to data_dash/otp/ and is auto-managed; do not edit manually.
// ---------------------------------------------------------------------------
define('OTP_HEALTH_FILE', __DIR__ . '/data_dash/otp/provider_health.json');
define('OTP_HEALTH_FAIL_TTL', 300);   // seconds to treat a provider as down after a failure (5 min)
define('OTP_HEALTH_OK_TTL',   60);    // re-check a healthy provider every 60 s even if cached OK

// ---------------------------------------------------------------------------
// DIAGNOSTIC TOKEN — temporary, for debugging only
//    Lets you access the diagnostic endpoint from any browser using:
//    https://yourdomain.com/email_otp.php?action=otp_diag&token=YOUR_TOKEN_HERE
//
//    IMPORTANT: Remove or comment out this line once OTP is working.
// ---------------------------------------------------------------------------
define('OTP_DIAG_TOKEN', 'e8fba82dc893424cfcd2660f766d94ef632c2e1fec748ea');
