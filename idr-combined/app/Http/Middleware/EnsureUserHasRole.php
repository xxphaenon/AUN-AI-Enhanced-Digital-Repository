<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: ->middleware('role:admin')
 *
 * Runs after auth:sanctum. The role is read from MongoDB on every request,
 * so promoting or demoting someone takes effect immediately, even for
 * tokens issued earlier.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated. Please log in.');
        }

        if (! $user->hasRole(...$roles)) {
            abort(403, in_array('admin', $roles, true) && count($roles) === 1
                ? 'This action requires an administrator account.'
                : 'Your account is not allowed to do this.');
        }

        return $next($request);
    }
}
