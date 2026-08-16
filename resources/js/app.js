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

                // The card directly above the drop position, so the server can compute a
                // midpoint rather than renumbering the whole column.
                const previous = card.previousElementSibling;
                const afterId = previous ? Number(previous.dataset.id) : null;

                // Nothing actually changed — don't write a movement row for a card
                // picked up and put back. A no-op move would reset its step clock and
                // corrupt exactly the metric the board exists to show.
                if (targetStep === Number(event.from.dataset.stepId) && event.oldIndex === event.newIndex) {
                    return;
                }

                this.$wire.moveProject(publicId, targetStep, afterId);
            },
        });
    },
});
