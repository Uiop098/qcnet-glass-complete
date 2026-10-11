<?php
$lock_file = __DIR__ . '/setup.lock';
if (file_exists($lock_file)) die("Setup is already completed and locked. Please delete setup.php.");
$folders = ['data_dash', 'data_dash/temp', 'data_dash/otp', 'files_dash', 'data', 'files', 'files/wallpapers'];
foreach ($folders as $folder) {
    if (!is_dir($folder)) { mkdir($folder, 0755, true); echo "<p>✅ Created <b>$folder</b></p>"; }
    file_put_contents("$folder/.htaccess", "Require all denied\nDeny from all\n");
}
file_put_contents('files/wallpapers/.htaccess', "Require all granted\n");

$master_file = 'data_dash/master.json';
if (!file_exists($master_file)) {
    file_put_contents($master_file, json_encode(["xbot" => [ "password_hash" => password_hash("#123xbot", PASSWORD_DEFAULT) ]], JSON_PRETTY_PRINT));
    echo "<p>🛡️ Master Admin credentials initialized.</p>";
}

$sys_key_file = __DIR__ . '/data_dash/sys_key.php';
if (!file_exists($sys_key_file)) {
    $random_key = bin2hex(random_bytes(32));
    file_put_contents($sys_key_file, "<?php define('DASH_ENC_KEY', hex2bin('$random_key')); ?>");
    echo "<p>🔐 AES-256 Database Encryption key generated.</p>";
}

$root_htaccess = "DirectoryIndex gateway.html index.php\nOptions -Indexes\n<FilesMatch \"\\.json$\">\n    Order Allow,Deny\n    Deny from all\n</FilesMatch>";
file_put_contents(".htaccess", $root_htaccess);
file_put_contents($lock_file, "locked");
echo "<h3>🎉 Installation Complete! Access <a href='gateway.html'>gateway.html</a></h3>";
?>