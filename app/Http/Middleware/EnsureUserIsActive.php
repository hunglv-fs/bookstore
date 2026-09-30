<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests from deactivated accounts even if they still hold a valid
 * token, so deactivating a user takes effect immediately.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
