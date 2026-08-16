<?php

/**
 * The root route no longer renders a public page: unauthenticated visitors are sent
 * to sign-in, and authenticated ones are routed by account status. Worktrack has no
 * public surface at all beyond the sign-in and break-glass pages.
 */
it('sends an unauthenticated visitor to sign in', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('serves the sign-in page publicly', function () {
    $this->get('/login')->assertOk()->assertSee('Continue with Google');
});
