<?php
// index.php — entry point. Clean, standard server-side redirect (HTTP
// 301) to the portal gateway (choose Business Dashboard or QuickChat).
//
// Previously this was index.html using a 0-second <meta http-equiv="refresh">
// paired with a JavaScript window.location.replace() to the same target.
// That specific combination — an instant meta-refresh plus a JS redirect
// doing the same thing — is a pattern Safe Browsing's automated scanners
// associate with "sneaky redirects" used to obscure a phishing/malware
// site's real destination, since it's commonly seen in cloaking kits. A
// single server-side Location header, with no HTML/JS redirect layered on
// top, doesn't match that pattern and is standard, trusted practice.
header("Location: gateway.html", true, 301);
exit;
?>
