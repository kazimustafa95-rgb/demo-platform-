<?php

namespace App\Jobs;

use App\Models\Bill;
use App\Models\Setting;
use App\Services\CongressGovApi;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncFederalBillDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    protected int $congress;
    protected string $billType;
    protected string $billNumber;
    protected int $billId;

    public function __construct($congress, $billType, $billNumber, $billId)
    {
        $this->congress = (int) $congress;
        $this->billType = strtolower((string) $billType);
        $this->billNumber = (string) $billNumber;
        $this->billId = (int) $billId;
    }

    public function handle(CongressGovApi $api): void
    {
        $response = $api->getBillDetails(
            $this->congress,
            $this->billType,
            $this->billNumber
        );

        $data = $response['bill'] ?? $response;
        if (!is_array($data) || $data === []) {
            return;
        }

        $bill = Bill::find($this->billId);
        if (!$bill) {
            return;
        }

        $relatedDocuments = is_array($bill->related_documents) ? $bill->related_documents : [];
        $amendmentsHistory = is_array($bill->amendments_history) ? $bill->amendments_history : [];

        if (!empty($data['legislationUrl'])) {
            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'legislation',
                'label' => 'Official legislation page',
                'url' => $data['legislationUrl'],
            ]);
        }

        $summaries = [];
        $summaryUrl = $this->extractEndpointUrl($data['summaries'] ?? null);
        if ($summaryUrl) {
            $summaries = $api->getBillSummaries($summaryUrl);
            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'summary_feed',
                'label' => 'Official summaries feed',
                'url' => $summaryUrl,
                'count' => count($summaries),
            ]);
        }

        $summary = $this->extractSummaryText($summaries)
            ?: $bill->summary
            ?: data_get($data, 'latestAction.text');

        $textVersions = [];
        $billTextUrl = $bill->bill_text_url;
        $textVersionsUrl = $this->extractEndpointUrl($data['textVersions'] ?? null);
        if ($textVersionsUrl) {
            $textVersions = $api->getBillTextVersionsCollection($textVersionsUrl);
            $billTextUrl = $this->selectBillTextUrl($textVersions, $billTextUrl);

            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'text_version_feed',
                'label' => 'Official text versions feed',
                'url' => $textVersionsUrl,
                'count' => count($textVersions),
            ]);

            foreach ($textVersions as $version) {
                foreach (($version['formats'] ?? []) as $format) {
                    $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                        'type' => 'text_version',
                        'label' => $this->textVersionLabel($version, $format),
                        'url' => $format['url'] ?? null,
                        'date' => $version['date'] ?? null,
                        'version_type' => $version['type'] ?? null,
                        'format_type' => $format['type'] ?? null,
                    ]);
                }
            }
        }

        $sponsors = $this->extractSponsors($data['sponsors'] ?? []);

        $committees = [];
        $committeeUrl = $this->extractEndpointUrl($data['committees'] ?? null);
        if ($committeeUrl) {
            $committeeItems = $api->getBillCommitteesCollection($committeeUrl);
            $committees = $this->extractCommitteeNames($committeeItems);

            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'committee_feed',
                'label' => 'Official committees feed',
                'url' => $committeeUrl,
                'count' => count($committeeItems),
            ]);
        }

        $actions = [];
        $actionsUrl = $this->extractEndpointUrl($data['actions'] ?? null);
        if ($actionsUrl) {
            $actions = $api->getBillActionsCollection($actionsUrl);
            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'action_feed',
                'label' => 'Official actions feed',
                'url' => $actionsUrl,
                'count' => count($actions),
            ]);
        }

        $officialVoteDate = $this->extractOfficialVoteDate($actions) ?: $bill->official_vote_date;
        $votingDeadline = $bill->voting_deadline;
        $votingDeadlineHours = (int) Setting::get('voting_deadline_hours', 48);
        if (!blank($officialVoteDate)) {
            $votingDeadline = Carbon::parse($officialVoteDate)->subHours($votingDeadlineHours);
        }

        $amendmentsUrl = $this->extractEndpointUrl($data['amendments'] ?? null);
        if ($amendmentsUrl) {
            $amendments = $api->getBillAmendmentsCollection($amendmentsUrl);
            $amendmentsHistory = $this->formatAmendmentsHistory($amendments);

            foreach ($amendments as $amendment) {
                $type = trim((string) ($amendment['type'] ?? ''));
                $number = trim((string) ($amendment['number'] ?? ''));

                if ($type !== '' && $number !== '') {
                    if (config('queue.default') === 'sync') {
                        SyncFederalAmendmentDetails::dispatchSync($this->congress, $type, $number, $bill->id);
                    } else {
                        SyncFederalAmendmentDetails::dispatch($this->congress, $type, $number, $bill->id);
                    }
                }
            }

            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'amendment_feed',
                'label' => 'Official amendments feed',
                'url' => $amendmentsUrl,
                'count' => count($amendmentsHistory),
            ]);
        }

        $relatedBillsUrl = $this->extractEndpointUrl($data['relatedBills'] ?? null);
        if ($relatedBillsUrl) {
            $relatedBills = $api->getBillRelatedBillsCollection($relatedBillsUrl);

            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'related_bills_feed',
                'label' => 'Official related bills feed',
                'url' => $relatedBillsUrl,
                'count' => count($relatedBills),
            ]);

            foreach ($relatedBills as $relatedBill) {
                $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                    'type' => 'related_bill',
                    'label' => $this->relatedBillLabel($relatedBill),
                    'url' => $relatedBill['url'] ?? null,
                    'relationship_details' => $relatedBill['relationshipDetails'] ?? [],
                    'latest_action' => $relatedBill['latestAction'] ?? $relatedBill['lastestAction'] ?? null,
                ]);
            }
        }

        $subjectsUrl = $this->extractEndpointUrl($data['subjects'] ?? null);
        if ($subjectsUrl) {
            $subjects = $api->getBillSubjects($subjectsUrl) ?? [];
            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'subjects_feed',
                'label' => 'Official subjects feed',
                'url' => $subjectsUrl,
                'policy_area' => data_get($subjects, 'policyArea.name'),
                'subjects' => array_values(array_filter(array_map(
                    fn ($subject) => $subject['name'] ?? null,
                    $subjects['legislativeSubjects'] ?? []
                ))),
            ]);
        }

        $titlesUrl = $this->extractEndpointUrl($data['titles'] ?? null);
        if ($titlesUrl) {
            $titles = $api->getBillTitlesCollection($titlesUrl);
            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'titles_feed',
                'label' => 'Official titles feed',
                'url' => $titlesUrl,
                'count' => count($titles),
            ]);
        }

        $cosponsorsUrl = $this->extractEndpointUrl($data['cosponsors'] ?? null);
        if ($cosponsorsUrl) {
            $cosponsors = $api->getBillCosponsorsCollection($cosponsorsUrl);
            $relatedDocuments = $this->addRelatedDocument($relatedDocuments, [
                'type' => 'cosponsors_feed',
                'label' => 'Official cosponsors feed',
                'url' => $cosponsorsUrl,
                'count' => count($cosponsors),
            ]);
        }

        $introducedDate = $this->extractIntroducedDate($data, $actions) ?: $bill->introduced_date;

        $status = $this->mapStatus(
            data_get($data, 'status'),
            data_get($data, 'latestAction.text'),
            $bill->status,
            $officialVoteDate
        );

        $bill->update([
            'title' => $data['title'] ?? $bill->title,
            'status' => $status,
            'introduced_date' => $introducedDate,
            'summary' => $summary,
            'bill_text_url' => $billTextUrl,
            'sponsors' => !empty($sponsors) ? $sponsors : ($bill->sponsors ?? []),
            'committees' => !empty($committees) ? $committees : ($bill->committees ?? []),
            'official_vote_date' => $officialVoteDate,
            'voting_deadline' => $votingDeadline,
            'amendments_history' => $amendmentsHistory,
            'related_documents' => array_values($relatedDocuments),
        ]);
    }

    private function extractSummaryText(array $summaries): ?string
    {
        usort($summaries, fn (array $a, array $b) => strcmp(
            (string) ($b['updateDate'] ?? $b['actionDate'] ?? ''),
            (string) ($a['updateDate'] ?? $a['actionDate'] ?? '')
        ));

        foreach ($summaries as $summary) {
            $text = trim((string) ($summary['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function extractSponsors(array $sponsors): array
    {
        $normalized = [];

        foreach ($sponsors as $sponsor) {
            if (!is_array($sponsor)) {
                continue;
            }

            $normalized[] = [
                'name' => $sponsor['fullName'] ?? null,
                'first_name' => $sponsor['firstName'] ?? null,
                'last_name' => $sponsor['lastName'] ?? null,
                'middle_name' => $sponsor['middleName'] ?? null,
                'bioguide_id' => $sponsor['bioguideId'] ?? null,
                'party' => $sponsor['party'] ?? null,
                'state' => $sponsor['state'] ?? null,
                'is_by_request' => $sponsor['isByRequest'] ?? null,
                'url' => $sponsor['url'] ?? null,
            ];
        }

        return $normalized;
    }

    private function extractCommitteeNames(array $committeeItems): array
    {
        $committees = [];

        foreach ($committeeItems as $committee) {
            $name = trim((string) ($committee['name'] ?? ''));
            if ($name !== '') {
                $committees[$name] = $name;
            }
        }

        return array_values($committees);
    }

    private function extractIntroducedDate(array $data, array $actions): ?string
    {
        $introducedDate = data_get($data, 'introducedDate');
        if (!blank($introducedDate)) {
            return $introducedDate;
        }

        $dates = array_values(array_filter(array_map(
            fn (array $action) => $action['actionDate'] ?? null,
            $actions
        )));

        if ($dates === []) {
            return null;
        }

        sort($dates);

        return $dates[0];
    }

    private function extractOfficialVoteDate(array $actions): ?string
    {
        $voteActions = array_values(array_filter($actions, fn (array $action) => $this->isVoteAction($action)));

        if ($voteActions === []) {
            return null;
        }

        usort($voteActions, fn (array $a, array $b) => strcmp(
            $this->actionTimestamp($b),
            $this->actionTimestamp($a)
        ));

        $latestVote = $voteActions[0];
        $actionDate = $latestVote['actionDate'] ?? null;
        if (blank($actionDate)) {
            return null;
        }

        $actionTime = $latestVote['actionTime'] ?? null;

        return !blank($actionTime)
            ? Carbon::parse($actionDate . ' ' . $actionTime)->toDateTimeString()
            : $actionDate;
    }

    private function actionTimestamp(array $action): string
    {
        $date = (string) ($action['actionDate'] ?? '');
        $time = (string) ($action['actionTime'] ?? '00:00:00');

        return trim($date . ' ' . $time);
    }

    private function formatAmendmentsHistory(array $amendments): array
    {
        $history = [];

        foreach ($amendments as $amendment) {
            $history[] = [
                'congress' => $amendment['congress'] ?? null,
                'type' => $amendment['type'] ?? null,
                'number' => $amendment['number'] ?? null,
                'description' => $amendment['description'] ?? null,
                'purpose' => $amendment['purpose'] ?? null,
                'latest_action' => $amendment['latestAction'] ?? null,
                'update_date' => $amendment['updateDate'] ?? null,
                'url' => $amendment['url'] ?? null,
            ];
        }

        return $history;
    }

    private function selectBillTextUrl(array $textVersions, ?string $currentUrl = null): ?string
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

        return $currentUrl;
    }

    private function textVersionLabel(array $version, array $format): string
    {
        $versionType = trim((string) ($version['type'] ?? 'Text version'));
        $formatType = trim((string) ($format['type'] ?? 'Document'));

        return $versionType . ' (' . $formatType . ')';
    }

    private function relatedBillLabel(array $relatedBill): string
    {
        $type = strtoupper((string) ($relatedBill['type'] ?? 'Bill'));
        $number = (string) ($relatedBill['number'] ?? '');

        return trim($type . ' ' . $number . ' related bill');
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

    private function isVoteAction(array $action): bool
    {
        $type = strtolower((string) ($action['type'] ?? ''));
        $text = strtolower((string) ($action['text'] ?? ''));

        if ($text === '' && $type === '') {
            return false;
        }

        if ($this->containsAny($text, [
            'recorded vote',
            'roll no.',
            'on passage',
            'passed house',
            'passed senate',
            'failed by',
            'not agreed to',
            'yeas and nays',
            'agreed to by',
            'without objection agreed to',
            'veto override',
            'voice vote',
            'yea-nay vote',
            'roll call vote',
        ])) {
            return true;
        }

        return $this->containsAny($type, ['vote'])
            && $this->containsAny($text, ['vote', 'roll', 'passed', 'failed', 'agreed']);
    }

    private function mapStatus(?string $apiStatus, ?string $latestActionText = null, ?string $currentStatus = null, ?string $officialVoteDate = null): string
    {
        $statusText = strtolower(trim((string) ($apiStatus ?? '')));
        $actionText = strtolower(trim((string) ($latestActionText ?? '')));
        $combined = trim($statusText . ' ' . $actionText);

        if ($this->containsAny($combined, [
            'became public law',
            'signed by president',
            'passed house',
            'passed senate',
            'agreed to',
            'enacted',
            'presented to president',
            'override veto successful',
        ])) {
            return 'passed';
        }

        if ($this->containsAny($combined, [
            'failed',
            'not agreed to',
            'did not pass',
            'vetoed',
            'defeated',
            'rejected',
            'withdrawn',
            'indefinitely postponed',
            'motion to invoke cloture not agreed',
        ])) {
            return 'failed';
        }

        if (!blank($officialVoteDate) && in_array($currentStatus, ['active', 'voting_closed', null], true)) {
            return Carbon::parse($officialVoteDate)->isPast() ? 'voting_closed' : 'active';
        }

        if (in_array($currentStatus, ['active', 'passed', 'failed', 'voting_closed'], true)) {
            return $currentStatus;
        }

        return 'active';
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
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
}
