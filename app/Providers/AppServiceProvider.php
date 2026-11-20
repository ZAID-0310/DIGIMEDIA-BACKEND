<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
    
    public function boot(): void
    {

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? strtolower(trim($email)) : '';
            $emailKey = $normalizedEmail !== '' ? $normalizedEmail : 'unknown';
            $emailHash = hash('sha256', $emailKey);

            return [
                Limit::perMinute(5)
                    ->by('login_ip:'.$request->ip())
                    ->response(function (Request $request, array $headers) use ($emailHash) {
                        Log::warning('Rate limit alcanzado por IP', [
                            'ip' => $request->ip(),
                            'email_hash' => $emailHash,
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Demasiados intentos. Intenta de nuevo en unos minutos.',
                        ], 429, $headers);
                    }),

                Limit::perMinute(5)
                    ->by('login_email:'.$emailKey)
                    ->response(function (Request $request, array $headers) use ($emailHash) {
                        Log::warning('Rate limit alcanzado por email', [
                            'ip' => $request->ip(),
                            'email_hash' => $emailHash,
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Demasiados intentos para esta cuenta. Intenta de nuevo en unos minutos.',
                        ], 429, $headers);
                    }),
            ];
        });

        RateLimiter::for('reset_password', function (Request $request) {
            return [
                // Límite por IP: máx 5 intentos/min
                Limit::perMinute(5)
                    ->by('reset_password_ip:' . $request->ip())
                    ->response(function () {
                        Log::warning('Rate limit reset_password alcanzado por IP', [
                            'ip' => request()->ip(),
                            'email' => request()->input('email'),
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Demasiados intentos de recuperación. Intenta de nuevo en unos minutos.',
                        ], 429);
                    }),

                // Límite por email: máx 3 intentos/min
                Limit::perMinute(3)
                    ->by('reset_password_email:' . strtolower($request->input('email', 'unknown')))
                    ->response(function () {
                        Log::warning('Rate limit reset_password alcanzado por email', [
                            'ip' => request()->ip(),
                            'email' => request()->input('email'),
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Demasiados intentos para este correo. Intenta de nuevo en unos minutos.',
                        ], 429);
                    }),
            ];
        });

        RateLimiter::for('update_password', function (Request $request) {
            return [
                // Límite por IP: máx 5 intentos/min
                Limit::perMinute(5)
                    ->by('update_password_ip:' . $request->ip())
                    ->response(function () {
                        Log::warning('Rate limit update_password alcanzado por IP', [
                            'ip' => request()->ip(),
                            'token_hash' => hash('sha256', request()->input('token')),
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Demasiados intentos para actualizar contraseña. Intenta de nuevo en unos minutos.',
                        ], 429);
                    }),

                // Límite por token: máx 3 intentos/min
                Limit::perMinute(3)
                    ->by('update_password_token:' . hash('sha256', strtolower((string) $request->input('token', 'unknown'))))
                    ->response(function () {
                        Log::warning('Rate limit update_password alcanzado por token', [
                            'ip' => request()->ip(),
                            'token_hash' => hash('sha256', request()->input('token')),
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Demasiados intentos con este token. Intenta de nuevo en unos minutos.',
                        ], 429);
                    }),
            ];
        });

        if (app()->environment('local')) {
        \Cloudinary\Configuration\Configuration::instance()->cloud->apiSecret;
        // Deshabilitar SSL verification globalmente para Cloudinary en local
        config(['cloudinary.curl_options' => [CURLOPT_SSL_VERIFYPEER => false]]);
        }
    }
}