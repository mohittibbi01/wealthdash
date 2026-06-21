<?php
/**
 * WealthDash — Google OAuth Configuration
 */
declare(strict_types=1);

define('GOOGLE_CLIENT_ID',     env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET', ''));
define('GOOGLE_REDIRECT_URI',  env('GOOGLE_REDIRECT_URI', APP_URL . '/auth/google_callback.php'));

define('RECAPTCHA_SITE_KEY',   env('RECAPTCHA_SITE_KEY', ''));
define('RECAPTCHA_SECRET_KEY', env('RECAPTCHA_SECRET_KEY', ''));
define('RECAPTCHA_ENABLED',    env('RECAPTCHA_ENABLED', false));

/**
 * Build Google OAuth2 authorize URL
 */
function google_auth_url(): string {
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'offline',
        'prompt'        => 'select_account',
        'state'         => csrf_token(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchange auth code for tokens
 */
function google_exchange_code(string $code): array|false {
    $postData = [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return false;
    return json_decode($response, true);
}

/**
 * Fetch Google user info with access token
 */
function google_get_userinfo(string $accessToken): array|false {
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return false;
    return json_decode($response, true);
}

/**
 * Verify reCAPTCHA v2 token
 */
function verify_recaptcha(string $token): bool {
    if (!RECAPTCHA_ENABLED || empty(RECAPTCHA_SECRET_KEY)) return true;

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $result['success'] ?? false;
}

