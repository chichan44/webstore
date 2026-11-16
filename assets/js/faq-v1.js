/* FAQ / Pusat Bantuan — accordion (satu item terbuka, item pertama auto-open,
   chevron berputar lewat CSS .faq-item.active, aria-expanded dijaga). */
(function () {
  'use strict';

  var items = document.querySelectorAll('.faq-item');

  function setState(item, open) {
    item.classList.toggle('active', open);
    var head = item.querySelector('.faq-head');
    if (head) head.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  document.addEventListener('click', function (ev) {
    var head = ev.target.closest ? ev.target.closest('.faq-head') : null;
    if (!head) return;
    var item = head.closest('.faq-item');
    if (!item) return;
    var wasOpen = item.classList.contains('active');
    for (var i = 0; i < items.length; i++) setState(items[i], false);
    if (!wasOpen) setState(item, true);
  });

  /* Auto-open item pertama (seperti referensi) */
  if (items.length) setState(items[0], true);
})();
