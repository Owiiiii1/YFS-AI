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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'instagram_app_id' => env('META_INSTAGRAM_APP_ID'),
        'instagram_app_secret' => env('META_INSTAGRAM_APP_SECRET'),
        'oauth_flow' => env('META_OAUTH_FLOW', 'instagram_login'),
        'oauth_redirect_uri' => env('META_OAUTH_REDIRECT_URI'),
        'oauth_authorize_url' => env('META_OAUTH_AUTHORIZE_URL', 'https://www.instagram.com/oauth/authorize'),
        'oauth_token_url' => env('META_OAUTH_TOKEN_URL', 'https://api.instagram.com/oauth/access_token'),
        'instagram_graph_base_url' => env('META_INSTAGRAM_GRAPH_BASE_URL', 'https://graph.instagram.com'),
        'instagram_messaging_send_path' => env('META_INSTAGRAM_MESSAGING_SEND_PATH'),
        'instagram_customer_profile_path' => env('META_INSTAGRAM_CUSTOMER_PROFILE_PATH', '/{recipient_id}'),
        'instagram_customer_profile_fields' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'META_INSTAGRAM_CUSTOMER_PROFILE_FIELDS',
            'id,username,name,profile_pic,profile_pic_url'
        ))))),
        'instagram_customer_profile_retry_hours' => (int) env('META_INSTAGRAM_CUSTOMER_PROFILE_RETRY_HOURS', 24),
        'token_exchange_url' => env('INSTAGRAM_TOKEN_EXCHANGE_URL', 'https://graph.instagram.com/access_token'),
        'token_exchange_method' => env('INSTAGRAM_TOKEN_EXCHANGE_METHOD', 'GET'),
        'token_exchange_use_version' => filter_var(env('INSTAGRAM_TOKEN_EXCHANGE_USE_VERSION', false), FILTER_VALIDATE_BOOLEAN),
        'token_refresh_url' => env('INSTAGRAM_TOKEN_REFRESH_URL', 'https://graph.instagram.com/refresh_access_token'),
        'token_refresh_days_before_expiry' => (int) env('INSTAGRAM_TOKEN_REFRESH_DAYS_BEFORE_EXPIRY', 14),
        'oauth_config_id' => env('META_OAUTH_CONFIG_ID'),
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v25.0'),
        'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'oauth_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'META_OAUTH_SCOPES',
            'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments'
        ))))),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
    ],

    'voice_runtime' => [
        'internal_token' => env('VOICE_RUNTIME_INTERNAL_TOKEN'),
    ],

    'young_fashion_show' => [
        'incoming_api_token' => env('INCOMING_API_TOKEN'),
        'sso_verify_endpoint' => env(
            'INCOMING_SSO_VERIFY_ENDPOINT',
            'https://app.youngfashionshow.com/api/incoming/ai-assistant/sso/verify',
        ),
    ],

];
