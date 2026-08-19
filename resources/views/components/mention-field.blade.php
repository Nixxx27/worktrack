{{--
    A comment box that can name a colleague — FR-4.5.

    The box used to promise this in its own placeholder ("type @ and a colleague's
    name") and then offer nothing: no list, no completion, no confirmation. The only
    way to reach somebody was to already know the exact spelling of their name as the
    admin had registered it, and to type all of it. This is the list.

    It is worth saying what it is NOT for. The server resolves "@jonathan" to Jonathan
    Cruz on its own now, so this is not load-bearing — it is discovery. Someone who has
    never been told the feature exists types @ and finds out who they can reach, and
    everyone else gets the exact spelling inserted for them rather than remembered.
    That ordering matters for deploys: this file needs `npm run build` to reach
    production and app.js has been left stale there before. If it is, the picker simply
    does not appear and every mention still works by hand.

    Alpine owns the menu entirely. Opening it, filtering it and choosing from it are
    all local, and a round trip between typing "@j" and seeing a name would put the
    list behind the word the person is already finishing.
--}}
@props(['names', 'field'])

<div class="relative" x-data="mentionBox(@js($names))" x-on:click.outside="open = false">
    <textarea x-ref="field"
              id="{{ $field }}"
              x-on:input="sync()"
              x-on:click="sync()"
              x-on:keyup="sync()"
              x-on:keydown="key($event)"
              x-on:blur="open = false"
              autocomplete="off"
              aria-autocomplete="list"
              aria-controls="{{ $field }}-mentions"
              :aria-expanded="open ? 'true' : 'false'"
              :aria-activedescendant="open ? '{{ $field }}-mention-' + active : null"
              {{ $attributes }}></textarea>

    {{-- Options are not focusable and the caret never leaves the textarea: that is the
         whole combobox pattern, and it is why choosing is bound to mousedown with
         preventDefault rather than to click. A click would blur the field first, the
         blur handler would close the menu, and the option would be gone by the time the
         click landed on it. --}}
    <div x-show="open" x-cloak
         id="{{ $field }}-mentions"
         role="listbox"
         aria-label="Colleagues you can mention"
         class="absolute inset-x-0 top-full z-20 mt-1 max-h-52 overflow-y-auto rounded-xl border border-line-strong bg-surface py-1 shadow-xl">
        <template x-for="(name, index) in matches" :key="name">
            <div :id="'{{ $field }}-mention-' + index"
                 role="option"
                 :aria-selected="index === active ? 'true' : 'false'"
                 x-on:mousedown.prevent="choose(name)"
                 x-on:mousemove="active = index"
                 :class="index === active ? 'bg-powder-200 text-royal-900' : 'text-ink'"
                 class="cursor-pointer px-3 py-1.5 text-sm"
                 x-text="'@' + name"></div>
        </template>
    </div>
</div>
