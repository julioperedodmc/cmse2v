<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    /**
     * Send a push notification using Firebase Cloud Messaging (FCM) HTTP v1 API.
     *
     * @param string $token Device FCM registration token
     * @param string $title Notification title
     * @param string $body Notification body text
     * @param array $data Custom key-value data payload
     * @return bool
     */
    public static function send(string $token, string $title, string $body, array $data = []): bool
    {
        if (empty($token)) {
            Log::warning('FCM v1: Cannot send push notification because device token is empty.');
            return false;
        }

        $projectId = config('services.fcm.project_id', env('FIREBASE_PROJECT_ID', 'electropoint-8c3d1'));
        $accessToken = static::getAccessToken($projectId);

        if (!$accessToken) {
            Log::error('FCM v1: Unable to obtain Google OAuth2 access token. Push aborted.');
            return false;
        }

        // Format all data values to strings (FCM HTTP v1 requires string values in data map)
        $formattedData = [];
        foreach ($data as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $formattedData[(string)$k] = json_encode($v);
            } else {
                $formattedData[(string)$k] = (string)$v;
            }
        }
        $formattedData['click_action'] = 'FLUTTER_NOTIFICATION_CLICK';

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $formattedData,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'high_importance_channel',
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'content-available' => 1,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json; UTF-8'])
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('FCM v1: Push notification sent successfully.', [
                    'title' => $title,
                    'token_preview' => substr($token, 0, 16) . '...',
                    'response' => $response->json(),
                ]);
                return true;
            }

            Log::error('FCM v1: Google API returned an error response.', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
                'token_preview' => substr($token, 0, 16) . '...',
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('FCM v1: Unexpected exception sending push notification.', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Retrieve or generate a valid Google OAuth2 Access Token for FCM HTTP v1.
     * Caches the token for 55 minutes to minimize OAuth token exchanges.
     */
    public static function getAccessToken(?string &$projectId = null): ?string
    {
        return Cache::remember('fcm_v1_access_token', 3300, function () use (&$projectId) {
            return static::generateAccessToken($projectId);
        });
    }

    /**
     * Parse service account credentials and perform JWT OAuth2 token exchange with Google.
     */
    protected static function generateAccessToken(?string &$projectId = null): ?string
    {
        $credentialsPath = config('services.fcm.credentials_path', env('FIREBASE_CREDENTIALS', storage_path('app/firebase-credentials.json')));

        // Also check fallback locations
        if (!file_exists($credentialsPath)) {
            $fallbacks = [
                storage_path('app/firebase-credentials.json'),
                storage_path('app/firebase-service-account.json'),
                base_path('firebase-credentials.json'),
                base_path('firebase-service-account.json'),
            ];
            foreach ($fallbacks as $fallback) {
                if (file_exists($fallback)) {
                    $credentialsPath = $fallback;
                    break;
                }
            }
        }

        if (!file_exists($credentialsPath)) {
            Log::warning("FCM v1: Service account credentials JSON not found at: {$credentialsPath}. Please place your Firebase service account JSON file there.");
            return null;
        }

        $credentialsContent = file_get_contents($credentialsPath);
        $credentials = json_decode($credentialsContent, true);

        if (!$credentials || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            Log::error('FCM v1: Invalid service account credentials JSON structure.');
            return null;
        }

        if (!empty($credentials['project_id'])) {
            $projectId = $credentials['project_id'];
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $encodedHeader = static::base64UrlEncode(json_encode($header));
        $encodedClaims = static::base64UrlEncode(json_encode($claims));
        $dataToSign = "{$encodedHeader}.{$encodedClaims}";

        $signature = '';
        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if (!$privateKey) {
            Log::error('FCM v1: Unable to parse private key from Firebase service account.');
            return null;
        }

        $success = openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            Log::error('FCM v1: Failed to sign JWT with private key.');
            return null;
        }

        $jwt = "{$dataToSign}." . static::base64UrlEncode($signature);

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                $tokenData = $response->json();
                return $tokenData['access_token'] ?? null;
            }

            Log::error('FCM v1: Failed to obtain access token from Google OAuth2.', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('FCM v1: Exception during OAuth2 token exchange.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Helper for URL-safe base64 encoding (standard for JWT).
     */
    protected static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

