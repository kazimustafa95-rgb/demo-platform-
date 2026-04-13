<?php

namespace App\Jobs;

use App\Models\Amendment;
use App\Models\Bill;
use App\Models\User;
use App\Services\OpenStatesApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SyncStateBillDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    protected string $externalId;

    public function __construct(string $externalId)
    {
        $this->externalId = $externalId;
    }

    public function handle(OpenStatesApi $api): void
    {
        $details = $api->getBill($this->externalId);

        if (!$details) {
            return;
        }

        $bill = Bill::where('external_id', $this->externalId)->first();

        if (!$bill) {
            return;
        }

        $summary = $this->extractSummaryText($details['abstracts'] ?? [])
            ?: $bill->summary
            ?: ($details['latest_action_description'] ?? null)
            ?: $bill->title;

        $sponsors = $this->extractSponsors($details['sponsorships'] ?? []);
        $committees = $this->extractCommitteeNames($details['actions'] ?? []);
        $billTextUrl = $this->selectBillTextUrl(
            $details['versions'] ?? [],
            $details['documents'] ?? [],
            $details['openstates_url'] ?? $bill->bill_text_url
        );
        $relatedDocuments = $this->extractRelatedDocuments($details);
        $amendmentsHistory = $this->extractAmendmentsHistory($details);

        $bill->update([
            'title' => $details['title'] ?? $bill->title,
            'summary' => $summary,
            'bill_text_url' => $billTextUrl,
            'sponsors' => $sponsors,
            'committees' => $committees,
            'amendments_history' => $amendmentsHistory,
            'related_documents' => array_values($relatedDocuments),
        ]);

        $this->syncOfficialAmendments($bill, $amendmentsHistory);
    }

    private function extractSummaryText(mixed $abstracts): ?string
    {
        $parts = [];

        foreach ($this->normalizeCollection($abstracts) as $abstract) {
            if (!is_array($abstract)) {
                continue;
            }

            $text = trim((string) ($abstract['abstract'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode("\n\n", array_unique($parts));
    }

    private function extractSponsors(mixed $sponsorships): array
    {
        $normalized = [];

        foreach ($this->normalizeCollection($sponsorships) as $sponsor) {
            if (!is_array($sponsor)) {
                continue;
            }

            $normalized[] = [
                'name' => $sponsor['name'] ?? null,
                'entity_type' => $sponsor['entity_type'] ?? null,
                'person_id' => data_get($sponsor, 'person.id'),
                'organization_id' => data_get($sponsor, 'organization.id'),
                'party' => data_get($sponsor, 'person.party'),
                'district' => data_get($sponsor, 'person.current_role.district'),
                'primary' => $sponsor['primary'] ?? false,
                'classification' => $sponsor['classification'] ?? null,
            ];
        }

        return $normalized;
    }

    private function extractCommitteeNames(mixed $actions): array
    {
        $committees = [];

        foreach ($this->normalizeCollection($actions) as $action) {
            if (!is_array($action)) {
                continue;
            }

            foreach ($this->normalizeCollection($action['related_entities'] ?? []) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $classification = strtolower(trim((string) data_get($entity, 'organization.classification', '')));
                $name = trim((string) ($entity['name'] ?? data_get($entity, 'organization.name') ?? ''));

                if ($name === '') {
                    continue;
                }

                if (in_array($classification, ['committee', 'subcommittee'], true) || str_contains(strtolower($name), 'committee')) {
                    $committees[$name] = $name;
                }
            }
        }

        return array_values($committees);
    }

    private function selectBillTextUrl(mixed $versions, mixed $documents, ?string $fallback = null): ?string
    {
        $preferredMediaTypes = [
            'text/html',
            'application/xhtml+xml',
            'application/pdf',
            'text/plain',
        ];

        foreach ($preferredMediaTypes as $preferredMediaType) {
            foreach ([$versions, $documents] as $collection) {
                foreach ($this->normalizeCollection($collection) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    foreach ($this->normalizeCollection($item['links'] ?? []) as $link) {
                        if (!is_array($link)) {
                            continue;
                        }

                        $url = $link['url'] ?? null;
                        $mediaType = strtolower(trim((string) ($link['media_type'] ?? '')));

                        if (!blank($url) && $mediaType === $preferredMediaType) {
                            return $url;
                        }
                    }
                }
            }
        }

        foreach ([$versions, $documents] as $collection) {
            foreach ($this->normalizeCollection($collection) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach ($this->normalizeCollection($item['links'] ?? []) as $link) {
                    $url = is_array($link) ? ($link['url'] ?? null) : null;

                    if (!blank($url)) {
                        return $url;
                    }
                }
            }
        }

        return $fallback;
    }

    private function extractRelatedDocuments(array $details): array
    {
        $documents = [];

        if (!blank($details['openstates_url'] ?? null)) {
            $documents = $this->addRelatedDocument($documents, [
                'type' => 'openstates_bill',
                'label' => 'Open States bill page',
                'url' => $details['openstates_url'],
            ]);
        }

        foreach ($this->normalizeCollection($details['sources'] ?? []) as $source) {
            if (!is_array($source)) {
                continue;
            }

            $documents = $this->addRelatedDocument($documents, [
                'type' => 'source',
                'label' => $source['note'] ?? 'Official source',
                'url' => $source['url'] ?? null,
            ]);
        }

        foreach ([
            'versions' => 'version',
            'documents' => 'document',
        ] as $key => $type) {
            foreach ($this->normalizeCollection($details[$key] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach ($this->normalizeCollection($item['links'] ?? []) as $link) {
                    if (!is_array($link)) {
                        continue;
                    }

                    $documents = $this->addRelatedDocument($documents, [
                        'type' => $type,
                        'label' => $item['note'] ?? ucfirst($type),
                        'url' => $link['url'] ?? null,
                        'date' => $item['date'] ?? null,
                        'classification' => $item['classification'] ?? null,
                        'media_type' => $link['media_type'] ?? null,
                    ]);
                }
            }
        }

        foreach ($this->normalizeCollection($details['related_bills'] ?? []) as $relatedBill) {
            if (!is_array($relatedBill)) {
                continue;
            }

            $documents = $this->addRelatedDocument($documents, [
                'type' => 'related_bill',
                'label' => trim((string) (($relatedBill['identifier'] ?? 'Bill') . ' related bill')),
                'url' => $relatedBill['openstates_url'] ?? null,
                'relation_type' => $relatedBill['relation_type'] ?? null,
                'session' => $relatedBill['legislative_session'] ?? null,
            ]);
        }

        return $documents;
    }

    private function extractAmendmentsHistory(array $details): array
    {
        $history = [];

        foreach ([
            'versions' => 'version',
            'documents' => 'document',
        ] as $key => $type) {
            foreach ($this->normalizeCollection($details[$key] ?? []) as $item) {
                if (!is_array($item) || !$this->isAmendmentDocument($item)) {
                    continue;
                }

                $history[] = [
                    'type' => $type,
                    'label' => $item['note'] ?? 'Amendment',
                    'description' => $item['note'] ?? $item['classification'] ?? 'Official amendment',
                    'classification' => $item['classification'] ?? null,
                    'date' => $this->normalizeNullableString($item['date'] ?? null),
                    'url' => data_get($item, 'links.0.url'),
                    'media_type' => data_get($item, 'links.0.media_type'),
                ];
            }
        }

        foreach ($this->normalizeCollection($details['actions'] ?? []) as $index => $action) {
            if (!is_array($action) || !$this->isAmendmentAction($action)) {
                continue;
            }

            $history[] = [
                'type' => 'action',
                'label' => 'Amendment action #' . ($index + 1),
                'description' => $action['description'] ?? 'Official amendment action',
                'classification' => $action['classification'] ?? [],
                'date' => $this->normalizeNullableString($action['date'] ?? null),
                'url' => null,
                'organization' => data_get($action, 'organization.name'),
            ];
        }

        $deduped = [];

        foreach ($history as $entry) {
            $key = md5(json_encode([
                $entry['type'] ?? null,
                $entry['label'] ?? null,
                $entry['description'] ?? null,
                $entry['date'] ?? null,
                $entry['url'] ?? null,
            ]));

            $deduped[$key] = $entry;
        }

        $history = array_values($deduped);

        usort($history, fn (array $a, array $b) => strcmp(
            (string) ($a['date'] ?? ''),
            (string) ($b['date'] ?? '')
        ));

        return $history;
    }

    private function isAmendmentDocument(array $item): bool
    {
        $classification = strtolower(trim((string) ($item['classification'] ?? '')));
        $note = strtolower(trim((string) ($item['note'] ?? '')));

        return str_contains($classification, 'amend')
            || str_contains($note, 'amend');
    }

    private function isAmendmentAction(array $action): bool
    {
        $description = strtolower(trim((string) ($action['description'] ?? '')));
        if (str_contains($description, 'amend')) {
            return true;
        }

        foreach ($this->normalizeCollection($action['classification'] ?? []) as $classification) {
            $classification = strtolower(trim((string) $classification));

            if (str_contains($classification, 'amend')) {
                return true;
            }
        }

        return false;
    }

    private function syncOfficialAmendments(Bill $bill, array $amendmentsHistory): void
    {
        if ($amendmentsHistory === []) {
            return;
        }

        $importUserId = $this->importUser()->id;

        foreach ($amendmentsHistory as $index => $entry) {
            $text = trim((string) ($entry['description'] ?? $entry['label'] ?? ''));
            if ($text === '') {
                $text = 'Official state amendment';
            }

            $entryDate = $this->normalizeNullableString($entry['date'] ?? null);

            $externalId = 'OPENSTATES-AMENDMENT-' . md5(json_encode([
                $bill->external_id,
                $entry['type'] ?? null,
                $entry['label'] ?? null,
                $entry['description'] ?? null,
                $entryDate,
                $entry['url'] ?? null,
                $index,
            ]));

            Amendment::updateOrCreate(
                ['external_id' => $externalId],
                [
                    'source' => Amendment::SOURCE_OPENSTATES,
                    'user_id' => $importUserId,
                    'bill_id' => $bill->id,
                    'chamber' => null,
                    'sponsors' => is_array($bill->sponsors) ? $bill->sponsors : [],
                    'latest_action' => [
                        'text' => $entry['description'] ?? $entry['label'] ?? null,
                        'date' => $entryDate,
                    ],
                    'proposed_at' => $entryDate,
                    'submitted_at' => $entryDate,
                    'text_url' => $entry['url'] ?? null,
                    'metadata' => [
                        'provider' => 'openstates',
                        'history_entry' => $entry,
                        'bill_external_id' => $bill->external_id,
                    ],
                    'amendment_text' => $text,
                    'category' => 'official',
                    'support_count' => 0,
                    'threshold_reached' => false,
                    'threshold_reached_at' => null,
                    'hidden' => false,
                ]
            );
        }
    }

    private function importUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'openstates-import@system.local'],
            [
                'name' => 'Open States Import',
                'password' => Str::random(40),
                'email_verified_at' => now(),
                'verified_at' => now(),
                'is_verified' => false,
            ]
        );
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

    private function addRelatedDocument(array $documents, array $candidate): array
    {
        $url = $candidate['url'] ?? null;
        if (blank($url)) {
            return $documents;
        }

        foreach ($documents as $existing) {
            if (($existing['url'] ?? null) === $url) {
                return $documents;
            }
        }

        $documents[] = $candidate;

        return $documents;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
