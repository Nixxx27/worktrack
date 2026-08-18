{{--
    The sign-out form, everywhere it appears.

    In the header this button sits in the same row as Board, Dashboard and Settings and
    is styled like them, so the control that ends your session reads as one more place
    to go. A mis-aimed click there does not just cost you a login: the drawer's comment
    box, new-task row and health reason are all deferred wire:model, so they live only
    in the browser until submitted and go with the session.

    One component rather than three hand-copied forms because the wording is a promise
    about what is about to be lost, and a promise re-typed per page drifts. The same
    mistake as the five hand-copied navs this header replaced.

    A native confirm() rather than a styled dialog: it is already the application's
    confirmation language — wire:confirm in the drawer and the tracker-archive guard
    raise this same browser dialog — and it needs no JavaScript of its own, which
    matters because two of the three call sites are plain Blade with no Livewire on
    the page to hang an Alpine component from.
--}}
<form method="POST" action="{{ route('logout') }}"
      onsubmit="return confirm('Sign out of Worktrack? Anything you have typed and not saved will be lost.')"
      {{ $attributes }}>
    @csrf
    {{ $slot }}
</form>
