<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyLedgerApiKey
{
    /**
     * Gate GET /usage (ADR-0029) to Ledger-L5 via a shared API key.
     *
     * Requires HTTPS outside local/testing — the load balancer terminates
     * TLS and forwards X-Forwarded-Proto, which bootstrap/app.php's
     * trustProxies(at: '*') already honors, so isSecure() reflects the
     * original scheme correctly in production.
     *
     * The presented key is never logged, only whether it matched.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure() && ! app()->environment(['local', 'testing'])) {
            abort(400, 'HTTPS required.');
        }

        $expected = config('services.ledger_l5.api_key');
        $presented = $request->header('X-Ledger-Api-Key');

        if (! $expected || ! $presented || ! hash_equals($expected, $presented)) {
            Log::warning('VerifyLedgerApiKey: rejected request — missing or invalid API key', [
                'ip' => $request->ip(),
            ]);

            abort(401, 'Invalid or missing API key.');
        }

        return $next($request);
    }
}
