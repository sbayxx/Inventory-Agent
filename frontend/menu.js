// Menu bridge for the deployed Vue bundle; keeps the original bundles unchanged.
(() => {
  let checking = false;
  let allowed = false;
  let lastNav = null;
  let pendingInventory = false;
  try {
    const redirect = new URLSearchParams(window.location.search).get('redirect');
    if (window.location.pathname === '/login' && redirect === '/inventory/') {
      sessionStorage.setItem('governit-inventory-after-login', '1');
    }
    pendingInventory = sessionStorage.getItem('governit-inventory-after-login') === '1';
  } catch { /* Storage can be disabled; the menu link still works. */ }
  function findNav() {
    return document.querySelector('nav a[href="/intune"]')?.closest('nav');
  }
  function render() {
    const nav = findNav();
    if (!nav || !allowed) return;
    // The deployed ITSM login always routes to '/', so finish the pending navigation.
    if (pendingInventory) {
      pendingInventory = false;
      try { sessionStorage.removeItem('governit-inventory-after-login'); } catch {}
      window.location.assign('/inventory/');
      return;
    }
    if (nav.querySelector('[data-inventory-menu]')) return;
    const link = document.createElement('a');
    link.href = '/inventory/';
    link.textContent = 'Inventory';
    link.dataset.inventoryMenu = 'true';
    link.className = nav.querySelector('a')?.className || '';
    nav.appendChild(link);
    const software = document.createElement('a');
    software.href = '/inventory/software.php';
    software.textContent = 'Software';
    software.dataset.inventoryMenu = 'true';
    software.className = link.className;
    nav.appendChild(software);
  }
  async function check() {
    if (checking) return;
    checking = true;
    try {
      const r = await fetch('/inventory/menu.php', {credentials: 'same-origin', cache: 'no-store'});
      allowed = r.ok && (await r.json()).enabled === true;
    } catch { allowed = false; }
    finally { checking = false; }
    if (!allowed) document.querySelectorAll('[data-inventory-menu]').forEach(n => n.remove());
    render();
  }
  new MutationObserver(() => {
    const nav = findNav();
    if (nav && nav !== lastNav) {
      lastNav = nav;
      check();
    }
    render();
  }).observe(document.documentElement, {childList: true, subtree: true});
  check(); setInterval(check, 30000);
})();
