<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\AuthUser;

class TokenAuthMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json([
                'detail' => 'Authentication credentials were not provided.'
            ], 401);
        }

        // Support standard Django Token header format: "Token <key>"
        // Also support "Bearer <key>" format just in case, but prioritize "Token"
        $token = null;
        if (preg_match('/^(Token|Bearer)\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[2];
        }

        if (!$token) {
            return response()->json([
                'detail' => 'Invalid token.'
            ], 401);
        }

        // Query the authtoken_token table to check if the token key exists
        $tokenRow = DB::table('authtoken_token')->where('key', $token)->first();

        if (!$tokenRow) {
            return response()->json([
                'detail' => 'Invalid token.'
            ], 401);
        }

        // Find the user associated with this token
        $user = AuthUser::find($tokenRow->user_id);

        if (!$user) {
            return response()->json([
                'detail' => 'Invalid token.'
            ], 401);
        }

        // Authenticate the user for the current request context
        Auth::setUser($user);

        return $next($request);
    }
}
