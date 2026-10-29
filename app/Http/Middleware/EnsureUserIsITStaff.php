<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsITStaff
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isIT()) {
            abort(Response::HTTP_FORBIDDEN, 'Unauthorized access. Only IT staff can access this area.');
        }

        return $next($request);
    }
}
