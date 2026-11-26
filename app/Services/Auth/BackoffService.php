<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BackoffService
{
    private const ATTEMPTS_TTL_MINUTES = 120;

    public function registerFailedAttempt(string $email, string $ip, string $reason): void
    {
        $attemptsKey = $this->getAttemptsKey($email);
        $backoffKey = $this->getBackoffKey($email);

        $attempts = Cache::lock($this->getLockKey($email), 10)->block(5, function () use ($attemptsKey, $backoffKey): int {
            $attempts = Cache::has($attemptsKey) ? Cache::increment($attemptsKey) : 1;
            Cache::put($attemptsKey, $attempts, now()->addMinutes(self::ATTEMPTS_TTL_MINUTES));

            $lockoutMinutes = $this->getLockoutMinutes($attempts);

            if ($lockoutMinutes > 0) {
                $backoffExpiry = now()->addMinutes($lockoutMinutes);
                Cache::put($backoffKey, $backoffExpiry->timestamp, $backoffExpiry);
            }

            return $attempts;
        });

        Log::info('Login fallido', [
            'email_hash' => hash('sha256', strtolower(trim($email))),
            'ip' => $ip,
            'reason' => $reason,
            'attempts' => $attempts,
        ]);
    }

    public function isBlocked(string $email): bool
    {
        return $this->getRemainingWaitSeconds($email) > 0;
    }

    public function getRemainingWaitSeconds(string $email): int
    {
        $backoffKey = $this->getBackoffKey($email);

        return Cache::lock($this->getLockKey($email), 10)->block(5, function () use ($backoffKey): int {
            $rawExpiry = Cache::get($backoffKey);

            if (! $rawExpiry) {
                return 0;
            }

            $expiryTimestamp = $rawExpiry instanceof \DateTimeInterface
                ? $rawExpiry->getTimestamp()
                : (int) $rawExpiry;

            $waitSeconds = $expiryTimestamp - now()->timestamp;

            if ($waitSeconds <= 0) {
                Cache::forget($backoffKey);

                return 0;
            }

            return $waitSeconds;
        });
    }

    public function reset(string $email): void
    {
        Cache::lock($this->getLockKey($email), 10)->block(5, function () use ($email): void {
            Cache::forget($this->getBackoffKey($email));
            Cache::forget($this->getAttemptsKey($email));
        });
    }

    private function getLockoutMinutes(int $attempts): int
    {
        return match (true) {
            $attempts >= 15 => 60,
            $attempts >= 10 => 15,
            $attempts >= 7 => 5,
            $attempts >= 4 => 2,
            default => 0,
        };
    }

    private function getAttemptsKey(string $email): string
    {
        return 'login_attempts:'.strtolower(trim($email));
    }

    private function getBackoffKey(string $email): string
    {
        return 'login_backoff:'.strtolower(trim($email));
    }

    private function getLockKey(string $email): string
    {
        return $this->getAttemptsKey($email).':lock';
    }
}
