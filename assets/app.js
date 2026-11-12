// Progressive enhancement only: every rule here is also enforced by the PHP backend.
document.addEventListener('DOMContentLoaded', function () {
  document.documentElement.classList.add('js');

  // Confirm before deleting.
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // Submit the status form as soon as a new status is picked.
  document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });

  // Friendly client-side validation for required fields.
  document.querySelectorAll('form[data-validate]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      var firstBad = null;
      form.querySelectorAll('[required]').forEach(function (field) {
        var slot = form.querySelector('[data-error-for="' + field.name + '"]');
        var empty = field.value.trim() === '';
        if (slot) slot.textContent = empty ? 'This field is required.' : '';
        if (empty && !firstBad) firstBad = field;
      });
      var pw = form.querySelector('input[name="password"][minlength]');
      var cf = form.querySelector('input[name="confirm"]');
      if (!firstBad && pw && pw.value.length < 8) {
        form.querySelector('[data-error-for="password"]').textContent = 'Use at least 8 characters for your password.';
        firstBad = pw;
      }
      if (!firstBad && pw && cf && pw.value !== cf.value) {
        form.querySelector('[data-error-for="confirm"]').textContent = 'The two passwords do not match.';
        firstBad = cf;
      }
      if (firstBad) { ev.preventDefault(); firstBad.focus(); }
    });
  });
});
