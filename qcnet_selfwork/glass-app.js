// glass-app.js — shared logic for every page: session storage, API
// calls, and the client-side encryption helpers. Loaded alongside (not
// instead of) glass-theme.js on every page.

const API = {
  auth: 'auth.php', api: 'api.php', rooms: 'rooms.php', admin: 'admin.php',
  kick: 'kick.php', mute: 'mute.php', react: 'react.php', typing: 'typing.php',
  read: 'read.php', edit: 'edit.php', theme: 'theme.php', wallpaperUpload: 'wallpaper_upload.php',
  upload: 'upload.php', download: 'download.php', linkPreview: 'linkpreview.php',
  poll: 'poll.php', sticker: 'sticker.php', gif: 'gif.php', translate: 'translate.php', qrSend: 'qr_send.php', qrJoinSend: 'qr_join_send.php',
  search: 'search.php', export: 'export.php', schedule: 'schedule.php', report: 'report.php',
  clearMine: 'clear_mine.php', dashboard: 'dashboard.php', backup: 'backup.php',
};

// ---------------- Session (per-tab, cleared on logout) ----------------
function getSession() {
  try { return JSON.parse(sessionStorage.getItem('qc_session')); } catch (e) { return null; }
}
function setSession(s) { sessionStorage.setItem('qc_session', JSON.stringify(s)); }
function clearSession() {
  const s = getSession();
  if (s && s.room) sessionStorage.removeItem('qc_enckey_' + s.room);
  sessionStorage.removeItem('qc_session');
}
function requireSession(redirectTo) {
  const s = getSession();
  if (!s || !s.token) { window.location.href = redirectTo || 'login.html'; return null; }
  return s;
}

// Super "master" token, held only between login.html -> rooms.html/create-room.html.
function getMasterToken() {
  return { token: sessionStorage.getItem('qc_master_token'), name: sessionStorage.getItem('qc_master_name') || '', username: sessionStorage.getItem('qc_master_username') || '', tier: sessionStorage.getItem('qc_master_tier') || 'master' };
}
function setMasterToken(token, defaultName, superUsername, tier) {
  sessionStorage.setItem('qc_master_token', token);
  sessionStorage.setItem('qc_master_name', defaultName || '');
  sessionStorage.setItem('qc_master_username', superUsername || '');
  sessionStorage.setItem('qc_master_tier', tier || 'master');
}
function clearMasterToken() {
  sessionStorage.removeItem('qc_master_token');
  sessionStorage.removeItem('qc_master_name');
  sessionStorage.removeItem('qc_master_username');
  sessionStorage.removeItem('qc_master_tier');
}

// "Remember me" — long-lived, survives browser restarts (localStorage).
function getRemember() {
  try { return JSON.parse(localStorage.getItem('qc_remember')); } catch (e) { return null; }
}
function setRemember(rememberToken) {
  if (!rememberToken) return;
  localStorage.setItem('qc_remember', JSON.stringify({ rememberToken }));
}
function clearRemember() { localStorage.removeItem('qc_remember'); }

// ---------------- Fetch helpers ----------------
async function apiPost(url, body) {
  let res;
  try {
    res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  } catch (e) {
    // fetch() itself throws on a dropped connection (phone screen lock,
    // backgrounded tab, brief signal loss, etc.) — without this catch,
    // that becomes an unhandled promise rejection and whatever button
    // triggered it goes silently dead with zero feedback to the person.
    return { res: { ok: false, status: 0 }, data: { ok: false, error: 'network_error', message: 'Could not reach the server — check your connection and try again.' } };
  }
  let data; try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Bad response from server.' }; }
  return { res, data };
}
async function apiGet(url) {
  let res;
  try {
    res = await fetch(url);
  } catch (e) {
    return { res: { ok: false, status: 0 }, data: { ok: false, error: 'network_error', message: 'Could not reach the server — check your connection and try again.' } };
  }
  let data; try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Bad response from server.' }; }
  return { res, data };
}

// Central place that decides what a failed response means for navigation.
// Returns true if it already handled the error (caller should stop).
function handleCommonErrors(res, data) {
  if (res.ok) return false;
  if (data.error === 'invalid_session') { clearSession(); window.location.href = 'login.html?msg=' + encodeURIComponent(data.message || 'Session expired. Please log in again.'); return true; }
  if (data.error === 'banned') { clearSession(); window.location.href = 'states.html?state=banned'; return true; }
  if (data.error === 'rate_limited') { showToast(data.message || 'Too many attempts — please wait a bit.', 'error'); return true; }
  return false;
}

// ---------------- Toasts ----------------
function showToast(message, kind) {
  let area = document.getElementById('toastArea');
  if (!area) {
    area = document.createElement('div');
    area.id = 'toastArea';
    area.className = 'toast-area';
    const phone = document.querySelector('.phone');
    (phone || document.body).prepend(area);
  }
  const el = document.createElement('div');
  el.className = 'toast ' + (kind || 'info');
  el.textContent = message;
  area.appendChild(el);
  setTimeout(() => el.remove(), 4500);
}

// ---------------- Formatting ----------------
function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str == null ? '' : String(str);
  return d.innerHTML;
}
function formatTime(ms) {
  if (!ms) return '';
  return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function formatDate(ms) {
  if (!ms) return '—';
  const d = new Date(ms);
  return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function formatSize(bytes) {
  if (!bytes) return '0 B';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

// ---------------- Encryption (AES-256-GCM, PBKDF2 from the room's passphrase) ----------------
// Envelope format the backend expects: "encv1:" + base64(iv[12 bytes] + ciphertext).
// The server treats everything after "encv1:" as opaque — it never sees the key or plaintext.
function bufToBase64(buf) {
  let binary = '';
  const bytes = new Uint8Array(buf);
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  return btoa(binary);
}
function base64ToBuf(b64) {
  const binary = atob(b64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
}
async function deriveRoomKey(passphrase, saltB64) {
  const enc = new TextEncoder();
  const saltBytes = saltB64 ? base64ToBuf(saltB64) : enc.encode('quickchat-fallback-salt');
  const keyMaterial = await crypto.subtle.importKey('raw', enc.encode(passphrase), { name: 'PBKDF2' }, false, ['deriveKey']);
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt: saltBytes, iterations: 100000, hash: 'SHA-256' },
    keyMaterial, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']
  );
}
async function encryptForRoom(key, plaintext) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const enc = new TextEncoder();
  const cipherBuf = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, enc.encode(plaintext));
  const combined = new Uint8Array(iv.length + cipherBuf.byteLength);
  combined.set(iv, 0); combined.set(new Uint8Array(cipherBuf), iv.length);
  return 'encv1:' + bufToBase64(combined);
}
async function decryptFromRoom(key, envelope) {
  if (!key || !envelope || envelope.indexOf('encv1:') !== 0) return null;
  try {
    const combined = base64ToBuf(envelope.slice(6));
    const iv = combined.slice(0, 12);
    const data = combined.slice(12);
    const dec = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, data);
    return new TextDecoder().decode(dec);
  } catch (e) { return null; }
}
function isEncryptedEnvelope(text) { return typeof text === 'string' && text.indexOf('encv1:') === 0; }

function cacheRoomPassphrase(room, passphrase) { sessionStorage.setItem('qc_enckey_' + room, passphrase); }
function getCachedRoomPassphrase(room) { return sessionStorage.getItem('qc_enckey_' + room); }

// PWA install support — registers once, on whichever page loads first.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => { navigator.serviceWorker.register('sw.js').catch(() => {}); });
}

// ---------------- Customize preferences (compact mode, reduced motion,
// notification sound, desktop notifications) — set from the ☰ drawer on
// any page, applied on every page via body classes / a shared beep. ----
const PREFS_KEY = 'qc_prefs';
function getPrefs() {
  try { return Object.assign({ compact: false, reduceMotion: false, liteMode: false, notifSound: false, desktopNotif: false }, JSON.parse(localStorage.getItem(PREFS_KEY)) || {}); }
  catch (e) { return { compact: false, reduceMotion: false, liteMode: false, notifSound: false, desktopNotif: false }; }
}
function setPrefs(p) { localStorage.setItem(PREFS_KEY, JSON.stringify(p)); applyPrefs(); }
function applyPrefs() {
  const p = getPrefs();
  document.body.classList.toggle('compact-mode', !!p.compact);
  document.body.classList.toggle('reduce-motion', !!p.reduceMotion);
  // Lite mode is a step beyond reduce-motion: it also drops
  // backdrop-filter blur (the single most expensive thing this UI
  // paints) and any custom room wallpaper, leaving just the current
  // theme's flat colours. See the body.lite-mode rules in
  // glass-theme.css, and applyRoomTheme() in room.html for the
  // wallpaper opt-out.
  document.body.classList.toggle('lite-mode', !!p.liteMode);
  if (window.QCOnPrefsChanged) window.QCOnPrefsChanged(p);
}
document.addEventListener('DOMContentLoaded', applyPrefs);

let __beepCtx = null;
function playNotifSound() {
  if (!getPrefs().notifSound) return;
  try {
    __beepCtx = __beepCtx || new (window.AudioContext || window.webkitAudioContext)();
    const osc = __beepCtx.createOscillator(); const gain = __beepCtx.createGain();
    osc.type = 'sine'; osc.frequency.value = 720;
    gain.gain.setValueAtTime(0.0001, __beepCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.12, __beepCtx.currentTime + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, __beepCtx.currentTime + 0.22);
    osc.connect(gain); gain.connect(__beepCtx.destination);
    osc.start(); osc.stop(__beepCtx.currentTime + 0.24);
  } catch (e) {}
}
function maybeNotifyDesktop(title, body) {
  if (!getPrefs().desktopNotif) return;
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  if (document.visibilityState === 'visible') return;
  try { new Notification(title, { body, icon: undefined }); } catch (e) {}
}
async function requestDesktopNotifPermission() {
  if (!('Notification' in window)) return false;
  if (Notification.permission === 'granted') return true;
  const perm = await Notification.requestPermission();
  return perm === 'granted';
}

// ---------------- Per-room draft autosave ----------------
function draftKey(room, user) { return 'qc_draft_' + room + '_' + user; }
function saveDraft(room, user, text) {
  const key = draftKey(room, user);
  if (text) localStorage.setItem(key, text); else localStorage.removeItem(key);
}
function loadDraft(room, user) { return localStorage.getItem(draftKey(room, user)) || ''; }

// ---------------- Offline send queue (per room) ----------------
function queueKey(room) { return 'qc_outbox_' + room; }
function getOutbox(room) { try { return JSON.parse(localStorage.getItem(queueKey(room))) || []; } catch (e) { return []; } }
function setOutbox(room, items) { localStorage.setItem(queueKey(room), JSON.stringify(items)); }
function pushOutbox(room, payload) { const items = getOutbox(room); items.push(payload); setOutbox(room, items); }

// ---------------- Sitewide hover loading-border effect ----------------
// Auto-tags every interactive element with .hover-loop (the actual
// spinning-border CSS lives in glass-app.css, theme-colored via
// --accent so it matches whichever palette is active, Golden Glass
// included). Works on both hover and touch (see glass-app.css).
//
// Batched + idle-scheduled: chat polling adds several message-row nodes
// at once, and tagging each one individually (a querySelectorAll call
// per added node) was adding real overhead to every poll cycle — this
// was the actual cause of the page feeling slower after this feature
// was added. Now all nodes added within one animation frame are
// collected and tagged in a single pass, deferred to idle time so it
// never competes with rendering/scrolling for the main thread.
(function () {
  const SELECTOR = 'button, .file-chip, .file-dl-btn, .code-copy-btn, ' +
    '.theme-switch button, .view-opt, .toggle-switch, a.rc-delete, ' +
    '.mini-btn, .msg-hover-actions button, .reaction-picker button';
  const pending = new Set();
  let scheduled = false;

  function flush() {
    scheduled = false;
    const roots = Array.from(pending);
    pending.clear();
    for (const root of roots) {
      if (!root.isConnected) continue; // skip nodes removed before we got to them
      if (root.matches && root.matches(SELECTOR)) root.classList.add('hover-loop');
      if (root.querySelectorAll) {
        root.querySelectorAll(SELECTOR).forEach((el) => el.classList.add('hover-loop'));
      }
    }
  }
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    if (window.requestIdleCallback) requestIdleCallback(flush, { timeout: 500 });
    else setTimeout(flush, 100);
  }

  document.addEventListener('DOMContentLoaded', () => {
    pending.add(document.body);
    schedule();
    const observer = new MutationObserver((mutations) => {
      for (const m of mutations) {
        m.addedNodes.forEach((node) => {
          if (node.nodeType === 1) pending.add(node);
        });
      }
      schedule();
    });
    observer.observe(document.body, { childList: true, subtree: true });
  });
})();

// ---------------- Loading skeletons ----------------
// Shared helpers for the skeleton system (CSS in glass-theme.css).
// Build a skeleton placeholder, show it while data loads, then swap in
// the real content with a short fade once it's ready.

// Chat message skeleton — a handful of alternating rows mimicking real
// message bubbles, so the chat feed doesn't look like a blank page
// while fetchAll() is still waiting on the first response.
function skeletonMessagesHtml(count) {
  count = count || 6;
  let html = '';
  for (let i = 0; i < count; i++) {
    const side = i % 3 === 0 ? 'mine' : 'theirs';
    const w1 = 50 + Math.floor(Math.random() * 35); // bubble width varies so it doesn't look robotic
    html += `<div class="skel-msg-row ${side}">
      <div class="skel-line skel-meta"></div>
      <div class="skel-block skel-bubble" style="width:${w1}%;"></div>
    </div>`;
  }
  return html;
}

// Room-list skeleton — a handful of card-shaped placeholders.
function skeletonRoomCardsHtml(count) {
  count = count || 4;
  let html = '';
  for (let i = 0; i < count; i++) {
    html += `<div class="skel-room-card">
      <div class="skel-line skel-title"></div>
      <div class="skel-line skel-meta-a"></div>
      <div class="skel-line skel-meta-b"></div>
    </div>`;
  }
  return html;
}

// Replaces a container's skeleton content with real content and fades
// it in. Pass a function that returns the real HTML/DOM — buildFn is
// only called once the swap actually happens, so callers can pass a
// closure that does the real rendering work at swap time.
function skeletonFadeSwap(container, buildFn) {
  if (!container) return;
  container.classList.remove('fade-in-content');
  // Force a reflow so re-adding fade-in-content below restarts the
  // animation even if this container was faded in before (e.g. a
  // manual refresh) — without this the browser can skip re-running an
  // animation on a class that "didn't change".
  void container.offsetWidth;
  buildFn();
  container.classList.add('fade-in-content');
}
