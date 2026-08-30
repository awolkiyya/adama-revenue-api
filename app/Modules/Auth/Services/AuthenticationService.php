<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\AccountLockedException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    /*
    |--------------------------------------------------------------------------
    | Account Lockout Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Maximum failed password attempts before account lockout.
     */
    private const MAX_FAILED_LOGIN_ATTEMPTS = 5;

    /**
     * Base lock duration.
     *
     * First lock  = 10 minutes
     * Second lock = 20 minutes
     * Third lock  = 30 minutes
     * ...
     */
    private const LOCK_DURATION_MINUTES = 10;

    /**
     * Maximum account lock duration.
     */
    private const MAX_LOCK_DURATION_MINUTES = 120;


    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Maximum login requests allowed for the same
     * identifier + IP within the decay period.
     */
    private const RATE_LIMIT_MAX_ATTEMPTS = 10;

    /**
     * Rate limiter decay in seconds.
     */
    private const RATE_LIMIT_DECAY_SECONDS = 60;


    /**
     * Validate user credentials for web login.
     *
     * This method only authenticates the credentials.
     *
     * It does NOT create a session.
     *
     * The controller is responsible for calling:
     *
     * Auth::login($user)
     *
     * after successful validation.
     *
     * @param array{
     *     email: string,
     *     password: string
     * } $data
     *
     * @return User
     *
     * @throws ValidationException
     * @throws AccountLockedException
     */
    public function validateCredentials(array $data): User
    {
        $email = strtolower(trim($data['email']));

        /*
        |--------------------------------------------------------------------------
        | Rate Limiting
        |--------------------------------------------------------------------------
        |
        | Limit requests by email + IP.
        |
        | This protects against:
        |
        | - Brute force attacks
        | - Automated login attempts
        | - Excessive requests against one account
        |
        */

        $rateLimitKey = $this->getRateLimitKey(
            $email,
            request()->ip()
        );

        if (RateLimiter::tooManyAttempts(
            $rateLimitKey,
            self::RATE_LIMIT_MAX_ATTEMPTS
        )) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            throw ValidationException::withMessages([
                'email' => [
                    sprintf(
                        'Too many login attempts. Please try again in %d seconds.',
                        $seconds
                    ),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */

        $user = User::query()
            ->where('email', $email)
            ->with([
                'administrativeUnit.parent.parent',
                'avatar',
                'roles.permissions',
            ])
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Account Lock Check
        |--------------------------------------------------------------------------
        |
        | Check lock BEFORE password verification.
        |
        | This prevents password validity from being revealed
        | while the account is locked.
        |
        */

        if ($user && $user->locked_until) {

            if ($user->locked_until->isFuture()) {
                $this->throwLockedException($user);
            }

            /*
            |--------------------------------------------------------------------------
            | Lock Expired
            |--------------------------------------------------------------------------
            |
            | Reset the current failed-attempt counter.
            |
            | IMPORTANT:
            |
            | lockout_count is NOT reset.
            |
            | This allows lock duration escalation.
            |
            */

            $user->update([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Credentials
        |--------------------------------------------------------------------------
        */

        if (
            !$user ||
            !$user->password ||
            !Hash::check(
                $data['password'],
                $user->password
            )
        ) {
            /*
            |--------------------------------------------------------------------------
            | Count Rate-Limit Attempt
            |--------------------------------------------------------------------------
            */

            RateLimiter::hit(
                $rateLimitKey,
                self::RATE_LIMIT_DECAY_SECONDS
            );

            /*
            |--------------------------------------------------------------------------
            | Unknown Email
            |--------------------------------------------------------------------------
            |
            | Never reveal whether the email exists.
            |
            */

            if (!$user) {
                throw ValidationException::withMessages([
                    'email' => [
                        'Invalid credentials.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Wrong Password
            |--------------------------------------------------------------------------
            */

            $this->recordFailedLogin($user);

            $user = $user->fresh();

            /*
            |--------------------------------------------------------------------------
            | Check Whether Failed Attempt Triggered Lockout
            |--------------------------------------------------------------------------
            */

            if (
                $user->locked_until &&
                $user->locked_until->isFuture()
            ) {
                $this->throwLockedException($user);
            }

            throw ValidationException::withMessages([
                'email' => [
                    'Invalid credentials.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Account Status
        |--------------------------------------------------------------------------
        */

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => [
                    'Account is inactive.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Successful Authentication
        |--------------------------------------------------------------------------
        |
        | Reset current failed attempts.
        |
        | DO NOT reset lockout_count.
        |
        | lockout_count tracks repeated lockouts and allows
        | escalation of the lock duration.
        |
        */

        if ($user->failed_login_attempts > 0) {
            $user->update([
                'failed_login_attempts' => 0,
                'last_failed_login_at' => null,
                'locked_until' => null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Clear Login Rate Limiter
        |--------------------------------------------------------------------------
        |
        | The user successfully authenticated.
        |
        */

        RateLimiter::clear($rateLimitKey);

        /*
        |--------------------------------------------------------------------------
        | Record Successful Login
        |--------------------------------------------------------------------------
        */

        $user->update([
            'last_login_at' => now(),
        ]);

        return $user->fresh();
    }


    /**
     * Build the rate-limit key.
     *
     * Uses both:
     *
     * - normalized email
     * - client IP
     *
     * This prevents an attacker from attacking one account
     * from a single IP without affecting every other user.
     */
    protected function getRateLimitKey(
        string $email,
        ?string $ip
    ): string {
        return 'login:' . sha1(
            strtolower($email) . '|' . ($ip ?? 'unknown')
        );
    }


    /**
     * Record a failed login attempt.
     *
     * Lockout policy:
     *
     * 5 failures
     *      ↓
     * 1st lock  = 10 minutes
     * 2nd lock  = 20 minutes
     * 3rd lock  = 30 minutes
     *
     * Maximum = 120 minutes.
     */
    protected function recordFailedLogin(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        /*
        |--------------------------------------------------------------------------
        | Update Failed Login Tracking
        |--------------------------------------------------------------------------
        */

        $user->update([
            'failed_login_attempts' => $attempts,
            'last_failed_login_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Lock Account
        |--------------------------------------------------------------------------
        */

        if ($attempts < self::MAX_FAILED_LOGIN_ATTEMPTS) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Increment Lockout Count
        |--------------------------------------------------------------------------
        */

        $lockoutCount = $user->lockout_count + 1;

        /*
        |--------------------------------------------------------------------------
        | Calculate Escalating Lock Duration
        |--------------------------------------------------------------------------
        */

        $minutes = min(
            self::LOCK_DURATION_MINUTES * $lockoutCount,
            self::MAX_LOCK_DURATION_MINUTES
        );

        /*
        |--------------------------------------------------------------------------
        | Lock Account
        |--------------------------------------------------------------------------
        */

        $user->update([
            'failed_login_attempts' => $attempts,
            'last_failed_login_at' => now(),
            'locked_until' => now()->addMinutes($minutes),
            'lockout_count' => $lockoutCount,
        ]);
    }


    /**
     * Throw locked-account exception.
     *
     * Provides the frontend with:
     *
     * - Human-readable message
     * - Remaining seconds
     * - Exact lock expiration
     */
    protected function throwLockedException(User $user): never
    {
        $remainingSeconds = max(
            0,
            now()->diffInSeconds($user->locked_until)
        );

        throw new AccountLockedException(
            message: $this->getLockMessage($remainingSeconds),
            remainingSeconds: $remainingSeconds,
            lockedUntil: $user->locked_until,
        );
    }


    /**
     * Generate human-readable lock message.
     */
    protected function getLockMessage(
        int $remainingSeconds
    ): string {
        if ($remainingSeconds <= 0) {
            return 'Your account lock has expired. You can try logging in again.';
        }

        /*
        |--------------------------------------------------------------------------
        | Seconds
        |--------------------------------------------------------------------------
        */

        if ($remainingSeconds < 60) {

            $seconds = $remainingSeconds;

            return sprintf(
                'Your account is temporarily locked. Please try again in %d %s.',
                $seconds,
                $seconds === 1
                    ? 'second'
                    : 'seconds'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Minutes
        |--------------------------------------------------------------------------
        */

        $minutes = intdiv(
            $remainingSeconds,
            60
        );

        $seconds = $remainingSeconds % 60;

        if ($minutes < 60) {

            if ($seconds > 0) {
                return sprintf(
                    'Your account is temporarily locked. Please try again in %d %s and %d %s.',
                    $minutes,
                    $minutes === 1
                        ? 'minute'
                        : 'minutes',
                    $seconds,
                    $seconds === 1
                        ? 'second'
                        : 'seconds'
                );
            }

            return sprintf(
                'Your account is temporarily locked. Please try again in %d %s.',
                $minutes,
                $minutes === 1
                    ? 'minute'
                    : 'minutes'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Hours
        |--------------------------------------------------------------------------
        */

        $hours = intdiv(
            $minutes,
            60
        );

        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes > 0) {
            return sprintf(
                'Your account is temporarily locked. Please try again in %d %s and %d %s.',
                $hours,
                $hours === 1
                    ? 'hour'
                    : 'hours',
                $remainingMinutes,
                $remainingMinutes === 1
                    ? 'minute'
                    : 'minutes'
            );
        }

        return sprintf(
            'Your account is temporarily locked. Please try again in %d %s.',
            $hours,
            $hours === 1
                ? 'hour'
                : 'hours'
        );
    }
}
