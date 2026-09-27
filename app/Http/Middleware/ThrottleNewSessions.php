<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Budget the creation of NEW sessions per client address.
 *
 * Every request through the web group persists a session -- measured at one
 * `sessions` row per request that arrives without a usable session cookie,
 * whatever the route or status: a guest page, an auth redirect to /login, a
 * CSRF 419, even a 429 from a route throttle (StartSession has already run
 * by the time ThrottleRequests answers). Rows expire after SESSION_LIFETIME
 * and are pruned by a lottery, so nothing bounded how fast an anonymous
 * caller could grow the application database.
 *
 * This runs BEFORE StartSession, so a rejected request never starts (or
 * stores) a session. It leaves alone any request presenting a session cookie
 * in the valid id format: EncryptCookies has already discarded anything that
 * does not decrypt under APP_KEY, so such a cookie was issued by this app and
 * maps to exactly one row however often it is replayed. Only cookie-less
 * requests -- first visits, and clients that keep no cookie jar -- draw on
 * the budget. Growth is therefore at most PER_MINUTE x SESSION_LIFETIME rows
 * per address.
 *
 * Deliberately not the `throttle:` route middleware: that class sits after
 * StartSession in the framework's middleware priority (and so would any
 * subclass), which is exactly the order this has to avoid.
 *
 * Behind a reverse proxy the address is the proxy's unless TRUSTED_PROXIES is
 * set (see the Docker notes), in which case every fresh visitor shares one
 * budget. Sixty first-page-loads a minute is still ample for this app, and a
 * returning browser is exempt.
 */
class ThrottleNewSessions
{
    public const PER_MINUTE = 60;

    public function handle(Request $request, Closure $next)
    {
        if ($this->presentsSessionCookie($request)) {
            return $next($request);
        }

        $key = 'new-sessions|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => self::PER_MINUTE,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }

    /**
     * Same shape test as Illuminate\Session\Store::isValidId: 40 alphanumerics.
     * Anything else makes StartSession mint a new id, i.e. a new row.
     */
    private function presentsSessionCookie(Request $request): bool
    {
        $id = $request->cookies->get(config('session.cookie'));

        return is_string($id) && ctype_alnum($id) && strlen($id) === 40;
    }
}
