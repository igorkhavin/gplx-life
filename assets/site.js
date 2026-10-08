// Mobile menu + FAQ accordions for the static GPLX Life site.
(function () {
  var btn = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');
  if (btn && nav) {
    btn.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  document.querySelectorAll('.gplx-faq__item').forEach(function (item) {
    var q = item.querySelector('.gplx-faq__q, .gplx-faq__toggle');
    if (!q) return;
    item.classList.add('is-closed');
    q.setAttribute('role', 'button');
    q.setAttribute('tabindex', '0');
    var toggle = function () { item.classList.toggle('is-closed'); };
    q.addEventListener('click', toggle);
    q.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
    });
  });
})();
