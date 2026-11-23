<?php

namespace App\Services\Providers;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChapaProvider
{
    protected string $baseUrl;
    protected string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.chapa.base_url'), '/');
        $this->secret  = config('services.chapa.secret');
    }

    /**
     * Initiate a Chapa payment
     *
     * @param Payment $payment
     * @param array $meta Optional meta data (plan_type, features, usage_limits, total_credit, etc.)
     * @return array
     */
    public function initiate(Payment $payment, array $meta = []): array
    {
        $txRef = $payment->transaction_id;

        // Dynamically include tx_ref in return_url
        $returnUrl = config('services.chapa.return_url') . '?tx_ref=' . $txRef;

        $payload = [
            'amount'       => $payment->amount,
            'currency'     => $payment->currency ?? 'ETB',
            'tx_ref'       => $txRef,
            'callback_url' => config('services.chapa.callback_url'), // server-to-server
            'return_url'   => $returnUrl, // user redirect with tx_ref
            'customization'=> [
                'title'       => 'Ghioon Payment',
                'description' => 'Business Service Subscription',
            ],
            'meta' => $meta, // Pass plan/credit info
        ];

        \Log::info('Chapa Payload', $payload);

        $response = Http::withToken($this->secret)
            ->post("{$this->baseUrl}/transaction/initialize", $payload)
            ->json();

        return $response;
    }


    /**
     * Verify Chapa payment
     *
     * @param Payment $payment
     * @return array|null
     */
    public function verify(string $txRef): ?array
    {
        $response = Http::withToken($this->secret)
            ->get("{$this->baseUrl}/transaction/verify/{$txRef}")
            ->json();

        \Log::info('Chapa verify response', ['response' => $response]);

        // Extract only the fields you need safely
        $status = $response['status'] ?? null;
        $meta = $response['data']['meta']['meta'] ?? null;

        return [
            'status' => $status,
            'meta' => $meta,
        ];
    }


}
