'use strict';
/* ============================================================================
   QUICKCHAT CODE BOX — application script
   Sections: icons · seed project · storage · state · render · explorer ·
   tabs · editor · layout · activity bar · statusbar · dialogs/context-menu ·
   search/command-palette · notifications · AI assistant · keyboard · init
============================================================================ */

/* ---------------------------- DOM helpers ---------------------------- */
const $  = (sel, root) => (root||document).querySelector(sel);
const $$ = (sel, root) => Array.from((root||document).querySelectorAll(sel));
const el = (tag, cls, html) => { const e=document.createElement(tag); if(cls) e.className=cls; if(html!=null) e.innerHTML=html; return e; };
const uid = () => Math.random().toString(36).slice(2,9);
const esc = s => String(s==null?'':s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; };

/* ---------------------------- Icon set (hand-built inline SVG) ---------------------------- */
const svgWrap = (inner, extra) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${extra&&extra.sw||1.8}" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;
const ICONS = {
  grid: svgWrap('<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'),
  layoutH: svgWrap('<rect x="3" y="3" width="18" height="7" rx="1.5"/><rect x="3" y="14" width="18" height="7" rx="1.5"/>'),
  layoutV: svgWrap('<rect x="3" y="3" width="7" height="18" rx="1.5"/><rect x="14" y="3" width="7" height="18" rx="1.5"/>'),
  arrowLeft: svgWrap('<path d="M19 12H5"/><path d="M11 18l-6-6 6-6"/>'),
  arrowRight: svgWrap('<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>'),
  search: svgWrap('<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>'),
  sparkle: svgWrap('<path d="M12 3l1.8 5.4L19 10l-5.2 1.6L12 17l-1.8-5.4L5 10l5.2-1.6L12 3z"/>', {sw:1.6}),
  x: svgWrap('<path d="M6 6l12 12M18 6L6 18"/>'),
  chevronRight: svgWrap('<path d="M9 6l6 6-6 6"/>'),
  chevronDown: svgWrap('<path d="M6 9l6 6 6-6"/>'),
  more: svgWrap('<circle cx="5" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.4" fill="currentColor" stroke="none"/>'),
  minus: svgWrap('<path d="M5 12h14"/>'),
  folder: svgWrap('<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>'),
  folderOpen: svgWrap('<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2H7a2 2 0 00-1.9 1.4L3 17V7z"/><path d="M3.5 17l2-6.6A2 2 0 017.4 9H21l-2.4 8H5.6a2 2 0 01-1.9-1.4z"/>'),
  fileGeneric: svgWrap('<path d="M6 2h8l5 5v14a1 1 0 01-1 1H6a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M14 2v5h5"/>'),
  fileCode: svgWrap('<path d="M6 2h8l5 5v14a1 1 0 01-1 1H6a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M14 2v5h5"/><path d="M9.5 13l-1.7 1.7L9.5 16.4"/><path d="M13 13l1.7 1.7-1.7 1.7"/>'),
  fileCss: svgWrap('<path d="M6 2h8l5 5v14a1 1 0 01-1 1H6a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M14 2v5h5"/><path d="M9 12l-1 5.5 4 1.4 4-1.4-.7-4"/>'),
  fileJson: svgWrap('<path d="M6 2h8l5 5v14a1 1 0 01-1 1H6a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M14 2v5h5"/><path d="M9 12c-1 0-1.2.6-1.2 1.4v1.2c0 .8-.4 1.4-1.3 1.4.9 0 1.3.6 1.3 1.4v1.2c0 .8.2 1.4 1.2 1.4"/><path d="M15 12c1 0 1.2.6 1.2 1.4v1.2c0 .8.4 1.4 1.3 1.4-.9 0-1.3.6-1.3 1.4v1.2c0 .8-.2 1.4-1.2 1.4"/>'),
  fileMd: svgWrap('<path d="M6 2h8l5 5v14a1 1 0 01-1 1H6a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M14 2v5h5"/><path d="M8 17v-4l1.8 2 1.8-2v4"/><path d="M14.5 13v4l1.7-1.9"/>'),
  gitBranch: svgWrap('<circle cx="6" cy="6" r="2.2"/><circle cx="6" cy="18" r="2.2"/><circle cx="18" cy="6" r="2.2"/><path d="M6 8.2V15.8"/><path d="M18 8.2c0 5-6 5.5-10 6.5"/>'),
  zap: svgWrap('<path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/>'),
  puzzle: svgWrap('<path d="M9 3h4a1.5 1.5 0 010 3h-1v3h3a1.5 1.5 0 100-3v-1a1.5 1.5 0 013 0v4a1.5 1.5 0 010 3v4a1 1 0 01-1 1h-4a1.5 1.5 0 000-3h-1v-3h-3v1a1.5 1.5 0 01-3 0v-1a1.5 1.5 0 00-3 0h-1a1 1 0 01-1-1v-4a1.5 1.5 0 000-3V9a1 1 0 011-1h4V5a1.5 1.5 0 013 0z"/>', {sw:1.4}),
  settings: svgWrap('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.9 2.9l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.6V21a2 2 0 01-4 0v-.1a1.7 1.7 0 00-1-1.6 1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.9-2.9l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.6-1H3a2 2 0 010-4h.1a1.7 1.7 0 001.6-1 1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.9-2.9l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.6V3a2 2 0 014 0v.1a1.7 1.7 0 001 1.6 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.9 2.9l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.6 1H21a2 2 0 010 4h-.1a1.7 1.7 0 00-1.6 1z"/>', {sw:1.4}),
  plus: svgWrap('<path d="M12 5v14M5 12h14"/>'),
  check: svgWrap('<path d="M20 6L9 17l-5-5"/>'),
  checkCircle: svgWrap('<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.3 2.3 4.7-5.6"/>'),
  alertCircle: svgWrap('<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/>'),
  alertTriangle: svgWrap('<path d="M12 3.5L2 20h20L12 3.5z"/><path d="M12 10v4"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/>'),
  infoCircle: svgWrap('<circle cx="12" cy="12" r="9"/><path d="M12 16v-4"/><circle cx="12" cy="8" r="1" fill="currentColor" stroke="none"/>'),
  send: svgWrap('<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>'),
  paperclip: svgWrap('<path d="M21 12.5l-8.5 8.5a4.5 4.5 0 01-6.4-6.4L14.6 6a3 3 0 014.3 4.2L10.4 18a1.5 1.5 0 01-2.1-2.1l7.1-7.1"/>'),
  copy: svgWrap('<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 01-1-1V4a1 1 0 011-1h10a1 1 0 011 1v1"/>'),
  trash: svgWrap('<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13"/><path d="M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/>'),
  edit: svgWrap('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>'),
  play: svgWrap('<path d="M6 4l14 8-14 8V4z"/>'),
  terminal: svgWrap('<path d="M4 5h16v14H4z"/><path d="M7 9l3 3-3 3"/><path d="M12 15h5"/>'),
  refresh: svgWrap('<path d="M21 12a9 9 0 11-3-6.7"/><path d="M21 4v6h-6"/>'),
  code: svgWrap('<path d="M8 6l-5.5 6L8 18"/><path d="M16 6l5.5 6L16 18"/>'),
  history: svgWrap('<path d="M3 12a9 9 0 109-9"/><path d="M3 4v6h6"/><path d="M12 8v5l3 2"/>'),
  bell: svgWrap('<path d="M18 8a6 6 0 10-12 0c0 6-2 7-2 7h16s-2-1-2-7z"/><path d="M10 20a2 2 0 004 0"/>'),
  duplicate: svgWrap('<rect x="7" y="7" width="13" height="13" rx="2"/><path d="M4 15V5a2 2 0 012-2h10"/>'),
  pin: svgWrap('<path d="M12 17v5"/><path d="M8 13.5V6a1 1 0 011-1h6a1 1 0 011 1v7.5l2 2.5H6l2-2.5z"/>'),
};
function icon(name, cls){ const s=el('span', cls||''); s.innerHTML=ICONS[name]||''; return s; }
function setIcon(elm, name){ if(elm) elm.innerHTML = ICONS[name]||''; }

/* ---------------------------- Seed project (virtual FS) ---------------------------- */
const APP_CSS = `:root {
  --primary-green: #667a60;
  --dark-bg: #2a2b2a;
  --light-text: #f4f2ea;
  --button-pink: #c98fa5;
  --font-tiempos: 'Tiempos', serif;
  --font-styrene: 'Styrene', sans-serif;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: var(--font-styrene);
  color: var(--light-text);
  background-color: var(--dark-bg);
  min-height: 100vh;
}

/* Menu Page Styles */
.menu-page, .confirmation-page {
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem;
}

.menu-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 0;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}

.menu-item .price {
  font-family: var(--font-tiempos);
  color: var(--primary-green);
}

.order-button {
  background: var(--button-pink);
  color: var(--dark-bg);
  border: none;
  border-radius: 8px;
  padding: 0.6rem 1.2rem;
  font-weight: 600;
  cursor: pointer;
}
`;

const APP_TSX = `import { useState } from 'react';
import './App.css';
import { formatDate, translateKey, getCurrencySymbol } from './src/utils/localizationUtils';

const LOCALE = 'fr-CA';

export default function App() {
  const [items] = useState([
    { id: 1, name: 'poutine_classic', price: 12.5 },
    { id: 2, name: 'tourtiere', price: 15 },
  ]);

  return (
    <div className="menu-page">
      <h1>{translateKey('menu.title', LOCALE)}</h1>
      <p>{formatDate(new Date(), LOCALE)}</p>
      {items.map(item => (
        <div className="menu-item" key={item.id}>
          <span>{translateKey('item.' + item.name, LOCALE)}</span>
          <span className="price">{getCurrencySymbol(LOCALE)}{item.price.toFixed(2)}</span>
        </div>
      ))}
    </div>
  );
}
`;

const LOCALIZATION_UTILS = `// Localization helpers for Quebec French (fr-CA) compliant menus.

export function formatDate(date, locale = 'en-US') {
  return new Intl.DateTimeFormat(locale, {
    year: 'numeric', month: 'long', day: 'numeric',
  }).format(date);
}

const DICTIONARY = {
  'fr-CA': {
    'menu.title': 'Notre menu',
    'item.poutine_classic': 'Poutine classique',
    'item.tourtiere': 'Tourtière du Lac-Saint-Jean',
  },
  'en-US': {
    'menu.title': 'Our menu',
    'item.poutine_classic': 'Classic poutine',
    'item.tourtiere': 'Lac-Saint-Jean meat pie',
  },
};

export function translateKey(key, locale = 'en-US') {
  return (DICTIONARY[locale] && DICTIONARY[locale][key]) || key;
}

export function getCurrencySymbol(locale = 'en-US') {
  return locale === 'fr-CA' ? '$' : '$';
}
`;

const QA_MD = `# QA Agent

Runs after every change to \`src/**\`.

1. Lint + typecheck the touched files.
2. Verify localized strings have both \`fr-CA\` and \`en-US\` entries.
3. Report failures as file:line, never silently skip a check.
`;
const QUEBEC_MD = `# Quebec French Compliance Agent

Ensures all customer-facing copy meets Charter of the French Language
requirements for Quebec storefronts.

- French text must be at least as prominent as any other language.
- Currency, date and address formats must follow fr-CA conventions.
- Flag any hardcoded English string in components under review.
`;
const PLAYWRIGHT_MD = `# Visual Testing (Playwright) Agent

Captures before/after screenshots for any component change and
diffs them against the baseline in \`playwright-traces/\`.

Fails the run on >0.1% pixel drift unless explicitly approved.
`;
const SETTINGS_JSON = `{
  "permissions": {
    "allow": ["Edit", "Read", "Write"]
  },
  "defaultAgent": "qa"
}
`;

function seedProject(){
  return {
    name: 'QUICKCHAT CODE BOX', type: 'folder', path: '', expanded: true,
    children: [
      { name:'.claude', type:'folder', path:'.claude', children:[] },
      { name:'agents', type:'folder', path:'agents', children:[
        { name:'qa.md', type:'file', path:'agents/qa.md', git:'U', content: QA_MD },
        { name:'quebec-french-compliant.md', type:'file', path:'agents/quebec-french-compliant.md', git:'U', content: QUEBEC_MD },
        { name:'visual-testing-playwright.md', type:'file', path:'agents/visual-testing-playwright.md', git:'U', content: PLAYWRIGHT_MD },
      ]},
      { name:'checkpoints', type:'folder', path:'checkpoints', children:[] },
      { name:'settings.local.json', type:'file', path:'settings.local.json', content: SETTINGS_JSON },
      { name:'dist', type:'folder', path:'dist', children:[] },
      { name:'node_modules', type:'folder', path:'node_modules', children:[] },
      { name:'playwright-traces', type:'folder', path:'playwright-traces', children:[
        { name:'menu-page.png.trace', type:'file', path:'playwright-traces/menu-page.png.trace', git:'U', content:'binary trace — open in Playwright viewer' },
      ]},
      { name:'public', type:'folder', path:'public', children:[] },
      { name:'src', type:'folder', path:'src', children:[
        { name:'assets', type:'folder', path:'src/assets', children:[] },
        { name:'components', type:'folder', path:'src/components', children:[] },
        { name:'data', type:'folder', path:'src/data', children:[] },
        { name:'pages', type:'folder', path:'src/pages', children:[] },
        { name:'types', type:'folder', path:'src/types', children:[] },
        { name:'utils', type:'folder', path:'src/utils', children:[
          { name:'localizationUtils.ts', type:'file', path:'src/utils/localizationUtils.ts', content: LOCALIZATION_UTILS },
        ]},
      ]},
      { name:'App.css', type:'file', path:'App.css', content: APP_CSS },
      { name:'App.tsx', type:'file', path:'App.tsx', content: APP_TSX },
    ]
  };
}

/* ---------------------------- Persistent storage (PHP backend, data/projects/<token>) ---------------------------- */
const API_URL = 'api.php';
function getUrlParam(name){ return new URLSearchParams(location.search).get(name); }
function cbToken(){
  let t = getUrlParam('p') || localStorage.getItem('cb-token');
  return t || null;
}
function setCbToken(t){
  localStorage.setItem('cb-token', t);
  const url = new URL(location.href); url.searchParams.set('p', t);
  history.replaceState(null, '', url.toString());
}
async function api(action, data, method){
  method = method || 'POST';
  const opts = { method, headers:{'Content-Type':'application/json'} };
  if(method === 'POST') opts.body = JSON.stringify({ action, ...data });
  const url = method === 'GET' ? `${API_URL}?action=${encodeURIComponent(action)}&${new URLSearchParams(data||{})}` : API_URL;
  const res = await fetch(url, opts);
  const json = await res.json();
  if(!json.ok) throw new Error(json.error || 'Request failed');
  return json;
}
async function ensureToken(){
  let t = cbToken();
  if(t) return t;
  try{
    const r = await api('create_project', {});
    setCbToken(r.token);
    return r.token;
  }catch(e){ console.warn('Could not reach backend, working locally only.', e); return null; }
}
const persistProject = debounce(() => saveState(), 500);
const persistSettings = debounce(() => saveState(), 400);
const persistWorkspace = debounce(() => saveState(), 400);
async function saveState(){
  const token = await ensureToken();
  const bundle = {
    project: PROJECT, settings: S.settings,
    workspace: { openTabs: S.tabs.map(t=>t.path), activeTab: S.activeTab, expanded:[...S.expanded] }
  };
  localStorage.setItem('cb-state-cache', JSON.stringify(bundle)); // instant local fallback
  if(!token){ setSyncBadge('offline'); return; }
  try{ await api('save_state', { token, state: bundle }); setSyncBadge('synced'); }
  catch(e){ console.warn('save_state failed', e); setSyncBadge('offline'); }
}
async function loadState(){
  const token = await ensureToken();
  if(token){
    try{ const r = await api('load_state', { token }); setSyncBadge('synced'); return r.state || null; }
    catch(e){ console.warn('load_state failed, trying local cache', e); setSyncBadge('offline'); }
  }
  try{ const cached = localStorage.getItem('cb-state-cache'); return cached ? JSON.parse(cached) : null; }
  catch(e){ return null; }
}
function setSyncBadge(status){
  const el2 = document.getElementById('sbSync'); if(!el2) return;
  el2.className = 'sb-item sb-sync sb-sync-' + status;
  el2.title = status === 'synced' ? 'Saved to server' : 'Working offline — changes are only saved on this device';
  el2.querySelector('span').textContent = status === 'synced' ? 'Synced' : 'Offline';
}

/* ---------------------------- Global state ---------------------------- */
let PROJECT = seedProject();
const S = {
  tabs: [],              // {path, pinned}
  activeTab: null,
  expanded: new Set(['']),
  selectedPath: null,
  history: [], historyIndex: -1,
  navigating: false,
  activityView: 'explorer',
  aiContext: [],
  aiBusy: false,
  aiHistory: [],          // [{role:'user'|'assistant', text}]
  settings: {
    askBeforeEdit: true, fontSize: 13, tabSize: 2, wordWrap: false, minimap: false, theme: 'dark-glass',
    panelWidths: { ai: 320, explorer: 260 }, layout: 'default'
  },
  problems: { errors: 0, warnings: 0 },
  git: { branch: 'main', dirty: true },
};

function findNode(path, node){
  node = node || PROJECT;
  if(node.path === path) return node;
  if(node.children){ for(const c of node.children){ const r = findNode(path, c); if(r) return r; } }
  return null;
}
function findParent(path, node, parent){
  node = node || PROJECT; parent = parent || null;
  if(node.path === path) return parent;
  if(node.children){ for(const c of node.children){ const r = findParent(path, c, node); if(r!==undefined && r!==null) return r; } }
  return null;
}
function flattenFiles(node, out){
  node = node || PROJECT; out = out || [];
  if(node.children){ for(const c of node.children){ if(c.type==='file') out.push(c); else flattenFiles(c, out); } }
  return out;
}
function extOf(path){ const m=/\.([a-z0-9]+)$/i.exec(path||''); return m ? m[1].toLowerCase() : ''; }
function fileIconFor(path){
  const e = extOf(path);
  if(['css'].includes(e)) return 'fileCss';
  if(['json'].includes(e)) return 'fileJson';
  if(['md','markdown'].includes(e)) return 'fileMd';
  if(['js','jsx','ts','tsx','py','php','java','c','cpp','h','sql','sh'].includes(e)) return 'fileCode';
  return 'fileGeneric';
}
function mimeFor(path){
  const e = extOf(path);
  const map = { html:'text/html', htm:'text/html', css:'text/css', js:'text/javascript', mjs:'text/javascript',
    jsx:'text/jsx', ts:'application/typescript', tsx:'text/typescript-jsx', json:'application/json',
    md:'text/markdown', markdown:'text/markdown', php:'application/x-httpd-php', py:'text/x-python',
    c:'text/x-csrc', h:'text/x-csrc', cpp:'text/x-c++src', hpp:'text/x-c++src', cc:'text/x-c++src',
    java:'text/x-java', sql:'text/x-sql', sh:'text/x-sh', bash:'text/x-sh', yml:'text/x-yaml', yaml:'text/x-yaml' };
  return map[e] || 'text/plain';
}
function langLabel(path){
  const e = extOf(path);
  const map = { html:'HTML', css:'CSS', js:'JavaScript', jsx:'JavaScript JSX', ts:'TypeScript', tsx:'TypeScript JSX',
    json:'JSON', md:'Markdown', php:'PHP', py:'Python', c:'C', cpp:'C++', java:'Java', sql:'SQL', sh:'Shell Script',
    yml:'YAML', yaml:'YAML', trace:'Binary' };
  return map[e] || 'Plain Text';
}

/* ============================================================================
   NOTIFICATIONS
============================================================================ */
const notifIcons = { success:'checkCircle', error:'alertCircle', warning:'alertTriangle', info:'infoCircle' };
function notify(type, title, message, timeout){
  const host = $('#notifications');
  const t = el('div', `toast t-${type}`);
  t.innerHTML = `<div class="ti">${ICONS[notifIcons[type]||'infoCircle']}</div>
    <div class="tb"><div class="tt">${esc(title)}</div>${message?`<div class="tm">${esc(message)}</div>`:''}</div>
    <div class="tx">${ICONS.x}</div>`;
  $('.tx', t).addEventListener('click', () => t.remove());
  host.appendChild(t);
  setTimeout(() => { if(t.parentNode) t.remove(); }, timeout || 4600);
}

/* ============================================================================
   TOOLTIPS
============================================================================ */
(function initTooltips(){
  const tip = $('#tooltip');
  let showTimer;
  document.addEventListener('mouseover', e => {
    const target = e.target.closest('[data-tip]');
    if(!target) return;
    clearTimeout(showTimer);
    showTimer = setTimeout(() => {
      const r = target.getBoundingClientRect();
      tip.textContent = target.getAttribute('data-tip');
      tip.style.left = Math.min(window.innerWidth-140, r.left + r.width/2) + 'px';
      tip.style.top = (r.bottom + 8) + 'px';
      tip.style.transform = 'translateX(-50%)';
      tip.classList.add('show');
    }, 420);
  });
  document.addEventListener('mouseout', e => {
    if(e.target.closest('[data-tip]')){ clearTimeout(showTimer); tip.classList.remove('show'); }
  });
  document.addEventListener('mousedown', () => tip.classList.remove('show'));
})();

/* ============================================================================
   DIALOGS (modal) + CONTEXT MENU
============================================================================ */
function openModal({ title, bodyHTML, wide, buttons, onMount }){
  const overlay = el('div', 'modal-overlay');
  overlay.innerHTML = `<div class="modal-box${wide?' wide':''}">
      <div class="modal-head"><h3>${esc(title)}</h3><button class="icon-btn" id="modalCloseX">${ICONS.x}</button></div>
      <div class="modal-body">${bodyHTML||''}</div>
      <div class="modal-foot"></div>
    </div>`;
  $('#dialogs').appendChild(overlay);
  const foot = $('.modal-foot', overlay);
  (buttons||[]).forEach(b => {
    const btn = el('button', 'btn' + (b.primary?' btn-primary':'') + (b.danger?' btn-danger':''), esc(b.label));
    btn.addEventListener('click', () => b.onClick && b.onClick(close));
    foot.appendChild(btn);
  });
  function close(){ overlay.classList.remove('show'); setTimeout(()=>overlay.remove(), 200); }
  $('#modalCloseX', overlay).addEventListener('click', close);
  overlay.addEventListener('mousedown', e => { if(e.target===overlay) close(); });
  requestAnimationFrame(() => overlay.classList.add('show'));
  if(onMount) onMount(overlay, close);
  return { overlay, close };
}

function promptModal({ title, label, value, placeholder, confirmLabel, onConfirm }){
  const { close } = openModal({
    title,
    bodyHTML: `<label style="display:block;font-size:11.5px;color:var(--text-dim);margin-bottom:6px;">${esc(label||'')}</label>
      <input type="text" id="promptInput" value="${esc(value||'')}" placeholder="${esc(placeholder||'')}" />`,
    buttons: [
      { label:'Cancel', onClick:(c)=>c() },
      { label: confirmLabel||'Create', primary:true, onClick:(c)=>{ const v=$('#promptInput').value.trim(); if(v){ onConfirm(v); } c(); } }
    ],
    onMount:(overlay)=>{
      const inp = $('#promptInput', overlay); inp.focus(); inp.select();
      inp.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); const v=inp.value.trim(); if(v){ onConfirm(v); } close(); } });
    }
  });
}

function confirmModal({ title, message, confirmLabel, danger, onConfirm }){
  openModal({
    title,
    bodyHTML: `<div>${esc(message)}</div>`,
    buttons: [
      { label:'Cancel', onClick:(c)=>c() },
      { label: confirmLabel||'Delete', danger: danger!==false, primary: danger===false, onClick:(c)=>{ onConfirm(); c(); } }
    ]
  });
}

let activeCtxMenu = null;
function closeCtxMenu(){ if(activeCtxMenu){ activeCtxMenu.remove(); activeCtxMenu=null; } }
function openContextMenu(x, y, items){
  closeCtxMenu();
  const menu = el('div', 'ctxmenu show');
  items.forEach(it => {
    if(it === '-'){ menu.appendChild(el('div','ctxmenu-sep')); return; }
    const row = el('div', 'ctxmenu-item' + (it.danger?' danger':''));
    row.innerHTML = `${it.icon?ICONS[it.icon]:''}<span>${esc(it.label)}</span>`;
    row.addEventListener('click', () => { closeCtxMenu(); it.onClick && it.onClick(); });
    menu.appendChild(row);
  });
  $('#contextMenus').appendChild(menu);
  const mw = 200, mh = items.length*32+10;
  menu.style.left = Math.min(x, window.innerWidth-mw-8) + 'px';
  menu.style.top = Math.min(y, window.innerHeight-mh-8) + 'px';
  activeCtxMenu = menu;
}
document.addEventListener('mousedown', e => { if(!e.target.closest('.ctxmenu')) closeCtxMenu(); });
document.addEventListener('scroll', closeCtxMenu, true);

/* ============================================================================
   FILE EXPLORER
============================================================================ */
function collectAllFolderPaths(node, out){ node=node||PROJECT; out=out||[]; if(node.type==='folder'){ out.push(node.path); (node.children||[]).forEach(c=>collectAllFolderPaths(c,out)); } return out; }

function renderExplorer(){
  const body = $('#explorerBody');
  body.innerHTML = '';
  const root = el('div', 'project-root');
  root.innerHTML = `${ICONS.folder}<span>${esc(PROJECT.name)}</span>`;
  body.appendChild(root);
  const list = el('div');
  (PROJECT.children||[]).forEach(child => list.appendChild(renderTreeNode(child, 0)));
  body.appendChild(list);
}

function nodeHasGitChange(node){
  if(node.type==='file') return !!node.git;
  return (node.children||[]).some(nodeHasGitChange);
}

function renderTreeNode(node, depth){
  const wrap = el('div', 'tree-node');
  const row = el('div', 'tree-row' + (S.selectedPath===node.path ? ' selected':''));
  row.setAttribute('draggable','true');
  row.dataset.path = node.path;
  const isFolder = node.type === 'folder';
  const isOpen = S.expanded.has(node.path);
  row.innerHTML = `
    <span class="tree-chevron ${isFolder ? (isOpen?'open':'') : 'leaf'}">${ICONS.chevronRight}</span>
    <span class="tree-icon">${isFolder ? ICONS[isOpen?'folderOpen':'folder'] : ICONS[fileIconFor(node.name)]}</span>
    <span class="tree-name">${esc(node.name)}</span>
    ${node.type==='file' && node.git ? `<span class="tree-git git-${node.git}">${node.git}</span>` : ''}
    ${isFolder && !isOpen && nodeHasGitChange(node) ? `<span class="folder-dot"></span>` : ''}
  `;
  row.addEventListener('click', () => {
    if(isFolder) toggleFolder(node.path); else openFile(node.path);
    S.selectedPath = node.path; renderExplorer();
  });
  row.addEventListener('contextmenu', e => { e.preventDefault(); showFileContextMenu(e, node); });
  row.style.paddingLeft = (4 + depth*0) + 'px';
  wrap.appendChild(row);

  if(isFolder && isOpen){
    const kids = el('div', 'tree-children');
    (node.children||[]).forEach(c => kids.appendChild(renderTreeNode(c, depth+1)));
    if(!node.children || !node.children.length){
      const empty = el('div', 'tree-row'); empty.style.color='var(--text-dim)'; empty.style.cursor='default';
      empty.innerHTML = `<span class="tree-chevron leaf"></span><span class="tree-icon"></span><span class="tree-name">— empty —</span>`;
      kids.appendChild(empty);
    }
    wrap.appendChild(kids);
  }
  return wrap;
}

function toggleFolder(path){
  if(S.expanded.has(path)) S.expanded.delete(path); else S.expanded.add(path);
  renderExplorer(); persistWorkspace();
}

function showFileContextMenu(e, node){
  S.selectedPath = node.path; renderExplorer();
  const items = [];
  if(node.type==='file'){
    items.push({ label:'Open', icon:'fileGeneric', onClick:()=>openFile(node.path) });
    items.push('-');
    items.push({ label:'Rename', icon:'edit', onClick:()=>renameNode(node) });
    items.push({ label:'Duplicate', icon:'duplicate', onClick:()=>duplicateNode(node) });
    items.push({ label:'Delete', icon:'trash', danger:true, onClick:()=>deleteNode(node) });
    items.push('-');
    items.push({ label:'Copy Path', icon:'copy', onClick:()=>copyPath(node.path) });
    items.push({ label:'Add to AI Context', icon:'sparkle', onClick:()=>addContext(node.path) });
  } else {
    items.push({ label:'New File', icon:'plus', onClick:()=>createNode(node, 'file') });
    items.push({ label:'New Folder', icon:'folder', onClick:()=>createNode(node, 'folder') });
    items.push('-');
    if(node.path){
      items.push({ label:'Rename', icon:'edit', onClick:()=>renameNode(node) });
      items.push({ label:'Delete', icon:'trash', danger:true, onClick:()=>deleteNode(node) });
      items.push('-');
    }
    items.push({ label:'Copy Path', icon:'copy', onClick:()=>copyPath(node.path||'/') });
    items.push({ label:'Add Folder to AI Context', icon:'sparkle', onClick:()=>addContext(node.path, true) });
    items.push({ label:'Refresh', icon:'refresh', onClick:()=>{ renderExplorer(); notify('info','Explorer refreshed'); } });
  }
  openContextMenu(e.clientX, e.clientY, items);
}

function copyPath(path){
  const text = path || '/';
  if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(text).catch(()=>{}); }
  notify('success', 'Path copied', text);
}

function createNode(parent, type){
  promptModal({
    title: type==='file' ? 'New File' : 'New Folder',
    label: `Name (inside ${parent.path || '/'})`,
    placeholder: type==='file' ? 'example.ts' : 'folder-name',
    confirmLabel: 'Create',
    onConfirm: (name) => {
      const path = parent.path ? `${parent.path}/${name}` : name;
      if(findNode(path)){ notify('error','Already exists', path); return; }
      const node = type==='file'
        ? { name, type:'file', path, git:'A', content:'' }
        : { name, type:'folder', path, children:[] };
      parent.children = parent.children || [];
      parent.children.push(node);
      S.expanded.add(parent.path);
      persistProject(); renderExplorer(); updateGitBadge();
      if(type==='file'){ openFile(path); }
      notify('success', `${type==='file'?'File':'Folder'} created`, path);
    }
  });
}

function renameNode(node){
  promptModal({
    title:'Rename', label:'New name', value: node.name, confirmLabel:'Rename',
    onConfirm:(name) => {
      const oldPath = node.path;
      const parentPath = oldPath.includes('/') ? oldPath.slice(0, oldPath.lastIndexOf('/')) : '';
      const newPath = parentPath ? `${parentPath}/${name}` : name;
      renamePathsRecursive(node, oldPath, newPath);
      node.name = name;
      if(node.git !== 'A') node.git = 'R';
      S.tabs.forEach(t => { if(t.path===oldPath){ t.path=newPath; } });
      if(S.activeTab===oldPath) S.activeTab = newPath;
      persistProject(); renderExplorer(); renderTabs(); renderBreadcrumbs(); updateGitBadge();
      notify('success','Renamed', `${oldPath} → ${newPath}`);
    }
  });
}
function renamePathsRecursive(node, oldPrefix, newPrefix){
  node.path = node.path.replace(oldPrefix, newPrefix);
  (node.children||[]).forEach(c => renamePathsRecursive(c, oldPrefix, newPrefix));
}

function duplicateNode(node){
  const parentPath = node.path.includes('/') ? node.path.slice(0, node.path.lastIndexOf('/')) : '';
  const parent = findNode(parentPath) || PROJECT;
  const base = node.name.replace(/(\.[^.]+)?$/, ' copy$1');
  const path = parentPath ? `${parentPath}/${base}` : base;
  const copy = { name: base, type:'file', path, git:'A', content: node.content };
  parent.children.push(copy);
  persistProject(); renderExplorer(); updateGitBadge();
  notify('success','File duplicated', path);
}

function deleteNode(node){
  confirmModal({
    title:'Delete', message:`Delete "${node.name}"? This cannot be undone.`, confirmLabel:'Delete',
    onConfirm: () => {
      const parentPath = node.path.includes('/') ? node.path.slice(0, node.path.lastIndexOf('/')) : '';
      const parent = findNode(parentPath) || PROJECT;
      parent.children = parent.children.filter(c => c !== node);
      closeTab(node.path, {silent:true});
      persistProject(); renderExplorer(); updateGitBadge();
      notify('success','Deleted', node.path);
    }
  });
}

$('#btnExplorerCollapseAll').addEventListener('click', () => {
  S.expanded = new Set(['']); renderExplorer(); notify('info','All folders collapsed');
});
$('#btnExplorerMore').addEventListener('click', (e) => {
  openContextMenu(e.clientX, e.clientY-140, [
    { label:'New File at root', icon:'plus', onClick:()=>createNode(PROJECT,'file') },
    { label:'New Folder at root', icon:'folder', onClick:()=>createNode(PROJECT,'folder') },
    { label:'Expand All', icon:'chevronDown', onClick:()=>{ S.expanded = new Set(collectAllFolderPaths()); renderExplorer(); } },
    { label:'Collapse All', icon:'minus', onClick:()=>{ S.expanded = new Set(['']); renderExplorer(); } },
  ]);
});

/* ============================================================================
   EDITOR (CodeMirror)
============================================================================ */
let CM = null;
function initEditor(){
  CM = CodeMirror.fromTextArea($('#cmSource'), {
    lineNumbers: true, theme:'default', tabSize: S.settings.tabSize, indentUnit: S.settings.tabSize,
    lineWrapping: S.settings.wordWrap, matchBrackets:true, autoCloseBrackets:true, styleActiveLine:true,
    foldGutter:true, gutters:['CodeMirror-linenumbers','CodeMirror-foldgutter'],
    extraKeys:{
      'Ctrl-Space':'autocomplete', 'Cmd-Space':'autocomplete',
      'Ctrl-/':'toggleComment', 'Cmd-/':'toggleComment',
      'Ctrl-F':'findPersistent', 'Cmd-F':'findPersistent',
      'Ctrl-H':'replace', 'Cmd-Alt-F':'replace',
      'Ctrl-G': () => goToLine(),
    }
  });
  CM.on('change', () => {
    if(S.suppressChange) return;
    const path = S.activeTab; if(!path) return;
    const node = findNode(path); if(!node) return;
    const content = CM.getValue();
    if(content !== node._savedContent){ markTabDirty(path, true); } else { markTabDirty(path, false); }
    node.content = content;
  });
  CM.on('cursorActivity', updateStatusBar);
  document.documentElement.style.setProperty('--editor-fs', S.settings.fontSize+'px');
}

function goToLine(){
  promptModal({ title:'Go to Line', label:'Line number', placeholder:'e.g. 42', confirmLabel:'Go',
    onConfirm:(v)=>{ const n=parseInt(v,10); if(n>0){ CM.setCursor({line:n-1, ch:0}); CM.scrollIntoView(null, 100); CM.focus(); } } });
}

function openFile(path){
  const node = findNode(path);
  if(!node || node.type !== 'file') return;
  if(!S.tabs.find(t => t.path === path)){ S.tabs.push({ path, pinned:false }); }
  setActiveTab(path);
  pushHistory(path);
}

function setActiveTab(path){
  S.activeTab = path;
  const node = findNode(path);
  $('#emptyEditor').classList.toggle('show', !node);
  if(node){
    node._savedContent = node._savedContent!=null ? node._savedContent : node.content;
    S.suppressChange = true;
    CM.setValue(node.content || '');
    S.suppressChange = false;
    CM.setOption('mode', mimeFor(path));
    CM.focus();
    if(node.content === node._savedContent) markTabDirty(path, false);
  }
  renderTabs(); renderBreadcrumbs(); updateStatusBar(); persistWorkspace();
}

function markTabDirty(path, dirty){
  const t = S.tabs.find(t => t.path===path);
  if(t){ t.dirty = dirty; renderTabs(); }
}

function saveActiveFile(){
  const path = S.activeTab; if(!path) return;
  const node = findNode(path); if(!node) return;
  node.content = CM.getValue();
  node._savedContent = node.content;
  if(!node.git) node.git = 'M'; else if(node.git!=='A' && node.git!=='U') node.git='M';
  markTabDirty(path, false);
  persistProject(); renderExplorer(); updateGitBadge(); updateStatusBar();
  notify('success', 'File saved', path);
}
function saveAllFiles(){
  let count = 0;
  S.tabs.forEach(t => { if(t.dirty){ const node=findNode(t.path); if(node){ node.content = (t.path===S.activeTab)?CM.getValue():node.content; node._savedContent=node.content; if(!node.git) node.git='M'; t.dirty=false; count++; } } });
  persistProject(); renderExplorer(); renderTabs(); updateGitBadge();
  notify('success', 'Saved all files', `${count} file(s) updated`);
}

function renderBreadcrumbs(){
  const bc = $('#breadcrumbs');
  if(!S.activeTab){ bc.innerHTML=''; return; }
  const parts = [PROJECT.name, ...S.activeTab.split('/')];
  bc.innerHTML = parts.map((p,i) => `<span class="crumb">${esc(p)}</span>` + (i<parts.length-1 ? ICONS.chevronRight : '')).join('');
}

/* ============================================================================
   TABS
============================================================================ */
function renderTabs(){
  const bar = $('#tabBar');
  bar.innerHTML = '';
  S.tabs.forEach(t => {
    const node = findNode(t.path);
    const tab = el('div', 'tab' + (t.path===S.activeTab ? ' active':''));
    tab.draggable = true; tab.dataset.path = t.path;
    tab.innerHTML = `
      ${t.pinned ? `<span class="tab-icon tab-pin">${ICONS.pin}</span>` : `<span class="tab-icon">${ICONS[fileIconFor(t.path)]}</span>`}
      <span class="tab-name">${esc(node ? node.name : t.path)}</span>
      ${t.dirty ? '<span class="tab-dirty"></span>' : `<span class="tab-close">${ICONS.x}</span>`}
    `;
    tab.addEventListener('click', () => setActiveTab(t.path));
    tab.addEventListener('mousedown', e => { if(e.button===1){ e.preventDefault(); closeTab(t.path); } });
    const closeEl = $('.tab-close', tab);
    if(closeEl) closeEl.addEventListener('click', e => { e.stopPropagation(); closeTab(t.path); });
    tab.addEventListener('contextmenu', e => { e.preventDefault(); showTabContextMenu(e, t); });
    tab.addEventListener('dragstart', () => tab.classList.add('dragging'));
    tab.addEventListener('dragend', () => { tab.classList.remove('dragging'); reorderTabsFromDOM(); });
    tab.addEventListener('dragover', e => {
      e.preventDefault();
      const dragging = $('.tab.dragging', bar);
      if(!dragging || dragging===tab) return;
      const rect = tab.getBoundingClientRect();
      const before = (e.clientX - rect.left) < rect.width/2;
      bar.insertBefore(dragging, before ? tab : tab.nextSibling);
    });
    bar.appendChild(tab);
  });
  const newBtn = el('div', 'tab-newbtn'); newBtn.innerHTML = ICONS.plus;
  newBtn.setAttribute('data-tip','New file');
  newBtn.addEventListener('click', () => createNode(PROJECT, 'file'));
  bar.appendChild(newBtn);
}
function reorderTabsFromDOM(){
  const order = $$('.tab', $('#tabBar')).map(t => t.dataset.path);
  S.tabs.sort((a,b) => order.indexOf(a.path) - order.indexOf(b.path));
  persistWorkspace();
}
function closeTab(path, opts){
  const idx = S.tabs.findIndex(t => t.path===path);
  if(idx===-1) return;
  const t = S.tabs[idx];
  if(t.dirty && !(opts&&opts.silent)){
    confirmModal({ title:'Unsaved changes', message:`"${path}" has unsaved changes. Close without saving?`, confirmLabel:'Close without saving',
      onConfirm:()=> reallyCloseTab(idx) });
    return;
  }
  reallyCloseTab(idx);
}
function reallyCloseTab(idx){
  const wasActive = S.tabs[idx].path === S.activeTab;
  S.tabs.splice(idx,1);
  if(wasActive){
    const next = S.tabs[idx] || S.tabs[idx-1];
    if(next) setActiveTab(next.path); else { S.activeTab=null; $('#emptyEditor').classList.add('show'); renderTabs(); renderBreadcrumbs(); updateStatusBar(); }
  } else renderTabs();
  persistWorkspace();
}
function closeOthers(path){ S.tabs = S.tabs.filter(t => t.path===path || t.pinned); setActiveTab(path); }
function closeAllTabs(){ S.tabs = S.tabs.filter(t=>t.pinned); if(!S.tabs.find(t=>t.path===S.activeTab)){ S.activeTab=null; $('#emptyEditor').classList.add('show'); } renderTabs(); renderBreadcrumbs(); updateStatusBar(); persistWorkspace(); }
function closeToRight(path){ const idx=S.tabs.findIndex(t=>t.path===path); S.tabs = S.tabs.filter((t,i)=> i<=idx || t.pinned); if(!S.tabs.find(t=>t.path===S.activeTab)) setActiveTab(path); else renderTabs(); persistWorkspace(); }

function showTabContextMenu(e, t){
  openContextMenu(e.clientX, e.clientY, [
    { label:'Close', icon:'x', onClick:()=>closeTab(t.path) },
    { label:'Close Others', icon:'x', onClick:()=>closeOthers(t.path) },
    { label:'Close All', icon:'x', onClick:()=>closeAllTabs() },
    { label:'Close to the Right', icon:'x', onClick:()=>closeToRight(t.path) },
    '-',
    { label: t.pinned ? 'Unpin' : 'Pin', icon:'pin', onClick:()=>{ t.pinned=!t.pinned; renderTabs(); } },
    { label:'Copy Path', icon:'copy', onClick:()=>copyPath(t.path) },
  ]);
}

/* Navigation history (back / forward across opened files) */
function pushHistory(path){
  if(S.navigating) return;
  S.history = S.history.slice(0, S.historyIndex+1);
  if(S.history[S.history.length-1] !== path) S.history.push(path);
  S.historyIndex = S.history.length-1;
  updateNavButtons();
}
function navBack(){ if(S.historyIndex>0){ S.historyIndex--; S.navigating=true; openFile(S.history[S.historyIndex]); S.navigating=false; updateNavButtons(); } }
function navFwd(){ if(S.historyIndex < S.history.length-1){ S.historyIndex++; S.navigating=true; openFile(S.history[S.historyIndex]); S.navigating=false; updateNavButtons(); } }
function updateNavButtons(){ $('#btnNavBack').disabled = S.historyIndex<=0; $('#btnNavFwd').disabled = S.historyIndex>=S.history.length-1; }

/* ============================================================================
   ACTIVITY BAR + SIDE PANEL VIEWS
============================================================================ */
const ACTIVITY_ITEMS = [
  { id:'explorer', label:'Explorer', icon:'duplicate' },
  { id:'search', label:'Search', icon:'search' },
  { id:'git', label:'Source Control', icon:'gitBranch', badgeKey:'gitChanges' },
  { id:'run', label:'Run / Debug', icon:'zap' },
  { id:'extensions', label:'Extensions', icon:'puzzle', badgeKey:'extUpdates' },
  { id:'settings', label:'Settings', icon:'settings' },
];
function renderActivityBar(){
  const bar = $('#activityBar');
  bar.innerHTML = '';
  ACTIVITY_ITEMS.forEach((item, i) => {
    if(i===3){ const sp = el('div','flex-spacer'); bar.appendChild(sp); }
    const btn = el('div', 'act-btn' + (S.activityView===item.id ? ' active':''));
    btn.dataset.tip = item.label; btn.setAttribute('data-tip', item.label);
    btn.dataset.viewId = item.id;
    btn.innerHTML = ICONS[item.icon];
    if(item.id==='git'){ const n = countGitChanges(); if(n>0) btn.innerHTML += `<span class="act-badge">${n>99?'99+':n}</span>`; }
    if(item.id==='extensions'){ btn.innerHTML += `<span class="act-badge">1</span>`; }
    // setActivityView() only flips the .active class on existing buttons
    // (see below) rather than calling back into this function — tearing
    // down and rebuilding the very button a touch is mid-click on was a
    // real source of flaky/inconsistent taps on some mobile browsers.
    btn.addEventListener('click', () => setActivityView(item.id));
    bar.appendChild(btn);
  });
}
function updateActivityBarActiveState(){
  $$('#activityBar .act-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.viewId === S.activityView));
}
function countGitChanges(node, count){ node=node||PROJECT; count=count||0;
  (node.children||[]).forEach(c => { if(c.type==='file'){ if(c.git) count++; } else count = countGitChanges(c, count); });
  return count;
}
function setActivityView(view){
  S.activityView = view;
  updateActivityBarActiveState();
  const titleMap = { explorer:'File Explorer', search:'Search', git:'Source Control', run:'Run & Debug', extensions:'Extensions', settings:'Settings' };
  $('#explorerPanelTitle').textContent = titleMap[view];
  const body = $('#explorerBody');
  body.innerHTML = '';
  // Open the drawer on mobile FIRST — this used to sit after a set of early
  // `return`s below and could never run, which is why tapping activity-bar
  // icons on a phone rendered the panel but never actually showed it.
  if(isMobileLayout()){
    openMobileDrawer('explorerPanel');
  }
  if(view==='explorer'){ renderExplorer(); return; }
  if(view==='search'){ renderSearchPanel(body); return; }
  if(view==='git'){ renderGitPanel(body); return; }
  if(view==='run'){ renderRunPanel(body); return; }
  if(view==='extensions'){ renderExtensionsPanel(body); return; }
  if(view==='settings'){ renderSettingsPanel(body); return; }
}

function renderSearchPanel(body){
  const block = el('div','side-panel-block');
  block.innerHTML = `
    <h4>Global Search</h4>
    <input class="sp-input" id="gsQuery" type="text" placeholder="Search in project…">
    <div class="sp-check-row"><input type="checkbox" id="gsCase"> Match case</div>
    <div class="sp-check-row"><input type="checkbox" id="gsWord"> Whole word</div>
    <div class="sp-check-row"><input type="checkbox" id="gsRegex"> Regex</div>
    <h4 id="gsCount">0 results</h4>
    <div id="gsResults"></div>`;
  body.appendChild(block);
  const run = debounce(() => {
    const q = $('#gsQuery').value;
    const results = q ? globalSearch(q, { caseSensitive:$('#gsCase').checked, wholeWord:$('#gsWord').checked, regex:$('#gsRegex').checked }) : [];
    $('#gsCount').textContent = `${results.length} result${results.length!==1?'s':''}`;
    const host = $('#gsResults'); host.innerHTML='';
    results.slice(0,200).forEach(r => {
      const item = el('div','sp-result-item');
      item.innerHTML = `<div class="sp-result-path">${esc(r.path)}:${r.line}</div><div class="sp-result-line">${r.html}</div>`;
      item.addEventListener('click', () => { openFile(r.path); setTimeout(()=>{ CM.setCursor({line:r.line-1, ch:r.col||0}); CM.scrollIntoView(null,100); }, 30); });
      host.appendChild(item);
    });
  }, 180);
  $('#gsQuery').addEventListener('input', run);
  $('#gsCase').addEventListener('change', run);
  $('#gsWord').addEventListener('change', run);
  $('#gsRegex').addEventListener('change', run);
}
function globalSearch(query, opts){
  const files = flattenFiles();
  const out = [];
  let re;
  try{
    if(opts.regex) re = new RegExp(query, opts.caseSensitive?'g':'gi');
    else{ const escQ = query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); re = new RegExp(opts.wholeWord?`\\b${escQ}\\b`:escQ, opts.caseSensitive?'g':'gi'); }
  }catch(e){ return []; }
  files.forEach(f => {
    const lines = (f.content||'').split('\n');
    lines.forEach((line, i) => {
      re.lastIndex = 0;
      const m = re.exec(line);
      if(m){ out.push({ path:f.path, line:i+1, col:m.index, html: esc(line.trim()).replace(esc(m[0]), `<mark>${esc(m[0])}</mark>`) }); }
    });
  });
  return out;
}

function renderGitPanel(body){
  const changed = [];
  (function walk(n){ (n.children||[]).forEach(c => { if(c.type==='file' && c.git) changed.push(c); else walk(c); }); })(PROJECT);
  const block = el('div','side-panel-block');
  block.innerHTML = `
    <h4>Branch</h4>
    <div style="display:flex;align-items:center;gap:7px;color:var(--text-primary);font-weight:600;">${ICONS.gitBranch}<span>${esc(S.git.branch)}</span></div>
    <h4>Changes (${changed.length})</h4>
    <div id="gitChangeList"></div>
    <button class="gbtn" id="btnCommit">Commit ${changed.length} change${changed.length!==1?'s':''}</button>
  `;
  body.appendChild(block);
  const list = $('#gitChangeList', block);
  changed.forEach(f => {
    const row = el('div','git-file-row');
    row.innerHTML = `<span class="tree-git git-${f.git}">${f.git}</span><span class="p">${esc(f.path)}</span>`;
    row.addEventListener('click', () => openFile(f.path));
    list.appendChild(row);
  });
  if(!changed.length){ list.innerHTML = `<div style="color:var(--text-dim);padding:8px 0;">No changes</div>`; }
  $('#btnCommit', block).addEventListener('click', () => {
    if(!changed.length){ notify('info','Nothing to commit'); return; }
    (function clear(n){ (n.children||[]).forEach(c => { if(c.type==='file') delete c.git; else clear(c); }); })(PROJECT);
    persistProject(); renderExplorer(); updateGitBadge(); renderActivityBar();
    if(S.activityView==='git') setActivityView('git');
    notify('success','Committed', `${changed.length} file(s) committed to ${S.git.branch}`);
  });

  const cpBlock = el('div','side-panel-block');
  cpBlock.innerHTML = `<h4>Checkpoints</h4><div id="cpList" style="color:var(--text-dim);font-size:11.5px;">Loading…</div><button class="gbtn" id="btnSaveCheckpoint">Save checkpoint</button>`;
  body.appendChild(cpBlock);
  $('#btnSaveCheckpoint', cpBlock).addEventListener('click', () => {
    promptModal({ title:'Save checkpoint', label:'Label (optional)', placeholder:'e.g. before refactor', confirmLabel:'Save',
      onConfirm: async (label) => {
        try{ const token = await ensureToken(); if(!token) throw new Error('No connection to server'); await api('save_checkpoint', { token, label, project: PROJECT }); notify('success','Checkpoint saved', label); loadCheckpointList(cpBlock); }
        catch(e){ notify('error','Could not save checkpoint', e.message); }
      } });
  });
  loadCheckpointList(cpBlock);
}
async function loadCheckpointList(cpBlock){
  const host = $('#cpList', cpBlock); if(!host) return;
  const token = cbToken();
  if(!token){ host.textContent = 'Not connected to a server yet — save once to enable checkpoints.'; return; }
  try{
    const r = await api('list_checkpoints', { token });
    const items = r.checkpoints || [];
    if(!items.length){ host.textContent = 'No checkpoints yet.'; return; }
    host.innerHTML = '';
    items.slice().reverse().forEach(cp => {
      const row = el('div','git-file-row');
      row.innerHTML = `<span class="p">${esc(cp.label || 'checkpoint')} — ${esc(new Date(cp.time*1000).toLocaleString())}</span>`;
      const btn = el('button','btn'); btn.textContent='Restore'; btn.style.fontSize='10.5px'; btn.style.padding='3px 8px';
      btn.addEventListener('click', () => {
        confirmModal({ title:'Restore checkpoint', message:`Replace the current project with "${cp.label||'checkpoint'}"? Unsaved changes will be lost.`, confirmLabel:'Restore', danger:false,
          onConfirm: async () => {
            try{ const rr = await api('restore_checkpoint', { token, file: cp.file }); PROJECT = rr.project; renderExplorer(); closeAllTabs(); openFile('App.tsx'); notify('success','Checkpoint restored'); }
            catch(e){ notify('error','Restore failed', e.message); }
          } });
      });
      row.appendChild(btn);
      host.appendChild(row);
    });
  }catch(e){ host.textContent = 'Could not load checkpoints.'; }
}

function renderRunPanel(body){
  const block = el('div','side-panel-block');
  block.innerHTML = `
    <h4>Run Configuration</h4>
    <div style="color:var(--text-dim);line-height:1.6;">This is a browser-only preview, so scripts don't execute server-side here — spec calls for sandboxed, isolated execution on a real backend.</div>
    <button class="gbtn" id="btnRunSim">${'▶'} Run App.tsx (simulated)</button>
    <h4>Console</h4>
    <div id="runConsole" style="font-family:var(--font-code);font-size:11.5px;color:var(--text-secondary);background:rgba(255,255,255,0.03);border:1px solid var(--glass-border);border-radius:9px;padding:8px;min-height:70px;">Idle.</div>
  `;
  body.appendChild(block);
  $('#btnRunSim', block).addEventListener('click', () => {
    const c = $('#runConsole', block);
    c.textContent = '> starting dev server (simulated)…';
    notify('info','Build started','App.tsx');
    setTimeout(() => { c.textContent += '\n> compiled successfully\n> ready on preview port'; notify('success','Build finished','No errors'); }, 900);
  });
}
function renderExtensionsPanel(body){
  const block = el('div','side-panel-block');
  block.innerHTML = `<h4>Installed</h4>`;
  body.appendChild(block);
  const ext = [
    { t:'AI Assistant', d:'Inline chat, diffs & approvals', icon:'sparkle' },
    { t:'Prettier', d:'Code formatter — active on save', icon:'code' },
    { t:'Playwright Runner', d:'Visual regression checks', icon:'zap' },
  ];
  ext.forEach(x => {
    const c = el('div','ext-card');
    c.innerHTML = `<div class="ico">${ICONS[x.icon]}</div><div><div class="t">${esc(x.t)}</div><div class="d">${esc(x.d)}</div></div>`;
    block.appendChild(c);
  });
}
const AI_PROVIDERS = {
  anthropic:  { label:'Anthropic (Claude)', keyUrl:'https://console.anthropic.com/settings/keys', model:'claude-sonnet-4-6' },
  openai:     { label:'OpenAI',             keyUrl:'https://platform.openai.com/api-keys',        model:'gpt-4o-mini' },
  groq:       { label:'Groq (free, fast)',  keyUrl:'https://console.groq.com/keys',                model:'llama-3.1-8b-instant' },
  openrouter: { label:'OpenRouter',         keyUrl:'https://openrouter.ai/keys',                   model:'openrouter/auto' },
  gemini:     { label:'Google Gemini',      keyUrl:'https://aistudio.google.com/app/apikey',       model:'gemini-1.5-flash' },
};
function getAiConfig(){
  return {
    provider: localStorage.getItem('cb-ai-provider') || 'groq',
    apiKey: localStorage.getItem('cb-ai-key') || '',
    model: localStorage.getItem('cb-ai-model') || '',
  };
}
function saveAiConfig(cfg){
  if(cfg.provider!=null) localStorage.setItem('cb-ai-provider', cfg.provider);
  if(cfg.apiKey!=null) localStorage.setItem('cb-ai-key', cfg.apiKey);
  if(cfg.model!=null) localStorage.setItem('cb-ai-model', cfg.model);
}
// Shared with the main QuickChat app's theme picker (see glass-theme.js's
// STORAGE_KEY) so opening Code Box shows whatever theme is already active
// in the chat, instead of tracking its own separate preference. 'cb-theme'
// is still read once as a fallback for anyone who set a Code Box theme
// before this synced with the main app.
const THEME_STORAGE_KEY = 'glass_preview_theme';
const ALL_THEMES = ['nocturne','aurora','ember','frost','sakura','ocean','forest','mono','sunset','neon','wine','mint','golden','platinum','obsidian'];
function currentTheme(){
  return localStorage.getItem(THEME_STORAGE_KEY) || localStorage.getItem('cb-theme') || 'nocturne';
}
function applyTheme(name){
  document.body.setAttribute('data-theme', name==='nocturne' ? '' : name);
  localStorage.setItem(THEME_STORAGE_KEY, name);
}
// Live sync: if the theme is changed from the chat tab (or another Code
// Box tab) while this one is open, the browser fires a `storage` event
// here — pick it up immediately rather than only on next reload.
window.addEventListener('storage', e => {
  if(e.key === THEME_STORAGE_KEY && e.newValue) applyTheme(e.newValue);
});

// A few known model IDs per provider, offered as quick picks — providers
// change their lineups over time (this is exactly what broke the old
// hardcoded Groq default, see ai_proxy.php's DEFAULT_MODEL comment), so
// this is deliberately just a convenience list that fills the actual
// model field below, not the only allowed values. The model field
// itself is a plain text input — type any current model ID your
// provider supports if none of these quick picks work.
const MODEL_OPTIONS = {
  anthropic:  ['claude-sonnet-4-6', 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5-20251001'],
  openai:     ['gpt-4o-mini', 'gpt-4o', 'gpt-5-mini', 'gpt-5'],
  groq:       ['llama-3.1-8b-instant', 'llama-3.3-70b-versatile', 'llama-3.1-70b-versatile', 'mixtral-8x7b-32768', 'gemma2-9b-it'],
  openrouter: ['openrouter/auto', 'anthropic/claude-sonnet-4', 'openai/gpt-4o-mini', 'meta-llama/llama-3.1-8b-instruct:free'],
  gemini:     ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.0-flash'],
};
function renderSettingsPanel(body){
  const cfg = getAiConfig();
  const effectiveModel = cfg.model || AI_PROVIDERS[cfg.provider].model;
  const block = el('div','side-panel-block');
  block.innerHTML = `
    <h4>AI Provider</h4>
    <select class="sp-input" id="setAiProvider">
      ${Object.entries(AI_PROVIDERS).map(([k,v]) => `<option value="${k}" ${cfg.provider===k?'selected':''}>${esc(v.label)}</option>`).join('')}
    </select>
    <input class="sp-input" id="setAiKey" type="password" placeholder="API key — stored only on this device" value="${esc(cfg.apiKey)}">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
      <a href="#" id="setAiKeyLink" style="font-size:11px;color:var(--accent-blue);text-decoration:none;">Get a free ${esc(AI_PROVIDERS[cfg.provider].label)} key →</a>
      <span id="aiKeyStatus" style="font-size:10.5px;color:var(--text-dim);">${cfg.apiKey?'Key saved':'No key set'}</span>
    </div>
    <label style="font-size:10.5px;color:var(--text-dim);display:block;margin:6px 0 3px;">Model</label>
    <select class="sp-input" id="setAiModelPreset" style="margin-bottom:4px;">
      ${(MODEL_OPTIONS[cfg.provider]||[]).map(m => `<option value="${esc(m)}">${esc(m)}</option>`).join('')}
      <option value="">Custom (type below)…</option>
    </select>
    <input class="sp-input" id="setAiModel" placeholder="Model ID" value="${esc(effectiveModel)}">
    <div style="font-size:10.5px;color:var(--text-dim);margin-bottom:4px;">If a request fails with "model does not exist", your provider likely renamed or retired it — check their docs and type the current model ID here.</div>
    <div class="settings-row"><span>Ask before editing</span><div class="switch ${S.settings.askBeforeEdit?'on':''}" id="setAskEdit"></div></div>
    <h4>Editor</h4>
    <div class="settings-row"><span>Font size</span><input type="number" id="setFontSize" min="10" max="22" value="${S.settings.fontSize}"></div>
    <div class="settings-row"><span>Tab size</span><input type="number" id="setTabSize" min="1" max="8" value="${S.settings.tabSize}"></div>
    <div class="settings-row"><span>Word wrap</span><div class="switch ${S.settings.wordWrap?'on':''}" id="setWrap"></div></div>
    <h4>Appearance</h4>
    <select class="sp-input" id="setTheme">
      ${ALL_THEMES.map(t => `<option value="${t}" ${(currentTheme()===t)?'selected':''}>${t[0].toUpperCase()+t.slice(1)}</option>`).join('')}
    </select>
    <div class="settings-row"><span>Reduced motion</span><div class="switch ${document.body.classList.contains('reduced')?'on':''}" id="setReduced"></div></div>
    <h4>Project</h4>
    <div style="font-size:11px;color:var(--text-dim);word-break:break-all;margin-bottom:8px;">${cbToken() ? 'Synced — link: ' + esc(location.href) : 'Not yet synced to a server project.'}</div>
    <button class="gbtn" id="btnCopyShareLink">Copy shareable link</button>
  `;
  body.appendChild(block);
  $('#setAiProvider', block).addEventListener('change', e => {
    const provider = e.target.value;
    saveAiConfig({ provider, model: '' }); // switching provider clears any custom model override from the old one
    $('#setAiKeyLink', block).textContent = `Get a free ${AI_PROVIDERS[provider].label} key →`;
    $('#setAiKeyLink', block).href = AI_PROVIDERS[provider].keyUrl;
    const presetSel = $('#setAiModelPreset', block);
    presetSel.innerHTML = (MODEL_OPTIONS[provider]||[]).map(m => `<option value="${esc(m)}">${esc(m)}</option>`).join('') + `<option value="">Custom (type below)…</option>`;
    $('#setAiModel', block).value = AI_PROVIDERS[provider].model;
  });
  $('#setAiKeyLink', block).href = AI_PROVIDERS[cfg.provider].keyUrl;
  $('#setAiKeyLink', block).target = '_blank'; $('#setAiKeyLink', block).rel = 'noopener';
  $('#setAiKey', block).addEventListener('change', e => { saveAiConfig({ apiKey:e.target.value.trim() }); $('#aiKeyStatus', block).textContent = e.target.value.trim() ? 'Key saved on this device' : 'No key set'; });
  $('#setAiModelPreset', block).addEventListener('change', e => { if(e.target.value) { $('#setAiModel', block).value = e.target.value; saveAiConfig({ model: e.target.value }); } });
  $('#setAiModel', block).addEventListener('change', e => saveAiConfig({ model: e.target.value.trim() }));
  $('#setFontSize', block).addEventListener('change', e => { S.settings.fontSize = +e.target.value||13; document.documentElement.style.setProperty('--editor-fs', S.settings.fontSize+'px'); persistSettings(); });
  $('#setTabSize', block).addEventListener('change', e => { S.settings.tabSize = +e.target.value||2; CM.setOption('tabSize', S.settings.tabSize); CM.setOption('indentUnit', S.settings.tabSize); persistSettings(); });
  $('#setWrap', block).addEventListener('click', () => { S.settings.wordWrap=!S.settings.wordWrap; CM.setOption('lineWrapping', S.settings.wordWrap); $('#setWrap', block).classList.toggle('on'); persistSettings(); });
  $('#setAskEdit', block).addEventListener('click', () => { toggleAskBeforeEdit(); $('#setAskEdit', block).classList.toggle('on', S.settings.askBeforeEdit); });
  $('#setTheme', block).addEventListener('change', e => applyTheme(e.target.value));
  $('#setReduced', block).addEventListener('click', () => { document.body.classList.toggle('reduced'); $('#setReduced', block).classList.toggle('on'); });
  $('#btnCopyShareLink', block).addEventListener('click', async () => {
    await ensureToken();
    if(navigator.clipboard) navigator.clipboard.writeText(location.href).catch(()=>{});
    notify('success','Link copied', 'Anyone with this link can open this project.');
  });
}

/* ============================================================================
   STATUS BAR
============================================================================ */
function renderStatusBarShell(){
  const bar = $('#statusBar');
  bar.innerHTML = `
    <div class="sb-item clickable" id="sbBranch">${ICONS.gitBranch}<span id="sbBranchLabel">main</span></div>
    <div class="sb-item" id="sbErrors">${ICONS.alertCircle}<span>0</span></div>
    <div class="sb-item" id="sbWarnings">${ICONS.alertTriangle}<span>0</span></div>
    <div class="sb-item sb-sync sb-sync-offline" id="sbSync">${ICONS.refresh}<span>Offline</span></div>
    <div id="sbSpacer"></div>
    <div class="sb-item" id="sbEncoding">UTF-8</div>
    <div class="sb-item" id="sbEol">LF</div>
    <div class="sb-item clickable" id="sbLang">Plain Text</div>
    <div class="sb-item clickable" id="sbIndent">Spaces: 2</div>
    <div class="sb-item clickable" id="sbFormatter">Prettier</div>
    <div class="sb-item clickable" id="sbNotif">${ICONS.bell}</div>
  `;
  $('#sbLang').addEventListener('click', e => {
    const langs = ['JavaScript','TypeScript','CSS','HTML','Markdown','JSON','Python','PHP'];
    openContextMenu(e.clientX, e.clientY-langs.length*32, langs.map(l => ({ label:l, onClick:()=>{ notify('info','Language mode set', l); } })));
  });
  $('#sbNotif').addEventListener('click', () => notify('info','Notifications', 'You are all caught up.'));
}
function updateGitBadge(){ S.git.dirty = countGitChanges()>0; const label = $('#sbBranchLabel'); if(label) label.textContent = S.git.branch + (S.git.dirty?'*':''); renderActivityBar(); }
function updateStatusBar(){
  const path = S.activeTab;
  $('#sbLang').textContent = path ? langLabel(path) : 'Plain Text';
  $('#sbIndent').textContent = 'Spaces: ' + S.settings.tabSize;
  $('#sbErrors').querySelector('span').textContent = S.problems.errors;
  $('#sbWarnings').querySelector('span').textContent = S.problems.warnings;
  $('#sbErrors').classList.toggle('sb-err', S.problems.errors>0);
  $('#sbWarnings').classList.toggle('sb-warn', S.problems.warnings>0);
  updateGitBadge();
}

/* ============================================================================
   LAYOUT (resizing / presets / window controls)
============================================================================ */
// Must match the @media (max-width: 980px) breakpoint in app.css — this is
// also where the AI/Explorer panels switch from side-by-side resizable
// columns to full-screen mobile drawers.
const MOBILE_LAYOUT_BREAKPOINT = 980;
function isMobileLayout(){ return window.innerWidth <= MOBILE_LAYOUT_BREAKPOINT; }

function applyPanelWidths(){
  // On mobile the panels are fixed-position drawers sized entirely by CSS
  // (min(94vw,440px), then calc(100vw - 20px) on very small screens) — an
  // inline pixel width set here would win over those media-query rules
  // (inline style always beats a non-!important CSS rule) and leave the
  // drawer stuck at whatever width was last set on desktop. So on mobile we
  // explicitly clear the inline width instead of setting one.
  if(isMobileLayout()){
    $('#aiPanel').style.width = '';
    $('#explorerPanel').style.width = '';
    return;
  }
  $('#aiPanel').style.width = S.settings.panelWidths.ai + 'px';
  $('#explorerPanel').style.width = S.settings.panelWidths.explorer + 'px';
}
function initDividers(){
  [ {id:'dividerAi', panel:'aiPanel', key:'ai', min:220, max:520},
    {id:'dividerExplorer', panel:'explorerPanel', key:'explorer', min:180, max:460} ].forEach(cfg => {
    const divider = $('#'+cfg.id);
    let dragging=false, startX=0, startW=0;
    divider.addEventListener('dblclick', () => { S.settings.panelWidths[cfg.key] = cfg.key==='ai'?320:260; applyPanelWidths(); persistSettings(); });
    divider.addEventListener('pointerdown', e => {
      // Dividers only resize the desktop side-by-side layout — on mobile
      // the panels are full-screen drawers, so there's nothing to drag
      // (the divider is hidden by CSS at this breakpoint too, but this
      // guard covers a mid-drag window resize/rotation as well).
      if(isMobileLayout()) return;
      dragging=true; startX=e.clientX; startW=S.settings.panelWidths[cfg.key];
      divider.classList.add('dragging'); divider.setPointerCapture(e.pointerId);
      document.body.style.userSelect='none';
    });
    divider.addEventListener('pointermove', e => {
      if(!dragging) return;
      const dx = e.clientX - startX;
      const delta = cfg.key==='explorer' ? -dx : dx;
      let w = Math.max(cfg.min, Math.min(cfg.max, startW + delta));
      S.settings.panelWidths[cfg.key] = w; applyPanelWidths();
    });
    const end = e => { if(!dragging) return; dragging=false; divider.classList.remove('dragging'); document.body.style.userSelect=''; persistSettings(); };
    divider.addEventListener('pointerup', end);
    divider.addEventListener('pointercancel', end);
  });
}

const LAYOUT_PRESETS = ['default','editor-focused','ai-focused','explorer-focused','single-editor'];
function setLayout(name){
  S.settings.layout = name;
  const ai = $('#aiPanel'), ex = $('#explorerPanel');
  ai.classList.remove('hidden'); ex.classList.remove('hidden');
  if(name==='editor-focused'){ ai.classList.add('hidden'); ex.classList.add('hidden'); }
  else if(name==='single-editor'){ ai.classList.add('hidden'); ex.classList.add('hidden'); }
  else if(name==='ai-focused'){ S.settings.panelWidths.ai = 440; applyPanelWidths(); }
  else if(name==='explorer-focused'){ S.settings.panelWidths.explorer = 380; applyPanelWidths(); }
  else { S.settings.panelWidths.ai=320; S.settings.panelWidths.explorer=260; applyPanelWidths(); }
  persistSettings();
  notify('info','Layout changed', name.replace('-',' '));
}
$('#btnLayoutCycle').addEventListener('click', () => {
  const idx = LAYOUT_PRESETS.indexOf(S.settings.layout);
  setLayout(LAYOUT_PRESETS[(idx+1) % LAYOUT_PRESETS.length]);
});
setIcon($('#btnLayoutCycle'), 'grid');
$('#btnLayoutH').addEventListener('click', () => { $('#workspace').style.flexDirection='column'; notify('info','Horizontal layout applied'); $('#dividerAi').classList.add('hidden'); $('#dividerExplorer').classList.add('hidden'); });
setIcon($('#btnLayoutH'), 'layoutH');
$('#btnLayoutV').addEventListener('click', () => { $('#workspace').style.flexDirection='row'; $('#dividerAi').classList.remove('hidden'); $('#dividerExplorer').classList.remove('hidden'); notify('info','Vertical layout restored'); });
setIcon($('#btnLayoutV'), 'layoutV');
setIcon($('#btnNavBack'),'arrowLeft'); setIcon($('#btnNavFwd'),'arrowRight');
$('#btnNavBack').addEventListener('click', navBack);
$('#btnNavFwd').addEventListener('click', navFwd);

/* Window controls */
setIcon($('#searchIcon'), 'search');
$('#btnWinClose').addEventListener('click', () => {
  $('#app').classList.add('closed'); $('#app').classList.remove('minimized');
  $('#reopenBar span').textContent = 'Workspace closed';
  $('#reopenBar').classList.add('show');
});
$('#reopenBtn').addEventListener('click', () => { $('#app').classList.remove('closed','minimized'); $('#reopenBar').classList.remove('show'); });
$('#btnWinMin').addEventListener('click', () => {
  $('#app').classList.toggle('minimized'); $('#app').classList.remove('closed');
  const isMin = $('#app').classList.contains('minimized');
  $('#reopenBar span').textContent = 'Workspace minimized';
  $('#reopenBar').classList.toggle('show', isMin);
});
const canFullscreen = !!(document.documentElement.requestFullscreen) && !('ontouchstart' in window);
$('#btnWinMax').addEventListener('click', () => {
  $('#app').classList.toggle('maximized');
  if(!canFullscreen) return;
  if(document.fullscreenElement){ document.exitFullscreen && document.exitFullscreen().catch(()=>{}); }
  else{ document.documentElement.requestFullscreen().catch(()=>{}); }
});
$('#reopenBar').addEventListener('click', e => { if(e.target.id==='reopenBar'){ $('#app').classList.remove('closed','minimized'); $('#reopenBar').classList.remove('show'); } });

/* Mobile drawers */
function openMobileDrawer(panelId){
  $('#aiPanel').classList.remove('drawer-open');
  $('#explorerPanel').classList.remove('drawer-open');
  $('#'+panelId).classList.add('drawer-open');
  $('#drawerScrim').classList.add('show');
}
function closeMobileDrawers(){
  $('#aiPanel').classList.remove('drawer-open');
  $('#explorerPanel').classList.remove('drawer-open');
  $('#drawerScrim').classList.remove('show');
}
$('#mobileAiToggle').addEventListener('click', () => openMobileDrawer('aiPanel'));
$('#drawerScrim').addEventListener('click', closeMobileDrawers);
// Explicit close buttons in each panel's own header — now that the
// mobile drawer is genuinely full-screen (see the mobile media query
// in app.css), there's no exposed scrim left to tap to dismiss it, so
// this is the actual way to close it on a phone rather than a nice-to-have.
$('#btnAiCloseMobile').addEventListener('click', closeMobileDrawers);
$('#btnExplorerCloseMobile').addEventListener('click', closeMobileDrawers);
setIcon($('#btnAiCloseMobile'), 'x');
setIcon($('#btnExplorerCloseMobile'), 'x');
setIcon($('#mobileAiToggle'), 'sparkle');

/* ============================================================================
   QUICK OPEN / COMMAND PALETTE
============================================================================ */
const COMMANDS = [
  { label:'New File', run:()=>createNode(PROJECT,'file') },
  { label:'New Folder', run:()=>createNode(PROJECT,'folder') },
  { label:'Save', run:saveActiveFile },
  { label:'Save All', run:saveAllFiles },
  { label:'Close Tab', run:()=>S.activeTab && closeTab(S.activeTab) },
  { label:'Close All Tabs', run:closeAllTabs },
  { label:'Format Document', run:formatDocument },
  { label:'Find', run:()=> CM && CM.execCommand('findPersistent') },
  { label:'Replace', run:()=> CM && CM.execCommand('replace') },
  { label:'Toggle Explorer', run:()=> $('#explorerPanel').classList.toggle('hidden') },
  { label:'Toggle AI Panel', run:()=> $('#aiPanel').classList.toggle('hidden') },
  { label:'Toggle Source Control', run:()=> setActivityView('git') },
  { label:'Run Tests', run:()=>{ setActivityView('run'); notify('info','Opened Run panel'); } },
  { label:'Build', run:()=>{ setActivityView('run'); } },
  { label:'Git Commit', run:()=> setActivityView('git') },
  { label:'Settings', run:()=> setActivityView('settings') },
  { label:'Toggle Fullscreen', run:()=> $('#btnWinMax').click() },
];
function formatDocument(){
  if(!CM) return;
  const lines = CM.getValue().split('\n').map(l => l.replace(/\s+$/,''));
  S.suppressChange = true; CM.setValue(lines.join('\n')); S.suppressChange = false;
  notify('success','Document formatted', 'Prettier');
}

let paletteEl = null, paletteMode = 'files';
function openPalette(mode){
  closePalette();
  paletteMode = mode;
  const overlay = el('div','palette-overlay');
  overlay.innerHTML = `<div class="palette-box">
    <div class="palette-input-row">${ICONS.search}<input id="paletteInput" placeholder="${mode==='commands' ? 'Type a command…' : mode==='ai' ? 'Ask AI…' : 'Go to file…'}" autocomplete="off"></div>
    <div class="palette-list" id="paletteList"></div>
  </div>`;
  document.body.appendChild(overlay);
  paletteEl = overlay;
  requestAnimationFrame(() => overlay.classList.add('show'));
  const input = $('#paletteInput', overlay);
  if(mode==='commands') input.value = '>';
  input.focus();
  overlay.addEventListener('mousedown', e => { if(e.target===overlay) closePalette(); });
  input.addEventListener('input', () => renderPaletteList(input.value));
  input.addEventListener('keydown', e => {
    const list = $('#paletteList', overlay);
    const items = $$('.palette-item', list);
    let hi = items.findIndex(i => i.classList.contains('hi'));
    if(e.key==='ArrowDown'){ e.preventDefault(); if(hi>=0) items[hi].classList.remove('hi'); hi=(hi+1)%items.length; items[hi]&&items[hi].classList.add('hi'); items[hi]&&items[hi].scrollIntoView({block:'nearest'}); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); if(hi>=0) items[hi].classList.remove('hi'); hi=(hi-1+items.length)%items.length; items[hi]&&items[hi].classList.add('hi'); items[hi]&&items[hi].scrollIntoView({block:'nearest'}); }
    else if(e.key==='Enter'){ e.preventDefault(); (items[hi]||items[0])&&(items[hi]||items[0]).click(); }
    else if(e.key==='Escape'){ closePalette(); }
  });
  renderPaletteList(mode==='commands' ? '>' : '');
}
function closePalette(){ if(paletteEl){ paletteEl.classList.remove('show'); const p=paletteEl; setTimeout(()=>p.remove(),150); paletteEl=null; } }
function renderPaletteList(query){
  const list = $('#paletteList'); if(!list) return;
  list.innerHTML = '';
  const isCommand = query.startsWith('>');
  if(isCommand){
    const q = query.slice(1).trim().toLowerCase();
    const matches = COMMANDS.filter(c => c.label.toLowerCase().includes(q));
    if(!matches.length){ list.innerHTML = `<div class="palette-empty">No matching commands</div>`; return; }
    matches.forEach((c,i) => {
      const item = el('div','palette-item'+(i===0?' hi':''));
      item.innerHTML = `${ICONS.zap}<span class="pn">${esc(c.label)}</span>`;
      item.addEventListener('click', () => { closePalette(); c.run(); });
      list.appendChild(item);
    });
    return;
  }
  const q = query.trim().toLowerCase();
  const files = flattenFiles();
  const matches = (q ? files.filter(f => f.path.toLowerCase().includes(q)) : files).slice(0,60);
  if(!matches.length){ list.innerHTML = `<div class="palette-empty">No matching files</div>`; return; }
  matches.forEach((f,i) => {
    const item = el('div','palette-item'+(i===0?' hi':''));
    item.innerHTML = `${ICONS[fileIconFor(f.path)]}<span class="pn">${esc(f.name)}</span><span class="pp">${esc(f.path)}</span>`;
    item.addEventListener('click', () => { closePalette(); openFile(f.path); });
    list.appendChild(item);
  });
}

const commandInput = $('#commandInput');
commandInput.addEventListener('focus', () => { $('#searchBar').classList.add('focused'); openPalette('files'); });
commandInput.addEventListener('blur', () => $('#searchBar').classList.remove('focused'));
commandInput.addEventListener('input', () => { if(!paletteEl) openPalette(commandInput.value.startsWith('>')?'commands':'files'); renderPaletteList(commandInput.value); });

/* ============================================================================
   AI ASSISTANT
============================================================================ */
function updateSuggestedChips(){
  const row = $('#suggestedRow');
  row.innerHTML = '';
  const chips = S.activeTab
    ? [ `Explain ${findNode(S.activeTab)?.name}`, `Add unit tests for ${findNode(S.activeTab)?.name}`, `Refactor this file for clarity` ]
    : [ `What does this project do?`, `Write unit tests for localizationUtils.ts`, `Add a French-Canadian date format helper` ];
  chips.forEach(c => {
    const chip = el('div','suggested-chip', esc(c));
    chip.addEventListener('click', () => { $('#aiInput').value = c; sendAiMessage(); });
    row.appendChild(chip);
  });
}

function addContext(path, isFolder){
  if(isFolder){
    const node = findNode(path);
    const files = flattenFiles(node);
    files.forEach(f => { if(!S.aiContext.includes(f.path)) S.aiContext.push(f.path); });
    notify('info','Added to AI context', `${files.length} file(s) from ${path||'/'}`);
  } else {
    if(!S.aiContext.includes(path)) S.aiContext.push(path);
  }
  renderAiContext();
}
function removeContext(path){ S.aiContext = S.aiContext.filter(p => p!==path); renderAiContext(); }
function renderAiContext(){
  const row = $('#aiContextRow');
  row.innerHTML = '';
  S.aiContext.forEach(p => {
    const chip = el('div','ctx-chip');
    chip.innerHTML = `${ICONS[fileIconFor(p)]}<span>${esc(p.split('/').pop())}</span>`;
    const rm = el('button'); rm.innerHTML = ICONS.x; rm.addEventListener('click', () => removeContext(p));
    chip.appendChild(rm);
    row.appendChild(chip);
  });
  const addBtn = el('button','ctx-add-btn', '+ Add current file');
  addBtn.addEventListener('click', () => { if(S.activeTab) addContext(S.activeTab); else notify('info','No file open'); });
  row.appendChild(addBtn);
  const tokenSpan = el('span'); tokenSpan.id='aiTokenIndicator';
  let chars = S.aiContext.reduce((n,p) => n + ((findNode(p)||{}).content||'').length, 0);
  tokenSpan.textContent = chars ? `~${Math.round(chars/4)} tokens` : '';
  row.appendChild(tokenSpan);
}

$('#btnAiAttach').addEventListener('click', () => { if(S.activeTab) addContext(S.activeTab); else notify('info','Open a file first to attach it'); });
setIcon($('#btnAiAttach'),'paperclip');
setIcon($('#aiSendBtn'),'send');
setIcon($('#btnAiMore'),'more');
setIcon($('#btnAiCollapse'),'x');
$('.ai-sparkle').innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 5.4L19 10l-5.2 1.6L12 17l-1.8-5.4L5 10l5.2-1.6L12 3z"/></svg>`;
$('#btnAiCollapse').addEventListener('click', () => { $('#aiPanel').classList.toggle('hidden'); });
$('#btnAiMore').addEventListener('click', e => {
  openContextMenu(e.clientX, e.clientY, [
    { label:'Clear conversation', icon:'trash', onClick:()=>{ S.aiHistory=[]; renderAiTimeline(); } },
    { label:'Clear context', icon:'x', onClick:()=>{ S.aiContext=[]; renderAiContext(); } },
  ]);
});

const approvalSwitch = $('#approvalSwitch');
function toggleAskBeforeEdit(){
  S.settings.askBeforeEdit = !S.settings.askBeforeEdit;
  approvalSwitch.classList.toggle('on', S.settings.askBeforeEdit);
  approvalSwitch.setAttribute('aria-checked', String(S.settings.askBeforeEdit));
  persistSettings();
}
approvalSwitch.addEventListener('click', toggleAskBeforeEdit);
approvalSwitch.addEventListener('keydown', e => { if(e.key===' '||e.key==='Enter'){ e.preventDefault(); toggleAskBeforeEdit(); } });

const aiInput = $('#aiInput');
aiInput.addEventListener('input', () => { aiInput.style.height='auto'; aiInput.style.height = Math.min(120, aiInput.scrollHeight) + 'px'; });
aiInput.addEventListener('keydown', e => { if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendAiMessage(); } });
$('#aiSendBtn').addEventListener('click', sendAiMessage);

const AI_EMPTY_HTML = `${ICONS.sparkle}Ask the assistant to read, explain or edit files in this project. It can propose real diffs you approve before anything changes.`;
function renderAiTimeline(){
  const host = $('#aiTimeline');
  host.innerHTML = '';
  if(!S.aiHistory.length){ host.appendChild(el('div','ai-empty', AI_EMPTY_HTML)); return; }
  S.aiHistory.forEach(entry => host.appendChild(renderTimelineEntry(entry)));
  host.scrollTop = host.scrollHeight;
}
function renderTimelineEntry(entry){
  if(entry.kind==='user'){ const b=el('div','msg-bubble msg-user', esc(entry.text)); return b; }
  if(entry.kind==='thinking'){
    const row = el('div','thinking-row');
    row.id = entry.id;
    row.innerHTML = `${ICONS.sparkle}<span>Thinking</span><span class="dot-flash"><span></span><span></span><span></span></span>`;
    return row;
  }
  if(entry.kind==='reply'){
    const b = el('div','msg-bubble msg-ai');
    b.innerHTML = `<div class="msg-ai-label">${ICONS.sparkle}<span>Assistant</span></div>${esc(entry.text)}`;
    return b;
  }
  if(entry.kind==='op'){ return renderOpCard(entry); }
  return el('div');
}
function opTypeMeta(type){
  const map = {
    READ:{icon:'fileGeneric', label:'Read'}, ANALYZE:{icon:'search', label:'Analyze'}, SEARCH:{icon:'search', label:'Search'},
    EDIT:{icon:'edit', label:'Edit'}, WRITE:{icon:'edit', label:'Write'}, CREATE:{icon:'plus', label:'Create'},
    DELETE:{icon:'trash', label:'Delete'}, RUN:{icon:'play', label:'Run'}, TERMINAL:{icon:'terminal', label:'Terminal'}, TEST:{icon:'checkCircle', label:'Test'},
  };
  return map[type] || map.READ;
}
function renderOpCard(op){
  const meta = opTypeMeta(op.type);
  const card = el('div', `op-card op-type-${op.type.toLowerCase()} status-${op.status}`);
  card.id = op.id;
  const statusIcon = op.status==='running' ? `<div class="spinner-sm"></div>`
    : op.status==='success' ? ICONS.check
    : op.status==='failed' ? ICONS.x
    : op.status==='cancelled' ? ICONS.minus
    : '';
  card.innerHTML = `
    <div class="op-row">
      <div class="op-icon">${ICONS[meta.icon]}</div>
      <div class="op-main">
        <div class="op-name">${meta.label}</div>
        ${op.file ? `<div class="op-path">${esc(op.file)}</div>` : ''}
      </div>
      <div class="op-status">${statusIcon}</div>
    </div>
    ${op.description ? `<div class="op-desc">${esc(op.description)}</div>` : ''}
    ${op.diff ? `<div class="op-diff-toggle"><button>View diff</button></div>` : ''}
  `;
  if(op.diff){
    $('.op-diff-toggle button', card).addEventListener('click', () => openApprovalDialog(op, true));
  }
  return card;
}
function addTimelineEntry(entry){ entry.id = entry.id || ('t_'+uid()); S.aiHistory.push(entry); renderAiTimeline(); return entry; }
function updateOpStatus(op, status){ op.status = status; const c = document.getElementById(op.id); if(c){ c.className = `op-card op-type-${op.type.toLowerCase()} status-${status}`; c.querySelector('.op-status').innerHTML = status==='running' ? '<div class="spinner-sm"></div>' : status==='success' ? ICONS.check : status==='failed' ? ICONS.x : status==='cancelled' ? ICONS.minus : ''; } }

function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }

async function runOperationsSequence(ops){
  for(const opDef of ops){
    const op = { kind:'op', type: opDef.type, file: opDef.file, description: opDef.description, status:'pending', diff: opDef.diff||null };
    addTimelineEntry(op);
    await sleep(120);
    updateOpStatus(op, 'running');
    await sleep(260 + Math.random()*260);
    updateOpStatus(op, opDef.willFail ? 'failed' : 'success');
    if(opDef.onSettle) opDef.onSettle(op);
  }
}

function computeLineDiff(oldStr, newStr){
  const a = (oldStr||'').split('\n'), b = (newStr||'').split('\n');
  const n=a.length, m=b.length;
  const dp = Array.from({length:n+1}, () => new Int32Array(m+1));
  for(let i=n-1;i>=0;i--) for(let j=m-1;j>=0;j--) dp[i][j] = a[i]===b[j] ? dp[i+1][j+1]+1 : Math.max(dp[i+1][j], dp[i][j+1]);
  const out = []; let i=0,j=0;
  while(i<n && j<m){
    if(a[i]===b[j]){ out.push({t:'ctx', text:a[i]}); i++; j++; }
    else if(dp[i+1][j] >= dp[i][j+1]){ out.push({t:'del', text:a[i]}); i++; }
    else { out.push({t:'add', text:b[j]}); j++; }
  }
  while(i<n){ out.push({t:'del', text:a[i]}); i++; }
  while(j<m){ out.push({t:'add', text:b[j]}); j++; }
  return out;
}
function renderDiffHTML(diff){
  return diff.map(d => `<div class="diff-line diff-${d.t}">${esc(d.text)}</div>`).join('');
}

function openApprovalDialog(op, viewOnly){
  const oldContent = (findNode(op.file) || {}).content || '';
  const diff = computeLineDiff(oldContent, op.diff.newContent);
  const { close } = openModal({
    title: viewOnly ? 'Diff preview' : 'Approve AI edit', wide:true,
    bodyHTML: `
      <div class="diff-file">${esc(op.file)}</div>
      <div class="diff-summary">${esc(op.diff.summary||'')}</div>
      <div class="diff-view">${renderDiffHTML(diff)}</div>
    `,
    buttons: viewOnly ? [{ label:'Close', primary:true, onClick:c=>c() }] : [
      { label:'Reject', onClick:(c)=>{ rejectEdit(op); c(); } },
      { label:'Apply', primary:true, onClick:(c)=>{ applyEdit(op); c(); } },
    ]
  });
}
function rejectEdit(op){ updateOpStatus(op, 'cancelled'); notify('warning','Change rejected', op.file); }
function applyEdit(op){
  let node = findNode(op.file);
  if(!node){
    const parentPath = op.file.includes('/') ? op.file.slice(0, op.file.lastIndexOf('/')) : '';
    ensureFolderPath(parentPath);
    const parent = findNode(parentPath) || PROJECT;
    node = { name: op.file.split('/').pop(), type:'file', path: op.file, git:'A', content:'' };
    parent.children.push(node);
    S.expanded.add(parentPath);
  }
  node.content = op.diff.newContent;
  if(!node.git) node.git = 'M';
  if(S.tabs.find(t=>t.path===op.file)){ if(S.activeTab===op.file){ node._savedContent=node.content; S.suppressChange=true; CM.setValue(node.content); S.suppressChange=false; markTabDirty(op.file,false);} }
  else { S.tabs.push({path:op.file, pinned:false}); }
  persistProject(); renderExplorer(); renderTabs(); updateGitBadge();
  updateOpStatus(op, 'success');
  notify('success','AI applied changes', op.file);
}
function ensureFolderPath(path){
  if(!path || findNode(path)) return;
  const parts = path.split('/'); let cur = '';
  let node = PROJECT;
  parts.forEach(p => {
    cur = cur ? cur+'/'+p : p;
    let child = node.children.find(c => c.path===cur && c.type==='folder');
    if(!child){ child = { name:p, type:'folder', path:cur, children:[] }; node.children.push(child); }
    node = child;
  });
}

function setAiBusy(busy){
  S.aiBusy = busy;
  $('#aiSendBtn').disabled = busy;
  $('#aiInput').disabled = busy;
  $('#searchSpinner').classList.toggle('spin', busy);
}

/* Parse ```lang\n// FILE: path\ncode``` blocks out of a free-text AI reply.
   A leading comment naming the file is optional — falls back to the active tab. */
function parseFencedBlocks(text){
  const re = /```([a-zA-Z0-9+#\-]*)\n([\s\S]*?)```/g;
  const blocks = []; const prose = []; let m; let lastIndex = 0;
  while((m = re.exec(text||''))){
    prose.push(text.slice(lastIndex, m.index));
    lastIndex = re.lastIndex;
    let body = m[2]; const lines = body.split('\n');
    let file = null;
    const fm = (lines[0]||'').match(/^\s*(?:\/\/|#|<!--)\s*FILE:\s*([^\s][^\n]*?)\s*(?:-->)?\s*$/i);
    if(fm){ file = fm[1].trim(); lines.shift(); body = lines.join('\n'); }
    blocks.push({ lang:m[1]||'', file, code: body.replace(/\n$/,'') });
  }
  prose.push(text.slice(lastIndex));
  return { blocks, prose: prose.join('\n').trim() };
}

async function sendAiMessage(){
  const text = aiInput.value.trim();
  if(!text || S.aiBusy) return;
  const aiCfg = getAiConfig();
  if(!aiCfg.apiKey){
    notify('warning', 'Add an AI key first', `Open Settings to add a free ${AI_PROVIDERS[aiCfg.provider].label} key — it's stored only on this device.`);
    setActivityView('settings');
    return;
  }
  aiInput.value = ''; aiInput.style.height='auto';
  addTimelineEntry({ kind:'user', text });
  $('#aiTaskCard').classList.add('show');
  $('#aiTaskTitle').textContent = text.length>72 ? text.slice(0,72)+'…' : text;
  $('#aiTaskFile').innerHTML = S.activeTab ? `${ICONS.fileGeneric} ${esc(S.activeTab)}` : '';
  $('#aiTaskStatus').textContent = 'Working…';
  setAiBusy(true);
  const thinking = addTimelineEntry({ kind:'thinking' });
  const readOp = addTimelineEntry({ kind:'op', type: S.activeTab?'READ':'ANALYZE', file:S.activeTab||'',
    description: S.activeTab ? 'Reading active file for context' : 'Analyzing your request', status:'pending' });

  try{
    const activeNode = S.activeTab ? findNode(S.activeTab) : null;
    const ctxFiles = S.aiContext.filter(p => p!==S.activeTab);
    let attached_file = null;
    if(ctxFiles.length){
      const combined = ctxFiles.map(p => { const n=findNode(p); return n ? `--- ${p} ---\n${(n.content||'').slice(0,2000)}` : ''; }).join('\n\n');
      attached_file = { name: ctxFiles.length===1 ? ctxFiles[0] : `${ctxFiles.length} files`, content: combined };
    }
    const history = S.aiHistory.filter(e => e.kind==='user' || e.kind==='reply').slice(-10)
      .map(e => ({ role: e.kind==='user' ? 'user' : 'assistant', content: e.text }));

    await sleep(150); updateOpStatus(readOp, 'running'); await sleep(220);

    const res = await fetch('ai_proxy.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        provider: aiCfg.provider, api_key: aiCfg.apiKey, model: aiCfg.model || undefined,
        code: activeNode ? (activeNode.content||'') : '', filename: S.activeTab || '', ext: S.activeTab ? extOf(S.activeTab) : '',
        message: text, history, attached_file
      })
    });
    const data = await res.json();
    if(!data.ok) throw new Error(data.error || 'AI request failed');
    updateOpStatus(readOp, 'success');
    removeTimelineEntry(thinking);
    $('#aiTaskStatus').textContent = 'Done';

    const { blocks, prose } = parseFencedBlocks(data.reply || '');
    if(blocks.length){
      await runOperationsSequence(blocks.map(b => {
        const file = b.file || S.activeTab;
        if(!file) return { type:'ANALYZE', file:'', description:'Proposed a snippet — open or create a file first to apply it.' };
        const exists = !!findNode(file);
        return { type: exists ? 'EDIT' : 'CREATE', file,
          description: exists ? `Proposed change to ${file}` : `Proposed new file ${file}`,
          diff: { file, newContent: b.code, summary: exists ? 'AI proposed an edit' : 'AI proposed a new file' },
          onSettle: (opCard) => { if(S.settings.askBeforeEdit) openApprovalDialog(opCard, false); else applyEdit(opCard); } };
      }));
    }
    if(prose) addTimelineEntry({ kind:'reply', text: prose });
    else if(!blocks.length) addTimelineEntry({ kind:'reply', text: data.reply || '(empty response)' });
  }catch(err){
    updateOpStatus(readOp, 'failed');
    removeTimelineEntry(thinking);
    addTimelineEntry({ kind:'op', type:'ANALYZE', file:'', description: 'AI request failed: ' + err.message, status:'failed' });
    $('#aiTaskStatus').textContent = 'Failed';
    notify('error', 'AI request failed', err.message);
  } finally {
    setAiBusy(false);
    renderAiContext();
  }
}
function removeTimelineEntry(entry){ S.aiHistory = S.aiHistory.filter(e => e!==entry); renderAiTimeline(); }

/* ============================================================================
   KEYBOARD SHORTCUTS
============================================================================ */
document.addEventListener('keydown', e => {
  const mod = e.ctrlKey || e.metaKey;
  if(mod && !e.shiftKey && e.key.toLowerCase()==='p'){ e.preventDefault(); openPalette('files'); return; }
  if(mod && e.shiftKey && e.key.toLowerCase()==='p'){ e.preventDefault(); openPalette('commands'); return; }
  if(mod && e.key.toLowerCase()==='k'){ e.preventDefault(); closePalette(); $('#aiPanel').classList.remove('hidden'); aiInput.focus(); return; }
  if(mod && e.key.toLowerCase()==='s' && !e.shiftKey){ e.preventDefault(); saveActiveFile(); return; }
  if(mod && e.shiftKey && e.key.toLowerCase()==='s'){ e.preventDefault(); saveAllFiles(); return; }
  if(mod && e.key.toLowerCase()==='w' && !e.shiftKey){ e.preventDefault(); if(S.activeTab) closeTab(S.activeTab); return; }
  if(mod && e.shiftKey && e.key.toLowerCase()==='w'){ e.preventDefault(); closeAllTabs(); return; }
  if(mod && e.key==='Tab'){ e.preventDefault(); const idx=S.tabs.findIndex(t=>t.path===S.activeTab); if(S.tabs.length){ const n=(idx+(e.shiftKey?-1:1)+S.tabs.length)%S.tabs.length; setActiveTab(S.tabs[n].path); } return; }
  if(mod && e.shiftKey && e.key.toLowerCase()==='b'){ e.preventDefault(); setActivityView('run'); return; }
  if(mod && e.key==='g'){ e.preventDefault(); goToLine(); return; }
  if(e.key==='F5'){ e.preventDefault(); setActivityView('run'); }
  if(e.key==='Escape'){ closePalette(); closeCtxMenu(); document.querySelectorAll('.modal-overlay.show').forEach(o => o.querySelector('#modalCloseX') && o.querySelector('#modalCloseX').click()); }
});

/* ============================================================================
   STARTUP
============================================================================ */
async function init(){
  applyTheme(currentTheme());

  const saved = await loadState();
  const savedSettings = saved ? saved.settings : null;
  const savedProject = saved ? saved.project : null;
  const savedWorkspace = saved ? saved.workspace : null;

  if(savedSettings) Object.assign(S.settings, savedSettings);
  approvalSwitch.classList.toggle('on', S.settings.askBeforeEdit);
  if(savedProject) PROJECT = savedProject;
  if(savedWorkspace){
    S.expanded = new Set(savedWorkspace.expanded && savedWorkspace.expanded.length ? savedWorkspace.expanded : ['']);
  }

  renderStatusBarShell();
  renderActivityBar();
  renderExplorer();
  applyPanelWidths();
  initDividers();
  initEditor();

  // 5-6. restore tabs
  if(savedWorkspace && savedWorkspace.openTabs && savedWorkspace.openTabs.length){
    savedWorkspace.openTabs.forEach(p => { if(findNode(p)) S.tabs.push({path:p, pinned:false}); });
    const active = savedWorkspace.activeTab && findNode(savedWorkspace.activeTab) ? savedWorkspace.activeTab : (S.tabs[0] && S.tabs[0].path);
    if(active) setActiveTab(active); else renderTabs();
  } else {
    openFile('App.tsx');
  }

  renderAiTimeline();
  renderAiContext();
  updateSuggestedChips();
  updateNavButtons();
  updateStatusBar();

  window.addEventListener('resize', () => {
    if(!isMobileLayout()){ closeMobileDrawers(); }
    // Re-sync inline widths whenever the layout crosses the mobile
    // breakpoint (window resize, or a tablet rotating) — otherwise a
    // stale inline pixel width from one mode can bleed into the other
    // until the next explicit resize/preset action calls this itself.
    applyPanelWidths();
  });

  setTimeout(() => {
    $('#loadingScreen').classList.add('done');
    notify('info', 'QuickChat Code Box ready', cbToken() ? 'Project restored.' : 'Set an AI key in Settings to enable the assistant.');
  }, 550);
}
init();
