<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:customer') or ->middleware('role:admin').
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (! $request->user()?->hasRole(RoleName::from($role))) {
            abort(Response::HTTP_FORBIDDEN, "This action is only available to {$role}s.");
        }

        return $next($request);
    }
}
