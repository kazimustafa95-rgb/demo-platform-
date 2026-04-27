<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseMessagingService
{
    private ?array $credentials = null;

    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = []): array
    {
        $deviceToken = trim($deviceToken);

        if ($deviceToken === '') {
            throw new RuntimeException('The device token is required.');
        }

        $projectId = trim((string) (config('services.firebase.project_id') ?: ($this->credentials()['project_id'] ?? '')));

        if ($projectId === '') {
            throw new RuntimeException('Firebase project ID is not configured.');
        }

        $response = Http::timeout((int) config('services.firebase.timeout_seconds', 15))
            ->withToken($this->accessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $this->normalizeData($data),
                    'android' => [
                        'priority' => 'HIGH',
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ]);

        $response->throw();

        return $response->json();
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));

        if ($clientEmail === '') {
            throw new RuntimeException('Firebase client email is missing from the service account JSON.');
        }

        return Cache::remember(
            'firebase:access-token:'.md5($clientEmail),
            now()->addMinutes(50),
            fn () => $this->fetchAccessToken()
        );
    }

    private function fetchAccessToken(): string
    {
        $credentials = $this->credentials();
        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
        $privateKey = trim((string) ($credentials['private_key'] ?? ''));

        if ($clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('Firebase service account JSON is missing the client email or private key.');
        }

        $issuedAt = now()->timestamp;
        $expiresAt = $issuedAt + 3600;

        $assertion = $this->signJwt([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], $privateKey);

        $response = Http::asForm()
            ->timeout((int) config('services.firebase.timeout_seconds', 15))
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        $response->throw();

        $accessToken = trim((string) $response->json('access_token'));

        if ($accessToken === '') {
            throw new RuntimeException('Firebase OAuth token response did not include an access token.');
        }

        return $accessToken;
    }

    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = $this->resolveCredentialsPath(trim((string) config('services.firebase.credentials_path')));

        if ($path === '') {
            throw new RuntimeException('Firebase credentials path is not configured.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Firebase credentials file could not be read.');
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        if (!is_array($credentials)) {
            throw new RuntimeException('Firebase credentials file does not contain valid JSON.');
        }

        return $this->credentials = $credentials;
    }

    private function resolveCredentialsPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\');
    }

    private function signJwt(array $claims, string $privateKey): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';

        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Firebase JWT assertion.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[(string) $key] = is_scalar($value)
                ? (string) $value
                : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $normalized;
    }
}
