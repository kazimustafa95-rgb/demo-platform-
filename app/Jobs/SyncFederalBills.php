<?php

namespace App\Jobs;

use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Services\CongressGovApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncFederalBills implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public function __construct(
        protected ?int $maxRecords = null,
    ) {
        $this->maxRecords = $this->normalizeLimit($this->maxRecords);
    }

    public function handle(CongressGovApi $api): void
    {
        $jurisdiction = Jurisdiction::where('type', 'federal')->first();

        if (!$jurisdiction) {
            Log::error('Federal jurisdiction not found.');
            return;
        }

        $offset = 0;
        $pageLimit = 250;
        $processed = 0;
        $fallbackCongress = (int) floor(((int) now()->format('Y') - 1789) / 2) + 1;

        do {
            $requestLimit = $this->remainingLimit($processed, $pageLimit);
        
            $response = $api->getBills($fallbackCongress, $offset, $requestLimit);
            if (!$response || !isset($response['bills'])) {
                break;
            }

            foreach ($response['bills'] as $billData) {
                $billCongress = (int) ($billData['congress'] ?? $fallbackCongress);
                $billType = $billData['type'] ?? null;
                $billNumber = $billData['number'] ?? null;

                if (!$billType || !$billNumber) {
                    continue;
                }

                $externalId = strtoupper((string) $billType) . '-' . $billNumber . '-' . $billCongress;
                
                $bill = Bill::firstOrNew(['external_id' => $externalId]);
                $bill->jurisdiction_id = $jurisdiction->id;
                $bill->number = (string) $billNumber;
                $bill->title = (string) ($billData['title'] ?? $bill->title ?? '');

                $introducedDate = data_get($billData, 'introducedDate');
                if (!blank($introducedDate)) {
                    $bill->introduced_date = $introducedDate;
                }

                $bill->status = $this->mapStatus(
                    data_get($billData, 'status'),
                    data_get($billData, 'latestAction.text'),
                    $bill->status
                );

                $bill->save();
                $processed++;

                $needsDetails = blank($bill->summary)
                    || blank($bill->bill_text_url)
                    || blank($bill->introduced_date)
                    || $bill->sponsors === null
                    || $bill->committees === null
                    || $bill->related_documents === null
                    || $bill->amendments_history === null;

                if ($needsDetails) {
                    SyncFederalBillDetails::dispatch($billCongress, (string) $billType, (string) $billNumber, $bill->id);
                }

                if ($this->limitReached($processed)) {
                    return;
                }
            }

            $offset += $requestLimit;
            $pagination = $response['pagination'] ?? [];
        } while ($offset < ($pagination['count'] ?? 0));
    }

    private function mapStatus(?string $apiStatus, ?string $latestActionText = null, ?string $currentStatus = null): string
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
            'enrolled bill',
            'presented to president',
            'motion to concur agreed',
            'override veto successful',
            'enacted',
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
            'tabled',
            'withdrawn',
            'indefinitely postponed',
            'motion to invoke cloture not agreed',
        ])) {
            return 'failed';
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

    private function normalizeLimit(?int $limit): ?int
    {
        return $limit && $limit > 0 ? $limit : null;
    }

    private function remainingLimit(int $processed, int $pageLimit): int
    {
        if ($this->maxRecords === null) {
            return $pageLimit;
        }

        return max(1, min($pageLimit, $this->maxRecords - $processed));
    }

    private function limitReached(int $processed): bool
    {
        return $this->maxRecords !== null && $processed >= $this->maxRecords;
    }
}
