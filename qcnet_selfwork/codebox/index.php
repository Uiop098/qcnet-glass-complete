<?php
/**
 * QuickChat Code Box — front-end shell.
 * Pure HTML/CSS/JS below; all backend work happens in api.php / ai_proxy.php.
 * Served as .php only so it can sit next to those endpoints on shared PHP hosting.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>QuickChat Code Box</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldgutter.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/dialog/dialog.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/show-hint.min.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>


<div id="loadingScreen">
  <div class="brand-mark">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2l2.2 6.8L21 11l-6.8 2.2L12 20l-2.2-6.8L3 11l6.8-2.2L12 2z" fill="#fff"/></svg>
  </div>
  <div class="loading-label">Loading QuickChat Code Box…</div>
  <div class="loading-bar"><span></span></div>
</div>

<div id="reopenBar"><span>Workspace closed</span><button id="reopenBtn">Reopen</button></div>

<div id="app" role="application" aria-label="QuickChat Code Box IDE">

  <header id="topbar">
    <div id="layoutControls">
      <button class="icon-btn" id="btnLayoutCycle" data-tip="Cycle layout" aria-label="Cycle layout"></button>
      <button class="icon-btn" id="btnLayoutH" data-tip="Horizontal layout" aria-label="Horizontal layout"></button>
      <button class="icon-btn" id="btnLayoutV" data-tip="Vertical layout (default)" aria-label="Vertical layout"></button>
    </div>
    <div class="group-sep"></div>
    <div id="navigationControls">
      <button class="icon-btn" id="btnNavBack" data-tip="Back" aria-label="Back" disabled></button>
      <button class="icon-btn" id="btnNavFwd" data-tip="Forward" aria-label="Forward" disabled></button>
    </div>

    <div id="commandSearch">
      <div class="search-bar" id="searchBar">
        <span id="searchIcon"></span>
        <input id="commandInput" type="text" placeholder="QuickChat Code box" autocomplete="off" spellcheck="false" aria-label="Search files, symbols and commands">
        <span class="search-kbd">Ctrl P</span>
        <div class="search-spinner" id="searchSpinner"></div>
      </div>
    </div>

    <div id="windowControls">
      <button class="winbtn win-close" id="btnWinClose" data-tip="Close" aria-label="Close"></button>
      <button class="winbtn win-min" id="btnWinMin" data-tip="Minimize" aria-label="Minimize"></button>
      <button class="winbtn win-max" id="btnWinMax" data-tip="Maximize" aria-label="Maximize"></button>
    </div>
  </header>

  <main id="workspace">

    <aside id="aiPanel" class="panel">
      <div class="panel-header">
        <div class="ai-sparkle"></div>
        <div class="panel-title"><b id="aiHeaderTitle">Ask AI</b></div>
        <button class="icon-btn" id="btnAiMore" data-tip="More" aria-label="More options"></button>
        <button class="icon-btn mobile-drawer-close" id="btnAiCloseMobile" data-tip="Close" aria-label="Close panel"></button>
        <button class="icon-btn" id="btnAiCollapse" data-tip="Collapse panel" aria-label="Collapse AI panel"></button>
      </div>

      <div id="aiTaskCard">
        <div class="ai-task-title" id="aiTaskTitle">—</div>
        <div class="ai-task-meta">
          <span id="aiTaskFile"></span>
          <span id="aiTaskStatus"></span>
        </div>
      </div>

      <div id="aiTimeline"></div>

      <div id="aiContextRow">
        <span id="aiTokenIndicator"></span>
      </div>

      <div id="aiComposerWrap">
        <div class="approval-toggle-row">
          <span>Ask before editing</span>
          <div class="switch on" id="approvalSwitch" role="switch" aria-checked="true" tabindex="0"></div>
        </div>
        <div class="composer-box">
          <textarea id="aiInput" rows="1" placeholder="Ask AI to read, explain or edit a file…"></textarea>
          <div class="composer-actions">
            <button class="icon-btn" id="btnAiAttach" data-tip="Add current file to context" aria-label="Attach file"></button>
            <div class="spacer"></div>
            <button id="aiSendBtn" aria-label="Send message"></button>
          </div>
        </div>
        <div class="suggested-row" id="suggestedRow"></div>
      </div>
    </aside>

    <div class="divider" id="dividerAi" data-target="aiPanel"></div>

    <section id="editorPanel" class="panel">
      <div id="tabBar"></div>
      <div id="breadcrumbs"></div>
      <div id="editorHost">
        <textarea id="cmSource"></textarea>
        <div id="emptyEditor">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/></svg>
          <div class="hint">No file open — <kbd>Ctrl</kbd> <kbd>P</kbd> to open a file</div>
        </div>
      </div>
    </section>

    <div class="divider" id="dividerExplorer" data-target="explorerPanel"></div>

    <aside id="explorerPanel" class="panel">
      <div class="panel-header">
        <div class="panel-title" id="explorerPanelTitle">File Explorer</div>
        <button class="icon-btn" id="btnExplorerCollapseAll" data-tip="Collapse all" aria-label="Collapse all"></button>
        <button class="icon-btn" id="btnExplorerMore" data-tip="More actions" aria-label="More actions"></button>
        <button class="icon-btn mobile-drawer-close" id="btnExplorerCloseMobile" data-tip="Close" aria-label="Close panel"></button>
      </div>
      <div id="explorerBody"></div>
    </aside>

    <nav id="activityBar" aria-label="Activity bar"></nav>
  </main>

  <footer id="statusBar"></footer>

  <div id="dialogs"></div>
  <div id="contextMenus"></div>
  <div id="notifications"></div>
</div>

<button id="mobileAiToggle" aria-label="Open AI assistant"></button>
<div class="drawer-scrim" id="drawerScrim"></div>
<div class="tooltip" id="tooltip"></div>

<!-- CodeMirror core -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/yaml/yaml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closebrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/selection/active-line.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldgutter.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/brace-fold.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/xml-fold.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/comment/comment.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/searchcursor.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/search.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/dialog/dialog.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/show-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/anyword-hint.min.js"></script>

<script src="assets/app.js"></script>
</body>
</html>
