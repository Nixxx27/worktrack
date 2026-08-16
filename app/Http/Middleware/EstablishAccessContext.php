<?php

namespace App\Http\Middleware;

use App\Authorization\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request's AccessContext.
 *
 * Registered GLOBALLY on the web group, never as a per-route alias. Livewire routes
 * every component interaction through one global update endpoint, so a per-route
 * binding would leave that endpoint contextless — and because the scope fails
 * closed, every Livewire interaction would throw. Global placement is required by
 * the frontend choice, not merely tidier.
 */
class EstablishAccessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AccessContext $context */
        $context = app(AccessContext::class);

        $user = $request->user();

        if ($user !== null) {
            $context->forUser($user);
        } else {
            $context->guest();
        }

        return $next($request);
    }
}
