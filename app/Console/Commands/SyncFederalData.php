<?php

namespace App\Console\Commands;

use App\Jobs\MaintainCommunityEngagement;
use App\Jobs\SyncFederalAmendments;
use App\Jobs\SyncFederalBills;
use App\Jobs\SyncFederalRepresentatives;
use App\Jobs\SyncStateBills;
use App\Jobs\SyncVotingResults;
use App\Services\CongressGovApi;
use App\Services\OpenStatesApi;
use Illuminate\Console\Command;

class SyncFederalData extends Command
{
    protected $signature = 'demos:sync-federal
                            {--now : Run jobs synchronously instead of dispatching to the queue}
                            {--with-state : Also sync state bills}
                            {--limit= : Max number of top-level federal records to sync per feed for testing}';

    protected $description = 'Sync federal legislative data, and optionally state bills, from upstream APIs';

    public function handle(): int
    {
        $this->info('Starting legislative data sync');

        $maxRecords = $this->normalizeLimit($this->option('limit'));

        if ($this->option('now')) {
            $originalQueue = config('queue.default');
            config(['queue.default' => 'sync']);

            try {
                $this->line('Running SyncFederalBills inline...');
                (new SyncFederalBills($maxRecords))->handle(app(CongressGovApi::class));

                $this->line('Running SyncFederalAmendments inline...');
                (new SyncFederalAmendments($maxRecords))->handle(app(CongressGovApi::class));

                $this->line('Running SyncFederalRepresentatives inline...');
                (new SyncFederalRepresentatives($maxRecords))->handle(app(CongressGovApi::class));

                if ($this->option('with-state')) {
                    $this->line('Running SyncStateBills inline...');
                    app(SyncStateBills::class)->handle(app(OpenStatesApi::class));
                }

                $this->line('Running SyncVotingResults inline...');
                (new SyncVotingResults($maxRecords))->handle(app(CongressGovApi::class));

                $this->line('Running MaintainCommunityEngagement inline...');
                app(MaintainCommunityEngagement::class)->handle();
            } finally {
                config(['queue.default' => $originalQueue]);
            }
        } else {
            SyncFederalBills::dispatch($maxRecords);
            SyncFederalAmendments::dispatch($maxRecords);
            SyncFederalRepresentatives::dispatch($maxRecords);
            SyncVotingResults::dispatch($maxRecords);
            MaintainCommunityEngagement::dispatch();

            if ($this->option('with-state')) {
                SyncStateBills::dispatch();
            }

            $this->line('Jobs dispatched to queue.');
            $this->line('Run `php artisan queue:work` to process them.');
        }

        if ($maxRecords !== null) {
            $this->info("Top-level record limit applied per federal feed: {$maxRecords}");
        }

        $this->info('Legislative sync kicked off.');

        return self::SUCCESS;
    }

    private function normalizeLimit(mixed $limit): ?int
    {
        $limit = is_numeric($limit) ? (int) $limit : null;

        return $limit && $limit > 0 ? $limit : null;
    }
}
