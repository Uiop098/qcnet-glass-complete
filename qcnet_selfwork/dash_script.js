let businessData = { users: {}, payments: [], reminders: [], links: [], products: [], stats: {}, settings: {} };
let currentUserRole = 'user';
let currentUsername = '';

let currentTenantId = 'main';
async function checkAuth() {
    const response = await fetch('dash_auth.php?action=check_auth'); const data = await response.json();
    if (data.status === 'success') {
        currentUserRole = data.role; currentUsername = data.user; currentTenantId = data.tenant_id || 'main';
        if (window.location.href.includes('dash_login.html')) { window.location.href = data.role === 'admin' ? 'dash_admin.html' : 'dash_user.html'; }
        else fetchDashboardData();
    } else {
        if (!window.location.href.includes('dash_login.html') && !window.location.href.includes('pay.html')) window.location.href = 'dash_login.html';
    }
}

async function fetchDashboardData() {
    const response = await fetch('dash_api.php?action=get_dashboard_data'); const data = await response.json();
    if (data.status === 'success') {
        businessData = data;
        renderTopBar();
        if (currentUserRole === 'admin' && currentTenantId === 'main') { const el = document.getElementById('masterControlLink'); if (el) el.style.display = 'block'; }
        if(currentUserRole === 'admin') renderUI('dashboard_home');
        if(currentUserRole === 'user') { renderUserUI('my_payments'); checkReturningProductOrder(); }
    }
}

function renderTopBar() {
    const tb = document.getElementById('top-bar');
    if(!tb) return;
    const userCount = Object.keys(businessData.users || {}).length;
    const displayName = businessData.my_profile?.name || currentUsername;
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
    const dateStr = new Date().toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    tb.innerHTML = `
        <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Menu">☰</button>
        <div>
            <div class="greeting-date">${dateStr}</div>
            <div class="greeting-title">${greeting}, ${displayName}.</div>
        </div>
        ${currentUserRole === 'admin' ? `<div class="stats-badge">👥 ${userCount} Users Registered</div>` : ''}
    `;
}

function toggleSidebar() {
    document.querySelector('.sidebar')?.classList.toggle('open');
    document.querySelector('.sidebar-backdrop')?.classList.toggle('open');
}
function closeSidebar() {
    document.querySelector('.sidebar')?.classList.remove('open');
    document.querySelector('.sidebar-backdrop')?.classList.remove('open');
}
// Ensures a tap-to-close backdrop exists behind the mobile drawer, without
// requiring every page (dash_admin.html, dash_user.html) to remember to add it.
function ensureSidebarBackdrop() {
    if (document.querySelector('.sidebar-backdrop')) return;
    const el = document.createElement('div');
    el.className = 'sidebar-backdrop';
    el.onclick = closeSidebar;
    document.body.appendChild(el);
}
ensureSidebarBackdrop();

// ---- Small inline SVG chart helpers (no external charting library) ----
function renderSparkline(values) {
    if (!values || !values.length) return '';
    const w = 100, h = 36, max = Math.max(...values, 1), min = Math.min(...values, 0);
    const range = (max - min) || 1;
    const step = w / (values.length - 1 || 1);
    const pts = values.map((v, i) => `${(i * step).toFixed(1)},${(h - ((v - min) / range) * h).toFixed(1)}`).join(' ');
    const areaPts = `0,${h} ${pts} ${w},${h}`;
    return `<svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" style="width:100%; height:56px; overflow:visible;">
        <polygon points="${areaPts}" fill="var(--dash-accent)" opacity="0.15"></polygon>
        <polyline points="${pts}" fill="none" stroke="var(--dash-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
    </svg>`;
}
function renderRing(percent) {
    const r = 28, c = 2 * Math.PI * r, offset = c - (Math.min(100, Math.max(0, percent)) / 100) * c;
    return `<svg width="72" height="72" viewBox="0 0 72 72" style="flex-shrink:0;">
        <circle cx="36" cy="36" r="${r}" fill="none" stroke="var(--dash-border)" stroke-width="8"></circle>
        <circle cx="36" cy="36" r="${r}" fill="none" stroke="var(--dash-accent)" stroke-width="8" stroke-linecap="round"
            stroke-dasharray="${c}" stroke-dashoffset="${offset}" transform="rotate(-90 36 36)"></circle>
        <text x="36" y="41" text-anchor="middle" font-size="18" font-weight="700" fill="var(--dash-text)">${percent}</text>
    </svg>`;
}
function timeAgoShort(ts) {
    if (!ts) return '';
    const diff = Math.floor(Date.now() / 1000) - ts;
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
    return new Date(ts * 1000).toLocaleDateString();
}

function renderUI(module) {
    closeSidebar();
    const area = document.getElementById('content-area'); document.getElementById('module-title').innerText = module.toUpperCase().replace('_', ' ');
    
    if (module === 'dashboard_home') {
        const s = businessData.stats || {};
        const trend = businessData.revenue_trend || [];
        const pending = businessData.pending_actions || [];
        const activity = businessData.recent_activity || [];
        area.innerHTML = `
            <div class="overview-row fade-in">
                <div class="overview-card">
                    <div class="card-label">💰 Net Revenue <span style="margin-left:auto; font-size:10px; padding:2px 8px; border-radius:999px; background:color-mix(in srgb, var(--dash-accent) 15%, transparent); color:var(--dash-accent);">Last 14 days</span></div>
                    <div class="card-value">₹${s.total_revenue || 0}</div>
                    <div class="card-sub">${s.received_count || 0} of ${s.total_payments || 0} payments received</div>
                    <div class="sparkline-wrap">${renderSparkline(trend)}</div>
                </div>
                <div class="overview-card">
                    <div class="card-label">📶 Payment Success Rate</div>
                    <div class="ring-wrap">
                        ${renderRing(s.success_rate || 0)}
                        <div class="ring-meta">
                            <div><b>${s.received_count || 0}</b> received</div>
                            <div><b>${(s.total_payments||0) - (s.received_count||0)}</b> pending</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stats-grid fade-in">
                <div class="stat-card"><h3>Total Users</h3><div class="val">${s.total_users || 0}</div></div>
                <div class="stat-card"><h3>Total Revenue</h3><div class="val">₹${s.total_revenue || 0}</div></div>
                <div class="stat-card"><h3>Products</h3><div class="val">${businessData.products.length}</div></div>
                <div class="stat-card"><h3>Open Actions</h3><div class="val">${pending.length}</div></div>
            </div>

            <div class="split-row fade-in">
                <div class="feed-card">
                    <div class="feed-title">Keep the loop moving</div>
                    <h4>Pending Actions</h4>
                    ${pending.length ? pending.map(p => `<div class="feed-item"><div class="fi-icon">⚠️</div><div><div>${p.label}</div><div class="fi-when">${p.tag}</div></div></div>`).join('') : '<p style="font-size:13px; color:var(--dash-muted);">Nothing pending — all caught up.</p>'}
                </div>
                <div class="feed-card">
                    <div class="feed-title">A quiet trail of progress</div>
                    <h4>Recent Activity</h4>
                    ${activity.length ? activity.map(a => `<div class="feed-item"><div class="fi-icon">🕘</div><div><div>${a.summary}</div><div class="fi-when">${timeAgoShort(a.ts)}</div></div></div>`).join('') : '<p style="font-size:13px; color:var(--dash-muted);">No activity recorded yet.</p>'}
                </div>
            </div>
        `;
        return;
    }
    
    if (module === 'settings') {
        let s = businessData.settings || {};
        area.innerHTML = `
            <div class="glass-panel fade-in" style="padding:25px; max-width:500px; display:flex; flex-direction:column; gap:5px;">
                <h3 style="margin:0 0 10px 0;">Payment API Settings</h3>
                <label>Payment Gateway Mode</label>
                <select id="set-mode"><option value="server" ${s.payment_mode==='server'?'selected':''}>Default Server Keys</option><option value="self" ${s.payment_mode==='self'?'selected':''}>Self (My Own Cashfree Keys)</option></select>
                <label>Cashfree App ID</label><input type="text" id="set-appid" value="${s.cf_app_id||''}">
                <label>Cashfree Secret Key</label><input type="password" id="set-secret" placeholder="••••••••">
                <label>Environment</label>
                <select id="set-env"><option value="SANDBOX" ${s.cf_env==='SANDBOX'?'selected':''}>Sandbox (Testing)</option><option value="PROD" ${s.cf_env==='PROD'?'selected':''}>Production</option></select>
                <button onclick="saveSettings()" class="primary-btn" style="margin-top:10px;">🔒 Save Settings</button>
            </div>`;
        return;
    }
    
    if (module === 'products') {
        area.innerHTML = `<div style="margin-bottom:15px;"><button onclick="showAddForm('products')" class="primary-btn">+ Add Membership/Product</button></div>
        <div class="table-container fade-in"><table><tr><th>Product Name</th><th>Price (₹)</th><th>Desc</th></tr>
        ${businessData.products.map(p => `<tr><td data-label="Product"><b>${p.name}</b></td><td data-label="Price">₹${p.price}</td><td data-label="Desc">${p.desc||'--'}</td></tr>`).join('')}
        </table></div>`;
        return;
    }

    let html = `<div style="margin-bottom:15px;"><button onclick="showAddForm('${module}')" class="primary-btn">${module === 'users' ? '+ Invite New User' : '+ Add Record'}</button></div>`;
    html += `<div class="table-container fade-in"><table>`;
    if (module === 'users') {
        html += `<tr><th>User</th><th>Tags</th><th>Actions</th></tr>`;
        Object.keys(businessData.users).forEach(u => {
            html += `<tr><td data-label="User"><b>${businessData.users[u].name||'--'}</b><br><span style="font-size:11px;color:#aaa;">@${u}</span></td>
            <td data-label="Tags"><span style="background:rgba(74,222,128,0.2); padding:3px 8px; border-radius:12px; font-size:11px; color:#4ade80;">${businessData.users[u].tags||'None'}</span></td>
            <td data-label="Actions"><button onclick="deleteUser('${u}')" style="font-size:11px; padding:5px 8px; background:rgba(255,107,107,0.3); color:#ff6b6b;">Del</button></td></tr>`;
        });
    } else if (module === 'payments') {
        html += `<tr><th>Client Username</th><th>Amount</th><th>Status</th></tr>`;
        businessData.payments.forEach(p => html += `<tr><td data-label="Client">@${p.client||'--'}</td><td data-label="Amount">₹${p.amount||'0'}</td><td data-label="Status">${p.status||'--'}</td></tr>`);
    } else if (module === 'reminders') {
        html += `<tr><th>Task</th><th>Priority</th><th>Due Date</th></tr>`;
        businessData.reminders.forEach(r => html += `<tr><td data-label="Task">${r.task||'--'}</td><td data-label="Priority">${r.priority||'--'}</td><td data-label="Due">${r.due||'--'}</td></tr>`);
    } else if (module === 'links') {
        html += `<tr><th>Title</th><th>URL</th></tr>`;
        businessData.links.forEach(l => html += `<tr><td data-label="Title">${l.title||'--'}</td><td data-label="URL"><a href="${l.url}" target="_blank" style="color:#4ade80">${l.url}</a></td></tr>`);
    }
    area.innerHTML = html + `</table></div>`;
}

function showAddForm(module) {
    const area = document.getElementById('content-area');
    let formHtml = `<div id="add-form" class="glass-panel fade-in" style="padding:25px; max-width:400px; display:flex; flex-direction:column; gap:5px;">`;
    if (module === 'users') {
        // Users aren't created directly here — usernames/passwords need
        // server-side hashing, uniqueness checks, and users.json is a keyed
        // object ({username: {...}}), not the flat array the generic
        // add_record/submitForm path assumes for payments/reminders/links.
        // Instead this generates a short-lived invite token (the same
        // create_pending flow QuickChat itself already uses for invites),
        // which the new user redeems at setup_account.html to set their own
        // username & password.
        formHtml += `<label>Contact Name</label><input type="text" name="name" placeholder="Full name" required><label>Date of Birth</label><input type="date" name="dob" required>`;
        formHtml += `<div style="display:flex; gap:10px; margin-top:10px;"><button onclick="createPendingUser()" class="primary-btn" style="flex:1;">Generate Invite Token</button></div></div>`;
        area.innerHTML = formHtml;
        return;
    }
    if (module === 'payments') { 
        let userOpts = Object.keys(businessData.users).map(u => `<option value="${u}">@${u} - ${businessData.users[u].name}</option>`).join('');
        formHtml += `<label>Select Client</label><select name="client">${userOpts}</select><label>Amount</label><input type="number" name="amount" placeholder="0.00"><label>Status</label><select name="status"><option>Pending</option><option>Received</option></select>`; 
    } 
    else if (module === 'products') { formHtml += `<label>Product Name</label><input type="text" name="name" required><label>Price (INR)</label><input type="number" name="price" required><label>Description</label><input type="text" name="desc">`; }
    else if (module === 'reminders') { formHtml += `<label>Task Description</label><input type="text" name="task" placeholder="..."><label>Priority</label><select name="priority"><option>High</option><option>Normal</option></select><label>Due Date</label><input type="date" name="due">`; } 
    else if (module === 'links') { formHtml += `<label>Link Title</label><input type="text" name="title" placeholder="..."><label>URL</label><input type="url" name="url" placeholder="https://...">`; }
    formHtml += `<div style="display:flex; gap:10px; margin-top:10px;"><button onclick="submitForm('${module}')" class="primary-btn" style="flex:1;">Save</button></div></div>`;
    area.innerHTML = formHtml;
}

async function createPendingUser() {
    const nameInput = document.querySelector('#add-form input[name="name"]');
    const dobInput = document.querySelector('#add-form input[name="dob"]');
    if (!nameInput.value.trim() || !dobInput.value) { alert("Name and date of birth are required."); return; }
    const form = new URLSearchParams(); form.append('name', nameInput.value.trim()); form.append('dob', dobInput.value);
    const res = await fetch('dash_auth.php?action=create_pending', { method: 'POST', body: form });
    const data = await res.json();
    if (data.status !== 'success') { alert(data.error || "Failed to generate invite."); return; }
    document.getElementById('content-area').innerHTML = `<div class="glass-panel fade-in" style="padding:25px; max-width:400px;">
        <h3 style="margin-top:0;">✅ Invite Token Created</h3>
        <p style="color:#aaa; font-size:13px;">Share this token with <b>${nameInput.value.trim()}</b>. It expires in 10 minutes and can only be used once, at the account setup page.</p>
        <div style="font-size:24px; letter-spacing:3px; text-align:center; background:rgba(0,0,0,0.2); border-radius:8px; padding:15px 0; margin:15px 0; font-weight:700;">${data.token}</div>
        <div style="display:flex; gap:10px;">
            <button onclick="navigator.clipboard.writeText('${data.token}'); this.innerText='Copied!';" class="primary-btn" style="flex:1;">📋 Copy Token</button>
            <button onclick="renderUI('users')" style="flex:1;">Done</button>
        </div>
    </div>`;
}

async function saveSettings() {
    // Previously this ran the payload through e2ee_encrypt() (just btoa() —
    // not encryption at all) and the server tried to AES-decrypt it as if it
    // were real ciphertext, using an IV derived from the server's own secret
    // key. That mismatch meant this call failed every time ("E2EE Decryption
    // Failed"), and even if it had "worked" it wasn't providing the claimed
    // protection — a client can't meaningfully encrypt something only the
    // server can read without either sharing a secret (which defeats the
    // purpose) or the server exposing its at-rest key to the browser (which
    // would be far worse, since that same key protects every tenant's data).
    // The real protection here is HTTPS in transit plus the server's existing
    // AES-256-CBC encryption at rest (save_secure_table), which already
    // applies correctly to every settings save.
    const form = new URLSearchParams();
    form.append('payment_mode', document.getElementById('set-mode').value);
    form.append('cf_app_id', document.getElementById('set-appid').value);
    form.append('cf_secret_key', document.getElementById('set-secret').value);
    form.append('cf_env', document.getElementById('set-env').value);
    const res = await fetch('dash_api.php?action=save_settings', { method: 'POST', body: form });
    const data = await res.json();
    if (data.status === 'success') { alert("Settings saved."); fetchDashboardData(); } else alert(data.error || "Failed to save settings.");
}

async function submitForm(table) {
    const inputs = document.querySelectorAll('#add-form input, #add-form select'); const formData = new URLSearchParams(); formData.append('table', table);
    inputs.forEach(input => formData.append(input.name, input.value));
    await fetch('dash_api.php?action=add_record', { method: 'POST', body: formData }); fetchDashboardData();
}

async function deleteUser(username) {
    if(confirm(`WARNING: Are you sure you want to permanently delete user @${username}?`)) {
        const form = new URLSearchParams(); form.append('username', username);
        await fetch('dash_api.php?action=delete_user', { method: 'POST', body: form }); fetchDashboardData();
    }
}

function renderUserUI(module) {
    closeSidebar();
    const area = document.getElementById('content-area'); document.getElementById('module-title').innerText = module.toUpperCase().replace('_', ' ');
    if (module === 'products') {
        let html = `<div class="stats-grid fade-in">`;
        businessData.products.forEach(p => {
            html += `<div class="stat-card" style="text-align:left;">
                <h3 style="color:#fff; font-size:18px;">${p.name}</h3><p style="color:#aaa; font-size:12px;">${p.desc||''}</p>
                <div class="val" style="margin-bottom:10px;">₹${p.price}</div>
                <button onclick="buyProduct('${p.id}')" class="primary-btn" style="width:100%;">Buy Now via Cashfree</button>
            </div>`;
        });
        area.innerHTML = html + `</div>`; return;
    }
    if (module === 'profile') { area.innerHTML = `<div class="glass-panel fade-in" style="padding:20px; max-width:400px;"><h3>Profile Details</h3><p><b>Name:</b> ${businessData.my_profile?.name||'--'}</p></div>`; return; }
    if (module === 'my_payments') {
        let html = `<div class="table-container fade-in"><table><tr><th>Amount</th><th>Status</th></tr>`;
        businessData.payments.forEach(p => html += `<tr><td data-label="Amount">₹${p.amount||'0'}</td><td data-label="Status">${p.status||'--'}</td></tr>`);
        area.innerHTML = html + `</table></div>`; return;
    }
    
    let html = `<div class="table-container fade-in"><table>`;
    if (module === 'reminders') {
        html += `<tr><th>Task</th><th>Priority</th><th>Due Date</th></tr>`;
        businessData.reminders.forEach(r => html += `<tr><td data-label="Task">${r.task||'--'}</td><td data-label="Priority">${r.priority||'--'}</td><td data-label="Due">${r.due||'--'}</td></tr>`);
    } else if (module === 'links') {
        html += `<tr><th>Title</th><th>URL</th></tr>`;
        businessData.links.forEach(l => html += `<tr><td data-label="Title">${l.title||'--'}</td><td data-label="URL"><a href="${l.url}" target="_blank" style="color:#4ade80">${l.url}</a></td></tr>`);
    }
    area.innerHTML = html + `</table></div>`;
}

async function buyProduct(pid) {
    const form = new URLSearchParams(); form.append('product_id', pid);
    const res = await fetch('tenant_payment_api.php?action=buy_product', { method: 'POST', body: form });
    const data = await res.json();
    if(data.status === 'success') {
        const cashfree = Cashfree({ mode: data.env === 'PROD' ? 'production' : 'sandbox' });
        cashfree.checkout({ paymentSessionId: data.payment_session_id, redirectTarget: "_self" });
    } else alert(data.error);
}

// Picks up ?order_id= after Cashfree redirects back from a product purchase
// (see tenant_payment_api.php's return_url) and finalizes it — this is what
// actually creates the payments.json record the admin sees; without this,
// the payment succeeds in Cashfree but never shows up anywhere in the app.
async function checkReturningProductOrder() {
    const params = new URLSearchParams(window.location.search);
    const orderId = params.get('order_id');
    if (!orderId) return;
    window.history.replaceState({}, '', window.location.pathname); // clean the URL
    try {
        const res = await fetch('tenant_payment_api.php?action=verify_product_order&order_id=' + encodeURIComponent(orderId));
        const data = await res.json();
        if (data.status === 'success') alert(data.already_credited ? `"${data.product_name}" was already confirmed.` : `Payment received — "${data.product_name}" purchased for ₹${data.amount}.`);
        else if (data.error) alert('Payment status: ' + data.error);
    } catch (e) { /* silent — user can still see status in My Payments */ }
}

function showForgotModal() { document.getElementById('forgotModal').classList.add('active'); }
function hideForgotModal() { document.getElementById('forgotModal').classList.remove('active'); }

async function sendOTP() {
    const email = document.getElementById('f-email').value; const dob = document.getElementById('f-dob').value;
    const form = new URLSearchParams(); form.append('email', email); form.append('dob', dob);
    const res = await fetch('dash_auth.php?action=forgot_password_step1', { method: 'POST', body: form });
    const data = await res.json();
    if(data.status === 'success') {
        alert(data.message);
        document.getElementById('step1').style.display='none'; document.getElementById('step2').style.display='flex';
    } else alert(data.error);
}

async function verifyOTP() {
    const email = document.getElementById('f-email').value; const code = document.getElementById('f-code').value;
    const form = new URLSearchParams(); form.append('email', email); form.append('code', code);
    const res = await fetch('dash_auth.php?action=forgot_password_step2', { method: 'POST', body: form });
    const data = await res.json();
    if(data.status === 'success') {
        const sel = document.getElementById('f-user-sel'); sel.innerHTML = data.accounts.map(u => `<option value="${u}">@${u}</option>`).join('');
        document.getElementById('step2').style.display='none'; document.getElementById('step3').style.display='flex';
    } else alert(data.error);
}

async function resetPass() {
    const email = document.getElementById('f-email').value; const code = document.getElementById('f-code').value;
    const user = document.getElementById('f-user-sel').value; const pass = document.getElementById('f-newpass').value;
    const form = new URLSearchParams(); form.append('email', email); form.append('code', code); form.append('username', user); form.append('new_password', pass);
    const res = await fetch('dash_auth.php?action=forgot_password_step3', { method: 'POST', body: form });
    const data = await res.json();
    if(data.status === 'success') { alert(data.message); window.location.reload(); } else alert(data.error);
}

async function submitLogin() {
    const role = document.getElementById('login-role').value; const user = document.getElementById('login-user').value;
    const pass = document.getElementById('login-pass').value; const msg = document.getElementById('login-msg');
    const form = new URLSearchParams(); form.append('username', user); form.append('password', pass);
    const endpoint = role === 'admin' ? 'admin_login' : 'user_login';
    try {
        const res = await fetch('dash_auth.php?action=' + endpoint, { method: 'POST', body: form }); const data = await res.json();
        if (data.status === 'success') { window.location.href = role === 'admin' ? 'dash_admin.html' : 'dash_user.html'; } 
        else if (data.status === '2fa_required') { show2faPrompt(data.pending_token, role); }
        else { msg.style.color = '#ff6b6b'; msg.innerText = data.error; }
    } catch (e) { msg.innerText = 'Connection error.'; }
}
function show2faPrompt(pendingToken, role) {
    const container = document.querySelector('.login-container');
    container.innerHTML = `
        <h2 style="margin-top:0; text-align:center;">🔐 Two-Factor Code</h2>
        <p style="font-size:13px; color:#ccc; text-align:center;">Enter the 6-digit code from your authenticator app (or a recovery code).</p>
        <input type="text" id="tfa-login-code" placeholder="6-digit code" maxlength="6" style="letter-spacing:3px; text-align:center;">
        <button class="primary-btn" onclick="submit2faLogin('${pendingToken}', '${role}')">Verify</button>
        <div id="tfa-login-msg" style="text-align:center; font-size:13px; font-weight:bold;"></div>
        <a href="dash_login.html" style="color:#a89bc2; text-align:center; font-size:12px; text-decoration:none;">← Start over</a>`;
}
async function submit2faLogin(pendingToken, role) {
    const code = document.getElementById('tfa-login-code').value.trim();
    const msg = document.getElementById('tfa-login-msg');
    const form = new URLSearchParams(); form.append('pending_token', pendingToken); form.append('code', code);
    try {
        const res = await fetch('dash_auth.php?action=verify_2fa_login', { method: 'POST', body: form }); const data = await res.json();
        if (data.status === 'success') { window.location.href = role === 'admin' ? 'dash_admin.html' : 'dash_user.html'; }
        else { msg.style.color = '#ff6b6b'; msg.innerText = data.error; }
    } catch (e) { msg.innerText = 'Connection error.'; }
}
async function logout() { await fetch('dash_auth.php?action=logout'); window.location.href = 'dash_login.html'; }
async function generateToken() {
    const name = prompt("Enter User's Name:"); if (!name) return; const dob = prompt("Enter User's Date of Birth (YYYY-MM-DD):"); if (!dob) return;
    const form = new URLSearchParams(); form.append('name', name); form.append('dob', dob);
    try {
        const res = await fetch('dash_auth.php?action=create_pending', { method: 'POST', body: form }); const data = await res.json();
        if (data.status === 'success') {
            try { await navigator.clipboard.writeText(data.token); alert(`Token Generated & Copied! Token: ${data.token} Expires in: 10 mins`); } 
            catch(err) { prompt("Copy your token (Expires in 10 mins):", data.token); }
        } else alert(data.error);
    } catch(e) { alert("Error generating token."); }
}

// ============ Hosted Theme System (JSON-driven, server-editable) ============
// Reads data_dash/themes.json (via dash_theme_api.php, always fresh — no
// caching) and applies whichever theme is selected as CSS custom properties
// on :root. A brand-new theme added to that file server-side (with its own
// wallpaper) just works on next page load — no code or CSS changes needed.
let hostedThemeManifest = null;

async function loadHostedThemes() {
    try {
        const res = await fetch('dash_theme_api.php?action=get_themes');
        if (!res.ok) return null;
        hostedThemeManifest = await res.json();
        return hostedThemeManifest;
    } catch (e) { return null; }
}

function findTheme(manifest, id) {
    if (!manifest || !manifest.themes) return null;
    return manifest.themes.find(t => t.id === id) || null;
}

function applyThemeObject(theme) {
    if (!theme) return;
    const root = document.documentElement.style;
    const c = theme.colors || {};
    if (c.background) root.setProperty('--dash-bg', c.background);
    if (c.surface) root.setProperty('--dash-surface', c.surface);
    if (c.accent) root.setProperty('--dash-accent', c.accent);
    if (c.accentStrong) root.setProperty('--dash-accent-strong', c.accentStrong);
    if (c.success) root.setProperty('--dash-success', c.success);
    if (c.danger) root.setProperty('--dash-danger', c.danger);
    if (c.text) root.setProperty('--dash-text', c.text);
    if (c.muted) root.setProperty('--dash-muted', c.muted);
    if (c.border) root.setProperty('--dash-border', c.border);
    const wp = theme.wallpaper;
    if (wp) {
        if (wp.type === 'image' && wp.url) root.setProperty('--dash-wallpaper', `url("${wp.url}")`);
        else if (wp.value) root.setProperty('--dash-wallpaper', wp.value);
    }
}

// Personal choice (this browser only) beats the site-wide server default —
// matches "users pick their own, admin sets what new visitors see."
async function initHostedTheme() {
    const manifest = await loadHostedThemes();
    if (!manifest) return; // falls back to the static defaults already in dash_style.css
    const chosen = localStorage.getItem('dash_theme_id');
    const theme = findTheme(manifest, chosen) || findTheme(manifest, manifest.defaultTheme) || manifest.themes[0];
    if (theme) { applyThemeObject(theme); localStorage.setItem('dash_theme_id', theme.id); }
    applyLayoutPrefs();
}

function selectHostedTheme(id) {
    const theme = findTheme(hostedThemeManifest, id);
    if (!theme) return;
    applyThemeObject(theme);
    localStorage.setItem('dash_theme_id', id);
}

// ---- Mobile/desktop sizing: Auto / Desktop / Mobile + density slider ----
// "Auto" (default) just lets the existing @media queries in dash_style.css
// do their normal responsive thing. "Desktop"/"Mobile" force that layout
// regardless of screen size (body[data-force-layout] overrides in the
// stylesheet); the density slider scales spacing/radius via --dash-scale
// and the base gap unit via --dash-gap, for manual fine-tuning on top.
function applyLayoutPrefs() {
    const mode = localStorage.getItem('dash_layout_mode') || 'auto';
    const density = parseInt(localStorage.getItem('dash_density') || '74', 10);
    document.body.setAttribute('data-force-layout', mode === 'auto' ? '' : mode);
    const scale = 0.82 + (density / 100) * 0.36; // ~0.82x - 1.18x
    const gap = 10 + Math.round((density / 100) * 12); // 10px - 22px
    document.documentElement.style.setProperty('--dash-scale', scale.toFixed(2));
    document.documentElement.style.setProperty('--dash-gap', gap + 'px');
}
function setLayoutMode(mode) { localStorage.setItem('dash_layout_mode', mode); applyLayoutPrefs(); }
function setDensity(value) { localStorage.setItem('dash_density', value); applyLayoutPrefs(); }

if (!document.querySelector('.blob') && document.body) {
    document.body.insertAdjacentHTML('afterbegin', '<div class="blob"></div><div class="blob b2"></div>');
}
initHostedTheme();

// Only auto-run the SPA's own auth-check-and-render flow on pages that
// actually have the #content-area shell it renders into (dash_admin.html,
// dash_user.html), or on the login page itself (which needs the "already
// logged in? redirect straight to the dashboard" behavior inside
// checkAuth(), even though it has no #content-area to render into).
// Other standalone pages (dash_profile.html, dash_settings.html,
// dash_theme_studio.html) still load this file for the shared theme system
// and helpers, but do their own auth checks — this would otherwise
// overwrite their #top-bar and error out trying to render into a
// #content-area that doesn't exist on those pages.
if (document.getElementById('content-area') || window.location.href.includes('dash_login.html')) { checkAuth(); }

// ---------------- Sitewide hover loading-border effect ----------------
// Dashboard-side equivalent of QuickChat's auto-tagger (see the matching
// block at the end of glass-app.js). Batched + idle-scheduled — see the
// comment there for why (this was the actual cause of the page feeling
// slower after this feature was added: tagging every added node
// individually during dynamic re-renders added real overhead).
(function () {
  const SELECTOR = 'button, a.mini-btn, .settings-btn, .toggle-switch, ' +
    '.rc-delete, .rc-btns button, .chat-item, .view-opt, .theme-switch button';
  const pending = new Set();
  let scheduled = false;

  function flush() {
    scheduled = false;
    const roots = Array.from(pending);
    pending.clear();
    for (const root of roots) {
      if (!root.isConnected) continue;
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
