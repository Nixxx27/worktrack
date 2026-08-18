import Sortable from 'sortablejs';

/**
 * Drag-and-drop for board columns.
 *
 * SortableJS owns the DOM inside each wire:ignore column, so Livewire never fights
 * the drag. The card moves under the cursor immediately (NFR-P3 asks for instant),
 * and the server is told afterwards. If the server refuses — a Viewer, or a project
 * someone else owns — Livewire re-renders from the database and the card snaps back
 * to where it really is. Optimism without deception.
 */
window.boardColumn = (stepId) => ({
    init() {
        Sortable.create(this.$el, {
            group: 'board',
            animation: 140,
            ghostClass: 'opacity-40',
            dragClass: 'rotate-1',

            onEnd: (event) => {
                const card = event.item;
                const column = event.to;

                const targetStep = Number(column.dataset.stepId);
                const publicId = card.dataset.project;
                const sameColumn = targetStep === Number(event.from.dataset.stepId);

                // The card directly above the drop position, so the server can compute a
                // midpoint rather than renumbering the whole column.
                const previous = card.previousElementSibling;
                const afterId = previous ? Number(previous.dataset.id) : null;

                // Nothing actually changed — don't write a movement row for a card
                // picked up and put back. A no-op move would reset its step clock and
                // corrupt exactly the metric the board exists to show.
                if (sameColumn && event.oldIndex === event.newIndex) {
                    return;
                }

                // FR-3.10 — a column under a sort has no hand-authored order for a drag
                // to express. The card would render wherever the sort puts it, and
                // telling the server would only overwrite the board_position kept
                // underneath for when the sort is switched back off.
                //
                // $refresh rather than reverting the DOM by hand: re-rendering from the
                // database is already how this board undoes a move it will not honour,
                // and index arithmetic against a list Sortable has just mutated is the
                // kind of correct-looking code that is wrong in one direction only.
                //
                // Read from the <section>, never from this element — it carries
                // wire:ignore.self, so its own attributes never re-morph and would still
                // claim manual order long after the sort changed.
                //
                // Cross-column drops fall through untouched: moving a card to another
                // step is the half of the drag that is never cosmetic. The server
                // discards afterId under a sort and appends.
                if (sameColumn && column.closest('[data-manual-order]')?.dataset.manualOrder === '0') {
                    this.$wire.$refresh();

                    return;
                }

                this.$wire.moveProject(publicId, targetStep, afterId);
            },
        });
    },
});

/**
 * Confirmation for sign-out.
 *
 * Progressive enhancement over a real POST form: the form is what actually signs you
 * out, and this only steps in front of it. If the browser has no <dialog>, the submit
 * runs as before — an unguarded sign-out beats a sign-out button that does nothing.
 *
 * The listener is delegated from the document rather than bound to the button, because
 * the header renders inside a Livewire component and a bound handler would not survive
 * the morph. It is also why the dialog markup carries wire:ignore.
 */
document.addEventListener('submit', (event) => {
    const form = event.target.closest?.('form[data-sign-out]');
    const dialog = document.getElementById('sign-out-dialog');

    if (! form || typeof dialog?.showModal !== 'function') {
        return;
    }

    event.preventDefault();

    const confirmButton = dialog.querySelector('[data-sign-out-confirm]');
    const cancelButton = dialog.querySelector('[data-sign-out-cancel]');

    // Submitting via HTMLFormElement.submit() rather than requestSubmit(): submit()
    // fires no submit event, so the interception above cannot catch its own release
    // and loop. Escape, the backdrop and Cancel all just close — the platform returns
    // focus to the button that opened this.
    const release = () => {
        cleanUp();
        dialog.close();
        form.submit();
    };

    const dismiss = () => {
        cleanUp();
        dialog.close();
    };

    // Handlers are per-open and removed on close so that opening the dialog twice does
    // not leave a second release() bound and fire the submit more than once.
    function cleanUp() {
        confirmButton?.removeEventListener('click', release);
        cancelButton?.removeEventListener('click', dismiss);
        dialog.removeEventListener('click', backdrop);
        dialog.removeEventListener('close', cleanUp);
    }

    function backdrop(clickEvent) {
        if (clickEvent.target === dialog) {
            dismiss();
        }
    }

    confirmButton?.addEventListener('click', release);
    cancelButton?.addEventListener('click', dismiss);
    dialog.addEventListener('click', backdrop);
    dialog.addEventListener('close', cleanUp);

    dialog.showModal();
});
