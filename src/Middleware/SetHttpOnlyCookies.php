<?php

namespace PlaylogiqUtils\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class SetHttpOnlyCookies
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $cookies = $response->headers->getCookies();
        if (empty($cookies)) {
            return $response;
        }

        $response->headers->remove('Set-Cookie');
        foreach ($cookies as $cookie) {
            $new = new Cookie(
                $cookie->getName(),
                $cookie->getValue(),
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain(),
                true,
                true,
                $cookie->isRaw(),
                method_exists($cookie, 'getSameSite') ? $cookie->getSameSite() : null
            );
            $response->headers->setCookie($new);
        }

        return $response;
    }
}