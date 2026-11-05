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

/* Formularze: blokada przycisku z komunikatem, fokus na podsumowaniu błędów. */
(function () {
  'use strict';
  document.querySelectorAll('form[data-loading-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"][data-loading-text]');
      if (btn) {
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = btn.getAttribute('data-loading-text');
      }
    });
  });
  var summary = document.querySelector('[data-focus]');
  if (summary) { summary.focus(); }
})();

/* NIP: suma kontrolna w przeglądarce + dane firmy z rejestru MF (/api/firma/{nip}). */
(function () {
  'use strict';
  var input = document.querySelector('[data-nip-lookup]');
  if (!input || !window.fetch) { return; }
  var target = document.querySelector(input.getAttribute('data-target'));
  var status = document.querySelector(input.getAttribute('data-status'));
  var initial = status ? status.textContent : '';
  var last = '';

  function valid(nip) {
    var d = nip.replace(/^PL/i, '').replace(/\D/g, '');
    if (d.length !== 10 || d === '0000000000') { return null; }
    var w = [6, 5, 7, 2, 3, 4, 5, 6, 7], sum = 0;
    for (var i = 0; i < 9; i++) { sum += w[i] * +d[i]; }
    return sum % 11 === +d[9] ? d : null;
  }
  function lookup() {
    var nip = valid(input.value);
    if (!nip || nip === last) { return; }
    last = nip;
    if (status) { status.textContent = input.getAttribute('data-text-searching'); }
    fetch(input.getAttribute('data-nip-lookup') + nip, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.found && j.company) {
          if (target && !target.value) { target.value = j.company.name; }
          if (status) { status.textContent = input.getAttribute('data-text-found').replace('{name}', j.company.name + (j.company.city ? ', ' + j.company.city : '')); }
        } else if (status) {
          status.textContent = input.getAttribute('data-text-not-found');
        }
      })
      .catch(function () { if (status) { status.textContent = initial; } });
  }
  input.addEventListener('blur', lookup);
  input.addEventListener('input', function () { if (valid(input.value)) { lookup(); } });
})();

/* Kod z telefonu: odliczanie do „Wyślij SMS” i informacja, gdy WhatsApp nie dotarł (SMS zapasowy). */
(function () {
  'use strict';
  var btn = document.querySelector('[data-countdown]');
  if (btn) {
    var left = parseInt(btn.getAttribute('data-countdown'), 10) || 0;
    var label = btn.textContent;
    var tpl = btn.getAttribute('data-countdown-text') || label;
    var tick = function () {
      if (left <= 0) { btn.disabled = false; btn.textContent = label; return; }
      btn.disabled = true;
      btn.textContent = tpl.replace('{seconds}', String(left));
      left -= 1;
      setTimeout(tick, 1000);
    };
    tick();
  }

  var info = document.getElementById('otp-sent');
  var url = info && info.getAttribute('data-status-url');
  if (!url || !window.fetch) { return; }
  var polls = 0;
  var poll = function () {
    polls += 1;
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) { return; }
        if (j.fallback_sent) {
          info.textContent = info.getAttribute('data-text-fallback');
          var form = document.querySelector('[data-sms-fallback]');
          if (form) { form.hidden = true; }
          return; // koniec — SMS poszedł
        }
        if (j.delivery === 'delivered' || j.delivery === 'read') { return; } // WhatsApp dotarł
        if (polls < 24) { setTimeout(poll, 5000); } // do 2 minut
      })
      .catch(function () {});
  };
  setTimeout(poll, 4000);
})();
