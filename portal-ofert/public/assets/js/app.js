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

/* Kreator oferty: licznik znaków, autozapis, upload z postępem, podgląd Markdown, potwierdzenia. */
(function () {
  'use strict';

  // Licznik znaków pod polami z maxlength (np. krótki opis).
  document.querySelectorAll('[data-counter][maxlength]').forEach(function (el) {
    var max = parseInt(el.getAttribute('maxlength'), 10);
    var out = document.createElement('p');
    out.className = 'char-counter';
    out.setAttribute('aria-live', 'polite');
    el.insertAdjacentElement('afterend', out);
    var upd = function () {
      out.textContent = el.value.length + ' / ' + max;
      out.classList.toggle('is-over', el.value.length > max);
    };
    el.addEventListener('input', upd);
    upd();
  });

  // Autozapis szkicu co 30 s (tylko gdy coś się zmieniło). Bez JS działa zwykły zapis przyciskiem.
  // window.portalSaveDraft() — zapis teraz (np. przed wgraniem zdjęć, które przeładowują stronę).
  window.portalSaveDraft = function () { return Promise.resolve(); };
  var form = document.querySelector('form[data-autosave]');
  if (form && window.fetch && window.FormData) {
    var status = form.querySelector('[data-autosave-status]');
    var dirty = false, busy = null;
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
    var save = function () {
      if (busy) { return busy; }
      if (!dirty) { return Promise.resolve(); }
      dirty = false;
      var data = new FormData(form);
      data.set('go', 'stay');
      busy = fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Autosave': '1', Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (status && j.message) { status.textContent = j.message; } })
        .catch(function () { dirty = true; })
        .then(function () { busy = null; });
      return busy;
    };
    window.portalSaveDraft = save;
    setInterval(save, 30000);
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  }

  // Upload z paskiem postępu (XHR). Bez JS formularz wysyła się normalnie.
  document.querySelectorAll('form[data-upload]').forEach(function (f) {
    var input = f.querySelector('input[type="file"]');
    var bar = f.querySelector('progress');
    var msg = f.querySelector('.dropzone__status');
    var send = function () {
      if (!input.files.length) { return; }
      // Najpierw zapis wpisanego tekstu — po wgraniu strona się przeładuje.
      window.portalSaveDraft().then(upload);
    };
    var upload = function () {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', f.action);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', function (e) {
        if (!e.lengthComputable) { return; }
        var p = Math.round(e.loaded / e.total * 100);
        bar.hidden = false; bar.value = p;
        msg.textContent = (f.getAttribute('data-text-progress') || '').replace('{percent}', p);
      });
      xhr.addEventListener('load', function () {
        var j = {};
        try { j = JSON.parse(xhr.responseText); } catch (e) { /* zwykła odpowiedź HTML */ }
        var errors = j.errors || [];
        bar.hidden = true;
        if (errors.length) { msg.textContent = errors.join(' '); }
        if (j.added > 0) {
          // Coś się dodało — odświeżamy listę (błędy innych plików zostają chwilę na ekranie).
          if (!errors.length) { msg.textContent = f.getAttribute('data-text-done') || ''; }
          setTimeout(function () { window.location.reload(); }, errors.length ? 3000 : 300);
        }
      });
      xhr.addEventListener('error', function () { f.submit(); });
      xhr.send(new FormData(f));
    };
    f.addEventListener('submit', function (e) { e.preventDefault(); send(); });
    input.addEventListener('change', send);
    ['dragenter', 'dragover'].forEach(function (t) { f.addEventListener(t, function (e) { e.preventDefault(); f.classList.add('is-dragover'); }); });
    ['dragleave', 'drop'].forEach(function (t) { f.addEventListener(t, function () { f.classList.remove('is-dragover'); }); });
    f.addEventListener('drop', function (e) { e.preventDefault(); input.files = e.dataTransfer.files; send(); });
  });

  // Podgląd Markdown (renderuje serwer — ten sam bezpieczny parser co na stronie oferty).
  document.querySelectorAll('textarea[data-md-preview]').forEach(function (ta) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'link-button';
    btn.textContent = ta.getAttribute('data-md-show');
    var box = document.createElement('div');
    box.className = 'prose md-preview';
    box.hidden = true;
    ta.insertAdjacentElement('afterend', box);
    box.insertAdjacentElement('afterend', btn);
    btn.addEventListener('click', function () {
      if (!box.hidden) { box.hidden = true; ta.hidden = false; btn.textContent = ta.getAttribute('data-md-show'); ta.focus(); return; }
      var data = new FormData();
      data.set('text', ta.value);
      data.set('_csrf', ta.form.querySelector('input[name="_csrf"]').value);
      fetch(ta.getAttribute('data-md-preview'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { box.innerHTML = j.html; box.hidden = false; ta.hidden = true; btn.textContent = ta.getAttribute('data-md-hide'); });
    });
  });

  // Potwierdzenie akcji nieodwracalnych.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); } });
  });
})();

/* Filtry: na szerokim ekranie zawsze rozwinięte (na telefonie zwinięte, chyba że coś zaznaczono). */
(function () {
  'use strict';
  var d = document.querySelector('[data-filters-details]');
  if (d && window.matchMedia('(min-width: 60rem)').matches) { d.open = true; }
  // Krótki adres wyników (do wklejenia w kampanię): puste pola nie trafiają do URL.
  var f = d && d.closest('form');
  if (f) {
    f.addEventListener('submit', function () {
      Array.prototype.forEach.call(f.elements, function (el) {
        if (el.name && (el.value === '' || el.name === 'strona')) { el.disabled = true; }
      });
      setTimeout(function () { Array.prototype.forEach.call(f.elements, function (el) { el.disabled = false; }); }, 0);
    });
  }
})();

/* Sortowanie: zmiana na liście od razu przeładowuje wyniki (bez JS — przycisk). */
(function () {
  'use strict';
  var f = document.querySelector('[data-sort-form]');
  if (f) { f.querySelector('select').addEventListener('change', function () { f.submit(); }); }
})();
