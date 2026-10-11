# QuickChat Code Box

A glass-styled, browser-based IDE shell — file explorer, tabbed CodeMirror
editor, Source Control / Run / Extensions / Settings panels, and an AI
assistant panel that proposes real diffs you approve before anything
changes.

## Files

```
index.php          Front-end shell (HTML). No logic — just markup + <link>/<script> tags.
assets/app.css      All styling (glass panels, 4 themes, responsive/mobile drawers).
assets/app.js       All client-side behaviour (editor, explorer, tabs, AI panel, etc).
common.php          Shared PHP helpers (JSON I/O, tokens, safe file writes).
api.php             Project save/load + checkpoints endpoint.
ai_proxy.php        Multi-provider AI proxy (Anthropic / OpenAI / Groq / OpenRouter / Gemini).
cleanup.php         Deletes projects untouched for 45+ days. Safe to run anytime.
data/projects/      Where saved projects live (auto-created). Blocked from web access by data/.htaccess.
```

## Requirements

Any standard PHP 8+ hosting with the `curl` and `mbstring` extensions
(both are on by default on virtually every host, including free ones
like InfinityFree). No database, no build step, no Node — just upload
the folder.

## Setup

1. Upload this whole folder to your PHP host (e.g. via FTP or a Termux
   `git clone` + upload).
2. Make sure `data/` is writable by PHP (`chmod -R 775 data` if needed).
3. Open `index.php` in a browser. That's it — a project token is created
   automatically on first save and appended to the URL (`?p=...`); bookmark
   or share that link to come back to the same project.
4. Open the **Settings** panel (gear icon in the activity bar) and paste in
   an API key for whichever AI provider you want to use. Keys are stored
   only in your own browser's `localStorage` — they're sent straight
   through to that provider on each request and are never written to disk
   or logged by `ai_proxy.php`.
   - Groq and Google Gemini both have generous free tiers if you want to
     try it without any cost.

## Optional: periodic cleanup

If your host supports scheduled/cron jobs (or "webcron"), point one at
`cleanup.php` every day or so to delete projects that haven't been opened
in 45+ days. It's also safe to just open that URL manually now and then.
Change `CB_PROJECT_RETENTION_DAYS` in `common.php` to adjust the window.

## Notes on scope

- This is a **single-user-per-project** tool: whoever has the `?p=...`
  link can open and edit that project. There's no login system and no
  real-time multiplayer collaboration — if you need that, it's a
  meaningfully bigger feature (live cursors, join requests, conflict
  resolution) that would need to be layered on top of `api.php`.
- The **Run panel** is intentionally a simulated console, not a real
  execution sandbox — this app never executes arbitrary code server-side
  or in the browser, by design.
- The **AI panel** calls whichever provider you configure, using your own
  key, through `ai_proxy.php`. It proposes changes as fenced code blocks
  in its reply; the front-end turns those into diff/approve cards.
