<?php

namespace App\Http\Middleware;

use App\Dba\InstanceState;
use App\Dba\Signature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** 0.20.8: only the instance manager (HMAC with the shared per-instance secret) may call /dba-api/*. */
class VerifyDbaSignature
{
    public function handle(Request $request, Closure $next)
    {
        $secret = InstanceState::secret();
        if ($secret === '') {
            return response()->json(['error' => 'instance not linked to a manager'], 503);
        }
        $nonce = (string) $request->header('X-Dba-Nonce');
        $err = Signature::check($secret, $request->method(), '/'.$request->path(), $request->header('X-Dba-Timestamp'), $nonce,
            $request->header('X-Dba-Signature'), (string) $request->getContent(), null, (int) config('erp.dba.window', Signature::WINDOW));
        if ($err) {
            return response()->json(['error' => $err], 401);
        }
        // replay protection: each nonce is accepted once inside the window
        try {
            DB::table('dba_nonces')->where('created_at', '<', now()->subSeconds(2 * Signature::WINDOW))->delete();
            DB::table('dba_nonces')->insert(['nonce' => strtolower($nonce), 'created_at' => now()]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json(['error' => 'replayed request'], 401);
        }

        return $next($request);
    }
}
