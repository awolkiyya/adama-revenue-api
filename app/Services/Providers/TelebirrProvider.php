<?php

namespace App\Services\Providers;

use App\Services\TelebirrService;
use Illuminate\Support\Facades\Log;
use App\Helpers\TelebirrHelper;
use Exception;

class TelebirrProvider
{
    protected TelebirrService $telebirrService;
    protected string $appId;
    protected string $appSecret;
    protected string $merchantAppId;
    protected string $merchantCode;
    protected string $privateKey;
    protected string $baseUrl;


    public function __construct()
    {
        $this->telebirrService = new TelebirrService();
        $this->appId          = config('services.telebirr.appid') ?? throw new Exception('Telebirr App ID not set');
        $this->appSecret      = config('services.telebirr.appsecret') ?? throw new Exception('Telebirr App Secret not set');
        $this->merchantAppId  = config('services.telebirr.merchant_app_id') ?? throw new Exception('Telebirr Merchant App ID not set');
        $this->merchantCode   = config('services.telebirr.shortcode') ?? throw new Exception('Telebirr Merchant Short Code not set');
        $this->baseUrl        = rtrim(config('services.telebirr.endpoint') ?? throw new Exception('Telebirr endpoint not set'), '/');
    }

    public function initiate(array $data, string $env = 'test'): array
    {
        try {
            if (empty($data['customer_phone'])) {
                throw new Exception("Customer phone is required for Telebirr payment.");
            }
    
            // 1️⃣ Get main token (UAT or Production)
            $fabricToken = $this->telebirrService->getMainToken();
        
    
            // 2️⃣ Create pre-order
            $createOrderResult = $this->telebirrService->createPreOrder(
                $fabricToken,
                "pay",
                $data['amount'],
                $data['order_id'] ?? null,
                $data['meta']
            );
    
            $prepayId = $createOrderResult['prepay_id'] ?? null;
            if (!$prepayId) {
                throw new Exception("prepay_id not found in preOrder response");
            }
    
            // 3️⃣ Build raw request for Flutter
            $rawRequest = TelebirrHelper::createRawRequest(
                $prepayId,
                $this->merchantAppId,
                $this->merchantCode
            );
    
            // 4️⃣ Build receiveCode for Flutter SDK
            $receiveCode = implode('$', [
                'TELEBIRR',
                'BUYGOODS',
                $this->merchantCode,
                (string)$data['amount'],
                $prepayId,
                '120m', // timeout_express, match your pre-order
            ]);
    
            // 5️⃣ Build H5 URL for web using rawRequest
            $h5Url = self::buildH5Url($rawRequest, $env);
    
            return [
                'status'       => 'success',
                'raw_request'  => $rawRequest,    // For Flutter app
                'receiveCode'  => $receiveCode,   // Correct format for Flutter SDK
                'h5_url'       => $h5Url,         // Web frontend
            ];
    
        } catch (Exception $e) {
            Log::error('Telebirr Initiate Error: ' . $e->getMessage(), ['data' => $data]);
            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
    
     /**
     * Build H5 Payment URL using Fabric token
     *
     * @param string $rawRequest
     * @param string $env 'test' or 'production'
     * @return string
     */
    protected static function buildH5Url(string $rawRequest, string $env = 'test'): string
    {
        $baseUrl = "https://196.188.120.3:38443/apiaccess/payment/web/paygate";

        // Ensure proper concatenation
        $connector = str_contains($rawRequest, '?') ? '&' : '?';
        $otherParams = 'version=1.0';

        return rtrim($baseUrl, '/') . $connector . $rawRequest . '&' . $otherParams;
    }

    /**
     * Verify Telebirr payment using Query Order
     *
     * @param array $callbackData The callback payload received from Telebirr
     * @return array ['status' => 'success|failed']
     */
    public function verify(array $callbackData): array
    {
        $orderId = $callbackData['merch_order_id'] ?? null;

        if (!$orderId) {
            \Log::warning('Telebirr callback missing merch_order_id', $callbackData);
            return ['status' => 'failed'];
        }

        // Get main token (UAT or Production)
        $fabricToken = $this->telebirrService->getMainToken();

        // Query the payment directly from Telebirr
        $queryResponse = $this->telebirrService->queryOrder($fabricToken, $orderId);

        $tradeStatus = $queryResponse['trade_status'] ?? 'Failed';

        // Determine status
        $status = $tradeStatus === 'Completed' ? 'success' : 'failed';

        return [
            'status' => $status,
        ];
    }




}
