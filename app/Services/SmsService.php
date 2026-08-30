<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsService
{
    private string $baseUrl;
    private string $token;
    private string $senderId;
    private string $paymentUrl;


    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.dagu_sms.base_url', ''),
            '/'
        );

        $this->token = config('services.dagu_sms.token', '');

        $this->senderId = config(
            'services.dagu_sms.sender_id',
            '9141'
        );

        $this->paymentUrl = rtrim(
            config('app.payment_url', ''),
            '/'
        );


        if (!$this->baseUrl || !$this->token) {
            throw new \RuntimeException(
                'Dagu SMS configuration missing'
            );
        }
    }



    /**
     * Send SMS to a single phone number
     */
    public function sendByPhone(
        string $phone,
        string $message,
        bool $flash = false
    ): array {

        return $this->sendSms(
            $phone,
            $message,
            $flash
        );
    }



    /**
     * Send revenue payment link SMS
     */
    public function sendPaymentLink(
        string $phone,
        string $taxpayerName,
        string $paymentToken,
        bool $flash = false
    ): array {

        $paymentLink = $this->paymentUrl
            . '/p/'
            . $paymentToken;


        $message =
            "Dear {$taxpayerName}, "
            . "your revenue payment is pending. "
            . "Pay securely here: {$paymentLink}";


        return $this->sendSms(
            $phone,
            $message,
            $flash
        );
    }



    /**
     * Send OTP SMS
     *
     * Store OTP separately in cache/database.
     */
    public function sendOtp(string $phone): array
    {
        $otp = random_int(
            100000,
            999999
        );


        $message =
            "Your verification code is {$otp}.";


        return [
            'otp' => $otp,

            'response' => $this->sendSms(
                $phone,
                $message
            ),
        ];
    }



    /**
     * Send bulk SMS
     */
    public function sendBulk(
        array $phones,
        string $message,
        bool $flash = false
    ): array {

        try {

            $phones = collect($phones)
                ->map(
                    fn ($phone) =>
                    $this->normalizePhone($phone)
                )
                ->values()
                ->toArray();



            $response = Http::timeout(30)
                ->retry(3, 500)
                ->withToken($this->token)
                ->post(
                    $this->baseUrl . '/to-phone-list',
                    [
                        'senderID' => $this->senderId,
                        'message' => $message,
                        'phones' => $phones,
                        'flash' => $flash,
                    ]
                );


            return $this->formatResponse(
                $response
            );


        } catch (Throwable $e) {


            Log::error(
                'Bulk SMS failed',
                [
                    'error' => $e->getMessage(),
                ]
            );


            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }



    /**
     * Internal SMS sender
     */
    private function sendSms(
        string $phone,
        string $message,
        bool $flash = false
    ): array {

        try {


            $phone = $this->normalizePhone(
                $phone
            );



            $response = Http::timeout(30)
                ->retry(3, 500)
                ->withToken($this->token)
                ->post(
                    $this->baseUrl . '/by-phone',
                    [
                        'senderID' => $this->senderId,

                        'message' => $message,

                        'phone' => $phone,

                        'flash' => $flash,
                    ]
                );



            return $this->formatResponse(
                $response
            );


        } catch (Throwable $e) {


            Log::error(
                'SMS sending failed',
                [
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]
            );


            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }



    /**
     * Standardize SMS gateway response
     */
    private function formatResponse($response): array
    {
        return [

            'status' => $response->successful()
                ? 'success'
                : 'failed',

            'http_status' => $response->status(),

            'body' => $response->body(),

            'json' => $response->json(),

        ];
    }



    /**
     * Normalize Ethiopian phone numbers
     *
     * 0912345678
     * => +251912345678
     *
     * 251912345678
     * => +251912345678
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace(
            '/[\s\-\(\)]/',
            '',
            trim($phone)
        );


        if (str_starts_with($phone, '+251')) {
            return $phone;
        }


        if (str_starts_with($phone, '251')) {
            return '+' . $phone;
        }


        if (str_starts_with($phone, '0')) {
            return '+251' . substr($phone, 1);
        }


        return $phone;
    }
}