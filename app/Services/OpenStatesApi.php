<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenStatesApi
{
    private const QUOTA_CACHE_KEY = 'openstates:quota_exceeded_until';

    protected string $apiKey;
    protected string $baseUrl = 'https://v3.openstates.org/';
    protected int $maxPerPage;
    protected int $requestIntervalMs;
    protected int $timeoutSeconds;
    protected int $connectTimeoutSeconds;
    protected int $retryDelayMs;
    protected static float $lastRequestAt = 0.0;

    public function __construct()
    {
        $this->apiKey = config('services.open_states.api_key');
        $this->maxPerPage = max(1, (int) config('services.open_states.max_per_page', 20));
        $this->requestIntervalMs = max(0, (int) config('services.open_states.request_interval_ms', 6500));
        $this->timeoutSeconds = max(1, (int) config('services.open_states.timeout_seconds', 60));
        $this->connectTimeoutSeconds = max(1, (int) config('services.open_states.connect_timeout_seconds', 15));
        $this->retryDelayMs = max(0, (int) config('services.open_states.retry_delay_ms', 1500));
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
        return $this->request(
            'bills/' . rawurlencode((string) $id),
            [
                'include' => $this->normalizeInclude([
                    'abstracts',
                    'actions',
                    'sponsorships',
                    'documents',
                    'versions',
                    'related_bills',
                    'sources',
                ]),
            ],
            'bill detail'
        );
    }

    public function getPerson($id, array $include = [])
    {
        return $this->request(
            'people/' . rawurlencode((string) $id),
            [
                'include' => $this->normalizeInclude($include),
            ],
            'person detail'
        );
    }

    public function getLegislators($jurisdiction, $chamber = null, $page = 1, $perPage = 50, array $include = [])
    {
        $params = [
            'jurisdiction' => $jurisdiction,
            'page' => $page,
            'per_page' => max(1, min((int) $perPage, $this->maxPerPage)),
            'include' => $this->normalizeInclude($include),
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

    public function isQuotaExceeded(): bool
    {
        $until = Cache::get(self::QUOTA_CACHE_KEY);
        
        if (blank($until)) {
            return false;
        }
        return now()->lt(Carbon::parse((string) $until));
    }

    private function request(string $endpoint, array $params, string $context): ?array
    {
        if ($this->isQuotaExceeded()) {
            return null;
        }

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->throttle();

            try {
                $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
                    ->acceptJson()
                    ->connectTimeout($this->connectTimeoutSeconds)
                    ->timeout($this->timeoutSeconds)
                    ->get($this->buildUrl($endpoint, $params));
            } catch (ConnectionException $exception) {
                Log::warning("OpenStates API connection error ({$context}): " . $exception->getMessage(), [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                ]);

                if ($attempt < $maxAttempts && $this->retryDelayMs > 0) {
                    usleep($this->retryDelayMs * 1000);
                    continue;
                }

                return null;
            }

            if ($response->status() === 429 && $this->isDailyLimitExceeded($response->body())) {
                $this->markQuotaExceeded($response->body(), $endpoint, $context);

                return null;
            }

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

    private function normalizeInclude(array $include): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value) => is_string($value) ? trim($value) : null,
            $include
        ))));
    }

    private function buildUrl(string $endpoint, array $params): string
    {
        $base = $this->baseUrl . ltrim($endpoint, '/');
        $pairs = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item === null || $item === '') {
                        continue;
                    }

                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
                }

                continue;
            }

            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $pairs === [] ? $base : $base . '?' . implode('&', $pairs);
    }

    private function isDailyLimitExceeded(string $body): bool
    {
        $normalized = strtolower($body);

        return str_contains($normalized, 'exceeded limit')
            || (str_contains($normalized, '/day') && str_contains($normalized, 'detail'));
    }

    private function markQuotaExceeded(string $body, string $endpoint, string $context): void
    {
        if ($this->isQuotaExceeded()) {
            return;
        }

        $until = now()->endOfDay();

        Cache::put(self::QUOTA_CACHE_KEY, $until->toISOString(), $until);

        Log::warning('OpenStates daily quota exhausted; skipping additional requests until reset.', [
            'context' => $context,
            'endpoint' => $endpoint,
            'retry_available_after' => $until->toISOString(),
            'detail' => $body,
        ]);
    }
}
