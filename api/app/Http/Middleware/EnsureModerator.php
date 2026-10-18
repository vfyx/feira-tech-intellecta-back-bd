<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureModerator
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isModerator(), 403, 'Acesso restrito à moderação.');
        return $next($request);
    }
}
