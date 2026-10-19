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
  // Real modal, not browser confirm(): typed confirmation and a reason.
  const deleteDialog = document.querySelector('#delete-user-dialog');
  const deleteForm = deleteDialog?.querySelector('[data-delete-user-form]');
  const deleteConfirm = deleteDialog?.querySelector('[data-delete-user-confirm]');
  const deleteSubmit = deleteDialog?.querySelector('[data-submit-delete-user]');
  const deleteReason = deleteDialog?.querySelector('textarea[name="reason"]');
  const syncDelete = () => {
    const reason = deleteReason?.value.trim() || '';
    const valid = deleteConfirm?.value === 'DELETE' && reason.length >= 10 && reason.length <= 500;
    if (deleteSubmit) deleteSubmit.disabled = !valid;
  };
  document.querySelector('[data-open-delete-user]')?.addEventListener('click', () => {
    deleteDialog?.showModal();
    deleteConfirm?.focus();
    syncDelete();
  });
  deleteDialog?.querySelector('[data-cancel-delete-user]')?.addEventListener('click', () => deleteDialog.close());
  deleteConfirm?.addEventListener('input', syncDelete);
  deleteReason?.addEventListener('input', syncDelete);
  deleteForm?.addEventListener('submit', (event) => {
    if (deleteConfirm?.value !== 'DELETE' || !deleteReason?.value.trim() ||
        deleteReason.value.trim().length < 10) {
      event.preventDefault();
      deleteReason?.reportValidity();
      deleteConfirm?.reportValidity();
    }
  });
  deleteDialog?.addEventListener('close', () => {
    deleteForm?.reset();
    syncDelete();
  });
  const json = document.querySelector('textarea[name="value_json"]');
  json?.addEventListener('blur', () => {
    try { JSON.parse(json.value); json.setCustomValidity(''); }
    catch { json.setCustomValidity('Enter valid JSON before publishing.'); }
  });
  json?.addEventListener('input', () => json.setCustomValidity(''));
})();
