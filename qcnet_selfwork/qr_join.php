<?php
// qr_join.php — the page a scanned "invite" QR code opens.
//
// No session of its own — the only thing that lets it do anything is a
// 30-minute, multi-use token minted by qr_join_send.php. Rules:
//   1. Opened directly (no token, or an expired one) -> redirect to
//      login.html, exactly like qr_upload.php does for the file-QR flow.
//   2. A valid token shows a single field: pick a display name. No PIN.
//   3. The token is NOT single-use — anyone who scans it while it's
//      valid can join. What IS enforced is a hard, shared cutoff: the
//      session created here carries the SAME expiry as the token
//      itself (qrJoinExpires, checked in common.php's get_session), so
//      every guest who joined via this code is signed out together
//      the moment the code's 30 minutes are up.

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];

function clean_join_username($raw) {
    return mb_substr(trim((string)$raw), 0, 40);
}

if ($method === 'POST') {
    header('Content-Type: application/json');

    if (!rate_limit_allow('qr_join_post', get_client_ip(), 20, 300)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts — please wait a bit.']);
        exit;
    }

    $j = isset($_POST['j']) ? (string)$_POST['j'] : '';
    $rec = peek_join_token($j);
    if (!$rec) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'expired', 'message' => 'This join code has expired. Ask the host for a new one.']);
        exit;
    }

    $room = $rec['room'];
    $username = clean_join_username($_POST['username'] ?? '');
    if ($username === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Please enter a name.']);
        exit;
    }
    if (in_array(strtolower($username), ['host'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'That name is reserved. Please choose another.']);
        exit;
    }

    $config = read_config($room);
    if (!$config) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'This room no longer exists.']);
        exit;
    }
    $fwIp = get_client_ip();
    if (is_banned($config, $username)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You have been removed from this chat by the host.']);
        exit;
    }
    if (is_ip_banned($config, $fwIp)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This device has been blocked from this chat.']);
        exit;
    }

    record_user_ip($config, $username, $fwIp);
    write_config($room, $config);

    // qrJoinExpires ties this session's hard cutoff to the SAME moment
    // the join code itself expires — not 30 minutes from right now, so
    // a whole batch of guests who trickled in throughout the window
    // all get signed out at once (see get_session() in common.php).
    $sessionToken = create_session($room, $username, 'member', ['qrJoinExpires' => $rec['expires']]);

    echo json_encode([
        'ok' => true,
        'token' => $sessionToken,
        'role' => 'member',
        'user' => $username,
        'room' => safe_room_name($room),
    ]);
    exit;
}

// ---------------------------------------------------------------------
// GET — render the "pick a name" page, but only for a currently-valid
// join code. Anything else bounces to login.html, same treatment as
// opening qr_upload.php directly.
// ---------------------------------------------------------------------
if (!rate_limit_allow('qr_join_get', get_client_ip(), 30, 60)) {
    http_response_code(429);
    header('Content-Type: text/plain');
    echo 'Too many requests — please wait a bit and rescan the code.';
    exit;
}

$j = isset($_GET['j']) ? (string)$_GET['j'] : '';
$rec = peek_join_token($j);
if (!$rec) {
    header('Location: login.html?msg=' . rawurlencode('That join code is invalid or has expired. Ask the host for a new one.'));
    exit;
}

$jSafe = htmlspecialchars($j, ENT_QUOTES);
$roomSafe = htmlspecialchars($rec['room'], ENT_QUOTES);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
<title>Join room — QuickChat Glass</title>
<link rel="stylesheet" href="glass-theme.css">
<link rel="stylesheet" href="glass-app.css">
<style>
  .qj-wrap { max-width: 420px; margin: 0 auto; padding: 28px 20px 40px; }
  .qj-card { text-align: center; }
  .qj-room { opacity: .7; font-size: 13px; margin-bottom: 4px; }
  .qj-status { margin-top: 14px; font-size: 14px; min-height: 20px; }
  .qj-status.error { color: #ff6b6b; }
</style>
</head>
<body data-theme="nocturne">
<div class="blob" style="top:-10%; left:-10%;"></div>
<div class="blob b2"></div>
<div class="phone">
  <div class="qj-wrap">
    <div class="glass qj-card" style="padding:24px 18px;border-radius:20px;">
      <div class="qj-room">Joining room: <strong><?php echo $roomSafe; ?></strong></div>
      <h2 class="fdisp" style="margin:6px 0 2px;">👋 Pick a name</h2>
      <div class="field" style="text-align:left;margin-top:14px;">
        <label for="qjName">Display name</label>
        <input id="qjName" type="text" maxlength="40" placeholder="e.g. Alex" autocomplete="off" />
      </div>
      <button class="btn-primary" id="qjJoinBtn" style="width:100%;margin-top:10px;">Join room</button>
      <div class="qj-status" id="qjStatus"></div>
      <div class="hint-text" style="margin-top:14px;">No password needed — this link was shared by the host. It works for a limited time only.</div>
    </div>
  </div>
</div>
<script>
(function () {
  var joinToken = <?php echo json_encode($j); ?>;
  var nameInput = document.getElementById('qjName');
  var joinBtn = document.getElementById('qjJoinBtn');
  var statusEl = document.getElementById('qjStatus');

  function setStatus(msg, cls) {
    statusEl.textContent = msg || '';
    statusEl.className = 'qj-status' + (cls ? ' ' + cls : '');
  }

  function doJoin() {
    var name = nameInput.value.trim();
    if (!name) { setStatus('Please enter a name.', 'error'); nameInput.focus(); return; }
    joinBtn.disabled = true;
    setStatus('Joining…');

    var fd = new URLSearchParams();
    fd.append('j', joinToken);
    fd.append('username', name);

    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
      .then(function (r) {
        if (r.data && r.data.ok) {
          try {
            sessionStorage.setItem('qc_session', JSON.stringify({
              token: r.data.token, role: r.data.role, user: r.data.user, room: r.data.room,
            }));
          } catch (e) {}
          setStatus('✅ Joined! Taking you in…', '');
          window.location.href = 'room.html';
        } else {
          var msg = (r.data && r.data.message) || (r.data && r.data.error) || 'Could not join.';
          setStatus(msg, 'error');
          joinBtn.disabled = false;
          if (r.data && r.data.error === 'expired') {
            setTimeout(function () { window.location.href = 'login.html'; }, 2000);
          }
        }
      })
      .catch(function () {
        setStatus('Network error — please try again.', 'error');
        joinBtn.disabled = false;
      });
  }

  joinBtn.addEventListener('click', doJoin);
  nameInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') doJoin(); });
  nameInput.focus();
})();
</script>
</body>
</html>
