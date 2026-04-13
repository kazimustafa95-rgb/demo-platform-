<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenStatesApi
{
    protected string $apiKey;
    protected string $baseUrl = 'https://v3.openstates.org/';
    protected int $maxPerPage;
    protected int $requestIntervalMs;
    protected static float $lastRequestAt = 0.0;

    public function __construct()
    {
        $this->apiKey = config('services.open_states.api_key');
        $this->maxPerPage = max(1, (int) config('services.open_states.max_per_page', 20));
        $this->requestIntervalMs = max(0, (int) config('services.open_states.request_interval_ms', 6500));
    }

    public function getBills($jurisdiction, $session = null, $page = 1, $perPage = 50)
    {
        $perPage = max(1, min((int) $perPage, $this->maxPerPage));

        return $this->request('bills', [
            'jurisdiction' => $jurisdiction,
            'session' => $session,
            'page' => $page,
            'per_page' => $perPage,
        ], 'bills');
    }

    public function getBill($id)
    {
        return $this->request('bills/' . $id, [], 'bill detail');
    }

    public function getLegislators($jurisdiction, $chamber = null, $page = 1, $perPage = 50)
    {
        $params = [
            'jurisdiction' => $jurisdiction,
            'page' => $page,
            'per_page' => max(1, min((int) $perPage, $this->maxPerPage)),
        ];

        if ($chamber) {
            $params['chamber'] = $chamber;
        }

        return $this->request('people', $params, 'legislators');
    }

    public function getVoteEvents($billId)
    {
        return $this->request('vote-events', [
            'bill' => $billId,
            'include' => 'votes',
        ], 'vote events');
    }

    public function getPeopleByLocation($lat, $lng)
    {
        $response = $this->request('people.geo', [
            'lat' => $lat,
            'lng' => $lng,
        ], 'geo lookup');

        return $response['results'] ?? [];
    }

    private function request(string $endpoint, array $params, string $context): ?array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->throttle();

            $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
                ->get($this->baseUrl . ltrim($endpoint, '/'), array_filter($params, fn ($value) => $value !== null));

            if ($response->status() === 429 && $attempt < $maxAttempts) {
                $retryAfter = max(
                    1,
                    (int) ($response->header('Retry-After') ?: ceil($this->requestIntervalMs / 1000))
                );
                sleep($retryAfter);
                continue;
            }

            if ($response->failed()) {
                Log::error("OpenStates API error ({$context}): " . $response->body(), [
                    'status' => $response->status(),
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                ]);

                return null;
            }

            return $response->json();
        }

        return null;
    }

    private function throttle(): void
    {
        if ($this->requestIntervalMs <= 0) {
            self::$lastRequestAt = microtime(true);
            return;
        }

        $now = microtime(true);
        $elapsedMs = ($now - self::$lastRequestAt) * 1000;
        $sleepMs = $this->requestIntervalMs - $elapsedMs;

        if ($sleepMs > 0) {
            usleep((int) round($sleepMs * 1000));
        }

        self::$lastRequestAt = microtime(true);
    }
}
