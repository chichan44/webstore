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
