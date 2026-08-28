<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'shopify' => [
        'store_url' => env('SHOPIFY_STORE_URL'),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
        'api_version' => env('SHOPIFY_API_VERSION', '2024-01'),
        'location_id' => env('SHOPIFY_LOCATION_ID'),
        'app_url' => env('SHOPIFY_APP_URL'),
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        'scopes' => env('SHOPIFY_SCOPES', \App\Services\ShopifyOAuthService::DEFAULT_SCOPES),
    ],

    'uyumsoft' => [
        'username' => env('UYUMSOFT_API_USER'),
        'password' => env('UYUMSOFT_API_PASSWORD'),
        'base_url' => env('UYUMSOFT_BASE_URL'),
        'einvoice_wsdl' => env(
            'UYUMSOFT_EINVOICE_WSDL',
            'https://efatura.uyumsoft.com.tr/Services/Integration?wsdl'
        ),
        'einvoice_username' => env('UYUMSOFT_EINVOICE_USER'),
        'einvoice_password' => env('UYUMSOFT_EINVOICE_PASSWORD'),
        'warehouse_id' => env('UYUMSOFT_WAREHOUSE_ID'),
        'branch_code' => env('UYUMSOFT_BRANCH_CODE'),
    ],

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'log'),
        'api_key' => env('SMS_API_KEY'),
        'api_secret' => env('SMS_API_SECRET'),
        'header' => env('SMS_HEADER', 'ETICART'),
    ],

];
