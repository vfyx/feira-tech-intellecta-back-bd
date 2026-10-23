<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EnsureActive {
    public function handle(Request $request, Closure $next) {
        abort_if($request->user()?->suspended_at !== null, 403, 'Esta conta está suspensa.');
        return $next($request);
    }
}
