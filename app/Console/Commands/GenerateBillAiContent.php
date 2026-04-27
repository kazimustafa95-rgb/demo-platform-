<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Services\BillAiContentService;
use Illuminate\Console\Command;

class GenerateBillAiContent extends Command
{
    protected $signature = 'demos:generate-bill-ai
                            {--bill_id= : Generate AI copy only for a single bill id}
                            {--force : Regenerate AI fields even when they already exist}
                            {--limit= : Limit how many bills are processed}';

    protected $description = 'Generate ai_summary_plain and ai_bill_impact for bills using OpenAI';

    public function handle(BillAiContentService $billAiContentService): int
    {
        if (!$billAiContentService->isConfigured()) {
            $this->error('Set OPENAI_API_KEY before running bill AI generation.');

            return self::FAILURE;
        }

        $billId = $this->normalizeBillId($this->option('bill_id'));
        $limit = $this->normalizeLimit($this->option('limit'));
        $force = (bool) $this->option('force');

        if ($this->option('bill_id') !== null && $billId === null) {
            $this->error('The --bill_id option must be a numeric bill id.');

            return self::FAILURE;
        }

        $query = Bill::query()
            ->with('jurisdiction')
            ->orderBy('id');

        if ($billId !== null) {
            $query->whereKey($billId);
        }

        if (!$force) {
            $query->where(function ($billQuery): void {
                $billQuery->whereNull('ai_summary_plain')
                    ->orWhereNull('ai_bill_impact');
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $bills = $query->get();

        if ($bills->isEmpty()) {
            $this->info('No bills need AI content generation.');

            return self::SUCCESS;
        }

        $this->info("Generating AI bill content for {$bills->count()} bill(s).");

        $successCount = 0;
        $failureCount = 0;
        $progressBar = $this->output->createProgressBar($bills->count());
        $progressBar->start();

        foreach ($bills as $bill) {
            try {
                $billAiContentService->generateAndStore($bill, $force);
                $successCount++;
            } catch (\Throwable $exception) {
                $failureCount++;
                $this->newLine();
                $this->error("Bill {$bill->id} failed: {$exception->getMessage()}");
                report($exception);
            } finally {
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);
        $this->info("AI content generated for {$successCount} bill(s).");

        if ($failureCount > 0) {
            $this->warn("{$failureCount} bill(s) failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function normalizeBillId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function normalizeLimit(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
