// Shared across all pages.
//
// 1) Restores whichever theme was last picked (localStorage survives
//    navigating to a different file, unlike in-page state).
// 2) Wires up the hamburger button (either the floating corner one, or
//    the one already inside the chat header) to open/close a slide-in
//    menu drawer.
// 3) Wires up the theme-switch pill buttons that now live INSIDE that
//    drawer, instead of floating at the top of the page.
(function () {
  const STORAGE_KEY = 'glass_preview_theme';

  function syncCurrentChip(theme) {
    // Present only on pages that have the theme accordion (currently just
    // room.html) — a no-op everywhere else, so this is safe to call from
    // every page that loads this shared script.
    const chipDot = document.getElementById('themeCurrentDot');
    const chipLabel = document.getElementById('themeCurrentLabel');
    if (!chipDot && !chipLabel) return;
    const btn = document.querySelector(`.theme-switch button[data-theme="${theme}"]`);
    const label = btn ? (btn.dataset.label || theme) : theme;
    const dotColor = btn ? btn.style.getPropertyValue('--dot') : '';
    if (chipLabel) chipLabel.textContent = label;
    if (chipDot && dotColor) chipDot.style.setProperty('--dot', dotColor);
  }

  window.QCSyncThemeChip = syncCurrentChip;

  function applyTheme(theme) {
    document.body.setAttribute('data-theme', theme);
    localStorage.setItem(STORAGE_KEY, theme);
    document.querySelectorAll('.theme-switch button').forEach((b) => {
      const isActive = b.dataset.theme === theme;
      b.classList.toggle('active', isActive);
      b.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    syncCurrentChip(theme);
  }

  // Restore saved theme as early as possible (avoids a flash of the
  // markup's default data-theme on load).
  const saved = localStorage.getItem(STORAGE_KEY);
  if (saved) document.body.setAttribute('data-theme', saved);

  // Dashboard theme sync (see room.html <head>) — a syncTheme URL param
  // set by dash_msg.php takes priority for THIS page view only. It's
  // intentionally NOT saved to localStorage, so it never overwrites the
  // person's own QuickChat theme choice for normal (non-embedded) visits.
  const pending = document.documentElement.getAttribute('data-pending-theme');
  if (pending) document.body.setAttribute('data-theme', pending);

  document.addEventListener('DOMContentLoaded', () => {
    const current = document.body.getAttribute('data-theme') || 'nocturne';

    // Sync theme pill button states
    document.querySelectorAll('.theme-switch button').forEach((btn) => {
      const isActive = btn.dataset.theme === current;
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      btn.addEventListener('click', () => applyTheme(btn.dataset.theme));
    });
    syncCurrentChip(current);

    // ---- Menu drawer open / close ----
    const drawer = document.querySelector('.menu-drawer');
    const scrim = document.querySelector('.menu-scrim');
    const openers = document.querySelectorAll('[data-menu-open]');
    const closers = document.querySelectorAll('[data-menu-close]');

    function openDrawer() {
      if (!drawer) return;
      drawer.classList.add('open');
      if (scrim) scrim.classList.add('open');
      drawer.setAttribute('aria-hidden', 'false');
    }
    function closeDrawer() {
      if (!drawer) return;
      drawer.classList.remove('open');
      if (scrim) scrim.classList.remove('open');
      drawer.setAttribute('aria-hidden', 'true');
    }

    openers.forEach((el) =>
      el.addEventListener('click', (e) => {
        e.preventDefault();
        openDrawer();
      })
    );
    closers.forEach((el) => el.addEventListener('click', closeDrawer));
    if (scrim) scrim.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeDrawer();
    });
  });
})();
