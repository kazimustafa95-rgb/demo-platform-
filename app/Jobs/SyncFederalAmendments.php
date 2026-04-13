<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Services\CongressGovApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SyncFederalAmendments implements ShouldQueue
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
        $congress = $api->currentCongress();
        $fromDateTime = $this->resolveFromDateTime();
        $toDateTime = Carbon::now('UTC')->toIso8601String();

        $offset = 0;
        $pageLimit = 250;
        $processed = 0;

        do {
            $requestLimit = $this->remainingLimit($processed, $pageLimit);
            
            $response = $api->getAmendments($congress, $offset, $requestLimit, $fromDateTime, $toDateTime);
            if (!$response || !isset($response['amendments'])) {
                break;
            }

            foreach ($response['amendments'] as $amendment) {
                $type = trim((string) ($amendment['type'] ?? ''));
                $number = trim((string) ($amendment['number'] ?? ''));

                if ($type === '' || $number === '') {
                    continue;
                }

                $processed++;
                SyncFederalAmendmentDetails::dispatch($congress, $type, $number);

                if ($this->limitReached($processed)) {
                    Setting::updateOrCreate(
                        ['key' => 'federal_amendments_last_synced_at'],
                        ['value' => $toDateTime]
                    );

                    return;
                }
            }

            $offset += $requestLimit;
            $pagination = $response['pagination'] ?? [];
        } while ($offset < ($pagination['count'] ?? 0));

        Setting::updateOrCreate(
            ['key' => 'federal_amendments_last_synced_at'],
            ['value' => $toDateTime]
        );
    }

    private function resolveFromDateTime(): string
    {
        $stored = Setting::get('federal_amendments_last_synced_at');

        if (!blank($stored)) {
            try {
                return Carbon::parse($stored)->utc()->toIso8601String();
            } catch (\Throwable) {
                // Fall through to default lookback.
            }
        }

        return Carbon::now('UTC')->subDays(30)->startOfDay()->toIso8601String();
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
