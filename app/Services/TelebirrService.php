<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Helpers\TelebirrHelper;
use phpseclib3\Crypt\PublicKeyLoader;


class TelebirrService
{
    protected string $appId;
    protected string $appSecret;
    protected string $merchantAppId;
    protected string $merchantCode;
    protected string $publicKey; // For callback verification
    protected string $baseUrl;

    public function __construct()
    {
        $this->appId          = config('services.telebirr.appid') ?? throw new Exception('Telebirr App ID not set');
        $this->appSecret      = config('services.telebirr.appsecret') ?? throw new Exception('Telebirr App Secret not set');
        $this->merchantAppId  = config('services.telebirr.merchant_app_id') ?? throw new Exception('Telebirr Merchant App ID not set');
        $this->merchantCode   = config('services.telebirr.shortcode') ?? throw new Exception('Telebirr Merchant Short Code not set');
        $this->baseUrl        = rtrim(config('services.telebirr.endpoint') ?? throw new Exception('Telebirr endpoint not set'), '/');
    }

    /**
     * Get Main Token using appSecret
     */
    public function getMainToken(): string
    {
        try {
            $response = Http::withHeaders([
                'X-APP-Key' => $this->appId,
            ])
            ->withOptions(['verify' => false]) // Enable in production
            ->timeout(30)
            ->post($this->baseUrl . '/payment/v1/token', [
                'appSecret' => $this->appSecret
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['token'])) {
                    return $data['token'];
                }
                throw new Exception('No main token returned');
            }

            throw new Exception('Main token request failed: ' . $response->body());

        } catch (Exception $e) {
            Log::error('Telebirr Main Token Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Request a Fabric Token (H5 token) for a given customer
     */
    public function applyH5FabricToken(string $customerPhone): array
    {
        $payload = [];

        try {
            $mainToken = $this->getMainToken();

            $customerPhone = preg_replace('/\D/', '', $customerPhone);
            if (empty($customerPhone)) {
                throw new \InvalidArgumentException('Invalid customer phone number.');
            }
            $payload = TelebirrHelper::createH5TokenPayload(
                $customerPhone,
                $this->merchantAppId,
                $this->merchantCode,
            );
            // dd($payload);

            $response = Http::withHeaders([
                'Authorization' => $mainToken,
                'X-APP-Key'     => $this->appId,
            ])
            ->timeout(30)
            ->withOptions(['verify' => false])
            ->post($this->baseUrl . '/payment/v1/auth/applyH5Token', $payload);

            if ($response->successful()) {
                $result = $response->json();
            
                // check inside biz_content.access_token
                $accessToken = $result['biz_content']['access_token'] ?? null;
            
                if ($accessToken) {
                    return ['token' => $accessToken];
                }
            
                throw new \Exception($result['errorMsg'] ?? $result['msg'] ?? 'Fabric token request failed');
            }
            
            throw new \Exception('Fabric token request failed: ' . $response->body());
            

        } catch (\Exception $e) {
            Log::error('Telebirr Fabric Token Error', [
                'payload' => $payload,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create PreOrder
     */
    public function createPreOrder(string $fabricToken, string $title, string $amount,string $order_id, array $meta=[]): array
    {
        try {
             // 2️⃣ Create Pre-Order payload
             $preOrderPayload = TelebirrHelper::createPreOrderRequest(
                $title,  // title
                (string) $amount,    // amount
                $this->merchantAppId,
                $this->merchantCode,
                $order_id,
                $meta
            );
   // 🧾 Safe logging (deep copy, no mutation)
        Log::info('Telebirr PreOrder → Prepared Payload', [
            'endpoint' => $this->baseUrl . '/payment/v1/merchant/preOrder',
            'payload'  => json_decode(json_encode($preOrderPayload), true),
        ]);
            $response = Http::withHeaders([
                'Authorization' => $fabricToken,
                'X-APP-Key'     => $this->appId,
                'Content-Type'  => 'application/json'
            ])
            ->timeout(30)
            ->withOptions(['verify' => false])
            ->post($this->baseUrl . '/payment/v1/merchant/preOrder',$preOrderPayload );
            Log::info('Telebirr PreOrder → Raw Response', [
            'status_code' => $response->status(),
            'body'        => $response->body(),
        ]);
            if (!$response->successful()) {
                throw new \Exception('Failed to create preOrder: ' . $response->body());
            }

            $resJson = $response->json();
            Log::info('Telebirr PreOrder → Parsed Response JSON', [
            'biz_content' => $resJson['biz_content'] ?? null,
            'prepay_id'   => $resJson['biz_content']['prepay_id'] ?? null,
            ]);
            return [
                'status' => 'success',
                'prepay_id' => $resJson['biz_content']['prepay_id'] ?? null,
                'raw_response' => $response->body()
            ];

        } catch (\Exception $e) {
            Log::error('Telebirr Create PreOrder Error', [
                'exception' => $e->getMessage(),
                'payload'   => $orderPayload ?? []
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    /**
     * Query Telebirr payment status
     */
    public function createQueryOrder(string $fabricToken, string $merch_order_id): array
    {
        try {
            // Create Query Order payload
            $queryOrderPayload = TelebirrHelper::createQueryOrderRequest(
                $this->merchantAppId,
                $this->merchantCode,
                $merch_order_id
            );

            $response = Http::withHeaders([
                'Authorization' => $fabricToken,
                'X-APP-Key'     => $this->appId,
                'Content-Type'  => 'application/json'
            ])
            ->timeout(30)
            ->withOptions(['verify' => false])
            ->post($this->baseUrl . '/payment/v1/merchant/queryOrder', $queryOrderPayload);

            if (!$response->successful()) {
                throw new \Exception('Failed to query order: ' . $response->body());
            }

            $resJson = $response->json();

            // Extract relevant fields
            return [
                'trade_status' => $resJson['trade_status'] ?? 'Failed',
                'meta'         => $resJson['meta'] ?? [],
            ];

        } catch (\Exception $e) {
            Log::error('Telebirr Query Order Error', [
                'exception' => $e->getMessage(),
                'payload'   => $queryOrderPayload ?? []
            ]);

            return [
                'trade_status' => 'Failed',
                'meta'         => [],
            ];
        }
    }


    /**
     * Verify Telebirr callback using Public Key (RSA-SHA256)
     */
    public function verifyCallback(array $callbackData): bool
    {
        $signature = $callbackData['signature'] ?? null;
        if (!$signature) return false;

        unset($callbackData['signature']);
        $payloadJson = json_encode($callbackData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $rsa = PublicKeyLoader::load($this->publicKey)
                ->withHash('sha256')
                ->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1);

            return $rsa->verify($payloadJson, base64_decode($signature));

        } catch (\Exception $e) {
            Log::error('Telebirr Callback Verification Error', [
                'exception' => $e->getMessage(),
                'callbackData' => $callbackData
            ]);
            return false;
        }
    }
}
