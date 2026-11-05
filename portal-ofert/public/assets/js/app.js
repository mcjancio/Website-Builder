/* Portal Ofert — skrypty wspólne. Strona działa bez JS; JS tylko ulepsza. */
(function () {
  'use strict';
  document.documentElement.classList.add('js');

  // Menu mobilne (przycisk z aria-expanded, zamykanie Escape).
  var toggle = document.querySelector('.nav-toggle');
  var nav = toggle && document.getElementById(toggle.getAttribute('aria-controls'));
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!open));
      nav.classList.toggle('is-open', !open);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        toggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
        toggle.focus();
      }
    });
  }
})();
