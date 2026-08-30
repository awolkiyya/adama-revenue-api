<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Models\OtpVerification;
use App\Modules\Auth\Resources\UserResource;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Supports\PhoneNumber;



class OtpService
{

    public function __construct(
        private SmsService $smsService,
        private AuthTokenService $authTokenService
    ) {
    }



    /**
     * Send OTP for existing user login
     */
    public function send(array $data): array
    {
        $phone = PhoneNumber::normalizeEthiopian($data['phone']);
        $type = $data['type'] ?? 'login';

        $user = User::where('phone', $phone)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'phone' => ['No account found with this phone number.']
            ]);
        }

        /**
         * Prevent OTP spam
         */
        $recentOtp = OtpVerification::where('phone', $phone)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($recentOtp) {
            throw ValidationException::withMessages([
                'phone' => ['Please wait before requesting another OTP.']
            ]);
        }

        return $this->issueOtp($user, $phone, $type);
    }






    /**
     * Verify OTP and authenticate user
     */
    public function verify(array $data): array
    {

        $phone = PhoneNumber::normalizeEthiopian($data['phone']);



        $code = $data['otp'];


        $type = $data['type'] ?? 'login';





        /**
         * Find active OTP
         */
        $otp = OtpVerification::where('phone', $phone)
            ->where('type', $type)
            ->where('is_used', false)
            ->whereNull('verified_at')
            ->latest()
            ->first();





        if (!$otp) {

            throw ValidationException::withMessages([
                'otp' => [
                    'OTP not found.'
                ]
            ]);

        }





        /**
         * Check expiration
         */
        if (
            now()->greaterThan(
                $otp->expires_at
            )
        ) {


            throw ValidationException::withMessages([
                'otp' => [
                    'OTP expired.'
                ]
            ]);

        }





        /**
         * Limit attempts
         */
        if ($otp->attempts >= 5) {


            throw ValidationException::withMessages([
                'otp' => [
                    'Maximum OTP attempts exceeded.'
                ]
            ]);

        }





        /**
         * Validate OTP
         */
        if (!Hash::check(
            $code,
            $otp->code
        )) {


            $otp->increment(
                'attempts'
            );


            throw ValidationException::withMessages([
                'otp' => [
                    'Invalid OTP.'
                ]
            ]);

        }





        /**
         * Mark OTP completed
         */
        $otp->update([

            'verified_at' => now(),

            'is_used' => true,

        ]);






        /**
         * Load user
         */
        $user = User::where(
            'id',
            $otp->user_id
        )
        ->with([
            'administrativeUnit.parent.parent',
            'citizenAccount.citizen',
            'citizenAccount.citizen.avatar',
        ])
        ->first();





        if (!$user) {

            throw ValidationException::withMessages([
                'phone' => [
                    'User account not found.'
                ]
            ]);

        }





        if (!$user->is_active) {

            throw ValidationException::withMessages([
                'phone' => [
                    'Account is inactive.'
                ]
            ]);

        }






       /**
         * Create Sanctum Token
        */
        $token = $this->authTokenService->createToken(
            $user,
            'mobile'
        );



        return [

            'verified' => true,

            'user' => $user,

            'token' => $token,

        ];

    }







    /**
     * Normalize Ethiopian phone numbers
     */
    private function normalizePhone(
        string $phone
    ): string {


        $phone = preg_replace(
            '/[\s\-\(\)]/',
            '',
            trim($phone)
        );



        if (
            str_starts_with(
                $phone,
                '+251'
            )
        ) {

            return $phone;

        }



        if (
            str_starts_with(
                $phone,
                '251'
            )
        ) {

            return '+' . $phone;

        }



        if (
            str_starts_with(
                $phone,
                '0'
            )
        ) {

            return '+251' . substr($phone,1);

        }



        return $phone;

    }

    /**
     * Resend OTP (reuses the same cooldown + issuance logic as send())
     */
    public function resend(array $data): array
    {
        $phone = PhoneNumber::normalizeEthiopian($data['phone']);
        $type = $data['type'] ?? 'login';

        $user = User::where('phone', $phone)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'phone' => ['No account found with this phone number.']
            ]);
        }

        $recentOtp = OtpVerification::where('phone', $phone)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($recentOtp) {
            throw ValidationException::withMessages([
                'phone' => ['Please wait before requesting another OTP.']
            ]);
        }

        return $this->issueOtp($user, $phone, $type);
    }


    /**
     * Core OTP generation + storage + SMS dispatch.
     * Shared by send() and resend().
     */
    private function issueOtp(User $user, string $phone, string $type): array
    {
        /**
         * Disable previous OTPs
         */
        OtpVerification::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->where('is_used', false)
            ->update(['is_used' => true]);

        /**
         * Generate OTP
         */
        $otp = random_int(100000, 999999);

        /**
         * Store OTP
         */
        $verification = DB::transaction(function () use ($user, $phone, $type, $otp) {
            return OtpVerification::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'phone' => $phone,
                'code' => Hash::make($otp),
                'type' => $type,
                'expires_at' => now()->addMinutes(5),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        /**
         * Send SMS
         */
        $smsResponse = $this->smsService->sendByPhone(
            $phone,
            "Lakkoofsi mirkaneessaa seenumaa (OTP) keessan: {$otp}. Sirna Galii Mana Qopheessaa Adaamaa. Nama biraatti hin ibsinaa."
        );

        if (isset($smsResponse['status']) && $smsResponse['status'] === 'failed') {
            Log::error('OTP SMS failed', [
                'user_id' => $user->id,
                'phone' => $phone,
                'response' => $smsResponse,
            ]);

            throw ValidationException::withMessages([
                'phone' => ['Unable to send OTP. Please try again.']
            ]);
        }

        return [
            'message' => 'OTP sent successfully.',
            'expires_at' => $verification->expires_at,
        ];
    }

}