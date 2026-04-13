<?php

namespace App\Jobs;

use App\Models\Amendment;
use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\CongressGovApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SyncFederalAmendmentDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        protected int $congress,
        protected string $amendmentType,
        protected string $amendmentNumber,
        protected ?int $billId = null,
    ) {
    }

    public function handle(CongressGovApi $api): void
    {
        $response = $api->getAmendmentDetails($this->congress, $this->amendmentType, $this->amendmentNumber);
        $data = $response['amendment'] ?? $response;

        if (!is_array($data) || $data === []) {
            return;
        }

        $bill = $this->resolveBill($data);
        if (!$bill) {
            return;
        }

        $actions = [];
        $actionsUrl = $this->extractEndpointUrl($data['actions'] ?? null);
        if ($actionsUrl) {
            $actions = $api->getAmendmentActionsCollection($actionsUrl);
        }

        $cosponsors = [];
        $cosponsorsUrl = $this->extractEndpointUrl($data['cosponsors'] ?? null);
        if ($cosponsorsUrl) {
            $cosponsors = $api->getAmendmentCosponsorsCollection($cosponsorsUrl);
        }

        $textVersions = [];
        $textVersionsUrl = $this->extractEndpointUrl($data['textVersions'] ?? null);
        if ($textVersionsUrl) {
            $textVersions = $api->getAmendmentTextVersionsCollection($textVersionsUrl);
        }

        $childAmendments = [];
        $childrenUrl = $this->extractEndpointUrl($data['amendmentsToAmendment'] ?? null);
        if ($childrenUrl) {
            $childAmendments = $api->getAmendmentChildrenCollection($childrenUrl);
        }

        $externalId = $this->buildExternalId(
            (int) ($data['congress'] ?? $this->congress),
            (string) ($data['type'] ?? $this->amendmentType),
            (string) ($data['number'] ?? $this->amendmentNumber)
        );

        Amendment::updateOrCreate(
            ['external_id' => $externalId],
            [
                'source' => Amendment::SOURCE_CONGRESS_GOV,
                'user_id' => $this->importUser()->id,
                'bill_id' => $bill->id,
                'congress' => (int) ($data['congress'] ?? $this->congress),
                'amendment_type' => strtoupper((string) ($data['type'] ?? $this->amendmentType)),
                'amendment_number' => (string) ($data['number'] ?? $this->amendmentNumber),
                'chamber' => $data['chamber'] ?? null,
                'sponsors' => $this->normalizePeople($data['sponsors'] ?? []),
                'latest_action' => $data['latestAction'] ?? null,
                'proposed_at' => $data['proposedDate'] ?? null,
                'submitted_at' => $data['submittedDate'] ?? null,
                'text_url' => $this->selectTextUrl($textVersions),
                'congress_gov_url' => $data['url'] ?? $this->defaultApiUrl(),
                'metadata' => [
                    'description' => $data['description'] ?? null,
                    'purpose' => $data['purpose'] ?? null,
                    'notes' => $this->extractNotes($data['notes'] ?? []),
                    'actions' => $actions,
                    'action_count' => count($actions),
                    'cosponsors' => $cosponsors,
                    'cosponsor_count' => count($cosponsors),
                    'text_versions' => $textVersions,
                    'amended_bill' => $data['amendedBill'] ?? null,
                    'amended_amendment' => $data['amendedAmendment'] ?? null,
                    'child_amendments' => $childAmendments,
                    'relationship_url' => $childrenUrl,
                ],
                'amendment_text' => $this->resolveAmendmentText($data),
                'category' => 'official',
                'support_count' => 0,
                'threshold_reached' => false,
                'threshold_reached_at' => null,
                'hidden' => false,
            ]
        );
    }

    private function resolveBill(array $data): ?Bill
    {
        if ($this->billId) {
            $bill = Bill::find($this->billId);
            if ($bill) {
                return $bill;
            }
        }

        $billData = $data['amendedBill'] ?? null;
        if (!is_array($billData)) {
            return null;
        }

        $billCongress = (int) ($billData['congress'] ?? 0);
        $billType = strtoupper(trim((string) ($billData['type'] ?? '')));
        $billNumber = trim((string) ($billData['number'] ?? ''));

        if ($billCongress === 0 || $billType === '' || $billNumber === '') {
            return null;
        }

        $externalId = $billType . '-' . $billNumber . '-' . $billCongress;
        $bill = Bill::where('external_id', $externalId)->first();
        if ($bill) {
            return $bill;
        }

        $jurisdiction = Jurisdiction::where('type', 'federal')->first();
        if (!$jurisdiction) {
            return null;
        }

        return Bill::create([
            'external_id' => $externalId,
            'jurisdiction_id' => $jurisdiction->id,
            'number' => $billNumber,
            'title' => (string) ($billData['title'] ?? ($billType . ' ' . $billNumber)),
            'status' => 'active',
            'introduced_date' => $billData['introducedDate'] ?? null,
        ]);
    }

    private function importUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'congress.gov-import@system.local'],
            [
                'name' => 'Congress.gov Import',
                'password' => Str::random(40),
                'email_verified_at' => now(),
                'verified_at' => now(),
                'is_verified' => false,
            ]
        );
    }

    private function buildExternalId(int $congress, string $type, string $number): string
    {
        return strtoupper(trim($type)) . '-' . trim($number) . '-' . $congress;
    }

    private function defaultApiUrl(): string
    {
        return sprintf(
            'https://api.congress.gov/v3/amendment/%d/%s/%s?format=json',
            $this->congress,
            strtolower($this->amendmentType),
            $this->amendmentNumber
        );
    }

    private function normalizePeople(mixed $people): array
    {
        $normalized = [];

        foreach ($this->normalizeCollection($people) as $person) {
            if (!is_array($person)) {
                continue;
            }

            $normalized[] = [
                'name' => $person['fullName'] ?? null,
                'first_name' => $person['firstName'] ?? null,
                'last_name' => $person['lastName'] ?? null,
                'middle_name' => $person['middleName'] ?? null,
                'bioguide_id' => $person['bioguideId'] ?? null,
                'party' => $person['party'] ?? null,
                'state' => $person['state'] ?? null,
                'url' => $person['url'] ?? null,
                'is_by_request' => $person['isByRequest'] ?? null,
            ];
        }

        return $normalized;
    }

    private function resolveAmendmentText(array $data): string
    {
        foreach ([
            $data['description'] ?? null,
            $data['purpose'] ?? null,
            $this->extractNotes($data['notes'] ?? []),
            data_get($data, 'latestAction.text'),
        ] as $candidate) {
            $text = trim(strip_tags((string) $candidate));

            if ($text !== '') {
                return $text;
            }
        }

        return 'Official congressional amendment';
    }

    private function extractNotes(mixed $notes): ?string
    {
        $parts = [];

        foreach ($this->normalizeCollection($notes) as $note) {
            if (!is_array($note)) {
                continue;
            }

            foreach (['text', 'description', 'note'] as $key) {
                $value = trim(strip_tags((string) ($note[$key] ?? '')));
                if ($value !== '') {
                    $parts[] = $value;
                    break;
                }
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode("\n", array_unique($parts));
    }

    private function selectTextUrl(array $textVersions): ?string
    {
        $rankedFormats = ['formatted text', 'formatted xml', 'xml', 'pdf'];

        foreach ($rankedFormats as $preferredFormat) {
            foreach ($textVersions as $version) {
                foreach (($version['formats'] ?? []) as $format) {
                    $formatType = strtolower((string) ($format['type'] ?? ''));
                    $url = $format['url'] ?? null;

                    if ($formatType === $preferredFormat && !blank($url)) {
                        return $url;
                    }
                }
            }
        }

        foreach ($textVersions as $version) {
            foreach (($version['formats'] ?? []) as $format) {
                if (!blank($format['url'] ?? null)) {
                    return $format['url'];
                }
            }
        }

        return null;
    }

    private function extractEndpointUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) !== '' ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        $candidate = $value['url'] ?? $value[0] ?? data_get($value, '0.url');

        return is_string($candidate) && trim($candidate) !== '' ? $candidate : null;
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
