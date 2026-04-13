<?php

namespace App\Jobs;

use App\Models\Amendment;
use App\Models\CitizenProposal;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MaintainCommunityEngagement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function handle(): void
    {
        $this->refreshAmendments();
        $this->refreshCitizenProposals();
    }

    private function refreshAmendments(): void
    {
        $threshold = (int) Setting::get('amendment_threshold', 1000);
        $autoHideThreshold = (int) Setting::get('auto_hide_report_count', 10);

        Amendment::query()
            ->userGenerated()
            ->withCount(['supports', 'reports'])
            ->chunkById(200, function ($amendments) use ($threshold, $autoHideThreshold): void {
                foreach ($amendments as $amendment) {
                    $supportCount = (int) $amendment->supports_count;
                    $shouldReachThreshold = $supportCount >= $threshold;
                    $shouldHide = (int) $amendment->reports_count >= $autoHideThreshold;

                    $amendment->update([
                        'support_count' => $supportCount,
                        'threshold_reached' => $shouldReachThreshold,
                        'threshold_reached_at' => $shouldReachThreshold
                            ? ($amendment->threshold_reached_at ?? now())
                            : null,
                        'hidden' => $amendment->hidden || $shouldHide,
                    ]);
                }
            });
    }

    private function refreshCitizenProposals(): void
    {
        $threshold = (int) Setting::get('proposal_threshold', 5000);
        $autoHideThreshold = (int) Setting::get('auto_hide_report_count', 10);

        CitizenProposal::query()
            ->withCount(['supports', 'reports'])
            ->chunkById(200, function ($proposals) use ($threshold, $autoHideThreshold): void {
                foreach ($proposals as $proposal) {
                    $supportCount = (int) $proposal->supports_count;
                    $shouldReachThreshold = $supportCount >= $threshold;
                    $shouldHide = (int) $proposal->reports_count >= $autoHideThreshold;

                    $proposal->update([
                        'support_count' => $supportCount,
                        'threshold_reached' => $shouldReachThreshold,
                        'threshold_reached_at' => $shouldReachThreshold
                            ? ($proposal->threshold_reached_at ?? now())
                            : null,
                        'hidden' => $proposal->hidden || $shouldHide,
                    ]);
                }
            });
    }
}
