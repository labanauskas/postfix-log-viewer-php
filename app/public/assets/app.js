/* Shared: theme + sidebar (same behaviour as the AdminKit template) */

function toggleTheme() {
  const html = document.documentElement;
  const dark = html.getAttribute('data-theme') === 'dark';
  html.setAttribute('data-theme', dark ? 'light' : 'dark');
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = 'ti ' + (dark ? 'ti-moon' : 'ti-sun');
  try { localStorage.setItem('adminkit-theme', dark ? 'light' : 'dark'); } catch (e) {}
}

(function () {
  const t = document.documentElement.getAttribute('data-theme');
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = 'ti ' + (t === 'dark' ? 'ti-sun' : 'ti-moon');
})();

let sidebarCollapsed = false;

function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const main = document.getElementById('mainContent');
  if (!sb || !main) return;
  if (window.innerWidth <= 768) {
    sb.classList.toggle('mobile-open');
  } else {
    sidebarCollapsed = !sidebarCollapsed;
    sb.classList.toggle('collapsed', sidebarCollapsed);
    main.classList.toggle('sidebar-collapsed', sidebarCollapsed);
  }
}

function closeMobileSidebar() {
  const sb = document.getElementById('sidebar');
  if (sb) sb.classList.remove('mobile-open');
}

/* Click handlers (no inline onclick attributes: the Content-Security-Policy blocks them) */
document.addEventListener('click', function (e) {
  const el = e.target.closest('[data-action]');
  if (!el) return;
  const a = el.dataset.action;
  if (a === 'theme') toggleTheme();
  else if (a === 'sidebar') toggleSidebar();
  else if (a === 'close-sidebar') closeMobileSidebar();
});
