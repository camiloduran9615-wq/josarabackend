<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyByTenantIdentifier
{
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized) {
            return $next($request);
        }

        $identifier = $this->resolveIdentifier($request);
        $tenant = Tenant::resolveByPublicIdentifier($identifier);

        if ($tenant === null) {
            abort(Response::HTTP_NOT_FOUND, 'Empresa no encontrada.');
        }

        tenancy()->initialize($tenant);

        return $next($request);
    }

    private function resolveIdentifier(Request $request): ?string
    {
        $routeIdentifier = $request->route('tenant');
        if (is_string($routeIdentifier) && trim($routeIdentifier) !== '') {
            return trim($routeIdentifier);
        }

        $host = $request->getHost();
        foreach (config('tenancy.central_domains', []) as $centralDomain) {
            $centralDomain = trim((string) $centralDomain);
            if ($centralDomain === '') {
                continue;
            }

            if ($host === $centralDomain) {
                return null;
            }

            if (str_ends_with($host, '.'.$centralDomain)) {
                $subdomain = substr($host, 0, -strlen('.'.$centralDomain));
                if ($subdomain !== '' && ! str_contains($subdomain, '.')) {
                    return $subdomain;
                }
            }
        }

        return null;
    }
}
