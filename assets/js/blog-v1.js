/* Blog listing: pencarian live (submit form setelah jeda ketik), ala koalastore.digital/blogs. */
(function () {
  var form = document.querySelector('.blog-toolbar');
  if (!form) return;
  var input = form.querySelector('input[name="q"]');
  if (!input) return;
  var timer = null;
  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      form.submit();
    }, 450);
  });
})();

/* Halaman artikel: tombol "Copy Link" — salin URL halaman ke clipboard. */
(function () {
  var btn = document.querySelector('[data-copy-link]');
  if (!btn) return;
  var timer = null;
  function flash() {
    btn.classList.add('copied');
    clearTimeout(timer);
    timer = setTimeout(function () {
      btn.classList.remove('copied');
    }, 1800);
  }
  function legacyCopy(url) {
    var ta = document.createElement('textarea');
    ta.value = url;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      flash();
    } catch (e) {}
    document.body.removeChild(ta);
  }
  btn.addEventListener('click', function () {
    var url = btn.getAttribute('data-copy-link') || window.location.href;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(flash, function () {
        legacyCopy(url);
      });
    } else {
      legacyCopy(url);
    }
  });
})();
