<?php

namespace App\Services;

class RevenueNotificationService
{
    public function __construct(
        private SmsService $sms
    ) {}


    public function sendPaymentReminder(
        string $phone,
        string $taxpayerName,
        string $paymentToken
    ) {

        $link = "https://pay.adamacity.gov.et/p/{$paymentToken}";


        $message =
            "Dear {$taxpayerName}, "
            ."your revenue payment is pending. "
            ."Pay securely using: {$link}";


        return $this->sms->sendByPhone(
            $phone,
            $message
        );
    }
}