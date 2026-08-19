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
 * The @ token the caret is currently sitting inside, if it is sitting inside one.
 *
 * The lookbehind is the same rule the server matcher uses and exists for the same
 * reason: an @ that follows a letter or a digit belongs to an email address, and
 * "nikko@example.com" must not open a menu offering to complete "example.com".
 *
 * Spaces are inside the class on purpose — "Krystal Gail" has to keep the menu open
 * across the space, or the list closes exactly when it is being narrowed. It is
 * self-limiting rather than capped: run past the end of anybody's name and nothing
 * matches, so the menu closes on its own.
 */
const MENTION_TRIGGER = /(?:^|[^\p{L}\p{N}])@([\p{L}\p{N}'\-. ]{0,40})$/u;

/**
 * The @ picker in a comment box.
 *
 * Discovery, not plumbing. The server resolves a hand-typed "@jonathan" to Jonathan
 * Cruz without any of this, so a stale bundle costs the menu and nothing else — see
 * the note in components/mention-field.blade.php. What it buys is that nobody has to
 * know the feature exists, or remember how a colleague's name is spelled in the admin
 * screen, to use it.
 */
window.mentionBox = (names) => ({
    names,
    open: false,
    query: '',
    active: 0,

    /** Where the @ of the token being completed sits in the field. */
    at: -1,

    /**
     * Set while choose() writes to the field, because writing to the field fires the
     * input event this component listens to. Without it, inserting "@Jonathan Cruz "
     * immediately re-reads the caret, finds a perfectly good mention token under it and
     * reopens the menu on the name it has just finished inserting.
     */
    locked: false,

    get matches() {
        const needle = this.query.trim().toLowerCase();

        const pool = needle === ''
            ? this.names
            /* Any part of the name, not just the first: people look colleagues up by
               surname at least as often, and the picker inserts the full name either
               way, so being looser here can never produce something the server then
               fails to resolve. */
            : this.names.filter((name) => {
                const folded = name.toLowerCase();

                return folded.startsWith(needle)
                    || folded.split(' ').some((part) => part.startsWith(needle));
            });

        return pool.slice(0, 8);
    },

    sync() {
        if (this.locked) {
            return;
        }

        const field = this.$refs.field;
        const found = field.value.slice(0, field.selectionStart).match(MENTION_TRIGGER);

        if (! found || found[1].startsWith(' ')) {
            this.open = false;
            this.query = '';

            return;
        }

        /* Only when the token itself changed. sync() also runs on plain caret moves,
           and resetting the highlight every time would undo an arrow key on its way
           back up through keyup. */
        if (found[1] !== this.query) {
            this.query = found[1];
            this.active = 0;
        }

        this.at = field.selectionStart - found[1].length - 1;
        this.open = this.matches.length > 0;
    },

    choose(name) {
        const field = this.$refs.field;
        const tail = field.value.slice(field.selectionStart);
        /* A space after the name so the sentence can carry on being typed — unless the
           line already has one there, because picking a name out of the middle of a
           sentence should not leave a gap the author has to go back and close. */
        const head = `${field.value.slice(0, this.at)}@${name}${/^\s/.test(tail) ? '' : ' '}`;

        this.locked = true;
        field.value = head + tail;
        field.setSelectionRange(head.length, head.length);
        /* Livewire reads the field through its own input listener, so a value assigned
           in script is a value it never sees unless the event is raised by hand. */
        field.dispatchEvent(new Event('input', { bubbles: true }));
        this.locked = false;

        this.open = false;
        this.query = '';
        field.focus();
    },

    key(event) {
        if (! this.open) {
            return;
        }

        if (event.key === 'Escape') {
            /* The drawer closes on Escape from the window, so this one is taken out of
               the air. Dismissing a menu must never also discard the comment. */
            event.preventDefault();
            event.stopPropagation();
            this.open = false;

            return;
        }

        const options = this.matches;

        if (! options.length) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.active = (this.active + 1) % options.length;
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.active = (this.active - 1 + options.length) % options.length;
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            /* Enter is free to mean "choose" here: this is a textarea, so Enter inserts
               a newline rather than submitting, and there is no submission to steal. */
            event.preventDefault();
            this.choose(options[this.active]);
        }
    },
});

/**
 * Confirmation for sign-out.
 *
 * Upgrades the form's inline onsubmit confirm() into the real dialog. The inline guard
 * is the baseline precisely because it needs no build step; this file is the part a
 * deploy that skips `npm run build` can leave stale, and it has done exactly that once
 * already — production ran the new form against an old bundle and signed people out on
 * the first click.
 *
 * Hence the capture phase and stopPropagation(): the event is taken before it reaches
 * the form, so the inline confirm() never runs and nobody sees two prompts. Drop this
 * bundle and the inline guard simply takes over again.
 *
 * Delegated from the document rather than bound to the button, because the header
 * renders inside a Livewire component and a bound handler would not survive the morph.
 * It is also why the dialog markup carries wire:ignore.
 */
document.addEventListener('submit', (event) => {
    const form = event.target.closest?.('form[data-sign-out]');
    const dialog = document.getElementById('sign-out-dialog');

    if (! form || typeof dialog?.showModal !== 'function') {
        return;
    }

    // Before the form's own onsubmit, so the native confirm() stays out of the way.
    event.stopPropagation();
    event.preventDefault();

    const confirmButton = dialog.querySelector('[data-sign-out-confirm]');
    const cancelButton = dialog.querySelector('[data-sign-out-cancel]');

    // Submitting via HTMLFormElement.submit() rather than requestSubmit(): submit()
    // fires no submit event, so neither this interception nor the form's own inline
    // confirm() sees the release — no loop, and no second prompt on the way out.
    // Escape, the backdrop and Cancel all just close; the platform returns focus to
    // the button that opened this.
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
}, true);
