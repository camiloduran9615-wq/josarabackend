<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var mixed $user */
        $user = $request->user();

        if (! $user instanceof PlatformAdmin || ! $user->active) {
            abort(Response::HTTP_FORBIDDEN, 'No autorizado.');
        }

        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            abort(Response::HTTP_FORBIDDEN, 'No autorizado.');
        }

        return $next($request);
    }
}
