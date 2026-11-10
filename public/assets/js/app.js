(function () {
  'use strict';
  // Confirmation dialogs for destructive actions: <form data-confirm="Are you sure?">
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var msg = f.getAttribute && f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    // Loading indicator + double-submit guard
    var btn = f.querySelector && f.querySelector('button[type=submit],button:not([type])');
    if (btn && !btn.dataset.busy && !f.hasAttribute('data-noload')) {
      btn.dataset.busy = '1';
      var sp = document.createElement('span');
      sp.className = 'spinner-border spinner-border-sm ms-2';
      sp.setAttribute('role', 'status');
      sp.setAttribute('aria-hidden', 'true');
      btn.appendChild(sp);
      btn.classList.add('btn-busy');
    }
  });
  // Bond number inputs: keep digits only (supports Bangla digits), preserve leading zeros (text input)
  var bn = '০১২৩৪৫৬৭৮৯';
  document.querySelectorAll('input[data-digits]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var v = inp.value.replace(/[০-৯]/g, function (c) { return String(bn.indexOf(c)); });
      inp.value = v.replace(/[^0-9]/g, '');
    });
  });
  // Client-side series upper-casing
  document.querySelectorAll('input[data-series]').forEach(function (inp) {
    inp.addEventListener('blur', function () { inp.value = inp.value.replace(/\s+/g, '').toUpperCase(); });
  });
})();
