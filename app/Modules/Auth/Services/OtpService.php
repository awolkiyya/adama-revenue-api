<?php

namespace App\Modules\Auth\Services;

use App\Models\Citizen;
use App\Models\CitizenAccount;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\SmsService;
use App\Supports\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OtpService
{
    /**
     * OTP configuration.
     */
    private const OTP_EXPIRATION_MINUTES = 5;
    private const OTP_MAX_ATTEMPTS = 5;
    private const OTP_RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private SmsService $smsService,
        private AuthTokenService $authTokenService
    ) {
    }

    /**
     * ============================================================
     * SEND OTP
     * ============================================================
     *
     * Supports:
     *
     * 1. Existing user login
     * 2. Registered citizen without authentication account
     *
     * Existing user:
     *
     *     users
     *       ↓
     *     OTP
     *
     * New citizen:
     *
     *     citizens
     *       ↓
     *     OTP
     *       ↓
     *     verification
     *       ↓
     *     users + citizen_accounts
     *
     * IMPORTANT:
     * We do NOT create the User or CitizenAccount here.
     * They are created only after successful OTP verification.
     */
    public function send(array $data): array
    {
        $requestId = (string) Str::uuid();

        $phone = PhoneNumber::normalizeEthiopian(
            $data['phone']
        );

        $type = $data['type'] ?? 'login';

        Log::info('OTP request received.', [
            'request_id' => $requestId,
            'service' => self::class,
            'operation' => 'send',
            'type' => $type,
            'phone' => $this->maskPhone($phone),
            'ip_address' => request()->ip(),
        ]);

        Log::debug('OTP phone number normalized.', [
            'request_id' => $requestId,
            'service' => self::class,
            'operation' => 'send',
            'type' => $type,
            'phone' => $this->maskPhone($phone),
            'normalized_phone' => $this->maskPhone($phone),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Find existing authentication user
        |--------------------------------------------------------------------------
        */

        $user = User::query()
            ->where('phone', $phone)
            ->first();

        if ($user) {
            Log::info('OTP existing user account found.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'user_type' => $user->user_type,
                'is_active' => $user->is_active,
                'is_phone_verified' => $user->is_phone_verified,
                'phone' => $this->maskPhone($phone),
            ]);

            if (!$user->is_active) {
                Log::warning('OTP request rejected because user account is inactive.', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'phone' => $this->maskPhone($phone),
                ]);

                throw ValidationException::withMessages([
                    'phone' => [
                        'Account is inactive.'
                    ],
                ]);
            }

            return $this->sendOtpForExistingUser(
                $user,
                $phone,
                $type,
                $requestId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | No User account.
        |
        | Look for registered citizen.
        |--------------------------------------------------------------------------
        */

        Log::debug('OTP authentication user not found. Looking for registered citizen.', [
            'request_id' => $requestId,
            'phone' => $this->maskPhone($phone),
        ]);

        $citizen = Citizen::query()
            ->where('phone', $phone)
            ->where('is_active', true)
            ->first();

        if (!$citizen) {
            Log::warning('OTP account lookup failed.', [
                'request_id' => $requestId,
                'service' => self::class,
                'operation' => 'send',
                'type' => $type,
                'phone' => $this->maskPhone($phone),
                'reason' => 'citizen_not_found',
            ]);

            throw ValidationException::withMessages([
                'phone' => [
                    'No registered citizen was found with this phone number.'
                ],
            ]);
        }

        Log::info('Registered citizen found without authentication account.', [
            'request_id' => $requestId,
            'citizen_id' => $citizen->id,
            'citizen_uid' => $citizen->citizen_uid,
            'phone' => $this->maskPhone($phone),
            'is_active' => $citizen->is_active,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Safety check:
        | Make sure a CitizenAccount does not already exist.
        |--------------------------------------------------------------------------
        */

        $existingCitizenAccount = CitizenAccount::query()
            ->where('citizen_id', $citizen->id)
            ->first();

        if ($existingCitizenAccount) {
            Log::warning('Citizen account exists but authentication user was not found.', [
                'request_id' => $requestId,
                'citizen_id' => $citizen->id,
                'citizen_account_id' => $existingCitizenAccount->id,
                'user_id' => $existingCitizenAccount->user_id,
                'phone' => $this->maskPhone($phone),
                'reason' => 'orphaned_citizen_account',
            ]);

            throw ValidationException::withMessages([
                'phone' => [
                    'Citizen authentication account is inconsistent. Please contact support.'
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent OTP spam
        |--------------------------------------------------------------------------
        */

        $this->ensureCooldown(
            $phone,
            $type,
            $requestId
        );

        /*
        |--------------------------------------------------------------------------
        | Issue onboarding OTP.
        |
        | user_id is intentionally NULL because the User does
        | not exist until OTP verification succeeds.
        |--------------------------------------------------------------------------
        */

        return $this->issueOtp(
            user: null,
            phone: $phone,
            type: $type,
            requestId: $requestId
        );
    }

    /**
     * ============================================================
     * VERIFY OTP
     * ============================================================
     *
     * Supports:
     *
     * Existing user:
     *
     *     OTP
     *       ↓
     *     existing User
     *       ↓
     *     login
     *
     * First-time citizen:
     *
     *     OTP
     *       ↓
     *     Citizen
     *       ↓
     *     create User
     *       ↓
     *     create CitizenAccount
     *       ↓
     *     login
     */
    public function verify(array $data): array
    {
        $requestId = (string) Str::uuid();

        $phone = PhoneNumber::normalizeEthiopian(
            $data['phone']
        );

        $code = (string) $data['otp'];

        $type = $data['type'] ?? 'login';

        Log::info('OTP verification request received.', [
            'request_id' => $requestId,
            'service' => self::class,
            'operation' => 'verify',
            'type' => $type,
            'phone' => $this->maskPhone($phone),
            'ip_address' => request()->ip(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Find latest active OTP
        |--------------------------------------------------------------------------
        */

        $otp = OtpVerification::query()
            ->where('phone', $phone)
            ->where('type', $type)
            ->where('is_used', false)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        if (!$otp) {
            Log::warning('OTP verification failed because no active OTP was found.', [
                'request_id' => $requestId,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'reason' => 'otp_not_found',
            ]);

            throw ValidationException::withMessages([
                'otp' => [
                    'OTP not found.'
                ],
            ]);
        }

        Log::debug('Active OTP record found.', [
            'request_id' => $requestId,
            'otp_id' => $otp->id,
            'otp_user_id' => $otp->user_id,
            'phone' => $this->maskPhone($phone),
            'type' => $type,
            'attempts' => $otp->attempts,
            'expires_at' => $otp->expires_at,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Check expiration
        |--------------------------------------------------------------------------
        */

        if (now()->greaterThan($otp->expires_at)) {
            Log::warning('OTP verification failed because OTP expired.', [
                'request_id' => $requestId,
                'otp_id' => $otp->id,
                'phone' => $this->maskPhone($phone),
                'expires_at' => $otp->expires_at,
                'current_time' => now(),
                'reason' => 'otp_expired',
            ]);

            throw ValidationException::withMessages([
                'otp' => [
                    'OTP expired.'
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Check maximum attempts
        |--------------------------------------------------------------------------
        */

        if ($otp->attempts >= self::OTP_MAX_ATTEMPTS) {
            Log::warning('OTP verification rejected because maximum attempts were exceeded.', [
                'request_id' => $requestId,
                'otp_id' => $otp->id,
                'phone' => $this->maskPhone($phone),
                'attempts' => $otp->attempts,
                'maximum_attempts' => self::OTP_MAX_ATTEMPTS,
                'reason' => 'maximum_attempts_exceeded',
            ]);

            throw ValidationException::withMessages([
                'otp' => [
                    'Maximum OTP attempts exceeded.'
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate OTP
        |--------------------------------------------------------------------------
        */

        if (!Hash::check($code, $otp->code)) {
            $otp->increment('attempts');

            Log::warning('OTP verification failed because OTP code was invalid.', [
                'request_id' => $requestId,
                'otp_id' => $otp->id,
                'phone' => $this->maskPhone($phone),
                'attempts_after_failure' => $otp->attempts + 1,
                'reason' => 'invalid_otp',
            ]);

            throw ValidationException::withMessages([
                'otp' => [
                    'Invalid OTP.'
                ],
            ]);
        }

        Log::info('OTP code verified successfully.', [
            'request_id' => $requestId,
            'otp_id' => $otp->id,
            'phone' => $this->maskPhone($phone),
            'type' => $type,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Resolve or create authentication account
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | The User + CitizenAccount creation happens ONLY AFTER
        | successful OTP verification.
        |--------------------------------------------------------------------------
        */

        try {
            $result = DB::transaction(function () use (
                $otp,
                $phone,
                $type,
                $requestId
            ) {

                /*
                |--------------------------------------------------------------------------
                | Lock OTP row during finalization
                |--------------------------------------------------------------------------
                */

                $lockedOtp = OtpVerification::query()
                    ->whereKey($otp->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedOtp) {
                    Log::error('OTP disappeared while finalizing verification.', [
                        'request_id' => $requestId,
                        'otp_id' => $otp->id,
                    ]);

                    throw ValidationException::withMessages([
                        'otp' => [
                            'OTP verification could not be completed.'
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Prevent double verification
                |--------------------------------------------------------------------------
                */

                if (
                    $lockedOtp->is_used ||
                    $lockedOtp->verified_at !== null
                ) {
                    Log::warning('OTP verification rejected because OTP was already consumed.', [
                        'request_id' => $requestId,
                        'otp_id' => $lockedOtp->id,
                        'phone' => $this->maskPhone($phone),
                        'reason' => 'otp_already_used',
                    ]);

                    throw ValidationException::withMessages([
                        'otp' => [
                            'OTP has already been used.'
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Mark OTP as verified/used
                |--------------------------------------------------------------------------
                */

                $lockedOtp->update([
                    'verified_at' => now(),
                    'is_used' => true,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Existing User
                |--------------------------------------------------------------------------
                */

                $user = null;

                if ($lockedOtp->user_id) {
                    $user = User::query()
                        ->lockForUpdate()
                        ->find($lockedOtp->user_id);

                    Log::debug('OTP belongs to an existing authentication user.', [
                        'request_id' => $requestId,
                        'otp_id' => $lockedOtp->id,
                        'user_id' => $lockedOtp->user_id,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Existing User not available:
                | Find by phone.
                |--------------------------------------------------------------------------
                */

                if (!$user) {
                    $user = User::query()
                        ->lockForUpdate()
                        ->where('phone', $phone)
                        ->first();
                }

                /*
                |--------------------------------------------------------------------------
                | Existing User found
                |--------------------------------------------------------------------------
                */

                if ($user) {

                    Log::info('Existing authentication user resolved during OTP verification.', [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'user_type' => $user->user_type,
                        'phone' => $this->maskPhone($phone),
                    ]);

                    if (!$user->is_active) {
                        Log::warning('OTP verification rejected because existing user is inactive.', [
                            'request_id' => $requestId,
                            'user_id' => $user->id,
                            'phone' => $this->maskPhone($phone),
                        ]);

                        throw ValidationException::withMessages([
                            'phone' => [
                                'Account is inactive.'
                            ],
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Mark phone as verified
                    |--------------------------------------------------------------------------
                    */

                    if (!$user->is_phone_verified) {
                        $user->update([
                            'is_phone_verified' => true,
                        ]);

                        Log::info('Existing user phone marked as verified.', [
                            'request_id' => $requestId,
                            'user_id' => $user->id,
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | First-time citizen account
                |--------------------------------------------------------------------------
                */

                if (!$user) {

                    Log::info('No authentication user exists. Looking for registered citizen.', [
                        'request_id' => $requestId,
                        'phone' => $this->maskPhone($phone),
                    ]);

                    $citizen = Citizen::query()
                        ->lockForUpdate()
                        ->where('phone', $phone)
                        ->where('is_active', true)
                        ->first();

                    if (!$citizen) {

                        Log::warning('OTP verified but registered citizen could not be found.', [
                            'request_id' => $requestId,
                            'otp_id' => $lockedOtp->id,
                            'phone' => $this->maskPhone($phone),
                            'reason' => 'citizen_not_found_after_verification',
                        ]);

                        throw ValidationException::withMessages([
                            'phone' => [
                                'No active citizen record was found for this phone number.'
                            ],
                        ]);
                    }

                    Log::info('Registered citizen resolved for first-time account creation.', [
                        'request_id' => $requestId,
                        'citizen_id' => $citizen->id,
                        'citizen_uid' => $citizen->citizen_uid,
                        'phone' => $this->maskPhone($phone),
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Check whether CitizenAccount already exists
                    |--------------------------------------------------------------------------
                    */

                    $citizenAccount = CitizenAccount::query()
                        ->lockForUpdate()
                        ->where('citizen_id', $citizen->id)
                        ->first();

                    if ($citizenAccount) {

                        /*
                        |--------------------------------------------------------------------------
                        | Account exists but User lookup failed.
                        |--------------------------------------------------------------------------
                        */

                        $user = User::query()
                            ->lockForUpdate()
                            ->find($citizenAccount->user_id);

                        if (!$user) {
                            Log::error('Citizen account exists but linked user does not exist.', [
                                'request_id' => $requestId,
                                'citizen_id' => $citizen->id,
                                'citizen_account_id' => $citizenAccount->id,
                                'user_id' => $citizenAccount->user_id,
                            ]);

                            throw ValidationException::withMessages([
                                'phone' => [
                                    'Citizen authentication account is inconsistent. Please contact support.'
                                ],
                            ]);
                        }

                        if (!$user->is_active) {
                            throw ValidationException::withMessages([
                                'phone' => [
                                    'Account is inactive.'
                                ],
                            ]);
                        }

                        $user->update([
                            'is_phone_verified' => true,
                        ]);

                        Log::info('Existing citizen authentication account recovered.', [
                            'request_id' => $requestId,
                            'citizen_id' => $citizen->id,
                            'citizen_account_id' => $citizenAccount->id,
                            'user_id' => $user->id,
                        ]);
                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Create User
                        |--------------------------------------------------------------------------
                        */

                        $user = User::create([
                            'id' => (string) Str::uuid(),
                            'administrative_unit_id' => $citizen->administrative_unit_id,
                            'sector_id' => null,
                            'name' => $citizen->full_name,
                            'label' => 'Citizen',
                            'email' => $citizen->email,
                            'phone' => $phone,
                            'password' => null,
                            'user_type' => 'citizen',
                            'is_phone_verified' => true,
                            'is_active' => true,
                            'last_login_at' => now(),
                        ]);

                        Log::info('Citizen authentication User created after successful OTP verification.', [
                            'request_id' => $requestId,
                            'user_id' => $user->id,
                            'citizen_id' => $citizen->id,
                            'citizen_uid' => $citizen->citizen_uid,
                            'phone' => $this->maskPhone($phone),
                        ]);

                        /*
                        |--------------------------------------------------------------------------
                        | Create CitizenAccount
                        |--------------------------------------------------------------------------
                        */

                        $citizenAccount = CitizenAccount::create([
                            'id' => (string) Str::uuid(),
                            'user_id' => $user->id,
                            'citizen_id' => $citizen->id,
                            'login_type' => 'OTP',
                            'is_active' => true,
                            'last_login_at' => now(),
                        ]);

                        Log::info('CitizenAccount created successfully.', [
                            'request_id' => $requestId,
                            'citizen_account_id' => $citizenAccount->id,
                            'user_id' => $user->id,
                            'citizen_id' => $citizen->id,
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Update last login
                |--------------------------------------------------------------------------
                */

                $user->update([
                    'last_login_at' => now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Load complete authentication user
                |--------------------------------------------------------------------------
                */

                $user->load([
                    'administrativeUnit.parent.parent',
                    'citizenAccount.citizen',
                    'citizenAccount.citizen.avatar',
                ]);

                Log::info('OTP verification transaction completed successfully.', [
                    'request_id' => $requestId,
                    'otp_id' => $lockedOtp->id,
                    'user_id' => $user->id,
                    'user_type' => $user->user_type,
                    'phone' => $this->maskPhone($phone),
                ]);

                return $user;
            });

            /*
            |--------------------------------------------------------------------------
            | Create Sanctum Token
            |--------------------------------------------------------------------------
            */

            $token = $this->authTokenService->createToken(
                $result,
                'mobile'
            );

            Log::info('Sanctum authentication token created after OTP verification.', [
                'request_id' => $requestId,
                'user_id' => $result->id,
                'phone' => $this->maskPhone($phone),
                'token_name' => 'mobile',
            ]);

            return [
                'verified' => true,
                'user' => $result,
                'token' => $token,
            ];

        } catch (ValidationException $exception) {

            Log::warning('OTP verification rejected.', [
                'request_id' => $requestId,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'errors' => $exception->errors(),
            ]);

            throw $exception;

        } catch (Throwable $exception) {

            Log::error('Unexpected OTP verification failure.', [
                'request_id' => $requestId,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw ValidationException::withMessages([
                'otp' => [
                    'OTP verification could not be completed. Please try again.'
                ],
            ]);
        }
    }

    /**
     * ============================================================
     * RESEND OTP
     * ============================================================
     *
     * Supports both:
     *
     * - Existing users
     * - Registered citizens without authentication accounts
     */
    public function resend(array $data): array
    {
        $requestId = (string) Str::uuid();

        $phone = PhoneNumber::normalizeEthiopian(
            $data['phone']
        );

        $type = $data['type'] ?? 'login';

        Log::info('OTP resend request received.', [
            'request_id' => $requestId,
            'service' => self::class,
            'operation' => 'resend',
            'type' => $type,
            'phone' => $this->maskPhone($phone),
            'ip_address' => request()->ip(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Existing authentication user
        |--------------------------------------------------------------------------
        */

        $user = User::query()
            ->where('phone', $phone)
            ->first();

        if ($user) {

            if (!$user->is_active) {
                Log::warning('OTP resend rejected because user account is inactive.', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'phone' => $this->maskPhone($phone),
                ]);

                throw ValidationException::withMessages([
                    'phone' => [
                        'Account is inactive.'
                    ],
                ]);
            }

            $this->ensureCooldown(
                $phone,
                $type,
                $requestId
            );

            return $this->issueOtp(
                user: $user,
                phone: $phone,
                type: $type,
                requestId: $requestId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | No User: verify citizen exists
        |--------------------------------------------------------------------------
        */

        $citizen = Citizen::query()
            ->where('phone', $phone)
            ->where('is_active', true)
            ->first();

        if (!$citizen) {

            Log::warning('OTP resend failed because citizen was not found.', [
                'request_id' => $requestId,
                'phone' => $this->maskPhone($phone),
                'reason' => 'citizen_not_found',
            ]);

            throw ValidationException::withMessages([
                'phone' => [
                    'No registered citizen was found with this phone number.'
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent orphaned account situation
        |--------------------------------------------------------------------------
        */

        $existingCitizenAccount = CitizenAccount::query()
            ->where('citizen_id', $citizen->id)
            ->first();

        if ($existingCitizenAccount) {

            Log::error('OTP resend detected inconsistent citizen authentication account.', [
                'request_id' => $requestId,
                'citizen_id' => $citizen->id,
                'citizen_account_id' => $existingCitizenAccount->id,
                'user_id' => $existingCitizenAccount->user_id,
            ]);

            throw ValidationException::withMessages([
                'phone' => [
                    'Citizen authentication account is inconsistent. Please contact support.'
                ],
            ]);
        }

        $this->ensureCooldown(
            $phone,
            $type,
            $requestId
        );

        return $this->issueOtp(
            user: null,
            phone: $phone,
            type: $type,
            requestId: $requestId
        );
    }

    /**
     * ============================================================
     * SEND OTP FOR EXISTING USER
     * ============================================================
     */
    private function sendOtpForExistingUser(
        User $user,
        string $phone,
        string $type,
        string $requestId
    ): array {
        $this->ensureCooldown(
            $phone,
            $type,
            $requestId
        );

        return $this->issueOtp(
            user: $user,
            phone: $phone,
            type: $type,
            requestId: $requestId
        );
    }

    /**
     * ============================================================
     * ENSURE OTP COOLDOWN
     * ============================================================
     */
    private function ensureCooldown(
        string $phone,
        string $type,
        string $requestId
    ): void {
        $cutoff = now()->subSeconds(
            self::OTP_RESEND_COOLDOWN_SECONDS
        );

        $recentOtp = OtpVerification::query()
            ->where('phone', $phone)
            ->where('type', $type)
            ->where('created_at', '>=', $cutoff)
            ->exists();

        if ($recentOtp) {

            Log::warning('OTP request rejected because resend cooldown is active.', [
                'request_id' => $requestId,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'cooldown_seconds' => self::OTP_RESEND_COOLDOWN_SECONDS,
            ]);

            throw ValidationException::withMessages([
                'phone' => [
                    'Please wait before requesting another OTP.'
                ],
            ]);
        }
    }

    /**
     * ============================================================
     * ISSUE OTP
     * ============================================================
     *
     * user may be NULL for a first-time citizen.
     */
    private function issueOtp(
        ?User $user,
        string $phone,
        string $type,
        string $requestId
    ): array {
        try {

            /*
            |--------------------------------------------------------------------------
            | Disable previous active OTPs
            |--------------------------------------------------------------------------
            |
            | We use phone + type rather than user_id because a new citizen
            | does not have a User yet.
            |--------------------------------------------------------------------------
            */

            $disabledCount = OtpVerification::query()
                ->where('phone', $phone)
                ->where('type', $type)
                ->whereNull('verified_at')
                ->where('is_used', false)
                ->update([
                    'is_used' => true,
                ]);

            if ($disabledCount > 0) {
                Log::debug('Previous active OTP records disabled.', [
                    'request_id' => $requestId,
                    'phone' => $this->maskPhone($phone),
                    'type' => $type,
                    'disabled_count' => $disabledCount,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Generate OTP
            |--------------------------------------------------------------------------
            */

            $otpCode = (string) random_int(
                100000,
                999999
            );

            Log::debug('OTP code generated.', [
                'request_id' => $requestId,
                'phone' => $this->maskPhone($phone),

                /*
                 * NEVER log the actual OTP.
                 */
                'code_generated' => true,

                'expires_in_minutes' => self::OTP_EXPIRATION_MINUTES,
                'user_id' => $user?->id,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Store OTP
            |--------------------------------------------------------------------------
            */

            $verification = DB::transaction(
                function () use (
                    $user,
                    $phone,
                    $type,
                    $otpCode
                ) {

                    return OtpVerification::create([
                        'id' => (string) Str::uuid(),

                        /*
                         * NULL for first-time citizen registration.
                         */
                        'user_id' => $user?->id,

                        'phone' => $phone,

                        'code' => Hash::make(
                            $otpCode
                        ),

                        'type' => $type,

                        'expires_at' => now()->addMinutes(
                            self::OTP_EXPIRATION_MINUTES
                        ),

                        'ip_address' => request()->ip(),

                        'user_agent' => request()->userAgent(),

                    ]);
                }
            );

            Log::info('OTP verification record created.', [
                'request_id' => $requestId,
                'otp_id' => $verification->id,
                'user_id' => $user?->id,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'expires_at' => $verification->expires_at,
            ]);

            /*
            |--------------------------------------------------------------------------
            | SMS
            |--------------------------------------------------------------------------
            */

            Log::debug('Sending OTP SMS.', [
                'request_id' => $requestId,
                'otp_id' => $verification->id,
                'phone' => $this->maskPhone($phone),
                'sms_service' => get_class($this->smsService),
            ]);

            $smsResponse = $this->smsService->sendByPhone(
                $phone,
                "Lakkoofsi mirkaneessaa seenumaa (OTP) keessan: {$otpCode}. Sirna Galii Mana Qopheessaa Adaamaa. Nama biraatti hin ibsinaa."
            );

            Log::debug('OTP SMS service response received.', [
                'request_id' => $requestId,
                'otp_id' => $verification->id,
                'phone' => $this->maskPhone($phone),
                'response' => $smsResponse,
            ]);

            if (
                isset($smsResponse['status']) &&
                $smsResponse['status'] === 'failed'
            ) {

                Log::error('OTP SMS failed.', [
                    'request_id' => $requestId,
                    'otp_id' => $verification->id,
                    'user_id' => $user?->id,
                    'phone' => $this->maskPhone($phone),
                    'response' => $smsResponse,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Invalidate the OTP if SMS delivery failed.
                |--------------------------------------------------------------------------
                */

                $verification->update([
                    'is_used' => true,
                ]);

                throw ValidationException::withMessages([
                    'phone' => [
                        'Unable to send OTP. Please try again.'
                    ],
                ]);
            }

            Log::info('OTP SMS sent successfully.', [
                'request_id' => $requestId,
                'otp_id' => $verification->id,
                'user_id' => $user?->id,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
            ]);

            return [
                'message' => 'OTP sent successfully.',
                'expires_at' => $verification->expires_at,
            ];

        } catch (ValidationException $exception) {

            Log::warning('OTP issuance rejected.', [
                'request_id' => $requestId,
                'user_id' => $user?->id,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'errors' => $exception->errors(),
            ]);

            throw $exception;

        } catch (Throwable $exception) {

            Log::error('Unexpected OTP issuance failure.', [
                'request_id' => $requestId,
                'user_id' => $user?->id,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw ValidationException::withMessages([
                'phone' => [
                    'Unable to send OTP. Please try again.'
                ],
            ]);
        }
    }

    /**
     * ============================================================
     * MASK PHONE FOR LOGGING
     * ============================================================
     *
     * Example:
     *
     * +251912345750
     *
     * becomes approximately:
     *
     * +251******750
     */
    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 7) {
            return '***';
        }

        return substr($phone, 0, 4)
            . str_repeat('*', max(1, $length - 7))
            . substr($phone, -3);
    }
}