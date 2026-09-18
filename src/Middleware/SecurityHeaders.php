<?php

namespace PlaylogiqUtils\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        // This middleware is global, so a cache outage here would 500 every
        // single request — including the health endpoint, whose whole job is to
        // report that the cache is the component that is down. Fall back to
        // "flag off".
        try {
            $flag = (int) Cache::get('ff:csp:report_only', 0);
        } catch (\Throwable $e) {
            $flag = 0;
        }

        $response = $next($request);

        if ($flag !== 1) {
            return $response;
        }

        if (! $this->isHtml($request, $response)) {
            return $response;
        }

        $response->headers->remove('Content-Security-Policy');
        $response->headers->remove('Content-Security-Policy-Report-Only');
        $response->headers->remove('Report-To');
        $response->headers->remove('Reporting-Endpoints');
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    private function isHtml(Request $request, $response): bool
    {
        $ct = strtolower((string) $response->headers->get('Content-Type', ''));

        if (strpos($ct, 'text/html') !== false || strpos($ct, 'application/xhtml+xml') !== false) {
            return true;
        }

        $accept = strtolower((string) $request->headers->get('Accept', ''));

        if ($ct === '' && ($accept === '' || $accept === '*/*' || strpos($accept, 'text/html') !== false)) {
            return true;
        }

        return false;
    }
}
