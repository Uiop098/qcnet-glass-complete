<?php
// qr_upload.php — the page a scanned QR code actually opens.
//
// This page has NO session/login of its own. The only thing that lets
// it do anything is a short-lived, single-use token minted by
// qr_send.php. Rules enforced here, per spec:
//   1. Opened directly (no token, or a missing/expired/already-used
//      token) -> redirect straight to login.html. It never renders an
//      upload form for an untrusted visitor.
//   2. A valid token shows a small upload form limited to images and
//      everyday documents (see qr_allowed_extensions() in common.php).
//   3. The token is good for exactly one file. It's re-checked (and
//      only then burned) at the moment of upload, not merely at page
//      load, so re-validation happens server-side right before the
//      file actually gets written to disk.
//   4. The token is re-checked for expiry (from issue time) on the
//      upload itself too — a token that looked valid when the page
//      loaded but has since expired is rejected.

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------
// Lightweight status ping used by the chat-side QR overlay so it can
// say "file received" the moment it lands, instead of waiting for the
// normal chat poll interval. Read-only, never redirects, never touches
// the token itself.
// ---------------------------------------------------------------------
if ($method === 'GET' && isset($_GET['status'])) {
    header('Content-Type: application/json');
    $t = isset($_GET['t']) ? (string)$_GET['t'] : '';
    $tokens = read_qr_tokens();
    if (!isset($tokens[$t])) {
        echo json_encode(['ok' => true, 'state' => 'gone']); // expired & GC'd, or never existed
        exit;
    }
    $rec = $tokens[$t];
    if (!empty($rec['used'])) {
        echo json_encode(['ok' => true, 'state' => 'done']);
    } elseif (!isset($rec['expires']) || $rec['expires'] < time()) {
        echo json_encode(['ok' => true, 'state' => 'expired']);
    } else {
        echo json_encode(['ok' => true, 'state' => 'waiting', 'secondsLeft' => max(0, $rec['expires'] - time())]);
    }
    exit;
}

// ---------------------------------------------------------------------
// POST — the actual file handoff.
// ---------------------------------------------------------------------
if ($method === 'POST') {
    header('Content-Type: application/json');

    if (!rate_limit_allow('qr_upload_post', get_client_ip(), 20, 60)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts — please wait a bit.']);
        exit;
    }

    $t = isset($_POST['t']) ? (string)$_POST['t'] : '';
    $rec = peek_qr_upload_token($t);
    if (!$rec) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'expired', 'message' => 'This link has expired or was already used. Ask for a new QR code from the chat.']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err = isset($_FILES['file']) ? $_FILES['file']['error'] : 'no file';
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Upload failed (code: ' . $err . ')']);
        exit;
    }

    $file = $_FILES['file'];
    $maxFileSizeBytes = 5 * 1024 * 1024; // 5 MB cap on every file sent through this page — image or document

    if ($file['size'] > $maxFileSizeBytes) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File too large (max ' . round($maxFileSizeBytes / 1024 / 1024) . ' MB)']);
        exit;
    }

    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($ext, qr_allowed_extensions(), true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File type .' . $ext . ' is not allowed here — images and documents only.']);
        exit;
    }

    // Confirms the bytes actually look like that format (same magic-byte
    // check upload.php uses) — an extension is just a label, not proof.
    if (!verify_file_format($file['tmp_name'], $ext)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "This file's content doesn't match its .$ext extension, so it was rejected."]);
        exit;
    }

    // Everything about the file checks out — NOW burn the token. This is
    // the single atomic gate: if two requests raced each other for the
    // same token, only one of these calls gets a non-null record back.
    $confirmed = consume_qr_upload_token($t);
    if (!$confirmed) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'expired', 'message' => 'This link has expired or was already used. Ask for a new QR code from the chat.']);
        exit;
    }

    $room = $confirmed['room'];

    // Re-run the same banned/muted checks the issuing session was
    // subject to — the QR was minted while they were in good standing,
    // but a host could have banned/muted them in the ~100 seconds since.
    $config = read_config($room);
    if (!$config) $config = default_config();
    if ($confirmed['role'] === 'member' && is_banned($config, $confirmed['user'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'banned', 'message' => 'This chat member has been removed and can no longer send files.']);
        exit;
    }
    if ($confirmed['role'] !== 'super') {
        $remaining = mute_remaining_ms($config, $confirmed['user']);
        if ($remaining > 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'muted', 'message' => 'This chat member is currently muted and can\'t send files.']);
            exit;
        }
    }

    $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $safeBase = substr($safeBase, 0, 60) ?: 'file';
    $uniqueName = $safeBase . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . ($ext !== '' ? '.' . $ext : '');

    $destDir = room_files_dir($room);
    $destPath = $destDir . '/' . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save file on server']);
        exit;
    }

    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $category = in_array($ext, $imageExts, true) ? 'image' : 'file';

    $fileUrl = 'files/' . rawurlencode($room) . '/' . rawurlencode($uniqueName);
    $replySnippet = $confirmed['replyTo'] > 0 ? build_reply_snippet(find_message($room, $confirmed['replyTo'])) : null;

    $newMsg = append_message($room, [
        'type' => 'file',
        'user' => $confirmed['user'],
        'role' => $confirmed['role'],
        'text' => '',
        'fileName' => $origName,
        'fileUrl' => $fileUrl,
        'fileSize' => $file['size'],
        'fileExt' => $ext,
        'fileCategory' => $category,
        'isVoiceMessage' => false,
        'replyTo' => $replySnippet,
        'reactions' => [],
        'sentWhileEncrypted' => !empty($config['encEnabled']),
        'viaQr' => true, // cosmetic flag only — lets the chat UI label it "sent via QR"
    ]);

    echo json_encode(['ok' => true, 'message' => $newMsg]);
    exit;
}

// ---------------------------------------------------------------------
// GET — render the upload page, but ONLY for a currently-valid token.
// Anything else (no token, unknown token, expired, already used) is
// treated identically to "someone opened this URL directly" and gets
// bounced to the login page, per spec.
// ---------------------------------------------------------------------
if (!rate_limit_allow('qr_upload_get', get_client_ip(), 30, 60)) {
    http_response_code(429);
    header('Content-Type: text/plain');
    echo 'Too many requests — please wait a bit and rescan the code.';
    exit;
}

$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
$rec = peek_qr_upload_token($t);
if (!$rec) {
    header('Location: login.html?msg=' . rawurlencode('That QR link is invalid or has expired. Ask the chat for a new one.'));
    exit;
}

$secondsLeft = max(1, $rec['expires'] - time());
$tSafe = htmlspecialchars($t, ENT_QUOTES);
$roomSafe = htmlspecialchars($rec['room'], ENT_QUOTES);
$acceptAttr = htmlspecialchars(implode(',', array_map(function ($e) { return '.' . $e; }, qr_allowed_extensions())), ENT_QUOTES);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
<title>Send a file — QuickChat Glass</title>
<link rel="stylesheet" href="glass-theme.css">
<link rel="stylesheet" href="glass-app.css">
<style>
  .qr-upload-wrap { max-width: 420px; margin: 0 auto; padding: 28px 20px 40px; }
  .qr-upload-card { text-align: center; }
  .qr-upload-room { opacity: .7; font-size: 13px; margin-bottom: 4px; }
  .qr-upload-timer { font-family: var(--mono, monospace); font-size: 13px; margin: 10px 0 18px; opacity: .85; }
  .qr-upload-timer.low { color: #ff6b6b; }
  .qr-drop { border: 2px dashed var(--tint, #8883); border-radius: 16px; padding: 30px 16px; margin: 16px 0; cursor: pointer; }
  .qr-drop.has-file { border-style: solid; }
  .qr-file-name { font-size: 13px; margin-top: 8px; word-break: break-all; }
  .qr-status { margin-top: 14px; font-size: 14px; min-height: 20px; }
  .qr-status.error { color: #ff6b6b; }
  .qr-status.success { color: #4ade80; }
  .qr-compress-panel { display: none; text-align: left; background: rgba(255,255,255,0.05); border-radius: 12px; padding: 12px 14px; margin: 4px 0 14px; font-size: 13px; }
  .qr-compress-panel.show { display: block; }
  .qr-compress-row { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
  .qr-compress-row label { flex-shrink: 0; opacity: .85; }
  .qr-compress-row input[type="range"] { flex: 1; }
  .qr-compress-row input[type="number"] { width: 70px; border-radius: 8px; border: 1px solid var(--tint, #8884); background: transparent; color: inherit; padding: 4px 6px; }
  .qr-compress-mode { display: flex; gap: 14px; font-size: 12px; opacity: .8; margin-bottom: 2px; }
  .qr-compress-size-note { font-size: 12px; opacity: .7; margin-top: 6px; }
</style>
</head>
<body data-theme="nocturne">
<div class="blob" style="top:-10%; left:-10%;"></div>
<div class="blob b2"></div>
<div class="phone">
  <div class="qr-upload-wrap">
    <div class="glass qr-upload-card" style="padding:24px 18px;border-radius:20px;">
      <div class="qr-upload-room">Sending to room: <strong><?php echo $roomSafe; ?></strong></div>
      <h2 class="fdisp" style="margin:6px 0 2px;">📤 Send a file</h2>
      <div class="qr-upload-timer" id="qrTimer">Expires in <span id="qrSecs"><?php echo $secondsLeft; ?></span>s</div>

      <div class="qr-drop" id="qrDrop">
        <div id="qrDropLabel">Tap to choose a photo or document</div>
        <div class="qr-file-name" id="qrFileName"></div>
      </div>
      <input type="file" id="qrFileInput" accept="<?php echo $acceptAttr; ?>" style="display:none" />

      <div class="qr-compress-panel" id="qrCompressPanel">
        <div class="qr-compress-mode">
          <label><input type="radio" name="qrCompressMode" value="quality" id="qrModeQuality" checked> By quality</label>
          <label><input type="radio" name="qrCompressMode" value="target" id="qrModeTarget"> By target size</label>
        </div>
        <div class="qr-compress-row" id="qrQualityRow">
          <label for="qrQualitySlider">Quality</label>
          <input type="range" id="qrQualitySlider" min="10" max="100" step="5" value="80" />
          <span id="qrQualityLabel">80%</span>
        </div>
        <div class="qr-compress-row" id="qrTargetRow" style="display:none">
          <label for="qrTargetKb">Target size</label>
          <input type="number" id="qrTargetKb" min="20" max="5000" placeholder="e.g. 300" />
          <span>KB</span>
        </div>
        <div class="qr-compress-size-note" id="qrCompressNote">Photos are automatically resized and compressed before sending.</div>
      </div>

      <button class="btn-primary" id="qrSendBtn" style="width:100%" disabled>Send file</button>
      <div class="qr-status" id="qrStatus"></div>
      <div class="hint-text" style="margin-top:14px;">Images, PDFs, and common office documents only, up to 5 MB. This link works once.</div>
    </div>
  </div>
</div>
<script>
(function () {
  var token = <?php echo json_encode($t); ?>;
  var secsLeft = <?php echo (int)$secondsLeft; ?>;
  var timerEl = document.getElementById('qrTimer');
  var secsEl = document.getElementById('qrSecs');
  var drop = document.getElementById('qrDrop');
  var dropLabel = document.getElementById('qrDropLabel');
  var fileInput = document.getElementById('qrFileInput');
  var fileNameEl = document.getElementById('qrFileName');
  var sendBtn = document.getElementById('qrSendBtn');
  var statusEl = document.getElementById('qrStatus');
  var compressPanel = document.getElementById('qrCompressPanel');
  var modeQuality = document.getElementById('qrModeQuality');
  var modeTarget = document.getElementById('qrModeTarget');
  var qualityRow = document.getElementById('qrQualityRow');
  var targetRow = document.getElementById('qrTargetRow');
  var qualitySlider = document.getElementById('qrQualitySlider');
  var qualityLabel = document.getElementById('qrQualityLabel');
  var targetKbInput = document.getElementById('qrTargetKb');
  var compressNote = document.getElementById('qrCompressNote');
  var expired = false;

  // The file that will actually be uploaded when Send is pressed. Starts
  // as whatever was picked; if it's a compressible photo, Send replaces
  // this with the compressed copy and the original is never touched
  // again (no reference to it is kept, so it's just garbage-collected).
  var workingFile = null;
  var isCompressibleImage = false;

  var MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB — applies to every file, image or not
  var MAX_DIMENSION = 1920; // longest edge, in pixels, after resize
  // Only the everyday photo formats get compressed. GIF is skipped so
  // animations aren't flattened to a single frame; documents are never
  // touched at all.
  var COMPRESSIBLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

  function humanSize(bytes) {
    if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    return Math.round(bytes / 1024) + ' KB';
  }

  function setStatus(msg, cls) {
    statusEl.textContent = msg || '';
    statusEl.className = 'qr-status' + (cls ? ' ' + cls : '');
  }

  function tick() {
    if (expired) return;
    secsLeft -= 1;
    if (secsLeft <= 0) {
      expired = true;
      secsEl.textContent = '0';
      timerEl.classList.add('low');
      setStatus('This link has expired.', 'error');
      sendBtn.disabled = true;
      fileInput.disabled = true;
      setTimeout(function () { window.location.href = 'login.html'; }, 2000);
      return;
    }
    secsEl.textContent = String(secsLeft);
    if (secsLeft <= 10) timerEl.classList.add('low');
    setTimeout(tick, 1000);
  }
  setTimeout(tick, 1000);

  modeQuality.addEventListener('change', function () {
    qualityRow.style.display = ''; targetRow.style.display = 'none';
  });
  modeTarget.addEventListener('change', function () {
    qualityRow.style.display = 'none'; targetRow.style.display = '';
  });
  qualitySlider.addEventListener('input', function () {
    qualityLabel.textContent = qualitySlider.value + '%';
  });

  // ---- Compression: resize to MAX_DIMENSION, then re-encode as JPEG ----
  // either at a fixed quality, or by narrowing in on a target file size.
  function loadResizedCanvas(file) {
    var useBitmap = typeof createImageBitmap === 'function';
    var decode = useBitmap
      ? createImageBitmap(file, { imageOrientation: 'from-image' })
      : new Promise(function (resolve, reject) {
          var img = new Image();
          img.onload = function () { resolve(img); };
          img.onerror = reject;
          img.src = URL.createObjectURL(file);
        });
    return decode.then(function (src) {
      var w = src.width, h = src.height;
      var scale = Math.min(1, MAX_DIMENSION / Math.max(w, h));
      var tw = Math.max(1, Math.round(w * scale));
      var th = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = tw; canvas.height = th;
      var ctx = canvas.getContext('2d');
      // JPEG has no alpha channel — without this, transparent areas of a
      // PNG/WebP would flatten to black instead of white when re-encoded.
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, tw, th);
      ctx.drawImage(src, 0, 0, tw, th);
      if (src.close) src.close();
      else if (src.src) URL.revokeObjectURL(src.src);
      return canvas;
    });
  }

  function canvasToBlob(canvas, quality) {
    return new Promise(function (resolve) {
      canvas.toBlob(resolve, 'image/jpeg', quality);
    });
  }

  // Tries a few JPEG quality levels and keeps the highest-quality one
  // that still fits under targetBytes; if none fit even at the lowest
  // quality tried, falls back to whichever was smallest — a close miss
  // beats sending the original untouched.
  function compressToTarget(canvas, targetBytes) {
    var tries = [0.9, 0.75, 0.6, 0.45, 0.3, 0.2, 0.1];
    var withinBudget = null;
    var smallestSeen = null;
    return tries.reduce(function (chain, q) {
      return chain.then(function () {
        return canvasToBlob(canvas, q).then(function (blob) {
          if (!smallestSeen || blob.size < smallestSeen.size) smallestSeen = blob;
          if (blob.size <= targetBytes && (!withinBudget || blob.size > withinBudget.size)) {
            withinBudget = blob;
          }
        });
      });
    }, Promise.resolve()).then(function () {
      return withinBudget || smallestSeen;
    });
  }

  function compressImage(file, opts) {
    return loadResizedCanvas(file).then(function (canvas) {
      if (opts.mode === 'target' && opts.targetKb > 0) {
        return compressToTarget(canvas, opts.targetKb * 1024);
      }
      return canvasToBlob(canvas, opts.quality / 100);
    }).then(function (blob) {
      if (!blob || blob.size >= file.size) return file; // compression didn't actually help — keep the original
      var newName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
      return new File([blob], newName, { type: 'image/jpeg' });
    });
  }

  drop.addEventListener('click', function () { if (!expired) fileInput.click(); });

  fileInput.addEventListener('change', function () {
    var f = fileInput.files && fileInput.files[0];
    if (!f) return;

    if (f.size > MAX_UPLOAD_BYTES) {
      setStatus('That file is ' + humanSize(f.size) + ' — the limit here is 5 MB.', 'error');
      drop.classList.remove('has-file');
      compressPanel.classList.remove('show');
      sendBtn.disabled = true;
      workingFile = null;
      fileInput.value = '';
      return;
    }

    workingFile = f;
    isCompressibleImage = COMPRESSIBLE_TYPES.indexOf(f.type) !== -1;

    dropLabel.textContent = 'Selected:';
    fileNameEl.textContent = f.name + ' (' + humanSize(f.size) + ')';
    drop.classList.add('has-file');
    sendBtn.disabled = false;
    setStatus('');
    compressPanel.classList.toggle('show', isCompressibleImage);
  });

  sendBtn.addEventListener('click', function () {
    if (!workingFile || expired) return;
    sendBtn.disabled = true;
    fileInput.disabled = true;

    var proceed;
    if (isCompressibleImage) {
      setStatus('Compressing photo…');
      var opts = {
        mode: modeTarget.checked ? 'target' : 'quality',
        quality: parseInt(qualitySlider.value, 10),
        targetKb: parseInt(targetKbInput.value, 10) || 0,
      };
      var originalSize = workingFile.size;
      proceed = compressImage(workingFile, opts).then(function (compressed) {
        // From here on `workingFile` IS the compressed copy — the
        // original File object is dropped and never referenced again.
        workingFile = compressed;
        if (compressed.size < originalSize) {
          setStatus('Compressed ' + humanSize(originalSize) + ' → ' + humanSize(compressed.size) + '. Uploading…');
        } else {
          setStatus('Uploading…');
        }
      }).catch(function () {
        // Compression failing is not a reason to block the send —
        // fall back to the originally selected file.
        setStatus('Could not compress — sending original. Uploading…');
      });
    } else {
      setStatus('Uploading…');
      proceed = Promise.resolve();
    }

    proceed.then(function () {
      var fd = new FormData();
      fd.append('t', token);
      fd.append('file', workingFile);

      return fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
        .then(function (r) {
          if (r.data && r.data.ok) {
            expired = true;
            setStatus('✅ Sent! You can close this page.', 'success');
          } else {
            var msg = (r.data && r.data.message) || (r.data && r.data.error) || 'Upload failed.';
            setStatus(msg, 'error');
            if (r.data && r.data.error === 'expired') {
              expired = true;
              setTimeout(function () { window.location.href = 'login.html'; }, 2000);
            } else {
              sendBtn.disabled = false;
              fileInput.disabled = false;
            }
          }
        })
        .catch(function () {
          setStatus('Network error — please try again.', 'error');
          sendBtn.disabled = false;
          fileInput.disabled = false;
        });
    });
  });
})();
</script>
</body>
</html>
