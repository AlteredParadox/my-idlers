// Delete-confirmation dialog, driven by event delegation from the row buttons.
//
// Deliberately not a framework. This used to be a Vue app mounted on the page's
// whole #app container, which meant Vue's browser runtime compiler treated the
// server-rendered markup -- tables of stored hostnames, provider names, whois
// fields -- as a template. Blade's escaping does nothing to `{{ }}`, so any
// stored value containing a mustache became an expression the browser then
// evaluated. Delegation from one static listener needs no compiler at all.
//
// Markup contract (see components/delete-confirm-modal.blade.php):
//   #confirmDeleteModal[data-delete-uri]   the dialog, hidden with .d-none
//   .btn-delete[data-id][data-title]       any trigger, anywhere on the page
//   [data-modal-dismiss]                   any element that closes the dialog

function openDialog(modal, trigger) {
    const id = trigger.dataset.id || '';
    const form = modal.querySelector('form');
    const heading = modal.querySelector('.js-modal-title');
    const idInput = modal.querySelector('.js-modal-id');

    // textContent, never innerHTML: this is stored, user-controlled text and
    // the whole point of the rewrite is that it stays inert.
    if (heading) {
        heading.textContent = trigger.dataset.title || trigger.title || '';
    }
    if (idInput) {
        idInput.value = id;
    }
    if (form) {
        // Absolute: a relative action on a page loaded as /servers/ (the
        // trailing slash matches without a redirect) would POST the DELETE to
        // /servers/servers/{id} and 404.
        form.setAttribute('action', '/' + (modal.dataset.deleteUri || '') + '/' + id);
    }

    modal.classList.remove('d-none');
}

export function initDeleteModal() {
    const modal = document.getElementById('confirmDeleteModal');

    if (!modal) {
        return;
    }

    // Delegated from the document so rows added later -- DataTables paginates
    // and redraws these tables -- keep working without rebinding.
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.btn-delete');

        if (trigger) {
            openDialog(modal, trigger);
            return;
        }

        if (event.target.closest('[data-modal-dismiss]')) {
            event.preventDefault();
            modal.classList.add('d-none');
        }
    });
}
