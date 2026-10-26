(function(){
  'use strict';

  const doc = document;
  const body = doc.body;
  const byId = id => doc.getElementById(id);
  const safeStorage = {
    get(key){ try { return localStorage.getItem(key); } catch (_) { return null; } },
    set(key,value){ try { localStorage.setItem(key,value); } catch (_) {} }
  };
  const isMobile = () => window.matchMedia('(max-width:1080px)').matches;
  const sidebar = byId('admSidebar');
  const overlay = byId('admOverlay');
  const toggle = byId('admToggle');
  const globalSearch = doc.querySelector('.adm-topbar .adm-search');
  const globalSearchInput = byId('admGlobalSearch');
  const mobileSearchBtn = byId('admMobileSearchBtn');

  function openSidebar(){
    if (!sidebar) return;
    sidebar.classList.add('open');
    overlay?.classList.add('show');
    body.style.overflow = 'hidden';
  }
  function closeSidebar(){
    sidebar?.classList.remove('open');
    overlay?.classList.remove('show');
    if (!doc.querySelector('.product-modal.is-open')) body.style.overflow = '';
  }
  toggle?.addEventListener('click', () => {
    if (isMobile()) {
      sidebar?.classList.contains('open') ? closeSidebar() : openSidebar();
      return;
    }
    body.classList.toggle('adm-collapsed');
    safeStorage.set('admSidebarCollapsed', body.classList.contains('adm-collapsed') ? '1' : '0');
  });
  overlay?.addEventListener('click', closeSidebar);
  sidebar?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => { if (isMobile()) closeSidebar(); }));
  window.addEventListener('resize', () => { if (!isMobile()) closeSidebar(); });
  if (safeStorage.get('admSidebarCollapsed') === '1' && !isMobile()) body.classList.add('adm-collapsed');

  function toggleMobileSearch(force){
    if (!globalSearch) return;
    const open = typeof force === 'boolean' ? force : !globalSearch.classList.contains('mobile-open');
    globalSearch.classList.toggle('mobile-open', open);
    body.classList.toggle('adm-mobile-search-open', open);
    mobileSearchBtn?.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) setTimeout(() => globalSearchInput?.focus(), 20);
  }
  mobileSearchBtn?.addEventListener('click', e => { e.stopPropagation(); toggleMobileSearch(); });
  doc.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (window.matchMedia('(max-width:820px)').matches) toggleMobileSearch(true);
      globalSearchInput?.focus();
      globalSearchInput?.select();
    }
  });

  const themeBtn = byId('admThemeBtn');
  function applyTheme(theme){
    const next = theme === 'dark' ? 'dark' : 'light';
    body.dataset.adminTheme = next;
    safeStorage.set('admTheme', next);
    if (themeBtn) {
      themeBtn.innerHTML = next === 'dark' ? (window.adminIconMoon || '☾') : (window.adminIconSun || '☼');
      themeBtn.setAttribute('aria-label', next === 'dark' ? 'Gunakan tema terang' : 'Gunakan tema gelap');
      themeBtn.title = next === 'dark' ? 'Gunakan tema terang' : 'Gunakan tema gelap';
    }
  }
  applyTheme(safeStorage.get('admTheme') || 'light');
  themeBtn?.addEventListener('click', () => applyTheme(body.dataset.adminTheme === 'dark' ? 'light' : 'dark'));

  const notifBtn = byId('admNotifBtn');
  const notifPanel = byId('admNotifPanel');
  const profileBtn = byId('admProfileBtn');
  const profileMenu = byId('admProfileMenu');
  const helpBtn = byId('admHelpBtn');
  const helpPanel = byId('admHelpPanel');

  function escapeHtml(value){
    return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  }
  function closePanel(panel, button){ panel?.classList.remove('show'); button?.setAttribute('aria-expanded','false'); }
  function closeDropdowns(except){
    if (except !== 'notif') closePanel(notifPanel, notifBtn);
    if (except !== 'profile') closePanel(profileMenu, profileBtn);
    if (except !== 'help') closePanel(helpPanel, helpBtn);
  }
  function togglePanel(panel, button, name){
    if (!panel) return;
    const willOpen = !panel.classList.contains('show');
    closeDropdowns(name);
    panel.classList.toggle('show', willOpen);
    button?.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  }
  notifBtn?.addEventListener('click', e => { e.stopPropagation(); togglePanel(notifPanel, notifBtn, 'notif'); if (notifPanel?.classList.contains('show')) refreshNotifications(); });
  profileBtn?.addEventListener('click', e => { e.stopPropagation(); togglePanel(profileMenu, profileBtn, 'profile'); });
  helpBtn?.addEventListener('click', e => { e.stopPropagation(); togglePanel(helpPanel, helpBtn, 'help'); });
  [notifPanel,profileMenu,helpPanel].forEach(panel => panel?.addEventListener('click', e => e.stopPropagation()));
  doc.addEventListener('click', e => {
    closeDropdowns();
    if (window.matchMedia('(max-width:820px)').matches && globalSearch?.classList.contains('mobile-open') && !globalSearch.contains(e.target) && e.target !== mobileSearchBtn) toggleMobileSearch(false);
  });

  function notificationHead(unreadCount){
    const unread = Math.max(0, Number(unreadCount || 0));
    return '<div class="adm-notif-head"><b>Notifikasi</b><div><button class="adm-notif-read" type="button" data-mark-read aria-label="Tandai semua notifikasi sudah dibaca"'+(unread > 0 ? '' : ' disabled')+'>Sudah dibaca</button><a href="/admin/orders.php">Lihat semua</a></div></div>';
  }
  function updateNotificationBadge(count){
    if (!notifBtn) return;
    let badge = notifBtn.querySelector('em');
    const total = Math.max(0, Number(count || 0));
    notifBtn.classList.toggle('has-unread', total > 0);
    notifBtn.setAttribute('aria-label', total > 0 ? ('Notifikasi, '+total+' belum dibaca') : 'Notifikasi, semua sudah dibaca');
    if (total > 0) {
      if (!badge) { badge = doc.createElement('em'); notifBtn.appendChild(badge); }
      badge.textContent = String(Math.min(total,99));
    } else if (badge) {
      badge.remove();
    }
  }
  function markNotificationsReadInUi(){
    updateNotificationBadge(0);
    notifPanel?.querySelectorAll('.adm-notif-item.unread').forEach(item => {
      item.classList.remove('unread');
      item.classList.add('read');
    });
    const readButton = notifPanel?.querySelector('[data-mark-read]');
    if (readButton) readButton.disabled = true;
  }
  function renderNotifications(data){
    if (!data || !notifPanel) return;
    updateNotificationBadge(data.unread || 0);
    const items = Array.isArray(data.items) ? data.items.slice(0,6) : [];
    if (!items.length) {
      notifPanel.innerHTML = notificationHead(data.unread || 0) + '<div class="adm-notif-empty">Belum ada notifikasi pesanan.</div>';
      return;
    }
    notifPanel.innerHTML = notificationHead(data.unread || 0) + items.map(item => {
      const url = item.url || ('/admin/orders.php?detail=' + encodeURIComponent(item.invoice || ''));
      return '<a class="adm-notif-item '+(item.unread ? 'unread' : 'read')+'" href="'+escapeHtml(url)+'"><span class="'+escapeHtml(item.status || '')+'"></span><div><b>'+escapeHtml(item.label || 'Pesanan')+'</b><small>'+escapeHtml(item.invoice || '')+' · '+escapeHtml(item.buyer || '')+' · '+escapeHtml(item.total_formatted || '')+'</small></div></a>';
    }).join('');
  }
  function refreshNotifications(){
    if (!notifPanel) return;
    fetch('/api/admin_notifications.php',{credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(response => response.ok ? response.json() : null)
      .then(renderNotifications)
      .catch(() => {});
  }
  notifPanel?.addEventListener('click', e => {
    const button = e.target.closest('[data-mark-read]');
    if (!button || button.disabled) return;
    e.preventDefault();
    button.disabled = true;
    markNotificationsReadInUi();
    fetch('/api/admin_notifications.php',{
      method:'POST',credentials:'same-origin',
      headers:{'X-CSRF-Token':window.adminCsrf || '','Accept':'application/json'}
    }).then(response => response.ok ? response.json() : null)
      .then(renderNotifications)
      .catch(() => { button.disabled = false; });
  });
  notifPanel?.addEventListener('click', e => {
    const item = e.target.closest('.adm-notif-item.unread');
    if (!item) return;
    markNotificationsReadInUi();
    fetch('/api/admin_notifications.php',{
      method:'POST',credentials:'same-origin',keepalive:true,
      headers:{'X-CSRF-Token':window.adminCsrf || '','Accept':'application/json'}
    }).catch(() => {});
  });
  if (notifPanel) {
    setTimeout(refreshNotifications,1100);
    window.setInterval(refreshNotifications,45000);
  }

  function linkedChecks(form){
    if (!form?.id) return [];
    return Array.from(doc.querySelectorAll('input.bulk-row-check[form="'+form.id+'"]'));
  }
  function syncBulkMaster(form){
    const master = form?.querySelector('[data-bulk-check-all]');
    if (!master) return;
    const checks = linkedChecks(form).filter(cb => !cb.disabled);
    const checked = checks.filter(cb => cb.checked).length;
    master.checked = checks.length > 0 && checked === checks.length;
    master.indeterminate = checked > 0 && checked < checks.length;
    checks.forEach(cb => cb.closest('tr')?.classList.toggle('row-selected', cb.checked));
  }
  doc.addEventListener('change', e => {
    const master = e.target.closest('[data-bulk-check-all]');
    if (master) {
      const form = byId(master.getAttribute('data-bulk-target')) || master.closest('form');
      linkedChecks(form).forEach(cb => { if (!cb.disabled) cb.checked = master.checked; });
      syncBulkMaster(form);
      return;
    }
    const row = e.target.closest('.bulk-row-check');
    if (row) syncBulkMaster(byId(row.getAttribute('form')));
  });
  doc.querySelectorAll('.bulk-form').forEach(syncBulkMaster);
  doc.addEventListener('submit', e => {
    const submitter = e.submitter || doc.activeElement;
    const confirmation = submitter?.getAttribute?.('data-confirm');
    if (confirmation && !window.confirm(confirmation)) {
      e.preventDefault();
      return;
    }
    const form = e.target.closest('.bulk-form');
    if (!form) return;
    if (submitter?.name === 'action' && submitter.value === 'delete_selected') {
      const checked = linkedChecks(form).filter(cb => cb.checked);
      if (!checked.length) {
        e.preventDefault();
        window.alert('Pilih minimal satu item terlebih dahulu.');
      }
    }
  });

  function fallbackCopy(text){
    const area = doc.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly','');
    area.style.position = 'fixed';
    area.style.left = '-9999px';
    doc.body.appendChild(area);
    area.select();
    let ok = false;
    try { ok = doc.execCommand('copy'); } catch (_) {}
    area.remove();
    return ok;
  }
  function copyText(text, button){
    const completed = () => {
      if (!button) return;
      const old = button.innerHTML;
      button.classList.add('copied');
      button.innerHTML = '<span>✓</span><b>Tersalin</b>';
      window.setTimeout(() => { button.classList.remove('copied'); button.innerHTML = old; },1300);
    };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(completed).catch(() => { if (fallbackCopy(text)) completed(); });
    else if (fallbackCopy(text)) completed();
  }
  doc.addEventListener('click', e => {
    const copyButton = e.target.closest('[data-copy-order-stock],[data-copy-value],[data-copy-target]');
    if (copyButton) {
      e.preventDefault();
      const target = copyButton.getAttribute('data-copy-target');
      const targetValue = target ? (doc.querySelector(target)?.value || doc.querySelector(target)?.textContent || '') : '';
      copyText(copyButton.getAttribute('data-copy-order-stock') || copyButton.getAttribute('data-copy-value') || targetValue, copyButton);
      return;
    }
    const dismiss = e.target.closest('[data-dismiss-alert]');
    if (dismiss) dismiss.closest('.alert')?.remove();
  });

  doc.addEventListener('wheel', e => {
    const input = e.target?.closest?.('input.no-wheel-number[type="number"]');
    if (!input) return;
    e.preventDefault();
  },{passive:false});

  doc.addEventListener('click', e => {
    const button = e.target.closest('[data-toggle-secret]');
    if (!button) return;
    const input = doc.querySelector(button.dataset.toggleSecret || '');
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-label', reveal ? 'Sembunyikan' : 'Tampilkan');
    button.textContent = reveal ? '🙈' : '👁';
  });

  const productSearch = byId('adminProductSearch');
  const productRows = Array.from(doc.querySelectorAll('[data-product-row]'));
  const productEmpty = byId('productSearchEmpty');
  function normalize(value){
    const text = String(value || '').toLowerCase();
    return typeof text.normalize === 'function' ? text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim() : text.trim();
  }
  function filterProducts(){
    const term = normalize(productSearch?.value);
    let shown = 0;
    productRows.forEach(row => {
      const match = !term || normalize(row.textContent).includes(term);
      row.classList.toggle('product-row-hidden',!match);
      if (match) shown++;
    });
    if (productEmpty) productEmpty.style.display = shown ? 'none' : 'block';
  }
  productSearch?.addEventListener('input',filterProducts);
  if (productSearch) filterProducts();

  const productModal = byId('productFormModal');
  function openProductModal(){
    if (!productModal) return;
    productModal.classList.add('is-open');
    productModal.setAttribute('aria-hidden','false');
    body.style.overflow = 'hidden';
    window.setTimeout(() => productModal.querySelector('input[name="name"]')?.focus(),40);
  }
  function closeProductModal(){
    if (!productModal) return;
    productModal.classList.remove('is-open');
    productModal.setAttribute('aria-hidden','true');
    body.style.overflow = '';
    if (location.search && window.history?.replaceState) window.history.replaceState(null,'','products.php');
  }
  doc.querySelector('[data-open-product-modal]')?.addEventListener('click',openProductModal);
  productModal?.querySelectorAll('[data-close-product-modal]').forEach(button => button.addEventListener('click',closeProductModal));
  productModal?.addEventListener('click',e => { if (e.target === productModal) closeProductModal(); });
  if (productModal?.classList.contains('is-open')) body.style.overflow = 'hidden';
  /* Product-level manual stock was removed — stock lives on product_variants.
     The per-variant "Stok manual" cell show/hide is handled in admin/products.php. */

  doc.addEventListener('change', e => {
    const input = e.target.closest('input[type="file"][data-preview-target]');
    if (!input || !input.files?.[0]) return;
    const target = doc.querySelector(input.dataset.previewTarget);
    if (!target) return;
    const url = URL.createObjectURL(input.files[0]);
    target.src = url;
    target.onload = () => URL.revokeObjectURL(url);
  });

  const gatewaySelect = doc.querySelector('select[name="payment_gateway"]');
  function syncGatewaySections(){
    const gateway = gatewaySelect?.value || 'qris_static';
    doc.querySelectorAll('[data-gateway-section]').forEach(section => {
      const modes = String(section.dataset.gatewaySection || '').split(',');
      section.classList.toggle('is-muted',!modes.includes(gateway));
    });
  }
  gatewaySelect?.addEventListener('change',syncGatewaySections);
  syncGatewaySections();

  doc.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    closeDropdowns();
    if (productModal?.classList.contains('is-open')) closeProductModal();
    if (sidebar?.classList.contains('open')) closeSidebar();
    if (globalSearch?.classList.contains('mobile-open')) toggleMobileSearch(false);
  });
})();
