'use strict';

// confirm() could cancel a submit by returning false. The shared dialog
// is async, so the first submit only opens it and the POST waits for yes.
document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (form.dataset.confirming === '1') return;
        form.dataset.confirming = '1';
        try {
            const ask = globalThis.AppModal && globalThis.AppModal.confirm;
            if (typeof ask !== 'function') return;
            const ok = await ask({
                title: form.dataset.confirmTitle || 'Confirm',
                message: form.dataset.confirmMessage || '',
                confirmLabel: form.dataset.confirmLabel || 'Confirm',
                danger: form.dataset.confirmDanger === '1',
            });
            if (!ok || !form.isConnected) return;
            form.submit();
        } finally {
            if (form.isConnected) delete form.dataset.confirming;
        }
    });
});
