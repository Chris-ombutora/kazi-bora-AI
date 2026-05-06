<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use App\Models\User;

/**
 * JWT Authentication Middleware.
 * Validates the Bearer token on protected routes and injects the authenticated user.
 */
class JwtAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => 'Authentication required. Please provide a Bearer token.',
            ], 401);
        }

        try {
            $secret = config('services.jwt.secret');
            $algorithm = config('services.jwt.algorithm');

            $decoded = JWT::decode($token, new Key($secret, $algorithm));

            $user = User::find($decoded->sub);

            if (!$user) {
                return response()->json(['error' => 'User not found.'], 401);
            }

            // Inject user into request for downstream controllers
            $request->merge(['auth_user' => $user]);
            $request->setUserResolver(fn() => $user);

        } catch (ExpiredException $e) {
            return response()->json(['error' => 'Token expired. Please log in again.'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid token.'], 401);
        }

        return $next($request);
    }
}
