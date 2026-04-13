<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CongressGovApi
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.congress.gov/v3/';
    protected int $maxLimit = 250;
    protected bool $verifySsl;

    public function __construct()
    {
        $this->apiKey = config('services.congress_gov.api_key');
        $this->verifySsl = (bool) config('services.congress_gov.verify_ssl', true);
    }

    public function currentCongress(): int
    {
        $year = (int) now()->format('Y');

        return (int) floor(($year - 1789) / 2) + 1;
    }

    public function getBills($congress = null, $offset = 0, $limit = 250): ?array
    {
        $congress ??= $this->currentCongress();

        return $this->request('bills', "bill/{$congress}", [
            'offset' => $offset,
            'limit' => $this->sanitizeLimit($limit),
        ]);
    }

    public function getBillDetails($congress, $billType, $billNumber): ?array
    {
        return $this->request(
            'bill details',
            'bill/' . $congress . '/' . strtolower((string) $billType) . '/' . $billNumber
        );
    }

    public function getBillAmendments($congress, $billType, $billNumber, $offset = 0, $limit = 250): ?array
    {
        return $this->request(
            'bill amendments',
            'bill/' . $congress . '/' . strtolower((string) $billType) . '/' . $billNumber . '/amendments',
            [
                'offset' => $offset,
                'limit' => $this->sanitizeLimit($limit),
            ]
        );
    }

    public function getMembers($chamber = null, $state = null, $offset = 0, $limit = 250, ?bool $currentMember = null): ?array
    {
        $path = 'member';
        $params = [
            'offset' => $offset,
            'limit' => $this->sanitizeLimit($limit),
        ];

        if (!blank($state)) {
            $path .= '/' . strtoupper((string) $state);
        }

        if ($currentMember !== null) {
            $params['currentMember'] = $currentMember ? 'true' : 'false';
        }

        return $this->request('members', $path, $params);
    }

    public function getMemberDetails(string $bioguideId): ?array
    {
        return $this->request('member details', 'member/' . strtoupper($bioguideId));
    }

    public function getAmendments(
        ?int $congress = null,
        int $offset = 0,
        int $limit = 250,
        ?string $fromDateTime = null,
        ?string $toDateTime = null
    ): ?array {
        $congress ??= $this->currentCongress();

        return $this->request(
            'amendments',
            'amendment/' . $congress,
            [
                'offset' => $offset,
                'limit' => $this->sanitizeLimit($limit),
                'fromDateTime' => $fromDateTime,
                'toDateTime' => $toDateTime,
            ]
        );
    }

    public function getAmendmentDetails(int $congress, string $amendmentType, string $amendmentNumber): ?array
    {
        return $this->request(
            'amendment details',
            'amendment/' . $congress . '/' . strtolower($amendmentType) . '/' . $amendmentNumber
        );
    }

    public function getAmendmentActionsCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['actions']);
    }

    public function getAmendmentCosponsorsCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['cosponsors']);
    }

    public function getAmendmentTextVersionsCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['textVersions']);
    }

    public function getAmendmentChildrenCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['amendments']);
    }

    public function getAllAmendments(
        ?int $congress = null,
        ?string $fromDateTime = null,
        ?string $toDateTime = null
    ): array {
        $congress ??= $this->currentCongress();

        return $this->getAllPages(
            fn (int $offset, int $limit) => $this->getAmendments($congress, $offset, $limit, $fromDateTime, $toDateTime),
            ['amendments'],
            fn (array $payload): bool => isset($payload['number']) && isset($payload['type'])
        );
    }

    public function getHouseVotes(int $congress, int $session, int $offset = 0, int $limit = 250): ?array
    {
        return $this->request(
            'house votes',
            "house-vote/{$congress}/{$session}",
            [
                'offset' => $offset,
                'limit' => $this->sanitizeLimit($limit),
            ]
        );
    }

    public function getHouseVoteMembers(int $congress, int $session, int $voteNumber, int $offset = 0, int $limit = 250): ?array
    {
        return $this->request(
            'house vote members',
            "house-vote/{$congress}/{$session}/{$voteNumber}/members",
            [
                'offset' => $offset,
                'limit' => $this->sanitizeLimit($limit),
            ]
        );
    }

    public function getAllHouseVotes(int $congress, int $session): array
    {
        return $this->getAllPages(
            fn (int $offset, int $limit) => $this->getHouseVotes($congress, $session, $offset, $limit),
            ['houseRollCallVotes', 'houseVotes', 'rollCallVotes'],
            fn (array $payload): bool => isset($payload['rollCallNumber'])
        );
    }

    public function getAllHouseVoteMembers(int $congress, int $session, int $voteNumber): array
    {
        return $this->getAllPages(
            fn (int $offset, int $limit) => $this->getHouseVoteMembers($congress, $session, $voteNumber, $offset, $limit),
            ['results']
        );
    }

    public function getRollCallVotes($congress, $chamber, $sessionNumber, $rollCallNumber): ?array
    {
        return $this->request(
            'roll call',
            "roll-call/{$congress}/{$chamber}/{$sessionNumber}/{$rollCallNumber}"
        );
    }

    public function getBillSummary(string $url): ?array
    {
        return $this->requestUrl('bill summary', $url);
    }

    public function getBillSummaries(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['summaries']);
    }

    public function getBillTextVersions(string $url): ?array
    {
        return $this->requestUrl('bill text', $url);
    }

    public function getBillTextVersionsCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['textVersions']);
    }

    public function getBillCommittees(string $url): ?array
    {
        return $this->requestUrl('bill committees', $url);
    }

    public function getBillCommitteesCollection(string $url): array
    {
        return $this->getAllPagesByUrl(
            $url,
            ['committees'],
            fn (array $payload): bool => isset($payload['systemCode']) || isset($payload['name'])
        );
    }

    public function getBillActions(string $url): ?array
    {
        return $this->requestUrl('bill actions', $url);
    }

    public function getBillActionsCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['actions']);
    }

    public function getBillAmendmentsCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['amendments']);
    }

    public function getBillRelatedBillsCollection(string $url): array
    {
        return $this->getAllPagesByUrl(
            $url,
            ['relatedBills', 'bills'],
            fn (array $payload): bool => isset($payload['relationshipDetails']) || isset($payload['number'])
        );
    }

    public function getBillTitlesCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['titles']);
    }

    public function getBillCosponsorsCollection(string $url): array
    {
        return $this->getAllPagesByUrl($url, ['cosponsors']);
    }

    public function getBillSubjects(string $url): ?array
    {
        return $this->requestUrl('bill subjects', $url);
    }

    private function request(string $context, string $path, array $params = []): ?array
    {
        return $this->sendRequest($context, $this->baseUrl . ltrim($path, '/'), $params);
    }

    private function requestUrl(string $context, string $url, array $params = []): ?array
    {
        return $this->sendRequest($context, $url, $params);
    }

    private function sendRequest(string $context, string $url, array $params = []): ?array
    {
        try {
            $response = Http::retry(3, 500, throw: false)
                ->timeout(30)
                ->withOptions(['verify' => $this->verifySsl])
                ->acceptJson()
                ->get($url, $this->withDefaultParams($params));
        } catch (ConnectionException $exception) {
            Log::error("Congress.gov connection error ({$context}): " . $exception->getMessage(), [
                'url' => $url,
                'verify_ssl' => $this->verifySsl,
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::error("Congress.gov API error ({$context}): " . $response->body(), [
                'status' => $response->status(),
                'url' => $url,
                'verify_ssl' => $this->verifySsl,
            ]);

            return null;
        }

        return $response->json();
    }

    private function withDefaultParams(array $params = []): array
    {
        $params = array_merge([
            'api_key' => $this->apiKey,
            'format' => 'json',
        ], $params);

        return array_filter($params, fn ($value) => $value !== null && $value !== '');
    }

    private function sanitizeLimit(int $limit): int
    {
        return max(1, min($limit, $this->maxLimit));
    }

    private function getAllPagesByUrl(string $url, array $collectionKeys = [], ?callable $singleObjectDetector = null): array
    {
        return $this->getAllPages(
            fn (int $offset, int $limit) => $this->requestUrl('paginated collection', $url, [
                'offset' => $offset,
                'limit' => $limit,
            ]),
            $collectionKeys,
            $singleObjectDetector
        );
    }

    private function getAllPages(callable $fetchPage, array $collectionKeys = [], ?callable $singleObjectDetector = null): array
    {
        $offset = 0;
        $limit = $this->maxLimit;
        $items = [];

        do {
            $payload = $fetchPage($offset, $limit);

            if (!$payload) {
                break;
            }

            $pageItems = $this->extractCollection($payload, $collectionKeys, $singleObjectDetector);

            foreach ($pageItems as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $pagination = $payload['pagination'] ?? null;
            if (!is_array($pagination) || !isset($pagination['count'])) {
                break;
            }

            $offset += $limit;
        } while ($offset < (int) $pagination['count']);

        return $items;
    }

    private function extractCollection(array $payload, array $collectionKeys = [], ?callable $singleObjectDetector = null): array
    {
        foreach ($collectionKeys as $key) {
            $value = data_get($payload, $key);

            if ($value !== null) {
                return $this->normalizeCollection($value);
            }
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        if ($singleObjectDetector && $singleObjectDetector($payload)) {
            return [$payload];
        }

        return [];
    }

    private function normalizeCollection(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return $value;
        }

        if (array_key_exists('item', $value)) {
            return $this->normalizeCollection($value['item']);
        }

        return [$value];
    }
}
