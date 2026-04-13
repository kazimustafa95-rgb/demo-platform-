<?php

namespace App\Jobs;

use App\Models\Bill;
use App\Services\OpenStatesApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStateBillDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        $billTextUrl = $details['openstates_url'] ?? null;

        $sponsors = [];
        if (isset($details['sponsorships'])) {
            foreach ($details['sponsorships'] as $sponsor) {
                $sponsors[] = [
                    'name' => $sponsor['name'],
                    'entity_id' => $sponsor['entity_id'] ?? null,
                ];
            }
        }

        $bill->update([
            'summary' => $details['summary'] ?? null,
            'bill_text_url' => $billTextUrl,
            'sponsors' => $sponsors,
            'committees' => [],
            'amendments_history' => null,
        ]);
    }
}
