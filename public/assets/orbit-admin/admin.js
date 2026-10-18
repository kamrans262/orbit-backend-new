(() => {
  'use strict';
  const shell = document.querySelector('[data-shell]');
  const closeMenu = () => shell?.classList.remove('sidebar-open');
  document.querySelector('[data-menu]')?.addEventListener('click', () => shell?.classList.toggle('sidebar-open'));
  document.querySelector('[data-scrim]')?.addEventListener('click', closeMenu);
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenu(); });
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm || 'Confirm this operation?')) event.preventDefault();
    });
  });
  const json = document.querySelector('textarea[name="value_json"]');
  json?.addEventListener('blur', () => {
    try { JSON.parse(json.value); json.setCustomValidity(''); }
    catch { json.setCustomValidity('Enter valid JSON before publishing.'); }
  });
  json?.addEventListener('input', () => json.setCustomValidity(''));
})();
