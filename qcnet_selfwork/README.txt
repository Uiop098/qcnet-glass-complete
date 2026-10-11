QUICK CHAT — SELF-HOSTED, NO DEPENDENCIES
==========================================

WHAT'S NEW IN v35
  SECURITY FIXES (do these matter to you even on an existing install —
  see SECURITY NOTES below for full detail):
    1. Members can no longer upload .svg (was a stored-XSS risk).
    2. files/.htaccess now also forces Content-Disposition: attachment
       as defense-in-depth.
    3. Client-spoofable X-Forwarded-For is no longer trusted for
       rate-limiting/IP-ban decisions by default (see FIREWALL).
    4. Every password/PIN comparison uses hash_equals() (constant-time)
       instead of ==/===, closing a timing-attack side channel.
    5. super_users.php now stores SHA-256 password hashes instead of
       plain text — see ADDING MORE SUPER USERS below to regenerate
       yours.

  NEW MODERATION TOOLS — see MODERATION & SAFETY below:
    - Word/link filter (host-editable banned-word list)
    - Slow mode (minimum seconds between a member's messages)
    - Report message (🚩 on any message; shows up for host/super)
    - Co-hosts (grant specific members moderation powers without
      sharing the host password)
    - Auto-mute on repeat spam (3 identical messages in a row)

  NEW CONTENT TYPES — see CONTENT & MEDIA below:
    - Polls (📊 in the composer)
    - Stickers (🎟️ — a small built-in emoji sticker set)
    - GIF picker (🎬 — searches Tenor, sends by reference)
    - Inline translation (🌐 on any text message)
    - Scheduled messages (host/co-host/super, in Manage room)
    - Message search (🔍 in the ☰ menu)

  NEW ROOM MANAGEMENT — see ROOM MANAGEMENT below:
    - Room templates (save/apply a room's settings as a preset)
    - Auto-cleanup (delete a room after N days of inactivity)
    - Email digest to host for open reports while away
    - Undo delete — deleting a room from the super room browser now
      has a ~10 second undo window instead of being instant
    - Simple analytics dashboard + CSV export (super/host)
    - One-click full data backup (.zip, super only)

  NEW EVERYDAY / RELIABILITY FEATURES:
    - Draft autosave (per room+user, restored if you reload/close)
    - Offline queue — a message sent while disconnected is held and
      auto-retried instead of being lost
    - Connection health indicator (small dot next to the status text)
    - Jump-to-first-unread button
    - Image preview + confirm before sending a photo
    - "Clear my messages" — remove just your own messages
    - Browser notifications (opt-in, via 🔔 in the ☰ menu)
    - Installable as an app (PWA) — manifest.json + sw.js

WHAT'S INSIDE
  index.html       - frontend (HTML/CSS/JS, no external libraries)
  api.php          - fetch/send messages, clear chat (host/super only)
  auth.php         - login: member join, host login, super login/join
  upload.php       - file uploads (restricted for members, open for super)
  download.php     - the only way chat files are served (see DOWNLOAD
                     SYSTEM under FILE SHARING); files/ itself is now
                     locked and can't be fetched directly
  edit.php         - edits your own text message ("edited" tag)
  read.php         - read-receipt heartbeat ("seen by" tracking)
  linkpreview.php  - server-side link preview fetch (title/desc/image)
  export.php       - export a room's chat history, .txt/.json/.csv
                     (host/co-host/super)
  kick.php         - removes a user from a room (host/co-host/super)
  mute.php         - temporary "spammer timer" mute (host/co-host/super)
  admin.php        - room title, host password reset, unban (user + room-
                     scoped IP), pin/unpin, and v35: slow mode, word
                     filter, co-hosts, notify email, auto-cleanup, room
                     templates
  theme.php        - room wallpaper + glow color (super only, room-wide)
  wallpaper_upload.php - uploads a wallpaper image to the server (super only)
  typing.php       - marks a user as "currently typing"
  react.php        - toggles an emoji reaction on a message
  rooms.php        - GET lists all rooms for the super-user room browser;
                     POST lets a super user delete a room (with an undo
                     window, see ROOM MANAGEMENT) or reset its host
                     password/PIN directly from that browser
  report.php       - v35: report a message / resolve a report
  search.php       - v35: search a room's message history
  poll.php         - v35: create/vote/close a poll
  schedule.php     - v35: schedule a message for later / cancel it
  clear_mine.php   - v35: a member clears just their own messages
  translate.php    - v35: server-side proxy to a translation API
  gif.php          - v35: server-side proxy to Tenor GIF search + send
  sticker.php      - v35: built-in sticker catalog + send
  dashboard.php    - v35: super-only site-wide stats
  backup.php       - v35: super-only, downloads a .zip of all room
                     configs + message histories
  manifest.json    - v35: PWA manifest (installable app)
  sw.js            - v35: minimal service worker (see PWA below)
  common.php       - shared storage/session/config helpers
  firewall.php     - IP blocklist + rate limiting (brute-force/flood
                     protection) — wired into every entry point via
                     common.php, see FIREWALL below
  super_users.php  - super user accounts, SHA-256 password hashes (see
                     below)
  data/            - JSON files: messages, PINs, host passwords, bans
                     (usernames + room-scoped IPs), mutes, presence (incl.
                     read receipts), room titles/timestamps, theme,
                     remember-me tokens (see "REMEMBER ME" below),
                     link-preview cache, rate-limit counters, site-wide
                     IP blocklist (see FIREWALL), room templates
                     (.htaccess blocks direct browser access)
  files/           - uploaded files, organized by room, including a
                     wallpapers/ subfolder per room
                     (.htaccess blocks direct browser access, except
                     wallpapers/ — see DOWNLOAD SYSTEM under FILE
                     SHARING for why; v35 also forces
                     Content-Disposition: attachment as defense-in-depth)
  .htaccess        - security response headers (CSP, X-Frame-Options,
                     etc.) — see SECURITY HEADERS below. Apache only;
                     silently ignored on other web servers.

REQUIREMENTS
  Any PHP host works (PHP 7+). No database needed. The optional
  super-encryption-bypass feature needs the PHP openssl extension,
  which is enabled by default on almost every host (including free
  ones like InfinityFree) — nothing extra to install in practice.

HOW TO DEPLOY
  Upload the whole "quickchat" folder (all files, plus the data/ and
  files/ subfolders) to your site via FTP or your host's File
  Manager. Example: htdocs/quickchat/  or  public_html/quickchat/
  Make sure "data" and "files" are writable by PHP (755/775 if you
  hit errors). Then visit https://yourdomain.com/quickchat/

ROLES
  MEMBER — joins with room name + PIN + username. Sends messages,
  uploads common file types. Can be muted or kicked by a host/super.

  HOST — one per room, password-protected. The FIRST login for a new
  room sets that password (write it down — no recovery except via
  server access, or a super user resetting it, see below). Can clear
  chat, mute, and kick. The optional PIN field on the host login
  screen sets/changes the room PIN members need to join.

  SUPER USER — global accounts from super_users.php. Logs in with
  username + password only (no room name needed) and then browses
  EVERY room that exists — running or past — in a list showing:
  whether a host password is set, whether a PIN is set, who's
  currently active, when the room was created, and when it was last
  active. From there, join any room either as a visible Super User
  (crown icon, animated glowing message border) or "as Host
  (anonymously)" — entering with full host powers under the plain
  "Host" identity, indistinguishable from a real host login. Can also
  create brand-new rooms from the same screen. Right from the room
  browser, without joining first, a super user can also set a new
  host password, set a new PIN, or permanently delete a room
  (messages, files, and settings — irreversible).

  Super users can:
    - Upload any file type (except a small blocklist of
      server-executable extensions like .php/.exe, kept for safety)
    - Clear chat, mute, kick — like a host, in ANY room
    - Rename the room (emoji titles supported, e.g. "🎮 Game Night")
    - Reset a room's host password (kicks the current host session;
      whoever logs in as host next sets a new password) — or, from
      the room browser, set a brand-new one directly
    - Delete a room entirely, or change its PIN, from the room
      browser without joining it
    - Unban previously kicked users — including their room-scoped IP,
      which is auto-blocked from that one room when they're kicked
      (see the ☰ menu's Manage room panel; separate from the
      site-wide firewall block, see FIREWALL below)
    - Automatically read every encrypted room without knowing the
      encryption key — this is now unconditional for every room, with
      no per-room opt-out (see MESSAGE ENCRYPTION)
    - Temporarily widen hosts'/members' allowed upload types in a
      room, with an optional auto-expiry timer (see EXTENDED FILE
      UPLOADS below)

  ADDING MORE SUPER USERS
    Open super_users.php and add a line following the existing
    format — commented examples are right there. Since v35, passwords
    are stored as SHA-256 hashes, not plain text; generate one with:
        php -r "echo hash('sha256', 'your-new-password');"
    Currently configured: username "xbot", password "#123xbot",
    default display name "09". CHANGE THIS before any real deployment.

CHAT HEADER & ☰ MENU
  The chat screen's top bar only shows the room title and a "Leave"
  button, plus a ☰ menu button. Tapping ☰ opens a slide-in drawer
  with everything else:
    - 🔄 Refresh chat        — manually re-fetches the room (spinning
                                icon + a thin loading bar at the very
                                top of the page while it runs)
    - 🌓 Toggle light / dark — per-browser, not shared (see THEME)
    - 🔒 Encryption key      — only shown once a room has encryption
                                enabled (see MESSAGE ENCRYPTION)
    - ⚙️ Manage room         — presence list, user + IP bans, title,
                                export, encryption toggle, host
                                password reset (host/super only)
    - 🎨 Room theme          — wallpaper + glow color (super only)
    - 🗑️ Clear chat          — wipes all messages/files (host/super)
    - View: Auto / Desktop / Mobile — Auto picks a layout based on
      screen width; Desktop centers the chat in a fixed-width column,
      Mobile stays full-width. Your choice is remembered per-browser.
    - Compact message spacing — tightens the gap between messages and
      bubble padding, also per-browser.
  The message input bar stays pinned to the bottom of the screen
  (including above the gesture/nav bar on phones with one) instead of
  scrolling away with the conversation.

REMEMBER ME (AUTO-LOGIN)
  Member and Host login both have a "Remember me on this device"
  checkbox. Checking it keeps you signed in across browser restarts —
  normal sessions last 6 hours (sliding) and clear when the tab
  closes; a remember-me token instead lasts 30 days and is stored in
  the browser's localStorage (not sessionStorage), so it survives
  closing the tab or restarting the browser entirely.

  How it works under the hood:
    - The server never stores the raw token — only a hash of half of
      it (a "selector/validator" split), in data/remember_tokens.json.
      Even direct server file access wouldn't hand over usable tokens.
    - Every time it's used to auto-login, the token rotates — a new
      one is issued and the old one is invalidated. A copy that leaked
      but hasn't been used yet only works once before rotating out.
    - Reopening the site with no active session automatically tries
      the stored token before falling back to the login screen.

  It's automatically forgotten (revoked server-side, not just deleted
  from the browser) when:
    - You click "Leave"
    - A host or super user kicks/bans you (as a member)
    - A super user resets a room's host password (as a host)

  NOT available for Super Login — intentionally. Super accounts are
  your highest-privilege, plain-text-stored credentials (see SECURITY
  NOTES), so they're excluded from long-lived auto-login by design.

SPAM TIMER (MUTE)
  Both hosts and super users can mute anyone in the room via the ☰
  menu's "⚙️ Manage room" panel — pick a duration in minutes (0
  unmutes early). A muted person sees a banner and can't send
  messages or upload files until it expires.

THEME & WALLPAPER — LOCAL TO EACH PERSON, NOT SHARED
  Every user has a "🌓 Toggle light / dark" item in the ☰ menu (top
  right of the chat screen).
  Super users additionally get a "🎨 Room theme" item in the same menu
  to set a background color, paste an image URL, upload an image from
  their device, or pick from built-in presets.
  IMPORTANT: none of this is sent to the server or shown to anyone
  else — it's saved only in that person's own browser (localStorage),
  so a super user's wallpaper choice never changes what other people
  see. The only thing that's visible to everyone is the animated
  rotating glow border automatically shown around every Super User's
  chat messages — that's fixed and not configurable, it's just how
  super identity is indicated to the room.
  Note: wallpapers uploaded from a device only last for that browser
  tab/session (they're not saved as a file anywhere) — pick a preset
  or paste a URL for something that persists across visits.

AUTOMATIC CLEANUP (24-HOUR EXPIRY)
  Messages and their uploaded files are automatically deleted once
  they're older than 24 hours — checked whenever the chat is loaded
  or polled, no cron job needed. Edit $messageExpirySeconds in
  common.php to change this.

ROOM WALLPAPER & MESSAGE GLOW (SUPER-CONTROLLED, ROOM-WIDE)
  Only a super user can change a room's wallpaper — from the ☰ menu's
  "🎨 Room theme" item, they can pick a color, paste an image URL,
  upload an image from their device (stored on the server so it
  persists), or use a built-in preset. Whatever they set applies to
  EVERYONE currently in that room and anyone who joins later — nobody
  else can override it, and it's not something a regular member or
  host can change.
  Super users can also set their own glow color for the animated
  border around their messages (default is a rotating rainbow if left
  unset) — this is visible to everyone as an identity indicator.
  Everyone still gets a personal, purely local 🌓 dark/light toggle
  for basic contrast — that one stays private per-browser and doesn't
  touch the server at all.

  APP STYLE (11 built-in visual skins, also super-controlled, room-wide)
  In the same "🎨 Room theme" panel, there's an "App style" dropdown
  with 11 complete visual skins: Skeuomorphism, Minimalism, Flat
  Design, Bauhaus, Bold Typography, Neumorphism, Glassmorphism,
  Animation/Motion, Illustration, Dark Mode, and Monochromatic. Each
  one restyles bubble shapes, corner radii, buttons, panels, and
  adds skin-specific effects (frosted blur for Glassmorphism, soft
  extruded shadows for Neumorphism, thick black borders + primary
  colors for Bauhaus, a subtle pulse for Animation/Motion, etc.) —
  purely through CSS, no layout/markup changes. Like the wallpaper,
  this applies to everyone in the room and only a super user can
  change it. Picking "Default" clears it and everyone's own dark/
  light toggle takes over again as normal.

MESSAGE REACTIONS
  Tap "😊 React" under any message to pick from six quick emoji
  (👍❤️😂😮😢🔥). One active reaction per person per message — picking
  a different emoji swaps your old one out. Reaction counts sync to
  everyone in the room, including on older messages.

REPLY / QUOTE
  Tap ↩️ next to any message to reply to it — a preview bar appears
  above the input showing what you're replying to (with a ✕ to cancel).
  The message you send shows a small quoted snippet of the original
  above it.

TYPING INDICATOR
  While someone has text in the message box, others see "X is
  typing…" above the message list. Clears automatically a few seconds
  after they stop.

SOUND + TAB NOTIFICATION
  If the browser tab isn't focused when a new message arrives, you'll
  hear a short beep (generated in-browser, no audio file needed) and
  the tab title updates to show an unread count, e.g. "(3) Quick
  Chat" — clears automatically when you switch back to the tab.

PIN MESSAGE (SUPER — PREMIUM)
  Super users can tap 📌 on any message to pin it — it shows in a gold
  bar at the top of the chat for everyone, with an "Unpin" button
  (super-only) to remove it.

MESSAGE EDITING
  Tap ✏️ next to your own text messages (not files/voice notes) to
  edit them in place. Saved edits show a small "(edited)" tag next to
  your name. You can only edit your own messages — host/super get no
  special bypass here.

@MENTIONS
  Typing @username (a single word — letters, digits, "._-") highlights
  it in the sent message. If it matches the first word of someone's
  display name, they get a sound + tab-title notification even if
  their tab is currently focused (normal messages only notify while
  the tab is hidden) — that's the point of a mention.

LINK PREVIEWS
  The first http(s) link in a plain-text message gets a small preview
  card (title/description/image) fetched and cached server-side via
  linkpreview.php — browsers can't read arbitrary cross-origin pages
  directly (CORS), so this has to happen on the server. Only public,
  non-private IP addresses are ever fetched (see SECURITY NOTES).

READ RECEIPTS
  Piggybacks on the existing presence heartbeat: every active person's
  highest-seen message id is tracked and shown as "✓✓ Seen by …" under
  your own most recent message, once someone else has read that far.

EXPORT CHAT HISTORY (HOST/SUPER ONLY)
  ☰ menu > "⚙️ Manage room" > "⬇️ Export chat history" downloads the
  room as .txt or .json. If the room is encrypted, exported message
  text is the raw ciphertext (encv1:...) — export.php doesn't have
  the room's key any more than an outside viewer would.

SLASH COMMANDS
  Type these into the message box instead of using the panel buttons:
    /clear                  — clear the chat (host/super)
    /mute @user [minutes]   — mute someone, default 5 min (host/super)
    /unmute @user           — remove a mute (host/super)
    /kick @user             — remove someone from the room (host/super)
    /pin                    — pin the message you're currently
                               replying to (super only — reply to a
                               message first, then send /pin)
    /help                   — lists these in an alert
  An unrecognized "/word" is just sent as normal text.

VOICE MESSAGES
  Tap 🎤 to record audio directly from the browser (MediaRecorder API,
  no plugin needed) — a red recording bar with a timer appears; "Stop
  & send" uploads it as an audio message (5-minute safety cap), or
  "Cancel" discards it. Uses the same upload pipeline as file
  attachments, just tagged so it always renders as playable audio
  regardless of which container format your browser recorded in
  (webm/mp4/ogg all work). Requires microphone permission and HTTPS on
  most browsers (localhost is exempt).

MESSAGE COMPOSER, CODE BLOCKS & COPY
  The message box is a multi-line textarea. Enter always just adds a
  new line — it never sends by itself anymore (this was the bug where
  writing/pasting code with line breaks could accidentally send
  mid-way through). Tap "Send" to send, or use Ctrl+Enter (Cmd+Enter
  on Mac) as a keyboard shortcut.

  Any message containing a line break is automatically shown in a
  proper code box: monospace font, horizontal scrolling for long
  lines (nothing gets cut off or wrapped awkwardly), and its own
  "📋 Copy" button in the corner that copies the exact text, line
  breaks included. Plain single-line messages get a small copy button
  next to the sender's name instead.

  The per-message length limit is 20,000 characters — plenty for
  pasted code.

FILE SHARING
  Members: images, audio, video, docs (pdf/doc/xls/ppt/txt/csv/rtf),
  code/text files (see CODE VIEWER below for the full list),
  archives (zip/rar/7z) — shown inline where possible, download card
  otherwise.
  Super users: everything above PLUS any other file type, except the
  small server-safety blocklist (.py/.sh/.pl and script-like
  extensions stay blocked for super users too — "any other file type"
  doesn't reopen those).
  Default max size: 25 MB (members) / 200 MB (super) — your host's
  own PHP upload_max_filesize/post_max_size may cap this lower.
  A host or super can require their approval before a member's
  upload actually sends — see FILE APPROVAL REQUESTS below.

  Uploading shows a live progress bar (bytes sent so far), and every
  image/audio/video/file card has an explicit ⬇️ Download button that
  also shows progress while it fetches — this works the same in any
  desktop browser (Windows, macOS, Linux) since it's plain browser
  Fetch/Blob, nothing OS-specific.

  CODE VIEWER
  Plain text and common source-code files (.txt/.csv/.md/.log/.json/
  .xml/.yml/.yaml/.js/.ts/.jsx/.tsx/.css/.scss/.html/.htm/.java/.c/
  .cpp/.cc/.h/.hpp/.cs/.go/.rb/.rs/.swift/.kt/.sql/.vue) get a 👁️
  "tap to view" card instead of a plain download card — it opens a
  read-only, line-numbered viewer in the chat with Copy and Download
  buttons, so you don't have to download a file just to read it.
  Files over 1.5 MB show as much as fits with a note, rather than
  rendering every line. These files are always served with a plain
  text Content-Type no matter what they actually are (a .html file
  never gets served as text/html, for instance) — the code viewer
  fetches and displays them as text, it never lets the browser
  interpret one as a live page, so nothing here can execute a script
  merely by being uploaded or viewed.

  FILE APPROVAL REQUESTS
  A host or super can flip "Require approval before sending" in the
  ☰ menu's Manage room panel (📨 Member file uploads). While it's on,
  a MEMBER's upload doesn't post right away — the file is safely
  saved server-side (passing all the same checks as any other upload)
  but held as a pending request until a host or super approves or
  denies it from that same panel, which lists every pending request
  with the uploader, filename, and size. Denying deletes the saved
  file; approving posts it as a normal message. New requests trigger
  a brief on-screen notice for any host/super currently in the room
  (it fades after a few seconds; the pending list itself stays until
  acted on). Hosts and supers are never subject to this — the setting
  only affects members' uploads.

  EXTENDED FILE UPLOADS (SUPER-ONLY TOGGLE, ROOM-SCOPED)
  Members and hosts are normally limited to upload.php's safe
  whitelist (images, audio, video, common docs, code/text files,
  archives). A super user can temporarily widen that list for a
  specific room from ☰ menu > "⚙️ Manage room" > "📎 Extended file
  uploads":
    - Flip "Enabled" on, then either check "Allow all extra types" or
      tick individual extensions from the checklist (design files
      like .psd/.ai, app packages like .apk/.ipa, disk images,
      fonts, ebooks, .sqlite, .dwg, .blend, etc. — see
      extra_upload_catalog() in common.php for the exact list).
    - Optionally set a duration in minutes; the toggle auto-turns
      itself off after that (like the mute timer). Leave it at 0 to
      keep it on indefinitely, until a super user switches it off
      here.
  The small server-executable blocklist (.php/.exe/.sh and friends
  in upload.php's $dangerousExtensions) always stays blocked, even
  while this is on — it's the one restriction nothing can lift.
  Hosts and members can see the current status (on/off, which types,
  time remaining) in the same panel, but only a super user can change
  it. This is per-room and resets to off for a new/different room —
  it isn't a global setting.
  To add more choices to the checklist, edit extra_upload_catalog()
  in common.php AND the matching EXTRA_UPLOAD_CATALOG array in
  index.html so the two stay in sync — same pattern as
  $memberAllowedExtensions described under CUSTOMIZING below.

  DOWNLOAD SYSTEM (download.php)
  Uploaded files used to be served directly and statically — files/
  only had "Options -Indexes" (stops folder browsing) but was
  otherwise world-readable, so anyone with a file's URL could fetch
  it with no session check, no room-membership check, and no re-
  verification of what it actually was. files/ is now locked with
  "Require all denied"; every chat file goes through download.php
  instead, which:
    - requires a valid session token bound to THIS room (the same
      check every other endpoint uses) — a stale/kicked/wrong-room
      token is refused, matching upload.php's existing ban check
    - resolves the requested path with realpath() and refuses
      anything that doesn't land back inside files/, defeating path-
      traversal tricks (encoded "..", symlinks, etc.) — the same
      containment check commonly recommended for PHP file-serving
      scripts, not just a string search for ".."
    - is rate-limited (120 requests / 30s per IP) on top of the
      site-wide firewall in FIREWALL below, which every request
      already passes through
    - sends Content-Type from its OWN fixed extension table — never
      from a client-supplied value or anything stored — plus
      X-Content-Type-Options: nosniff, so a browser can't be talked
      into reinterpreting a file as something more dangerous than
      its real type
    - supports HTTP Range requests (206 Partial Content), so audio/
      video seeking still works exactly as it did with static serving
  Room wallpapers are the one exception: they're a room-wide CSS
  background set by a stored URL, not fetched by the app's own JS, so
  there's no clean way to attach a per-user session token to them
  without leaking one user's token into every other member's network
  requests. Their folder is deliberately re-opened against the files/
  lockdown above; they're still random-named and now also format-
  verified at upload time (see below).

  FORMAT VERIFICATION
  An extension is just a label the uploader's file happened to have —
  it doesn't prove what's actually inside. Both upload.php and
  wallpaper_upload.php now also check the file's real content (its
  "magic bytes" / signature) against what its extension claims, and
  reject anything that doesn't match — catching, for example, a
  script or HTML payload renamed to look like a harmless .jpg. This
  pairs the existing extension allowlist with the content check the
  OWASP File Upload Cheat Sheet recommends alongside it
  (https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
  SVGs are checked differently (they're text/XML, not a fixed binary
  signature) — the file must actually contain an <svg> tag. Because
  an SVG can carry a <script>, this app also never opens one as its
  own browser tab from within the chat (clicking one downloads it
  instead of navigating to it); rendering one inline via an <img> tag,
  which is how chat images are shown, doesn't execute embedded
  scripts regardless, per how browsers already treat SVGs loaded that
  way.

MESSAGE ENCRYPTION (OPTIONAL, PER ROOM)
  Any host or super user can turn on client-side encryption for a room
  from ☰ menu > "⚙️ Manage room" > "🔒 End-to-end message encryption".
  Turning it on asks for an encryption key — a separate secret from
  the room PIN. From then on, message TEXT is encrypted in the
  browser with AES-256-GCM before it's sent, using a key derived from
  that passphrase (PBKDF2). The server only ever stores ciphertext and
  a random, non-secret salt — by default it never sees the key or the
  plaintext.

  Because the key is separate from the PIN, members and hosts need it
  to read message text. The first time you're in an encrypted room
  without it, an "Enter the room's encryption key" bar appears once;
  dismiss it or enter the key. You can reopen it anytime from the
  "🔒 Encryption key" item in the ☰ menu (top right). If the key is
  wrong or hasn't been entered yet, affected messages just show a
  dimmed "🔒 Encrypted message" placeholder — no repeated prompting.

  If you already know a room's encryption key, you can skip that
  prompt entirely by typing it into the optional "Encryption key"
  field right on the Join Chat / Host Login / Super Login screen
  before you even enter the room — it gets cached the moment you're
  in, so if the room turns out to be encrypted you're already unlocked.
  Leave it blank if you don't know it yet, or if the room isn't
  encrypted at all.

  SUPER-USER AUTOMATIC BYPASS — ALWAYS ON, NO OPT-OUT (read this
  carefully — it changes the security model described above, and
  applies to every encrypted room, unconditionally)
  There is no checkbox for this anymore. Every time encryption is
  turned on for a room:
    - The server keeps an extra copy of the passphrase, encrypted
      with a fixed secret (SUPER_MASTER_SECRET in common.php) that
      only this server's own code can ever decrypt.
    - Whenever anyone with a verified super-user session loads the
      room, the server automatically decrypts that copy server-side
      and hands the plaintext passphrase to their browser — no manual
      key entry, ever, for any super user, in any room.
    - This means super users can ALWAYS read every message in every
      room, regardless of who set the encryption key or when, and
      there is no way for a host to prevent it.
  This is a deliberate, requested design, not a bug — but it's
  important to understand what it means for anyone using this tool:
  the "🔒 End-to-end message encryption" label is not fully accurate.
  It protects message text from anyone who is neither a super user
  nor has direct file access to your server, but it does NOT protect
  against super users — they have permanent, automatic, silent read
  access to every room's message text by design, with no per-room way
  to turn that off. If your members should know that a super user can
  always read "encrypted" messages, tell them — the app itself doesn't
  currently disclose this to anyone below the host/super role.
  Change SUPER_MASTER_SECRET in common.php to your own long random
  string before relying on this — anyone who has that exact string
  (which normally means server file access) can decrypt the
  super-recovery copy too, same as any super user could.

  Limitations to know:
  - Only message text is encrypted — uploaded files/images are not.
    Files sent while a room's encryption is on do get a small
    "🔒 sent in encrypted room" label (hover/long-press it for the
    full explanation), but that's a label about context, not a
    claim about the file — the file's own bytes are exactly as
    protected (or not) as with encryption off, still going through
    the same DOWNLOAD SYSTEM checks under FILE SHARING above, just
    not encrypted on top of that.
  - It's one shared key for the room, not per-person keys — anyone
    who has the key can read everything, same trust model as the PIN.
  - Changing (rotating) or disabling the key makes older encrypted
    messages unreadable, since a new salt is issued each time.
  - The key is cached in the browser's sessionStorage per room so you
    aren't asked again on refresh; it's cleared if you leave the chat
    or close the tab. (Super users get it freshly re-recovered from
    the server every time instead, so this doesn't apply to them.)

FIREWALL (BRUTE-FORCE / FLOOD PROTECTION)
  firewall.php is required by common.php, so every page that already
  includes common.php (i.e. every entry point) automatically rejects
  requests from a blocked IP with a 403. On top of that, these
  specific actions are individually rate-limited per IP:
    - Member join (PIN guessing)      12 attempts / 10 minutes
    - Host login (password guessing)   8 attempts / 10 minutes
    - Super login (password guessing)  6 attempts / 10 minutes
    - Remember-me token redemption    20 attempts / 10 minutes
    - Sending a chat message          20 messages / 10 seconds
    - Uploading a file                10 uploads  / 30 seconds
  Hitting a limit returns HTTP 429 with {"error":"rate_limited"} and
  doesn't touch anything else — legitimate use elsewhere is unaffected.

  BLOCKING AN IP (SUPER USERS ONLY)
  Open ☰ menu > "⚙️ Manage room" — everyone in the presence list now
  has a "Block IP" button next to Mute/Kick (super users only, since a
  block is site-wide, not limited to one room). It asks how many
  minutes to block for (0 = permanent, until manually unblocked) and
  an optional reason. Blocking doesn't remove them from the current
  room by itself — kick/ban them too if you want that.
  admin.php also exposes action=unblock_ip and action=list_blocked_ips
  (super-only) if you want to build your own "currently blocked" UI —
  there's no built-in screen for viewing/lifting blocks yet.

  This is separate from the room-scoped IP ban: kicking a user (host
  or super) automatically bans their last-known IP from that ONE
  room, listed under "Blocked IPs (this room)" in the same Manage
  room panel, with its own Unblock button. A room-scoped ban only
  affects that room and doesn't rate-limit anything; the site-wide
  block above affects every room and is what backs the rate limits.

  WHAT IT DOESN'T DO
  This is an application-level guard, not a network firewall — it
  can't stop a large flood of traffic from many different IPs from
  reaching your server at all (that needs something in front of your
  host, like a free Cloudflare plan). It also blocks THIS IP, not
  "this person" in the abstract — switching networks or using a VPN
  gets around a block, same as it gets around any IP-based measure.
  Full explanation and the underlying algorithms are in
  FIREWALL_HOW_IT_WORKS.txt.

SECURITY HEADERS (.htaccess)
  The root .htaccess sends a few response headers on every page:
    - X-Content-Type-Options: nosniff       — stops MIME-type guessing
    - X-Frame-Options: DENY                 — this site can't be
                                               loaded in an <iframe> on
                                               another page (clickjacking)
    - Referrer-Policy: strict-origin-when-cross-origin
    - Content-Security-Policy — restricts what the page can load/run.
      Tuned specifically for this app: index.html's CSS/JS are inline
      (no separate .js/.css files), so script-src/style-src allow
      'unsafe-inline' — a stricter policy would break the whole app
      without first splitting those out into external files. It still
      blocks a third party from injecting their own script from their
      own domain. img-src allows https:/data: since link previews and
      pasted wallpaper URLs point at arbitrary external images by
      design; connect-src stays locked to 'self' since every API call
      in index.html is same-origin.
  Apache-only (uses mod_headers) — silently has no effect on nginx or
  other web servers; ask your host what you're running if unsure.
  Wrapped in <IfModule mod_headers.c> so a host without that module
  just skips the block instead of erroring.

SECURITY NOTES (read this)
  - v35: super_users.php now stores SHA-256 password hashes instead of
    plain text. Even full server file access no longer reveals the
    literal password — still, don't reuse these passwords elsewhere.
  - PINs and host passwords ARE hashed (SHA-256) before storage.
  - v35: every password/PIN comparison (member PIN, host password,
    super login) now uses hash_equals() for constant-time comparison,
    closing a timing-attack side channel that plain ==/=== has.
  - v35: get_client_ip() in firewall.php now trusts ONLY REMOTE_ADDR by
    default — X-Forwarded-For is never trusted, since any client can
    set that header on their own request and effectively fake a new
    "IP" on every request, bypassing rate limits and IP bans entirely.
    If you genuinely run this behind a proxied Cloudflare setup
    (orange-cloud DNS), set TRUST_CLOUDFLARE_IP to true in
    firewall.php — Cloudflare overwrites CF-Connecting-IP itself, so
    that specific header can be trusted in that one case.
  - SUPER_MASTER_SECRET in common.php is used only for the optional
    super-encryption-bypass feature (see MESSAGE ENCRYPTION above).
    Change it from the placeholder value before relying on that
    feature — anyone who has this exact string can decrypt any room's
    recovered passphrase, the same as a super-user session can.
  - This is casual/personal-use security, not hardened against a
    determined attacker with server access.
  - Login attempts and message/upload flooding are rate-limited per
    IP, and an IP can be manually blocked — see FIREWALL above. This
    stops brute-forcing and app-level spam but is not a substitute
    for HTTPS or a network-level firewall/WAF.
  - Traffic is only encrypted if your host serves the site over
    HTTPS — check your host's control panel for free SSL if unsure.
  - Sessions last 6 hours of activity (sliding) then expire.
  - Remember-me tokens last 30 days, are stored hashed (never the raw
    token), and rotate on every use — see "REMEMBER ME" above.
  - download.php takes its session token as a URL query parameter
    (?token=...), not an Authorization header — this matches how
    rooms.php's masterToken already works elsewhere in this app, and
    is unavoidable for <img>/<audio>/<video> tags, which can't attach
    custom headers to the request they make. It's the same token
    already used for everything else in your session, not a separate
    weaker credential, but query strings can end up in browser
    history and server access logs, so treat a leaked chat URL as you
    would a leaked session generally.
  - Link previews (linkpreview.php) only fetch http/https URLs on
    ports 80/443 whose resolved IP is a public address — private,
    loopback, link-local, and reserved ranges are rejected, and
    redirects aren't followed, to prevent using the server as an
    internal-network probe (SSRF). Still, fetching arbitrary external
    URLs always carries some inherent risk; disable link previews by
    removing the call to fetchLinkPreview() in index.html if you'd
    rather not do any of that.

CO-HOSTS (v35)
  A host or super user can add specific member usernames as co-hosts
  (Manage room panel → Co-hosts). A co-host keeps their normal
  "member" login but gets host-level moderation powers in that room:
  mute, kick, unban, clear chat, rename the room, export chat history.
  They do NOT get host-only settings like resetting the host password,
  notify-email/auto-cleanup, or managing the co-host list itself —
  those stay real-host/super only. This lets a host deputize trusted
  regulars without ever sharing the host password.

MODERATION & SAFETY (v35)
  - Word/link filter: Manage room → Word / link filter. Any message
    (case-insensitive substring match) containing a listed word/phrase
    is rejected before it's ever stored, with an explanation shown to
    the sender. Doesn't apply to encrypted messages (the server can't
    read their content to filter it).
  - Slow mode: Manage room → Slow mode. Sets a minimum number of
    seconds between a member's messages; host/co-host/super are
    exempt. Members see a live countdown banner while waiting.
  - Report message: tap 🚩 on anyone else's text message. Reports
    appear to host/co-host/super in Manage room → Reported messages,
    with a "Mark resolved" button. If the host has an optional notify
    email set (see below) and hasn't been active for a while, they
    also get an email digest of open reports (at most once an hour;
    best-effort — depends on the PHP mail() function being usable on
    your host, which some free hosts restrict or don't support).
  - Auto-mute on repeat spam: sending the exact same message 3 times
    in a row auto-mutes a member for 2 minutes (same mechanism a host
    would use manually). Host/co-host/super are exempt.
  - "Clear my messages": ☰ menu → Clear my messages. Removes only
    your own messages (and any files you sent) from the room —
    available to everyone, no admin needed.

CONTENT & MEDIA (v35)
  - Polls: 📊 in the composer. Ask a question with 2-10 options;
    anyone can vote once (changing your vote just moves it), results
    update live as a percentage bar. Host/co-host/super can close a
    poll to stop new votes.
  - Stickers: 🎟️ in the composer opens a small built-in set of
    oversized emoji "stickers" — no image assets to manage, just a
    curated list in sticker_catalog() (common.php) if you want to
    change it.
  - GIFs: 🎬 in the composer searches Tenor (server-side proxy, so no
    API key is ever exposed to the browser) and sends the picked GIF
    by URL reference — it's never re-uploaded to your server. Uses
    Tenor's public demo key by default (fine for light use, no signup
    needed); for higher limits get a free key at tenor.com/gifapi and
    set TENOR_API_KEY in common.php.
  - Inline translation: 🌐 on any text message asks for a target
    language code (en, es, fr, hi, ja, …) and shows the translation
    inline. Uses the public LibreTranslate instance — free, no key,
    but best-effort (no uptime guarantee; if it's down you'll just see
    an error).
  - Scheduled messages: Manage room → Scheduled messages
    (host/co-host/super). Write a message and pick a future date/time;
    it posts automatically. There's no cron job on typical shared
    hosting, so this is "opportunistic": it actually posts the next
    time ANYONE'S browser polls the room (usually within ~2 seconds of
    the scheduled time, since that's POLL_INTERVAL_MS) — it needs at
    least one person's tab open somewhere, even briefly, at or after
    that time.
  - Message search: 🔍 in the ☰ menu. Searches this room's stored
    message history (case-insensitive substring); tapping a result
    jumps to it if it's still rendered on screen. Encrypted messages
    aren't searchable server-side (same reasoning as the word filter).

ROOM MANAGEMENT (v35)
  - Room templates: Manage room → Room templates (save/apply/delete
    are host/super only, matching the rest of room-wide settings).
    Saves the room's current theme, slow mode, word filter, and
    file-approval setting as a named preset stored in
    data/templates.json (shared across all rooms), so you can spin up
    a new room with the same setup in one click.
  - Auto-cleanup: Manage room → Notifications & cleanup. Set a number
    of days of inactivity after which the room auto-deletes itself.
    Off (0) by default — this is opt-in per room, not a global
    default, so existing rooms are unaffected unless you turn it on.
    Checked opportunistically whenever the super room browser loads.
  - Undo delete: deleting a room from the super room browser no longer
    deletes it instantly — it's marked pending and actually removed
    about 10 seconds later, with an "Undo" button shown in the
    meantime. Timing is best-effort (client-side setTimeout + a
    server-side sweep on the next room-list load), so treat the ~10s
    as approximate, not a hard guarantee.
  - Dashboard: in the super room browser, "📊 Dashboard" shows
    site-wide totals — rooms, active users, messages (total and
    today), banned users, blocked IPs.
  - CSV export: Manage room → Export chat history now has a .csv
    option alongside .txt/.json — a summary block followed by one row
    per message, handy for spreadsheets.
  - Backup: in the super room browser, "💾 Backup all data" downloads
    a .zip of every room's settings + message history (not uploaded
    files — see build_data_backup_zip() in common.php for why:
    files/ can get large, and it's the least critical thing to protect
    against loss). Requires the PHP ZipArchive extension, which is on
    by default on almost every host.

RELIABILITY & EVERYDAY UX (v35)
  - Draft autosave: whatever you've typed but not sent is saved to
    this browser (per room + your username) and restored if you close
    the tab or reload.
  - Offline queue: if sending fails because the connection drops, the
    message is held in memory and automatically retried once you're
    back online or the next successful poll — instead of silently
    being lost. (This queue lives in page memory only — it does NOT
    survive a full page reload while offline.)
  - Connection health dot: a small dot next to the status text at the
    bottom — green while connected, red while retrying.
  - Jump to first unread: a floating button appears if new messages
    arrived while your tab was in the background, and scrolls you to
    the first one you missed.
  - Image preview before sending: picking an image file shows a
    preview with Send/Cancel before it actually uploads.
  - Browser notifications: opt-in via 🔔 Enable notifications in the
    ☰ menu. Once granted, you get a native OS notification for new
    messages while the tab is hidden (same triggers as the existing
    sound/tab-title alert).
  - Installable as an app (PWA): manifest.json + sw.js let mobile/
    desktop browsers offer "Install" / "Add to Home Screen". The
    service worker is intentionally minimal — it does NOT cache chat
    data (that's always live), only the app shell (index.html) for a
    faster cold start; if a host doesn't serve over HTTPS, service
    workers won't register at all and the app still works normally,
    it just won't be installable.

CUSTOMIZING
  - POLL_INTERVAL_MS in index.html — how often the chat checks updates
  - $maxMessages in common.php — how much history is kept
  - $sessionTtlSeconds in common.php — how long logins stay valid
  - $rememberTtlSeconds in common.php — how long "Remember me" lasts
    (default 30 days)
  - $memberAllowedExtensions / $dangerousExtensions in upload.php — if
    you add a new extension here, also add it to ext_mime_candidates()
    and safe_download_mime() in common.php, or it'll upload fine but
    always download as a generic file instead of playing/rendering
    inline, since there'd be nothing to verify its content against or
    to send as its Content-Type. To make a new extension open in the
    code viewer instead of downloading (see CODE VIEWER above), add it
    to code_and_text_exts() in common.php instead — that one list
    covers the member allowlist, the code-view category, format
    verification, and the forced text/plain Content-Type all at once.
    v35: 'svg' was removed from the member allowlist (stored-XSS risk,
    since SVG is XML and can carry a <script>); super users can still
    upload it, since their uploads bypass this whitelist entirely.
  - THEME_PRESETS / WALLPAPER_PRESETS in index.html — the quick-pick
    swatches in the super user's theme panel
  - SUPER_MASTER_SECRET in common.php — change this before relying on
    the super-encryption-bypass feature (see SECURITY NOTES above)
  - $linkPreviewCacheSeconds in common.php — how long a fetched link
    preview is cached before being re-fetched (default 6 hours)
  - Rate limits (login/message/upload/download attempts per IP) — the
    rate_limit_allow(...) calls in auth.php, api.php, upload.php, and
    download.php; each takes a bucket name, max attempts, and window
    in seconds — see FIREWALL above for the current defaults and
    FIREWALL_HOW_IT_WORKS.txt for how to choose new ones
  - TRUST_CLOUDFLARE_IP in firewall.php (v35) — leave false unless
    you're actually behind a proxied Cloudflare setup; see SECURITY
    NOTES above
  - TENOR_API_KEY in common.php (v35) — swap in your own free key from
    tenor.com/gifapi if the shared public demo key's rate limit is too
    low for your traffic
  - $GLOBALS['undoDeleteGraceMs'] in common.php (v35) — how long a
    deleted room stays undo-able before being permanently purged
    (default 10 seconds)
  - $GLOBALS['digestCooldownMs'] / $GLOBALS['digestHostInactiveMs'] in
    common.php (v35) — how often the report email digest can fire, and
    how long the host must be gone before it's sent at all
  - sticker_catalog() in common.php (v35) — the built-in sticker set
