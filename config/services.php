<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional location for various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),

        'secret' => env('AWS_SECRET_ACCESS_KEY'),

        'region' => env(
            'AWS_DEFAULT_REGION',
            'us-east-1'
        ),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' =>
                env('SLACK_BOT_USER_OAUTH_TOKEN'),

            'channel' =>
                env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dagu SMS Gateway
    |--------------------------------------------------------------------------
    |
    | Configuration for Adama City SMS service.
    |
    */

    'dagu_sms' => [
        'base_url' =>
            env('DAGU_SMS_BASE_URL'),

        'token' =>
            env('DAGU_SMS_TOKEN'),

        'sender_id' =>
            env('DAGU_SMS_SENDER_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chapa Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Configuration for Chapa online payments.
    |
    | callback_url:
    |     Server-to-server callback endpoint used by Chapa
    |     to notify the Laravel backend about the transaction.
    |
    | return_url:
    |     Browser redirect URL used after the customer completes
    |     the Chapa checkout.
    |
    | IMPORTANT:
    |
    | These URLs are backend-controlled and must NOT be accepted
    | from the frontend request.
    |
    */

    'chapa' => [

        /*
        |--------------------------------------------------------------------------
        | Chapa API Base URL
        |--------------------------------------------------------------------------
        */

        'base_url' => env(
            'CHAPA_BASE_URL',
            'https://api.chapa.co'
        ),

        /*
        |--------------------------------------------------------------------------
        | Chapa Secret Key
        |--------------------------------------------------------------------------
        |
        | Used for authenticated server-to-server API requests.
        |
        */

        'secret_key' => env(
            'CHAPA_SECRET_KEY'
        ),

        /*
        |--------------------------------------------------------------------------
        | Chapa Public Key
        |--------------------------------------------------------------------------
        |
        | Used where a public Chapa key is required.
        |
        */

        'public_key' => env(
            'CHAPA_PUBLIC_KEY'
        ),

        /*
        |--------------------------------------------------------------------------
        | Callback URL
        |--------------------------------------------------------------------------
        |
        | Chapa -> Laravel backend.
        |
        | Example:
        |
        | https://api.example.com/api/v1/online-payments/chapa/callback
        |
        */

        'callback_url' => env(
            'CHAPA_CALLBACK_URL'
        ),

        /*
        |--------------------------------------------------------------------------
        | Return URL
        |--------------------------------------------------------------------------
        |
        | Chapa -> Customer browser -> Next.js frontend.
        |
        | Example:
        |
        | https://municipal.example.com/or/dashboard/payments/online/result
        |
        */

        'return_url' => env(
            'CHAPA_RETURN_URL'
        ),

        /*
        |--------------------------------------------------------------------------
        | Webhook Secret
        |--------------------------------------------------------------------------
        |
        | Secret used when validating Chapa webhook/callback
        | signatures, if applicable to the configured integration.
        |
        */

        'webhook_secret' => env(
            'CHAPA_WEBHOOK_SECRET'
        ),

        /*
        |--------------------------------------------------------------------------
        | HTTP Timeout
        |--------------------------------------------------------------------------
        */

        'timeout' => (int) env(
            'CHAPA_TIMEOUT',
            30
        ),

        /*
        |--------------------------------------------------------------------------
        | HTTP Connection Timeout
        |--------------------------------------------------------------------------
        */

        'connect_timeout' => (int) env(
            'CHAPA_CONNECT_TIMEOUT',
            10
        ),
        
    ],


    /*
    |--------------------------------------------------------------------------
    | Telebirr Payment Gateway
    |--------------------------------------------------------------------------
    */

    'telebirr' => [

        // Application credentials
        'appid' => env('TELEBIRR_APP_ID'),

        'appsecret' => env('TELEBIRR_APP_SECRET'),

        // Merchant credentials
        'merchant_app_id' => env('MERCHANT_APP_ID'),

        'shortcode' => env('TELEBIRR_SHORT_CODE'),

        // API endpoint
        'endpoint' => env(
            'TELEBIRR_ENDPOINT',
            'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway'
        ),
        'checkout_url' => env('TELEBIRR_CHECKOUT_URL'),


        // Callback and return URLs
        'callback_url' => env('TELEBIRR_CALLBACK_URL'),

        'return_url' => env('TELEBIRR_RETURN_URL'),

        // H5 checkout URL, if required by your integration
        'h5_url' => env('TELEBIRR_H5_URL'),

        // Signing configuration
        'private_key' => env('TELEBIRR_PRIVATE_KEY'),

        'private_key_path' => env('TELEBIRR_PRIVATE_KEY_PATH'),

        // HTTP configuration
        'timeout' => (int) env(
            'TELEBIRR_TIMEOUT',
            30
        ),

        'connect_timeout' => (int) env(
            'TELEBIRR_CONNECT_TIMEOUT',
            10
        ),

        'ca_bundle' => env('TELEBIRR_CA_BUNDLE'),


    ],

];