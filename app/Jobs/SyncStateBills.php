<?php

namespace App\Jobs;

use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\Setting;
use App\Services\OpenStatesApi;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStateBills implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public function __construct(
        protected ?int $maxRecords = null,
    ) {
        $this->maxRecords = $this->normalizeLimit($this->maxRecords);
    }

    public function handle(OpenStatesApi $api): void
    {
        $states = Jurisdiction::where('type', 'state')->get();
        $perPage = max(1, (int) config('services.open_states.max_per_page', 20));
        $votingDeadlineHours = (int) Setting::get('voting_deadline_hours', 48);
        $processed = 0;

        foreach ($states as $state) {
            $page = 1;

            do {
                $response = $api->getBills($state->code, null, $page, $perPage);

                if (!$response || !isset($response['results'])) {
                    break;
                }

                foreach ($response['results'] as $billData) {
                    $externalId = $billData['id'] ?? null;
                    if (blank($externalId)) {
                        continue;
                    }

                    $bill = Bill::firstOrNew(['external_id' => $externalId]);
                    $bill->jurisdiction_id = $state->id;
                    $bill->number = (string) ($billData['identifier'] ?? $bill->number ?? $externalId);
                    $bill->title = (string) ($billData['title'] ?? $bill->title ?? '');

                    $introducedDate = $billData['first_action_date'] ?? $billData['created_at'] ?? null;
                    if (!blank($introducedDate)) {
                        $bill->introduced_date = $introducedDate;
                    }

                    $officialVoteDate = $billData['latest_passage_date'] ?? null;
                    if (!blank($officialVoteDate)) {
                        $bill->official_vote_date = $officialVoteDate;
                        $bill->voting_deadline = Carbon::parse($officialVoteDate)->subHours($votingDeadlineHours);
                    }

                    $bill->status = $this->mapStatus(
                        $billData['latest_action_description'] ?? null,
                        $billData['latest_passage_date'] ?? null,
                        $bill->status
                    );

                    $bill->save();

                    $needsDetails = blank($bill->summary)
                        || blank($bill->bill_text_url)
                        || blank($bill->introduced_date)
                        || $bill->sponsors === null
                        || $bill->committees === null
                        || $bill->related_documents === null
                        || $bill->amendments_history === null;

                    if ($needsDetails) {
                        SyncStateBillDetails::dispatch((string) $externalId);
                    }

                    $processed++;

                    if ($this->limitReached($processed)) {
                        return;
                    }
                }

                $hasMorePages = $this->hasMorePages($response, $page);
                $page++;
            } while ($hasMorePages);
        }
    }

    private function mapStatus(?string $latestActionDescription, ?string $latestPassageDate, ?string $currentStatus = null): string
    {
        if (!blank($latestPassageDate)) {
            return 'passed';
        }

        $text = strtolower(trim((string) ($latestActionDescription ?? '')));

        if ($this->containsAny($text, [
            'failed',
            'defeated',
            'rejected',
            'vetoed',
            'not adopted',
            'not passed',
            'indefinitely postponed',
        ])) {
            return 'failed';
        }

        if (in_array($currentStatus, ['active', 'passed', 'failed'], true)) {
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

    private function hasMorePages(array $response, int $currentPage): bool
    {
        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];

        if (array_key_exists('next_page', $pagination)) {
            return !blank($pagination['next_page']);
        }

        $maxPage = (int) ($pagination['max_page'] ?? 0);
        if ($maxPage > 0) {
            return $currentPage < $maxPage;
        }

        $perPage = (int) ($pagination['per_page'] ?? 0);
        $totalItems = (int) ($pagination['total_items'] ?? 0);

        return $perPage > 0 && $totalItems > ($currentPage * $perPage);
    }

    private function normalizeLimit(?int $limit): ?int
    {
        return $limit && $limit > 0 ? $limit : null;
    }

    private function limitReached(int $processed): bool
    {
        return $this->maxRecords !== null && $processed >= $this->maxRecords;
    }
}
