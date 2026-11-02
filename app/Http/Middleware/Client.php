<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Client
{
    public function handle($request, Closure $next)
    {
        // Non-string JSON credentials can be coerced by SQL comparison rules.
        $token = $request->input('token', $request->route('token'));
        if (!is_string($token) || $token === '' || strlen($token) > 512) {
            throw new ApiException('token is error', 403);
        }

        $user = User::where('token', $token)->first();
        // A DB collation may equate strings that are not identical bearer tokens.
        if (!$user || $user->banned || !is_string($user->token) || !hash_equals($user->token, $token)) {
            throw new ApiException('token is error', 403);
        }

        Auth::setUser($user);
        return $next($request);
    }
}
