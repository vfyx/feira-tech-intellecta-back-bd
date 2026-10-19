<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureInstitutional
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->isAdmin() || $user->isModerator() || $user->isVerifiedInstitutional()),
            403,
            'Acesso institucional restrito.'
        );
        return $next($request);
    }
}
