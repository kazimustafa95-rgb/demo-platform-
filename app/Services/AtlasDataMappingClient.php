<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AtlasDataMappingClient
{
    private string $baseUrl;

    private string $username;

    private string $password;

    private int $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.district_population.atlas.base_url', 'https://www.l2datamapping.com'),
            '/'
        );
        $this->username = trim((string) config('services.district_population.atlas.username', ''));
        $this->password = (string) config('services.district_population.atlas.password', '');
        $this->timeoutSeconds = max(5, (int) config('services.district_population.atlas.timeout_seconds', 30));
    }

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    /**
     * @return array<int, array{customer_code:string, customer_name:string, exports_url:string, scope:string}>
     */
    public function listCustomerScopes(): array
    {
        $session = $this->login();
        $response = $this->request($session)->get($this->baseUrl . '/account/app-picker')->throw();

        preg_match_all(
            '/<a class="export-report-button" href="\/account\/export-report\?c=([^"]+)">.*?<\/a>/is',
            $response->body(),
            $matches,
            PREG_SET_ORDER
        );

        $scopes = [];

        foreach ($matches as $match) {
            $customerCode = strtoupper(trim((string) ($match[1] ?? '')));

            if ($customerCode === '') {
                continue;
            }

            $customerName = '';

            if (preg_match(
                '/<a id="' . preg_quote($customerCode, '/') . '"><\/a>\s*(.*?)\s*<\/span>/is',
                $response->body(),
                $nameMatch
            ) === 1) {
                $customerName = trim(strip_tags((string) ($nameMatch[1] ?? '')));
            }

            $scopes[] = [
                'customer_code' => $customerCode,
                'customer_name' => $customerName,
                'exports_url' => $this->baseUrl . '/account/export-report?c=' . rawurlencode($customerCode),
                'scope' => 'MANAGER:' . $customerCode,
            ];
        }

        return array_values(array_unique($scopes, SORT_REGULAR));
    }

    /**
     * @return array<int, array{
     *     customer_code:string,
     *     customer_name:string,
     *     dataset_id:string,
     *     application_label:string,
     *     pick_url:string,
     *     state_code:?string
     * }>
     */
    public function listApplications(?string $customerCode = null): array
    {
        $session = $this->login();
        $response = $this->request($session)->get($this->baseUrl . '/account/app-picker')->throw();
        $document = $this->htmlDocument($response->body());
        $xpath = new DOMXPath($document);
        $customerNames = [];

        foreach ($xpath->query('//a[@id]') as $anchor) {
            $id = strtoupper(trim((string) $anchor->getAttribute('id')));

            if ($id === '') {
                continue;
            }

            $customerNames[$id] = $this->customerNameForAnchor($anchor);
        }

        $applications = [];

        foreach ($xpath->query('//a[contains(@href, "/atlas/pick?") and contains(@class, "app")]') as $link) {
            $href = html_entity_decode(trim((string) $link->getAttribute('href')));

            if ($href === '') {
                continue;
            }

            $queryString = (string) parse_url($href, PHP_URL_QUERY);
            parse_str($queryString, $query);

            $appCustomerCode = strtoupper(trim((string) ($query['c'] ?? '')));
            $datasetId = strtoupper(trim((string) ($query['a'] ?? '')));

            if ($appCustomerCode === '' || $datasetId === '') {
                continue;
            }

            if ($customerCode !== null && strtoupper(trim($customerCode)) !== $appCustomerCode) {
                continue;
            }

            $label = $this->applicationLabelForLink($xpath, $link);
            $key = $appCustomerCode . '|' . $datasetId;

            $applications[$key] = [
                'customer_code' => $appCustomerCode,
                'customer_name' => $customerNames[$appCustomerCode] ?? '',
                'dataset_id' => $datasetId,
                'application_label' => $label !== '' ? $label : $datasetId,
                'pick_url' => str_starts_with($href, 'http') ? $href : $this->baseUrl . $href,
                'state_code' => $this->datasetStateCode($datasetId),
            ];
        }

        return array_values($applications);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchExports(
        string $scope,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?string $exportId = null,
        ?string $universeName = null
    ): array {
        $session = $this->login();
        $response = $this->request($session)
            ->asForm()
            ->post($this->baseUrl . '/account/search/export', [
                'export-start' => $startDate->format('Y-m-d 00:00:00'),
                'export-end' => $endDate->format('Y-m-d 23:59:59'),
                'scope' => $scope,
                'export-id' => $exportId ?? '',
                'universe-name' => $universeName ?? '',
            ])
            ->throw();

        $payload = $response->json();

        return array_values(Arr::wrap($payload['exports'] ?? []));
    }

    /**
     * @return array{filename:string, content:string, content_type:string|null}
     */
    public function downloadExport(string $exportId): array
    {
        $session = $this->login();
        $response = $this->request($session)
            ->asForm()
            ->post($this->baseUrl . '/exports/export-file', [
                'export-data' => json_encode(['export' => $exportId], JSON_THROW_ON_ERROR),
                'export-hide-unk' => '',
                'export-hide-noe' => '',
                'export-filename' => '',
            ])
            ->throw();

        return [
            'filename' => $this->extractFilename($response, $exportId),
            'content' => $response->body(),
            'content_type' => $response->header('Content-Type'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchDatasetStats(array $application): array
    {
        $session = $this->login();

        $this->request($session)
            ->get($application['pick_url'])
            ->throw();

        $datasetId = strtoupper(trim((string) ($application['dataset_id'] ?? '')));

        if ($datasetId === '') {
            throw new RuntimeException('Atlas application did not include a dataset id.');
        }

        return $this->request($session)
            ->get($this->baseUrl . '/atlas/stats/' . rawurlencode($datasetId) . '.voters.json')
            ->throw()
            ->json();
    }

    /**
     * @return array{scope:string, customer_code:string, customer_name:string, exports_url:string}
     */
    public function resolveScope(?string $customerCode = null): array
    {
        $scopes = $this->listCustomerScopes();

        if ($customerCode !== null && trim($customerCode) !== '') {
            $normalizedCode = strtoupper(trim($customerCode));

            foreach ($scopes as $scope) {
                if ($scope['customer_code'] === $normalizedCode) {
                    return $scope;
                }
            }

            throw new RuntimeException("No Atlas customer scope matched customer code [{$normalizedCode}].");
        }

        if (count($scopes) === 1) {
            return $scopes[0];
        }

        throw new RuntimeException('Atlas account has multiple customer scopes. Pass --customer-code to choose one.');
    }

    /**
     * @return array{
     *     customer_code:string,
     *     customer_name:string,
     *     dataset_id:string,
     *     application_label:string,
     *     pick_url:string,
     *     state_code:?string
     * }
     */
    public function resolveApplication(
        ?string $customerCode = null,
        ?string $datasetId = null,
        ?string $stateCode = null
    ): array {
        $applications = $this->listApplications($customerCode);

        if ($datasetId !== null && trim($datasetId) !== '') {
            $normalizedDatasetId = strtoupper(trim($datasetId));

            foreach ($applications as $application) {
                if ($application['dataset_id'] === $normalizedDatasetId) {
                    return $application;
                }
            }

            throw new RuntimeException("No Atlas application matched dataset id [{$normalizedDatasetId}].");
        }

        if ($stateCode !== null && trim($stateCode) !== '') {
            $normalizedStateCode = strtoupper(trim($stateCode));
            $applications = array_values(array_filter(
                $applications,
                fn (array $application): bool => ($application['state_code'] ?? null) === $normalizedStateCode
            ));

            if (count($applications) === 1) {
                return $applications[0];
            }

            if ($applications === []) {
                throw new RuntimeException("No Atlas application matched state code [{$normalizedStateCode}].");
            }
        }

        if (count($applications) === 1) {
            return $applications[0];
        }

        throw new RuntimeException('Atlas account has multiple applications. Pass --atlas-dataset to choose one.');
    }

    public function datasetStateCode(?string $datasetId): ?string
    {
        $datasetId = strtoupper(trim((string) $datasetId));

        if (preg_match('/(?:^|_)([A-Z]{2})$/', $datasetId, $matches) === 1) {
            return strtoupper((string) $matches[1]);
        }

        return null;
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    private function login(): CookieJar
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Atlas district population integration is not configured.');
        }

        $cookies = new CookieJar();

        $response = $this->request($cookies)
            ->asForm()
            ->post($this->baseUrl . '/account/login', [
                'username' => $this->username,
                'password' => $this->password,
                'r0' => '/atlas',
            ])
            ->throw();

        $body = strtolower($response->body());

        if (str_contains($body, 'placeholder="password"') && str_contains($body, 'placeholder="username"')) {
            throw new RuntimeException('Atlas login did not complete successfully. Verify the configured username and password.');
        }

        return $cookies;
    }

    private function request(CookieJar $cookies): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withOptions([
            'cookies' => $cookies,
            'allow_redirects' => true,
        ])
            ->accept('*/*')
            ->connectTimeout($this->timeoutSeconds)
            ->timeout($this->timeoutSeconds);
    }

    private function extractFilename(Response $response, string $exportId): string
    {
        $disposition = (string) $response->header('Content-Disposition', '');

        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));

        return match (true) {
            str_contains($contentType, 'json') => $exportId . '.json',
            str_contains($contentType, 'zip') => $exportId . '.zip',
            default => $exportId . '.csv',
        };
    }

    private function htmlDocument(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function customerNameForAnchor(\DOMElement $anchor): string
    {
        $node = $anchor->parentNode;

        while ($node !== null && $node->nodeName !== 'span') {
            $node = $node->parentNode;
        }

        if (!$node instanceof \DOMNode) {
            return '';
        }

        $label = trim(preg_replace('/\s+/', ' ', (string) $node->textContent) ?: '');
        $anchorId = trim((string) $anchor->getAttribute('id'));

        return trim(str_replace($anchorId, '', $label));
    }

    private function applicationLabelForLink(DOMXPath $xpath, \DOMElement $link): string
    {
        $nodes = $xpath->query('.//*[contains(@class, "a-right")]', $link);

        if ($nodes !== false && $nodes->length > 0) {
            $labelNode = $nodes->item(0);

            if ($labelNode instanceof \DOMElement) {
                $title = trim((string) $labelNode->getAttribute('title'));

                if ($title !== '') {
                    return $title;
                }

                return trim(preg_replace('/\s+/', ' ', (string) $labelNode->textContent) ?: '');
            }
        }

        return trim(preg_replace('/\s+/', ' ', (string) $link->textContent) ?: '');
    }
}
