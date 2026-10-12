<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateAuthenticationInput
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            // Fortify normalizes email and checks reset tokens before its complete validation.
            $request->validate([
                'email' => ['sometimes', 'nullable', 'string'],
                'token' => ['sometimes', 'nullable', 'string'],
            ]);
        }

        return $next($request);
    }
}
