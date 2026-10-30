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

    /*
     * Shengena identity (Keycloak, OpenID Connect). Sign-in with Shengena is
     * offered only when OIDC_ENABLED=true and the issuer and client are set.
     */
    'oidc' => [
        'enabled' => (bool) env('OIDC_ENABLED', false),
        // e.g. https://id.shengena.cloud/realms/shengena
        'issuer' => rtrim((string) env('OIDC_ISSUER', ''), '/'),
        // Where this server reaches the issuer, if different (e.g. inside Docker).
        'internal_issuer' => rtrim((string) env('OIDC_INTERNAL_ISSUER', ''), '/'),
        'client_id' => env('OIDC_CLIENT_ID', 'shengena-school'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('OIDC_REDIRECT_URI'),
        // Portal origin the browser returns to after sign-in and sign-out.
        'portal_url' => rtrim((string) env('OIDC_PORTAL_URL', ''), '/'),
    ],

];
