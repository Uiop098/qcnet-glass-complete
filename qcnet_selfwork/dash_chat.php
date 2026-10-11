<?php
require_once __DIR__ . '/dash_firewall.php';
if (!isset($_SESSION['dash_admin']) && !isset($_SESSION['dash_user'])) {
    header('Location: dash_login.html');
    exit;
}
$is_admin = isset($_SESSION['dash_admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chat</title>
<link rel="stylesheet" href="dash_style.css">
<style>
    body { overflow: hidden; }
    .chat-shell { display: flex; flex: 1; margin: 20px 20px 20px 0; gap: 16px; min-height: 0; }
    .chat-list { width: 260px; flex-shrink: 0; display: flex; flex-direction: column; gap: 10px; padding: 16px; overflow-y: auto; }
    .chat-list h4 { margin: 4px 0 8px; font-size: 11px; text-transform: uppercase; color: var(--dash-muted); letter-spacing: 0.06em; }
    .chat-item { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 10px; cursor: pointer; border: 1px solid transparent; }
    .chat-item:hover { background: rgba(255,255,255,0.05); }
    .chat-item.active { background: color-mix(in srgb, var(--dash-accent) 15%, transparent); border-color: var(--dash-accent); }
    .chat-item .ci-icon { width: 34px; height: 34px; border-radius: 10px; background: var(--dash-accent); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .chat-frame-wrap { flex: 1; border-radius: 16px; overflow: hidden; border: 1px solid var(--dash-border); position: relative; min-width: 0; }
    .chat-frame-wrap iframe { width: 100%; height: 100%; border: none; }
    .chat-empty { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--dash-muted); font-size: 14px; text-align: center; padding: 20px; }
    .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:60; align-items:center; justify-content:center; padding:20px; box-sizing:border-box; }
    .modal.active { display:flex; }
    .modal-box { width:100%; max-width:420px; padding:25px; display:flex; flex-direction:column; gap:12px; box-sizing:border-box; }
    .tab-row { display:flex; gap:6px; }
    .tab-row button { flex:1; }
    .tab-row button.active { background:color-mix(in srgb, var(--dash-accent) 20%, transparent); border-color:var(--dash-accent); color:var(--dash-accent); }
    @media (max-width: 768px) {
        .chat-shell { flex-direction: column; margin: 0 12px 12px 12px; }
        .chat-list { width: 100%; flex-direction: row; overflow-x: auto; padding: 10px; }
        .chat-item { flex-direction: column; min-width: 90px; text-align: center; }
        .chat-frame-wrap { flex: 1; min-height: 60vh; }
    }
</style>
</head>
<body>
<div class="blob"></div><div class="blob b2"></div>

<nav class="sidebar glass-panel fade-in">
    <h2 style="margin-top:5px; color:var(--dash-accent);">Chat</h2>
    <button id="backBtn" onclick="goBack()">Back to Dashboard</button>
    <div id="adminUserPicker" style="display:none; margin-top:14px;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--dash-muted); margin-bottom:8px;">Chat with a client</div>
        <select id="userSelect" onchange="openAdminDm(this.value)"><option value="">Select a client...</option></select>
    </div>
    <div style="margin-top:auto;"><hr style="border-color:var(--dash-border);"><button onclick="logout()">Log Out</button></div>
</nav>

<div class="chat-shell fade-in">
    <div class="chat-list glass-panel" id="chatList" style="display:none;">
        <h4>Your Chats</h4>
        <div id="chatItems"></div>
        <button class="primary-btn" style="margin-top:10px;" onclick="openModal('newChatModal')">+ New Chat</button>
    </div>
    <div class="chat-frame-wrap glass-panel" id="frameWrap">
        <div class="chat-empty" id="emptyState">Select a chat to get started.</div>
        <iframe id="chatFrame" style="display:none;"></iframe>
    </div>
</div>

<div class="modal" id="newChatModal"><div class="modal-box glass-panel">
    <h3 style="margin-top:0;">Start a New Chat</h3>
    <div class="tab-row">
        <button id="tab-req" class="active" onclick="switchNcTab('req')">Request a Chat</button>
        <button id="tab-redeem" onclick="switchNcTab('redeem')">Enter a Token</button>
    </div>
    <div id="nc-req">
        <p style="font-size:12px; color:var(--dash-muted); margin-top:0;">Enter another client's username. You'll get a token to share with them - once they enter it, you're both connected.</p>
        <input type="text" id="reqUsername" placeholder="their username">
        <button class="primary-btn" onclick="requestPeerChat()">Generate Token</button>
        <div id="reqMsg" style="font-size:12px;"></div>
    </div>
    <div id="nc-redeem" style="display:none;">
        <p style="font-size:12px; color:var(--dash-muted); margin-top:0;">Enter the token someone shared with you to start chatting.</p>
        <input type="text" id="redeemToken" placeholder="e.g. A1B2C3D4" style="text-transform:uppercase;">
        <button class="primary-btn" onclick="redeemPeerToken()">Connect</button>
        <div id="redeemMsg" style="font-size:12px;"></div>
    </div>
    <button onclick="closeModal('newChatModal')">Close</button>
</div></div>

<script src="dash_script.js"></script>
<script>
var isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;

function goBack() { window.location.href = isAdmin ? 'dash_admin.html' : 'dash_user.html'; }
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function switchNcTab(t) {
    document.getElementById('tab-req').classList.toggle('active', t === 'req');
    document.getElementById('tab-redeem').classList.toggle('active', t === 'redeem');
    document.getElementById('nc-req').style.display = t === 'req' ? '' : 'none';
    document.getElementById('nc-redeem').style.display = t === 'redeem' ? '' : 'none';
}
function logout() { fetch('dash_auth.php?action=logout').then(function(){ window.location.href = 'dash_login.html'; }); }

function loadClientList() {
    fetch('dash_chat_bridge.php?action=get_client_list').then(function(r){ return r.json(); }).then(function(data) {
        if (data.status !== 'success') return;
        var sel = document.getElementById('userSelect');
        (data.clients || []).forEach(function(c) {
            var o = document.createElement('option');
            o.value = c.username;
            o.textContent = c.name + ' (@' + c.username + ')';
            sel.appendChild(o);
        });
    });
}

function openAdminDm(username) {
    if (!username) return;
    fetch('dash_chat_bridge.php?action=get_dm_with_user&username=' + encodeURIComponent(username))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.status !== 'success') { alert(data.error); return; }
            launchChat(data);
        });
}

function loadMyChats() {
    fetch('dash_chat_bridge.php?action=get_my_chats').then(function(r){ return r.json(); }).then(function(data) {
        if (data.status !== 'success') return;
        renderChatList(data.chats);
        if (data.chats.length) openChatRoom(data.chats[0].room, data.chats[0].icon, data.chats[0].label);
    });
}

function renderChatList(chats) {
    var html = '';
    chats.forEach(function(c) {
        html += '<div class="chat-item" data-room="' + c.room + '" onclick="openChatRoom(\'' + c.room + '\',\'' + c.icon + '\',\'' + c.label.replace(/'/g,"\\'") + '\')">' +
                '<div class="ci-icon">' + c.icon + '</div><div>' + c.label + '</div></div>';
    });
    document.getElementById('chatItems').innerHTML = html;
}

var currentRoom = null;
function openChatRoom(room, icon, label) {
    currentRoom = room;
    document.querySelectorAll('.chat-item').forEach(function(el) {
        el.classList.toggle('active', el.dataset.room === room);
    });
    fetch('dash_chat_bridge.php?action=open_chat_session&room=' + encodeURIComponent(room))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.status !== 'success') { alert(data.error); return; }
            launchChat(data);
        });
}

function launchChat(data) {
    sessionStorage.setItem('qc_session', JSON.stringify({ token: data.token, role: data.role, user: data.user, room: data.room }));
    document.getElementById('emptyState').style.display = 'none';
    var frame = document.getElementById('chatFrame');
    frame.style.display = 'block';
    frame.src = 'room.html?embedded=1&_=' + Date.now();
}

function requestPeerChat() {
    var username = document.getElementById('reqUsername').value.trim();
    var msg = document.getElementById('reqMsg');
    if (!username) { msg.style.color = 'var(--dash-danger)'; msg.innerText = 'Enter a username.'; return; }
    fetch('dash_chat_bridge.php?action=request_peer_chat', { method: 'POST', body: new URLSearchParams({ username: username }) })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                msg.style.color = 'var(--dash-success)';
                msg.innerHTML = 'Share this token: <b style="font-family:monospace;font-size:16px;letter-spacing:2px;">' + data.token + '</b>';
            } else {
                msg.style.color = 'var(--dash-danger)'; msg.innerText = data.error;
            }
        });
}

function redeemPeerToken() {
    var token = document.getElementById('redeemToken').value.trim();
    var msg = document.getElementById('redeemMsg');
    fetch('dash_chat_bridge.php?action=redeem_peer_token', { method: 'POST', body: new URLSearchParams({ token: token }) })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                msg.style.color = 'var(--dash-success)'; msg.innerText = 'Connected! Loading chat...';
                setTimeout(function(){ closeModal('newChatModal'); loadMyChats(); }, 800);
            } else {
                msg.style.color = 'var(--dash-danger)'; msg.innerText = data.error;
            }
        });
}

if (isAdmin) {
    document.getElementById('adminUserPicker').style.display = '';
    loadClientList();
} else {
    document.getElementById('chatList').style.display = 'flex';
    loadMyChats();
}
</script>
</body>
</html>
