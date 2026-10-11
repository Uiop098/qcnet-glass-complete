<?php
// super_users.php — accounts for the "Super User" login.
//
// Super users can:
//   - Log into ANY room from the Super Login tab (no PIN needed)
//   - Upload any file type (only a small server-safety blocklist applies —
//     see the $dangerousExtensions list in upload.php)
//   - Change the room's background theme and their own message glow color
//   - Their messages always show a special glowing border in chat
//
// v35 SECURITY FIX: passwords are now stored as SHA-256 hashes instead
// of plain text (QuickChat_Improvement_Plan.txt PART 1 #4). Even a full
// server file leak no longer exposes the literal password. auth.php
// compares with hash_equals() for constant-time comparison, same as
// every other password check in this app.
//
// FORMAT: 'username' => ['sha256-hash-of-password', 'DefaultDisplayName']
// The display name is used in chat if the person doesn't type a
// custom one on the Super Login screen.
//
// TO ADD/CHANGE A SUPER USER: generate a hash for the new password and
// paste it in below. One-liner (run once, anywhere PHP is available,
// then discard the output after copying it here):
//
//     php -r "echo hash('sha256', 'your-new-password');"
//
// Never reuse these passwords anywhere else, and keep this file safe
// even though it no longer contains the literal passwords.
//
// LEFT INTENTIONALLY EMPTY: the Business Dashboard bridge (read_super_
// credentials() in common.php) already supplies exactly one default
// Super User — the master admin account in data_dash/master.json
// (username 'xbot', bcrypt-hashed, changeable from the dashboard or the
// Super User panel). Adding an 'xbot' entry here used to create a second,
// separate SHA-256-hashed 'xbot' account that silently got shadowed by
// the master.json one at login time — same username, two different
// password systems, invisible to anyone reading this file. If you want
// a standalone chat-only Super User that ISN'T tied to any Dashboard
// admin, add one below under a DIFFERENT username than any Dashboard
// account. Otherwise, leave this empty and there is exactly one default
// super user in the whole system.

return [
    // 'anotherUsername' => ['<sha256 hash of the password>', 'DisplayName'],
];
