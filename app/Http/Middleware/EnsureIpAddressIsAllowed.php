<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsureIpAddressIsAllowed
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('access.enabled', true)) {
            return $next($request);
        }

        $allowedIps = config('access.allowed_ips', []);
        $clientIp = $request->ip();

        abort_if(
            $clientIp === null
            || ! is_array($allowedIps)
            || ! IpUtils::checkIp($clientIp, $allowedIps),
            404,
        );

        return $next($request);
    }
}
