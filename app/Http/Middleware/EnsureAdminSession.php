<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjamin sesi profil admin aktif + memberlakukan App Lock.
 * Tidak ada login email/password; profil = nama saja.
 */
class EnsureAdminSession
{
    public const ACTIVITY_KEY = 'admin_activity';

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = $request->session()->get('admin_id');
        $admin = $adminId !== null ? User::find($adminId) : null;

        if ($admin === null) {
            return redirect()->route('profiles.index');
        }

        $lock = $admin->appLock;

        if ($lock !== null && $lock->enabled && $lock->pin_hash !== null) {
            $lastActivity = (int) $request->session()->get(self::ACTIVITY_KEY, now()->getTimestamp());
            $idleSeconds = now()->getTimestamp() - $lastActivity;
            $timeoutSeconds = max(1, (int) $lock->timeout_minutes) * 60;

            if ($idleSeconds >= $timeoutSeconds && ! $this->isPublicRoute($request)) {
                return redirect()->route('lock.show');
            }
        }

        $request->session()->put(self::ACTIVITY_KEY, now()->getTimestamp());

        return $next($request);
    }

    protected function isPublicRoute(Request $request): bool
    {
        return $request->routeIs('lock.*') || $request->routeIs('profiles.*');
    }
}
