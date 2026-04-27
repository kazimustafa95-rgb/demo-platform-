<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Jurisdiction;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillAiContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_details_exposes_stored_ai_fields(): void
    {
        $bill = Bill::create([
            'external_id' => 'HR-500-119',
            'jurisdiction_id' => $this->federalJurisdiction()->id,
            'number' => 'HR 500',
            'title' => 'Community Energy Reliability Act',
            'summary' => 'Improves community grid resilience and outage reporting.',
            'ai_summary_plain' => 'This bill updates energy reliability rules and requires clearer outage reporting.',
            'ai_bill_impact' => 'Residents and utilities could see more resilience planning, more reporting requirements, and better visibility into outage response.',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        $response = $this->getJson("/api/bills/{$bill->id}");

        $response->assertOk()
            ->assertJsonPath('bill.ai_summary_plain', 'This bill updates energy reliability rules and requires clearer outage reporting.')
            ->assertJsonPath('bill.ai_bill_impact', 'Residents and utilities could see more resilience planning, more reporting requirements, and better visibility into outage response.');
    }

    public function test_bill_details_generates_missing_ai_fields_and_limits_them_to_500_characters(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.bill_model' => 'gpt-5.4-mini',
            'services.openai.timeout_seconds' => 30,
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-501-119',
            'jurisdiction_id' => $this->federalJurisdiction()->id,
            'number' => 'HR 501',
            'title' => 'Neighborhood Clean Energy Investment Act',
            'summary' => "Creates local clean energy grants.\nAdds reporting requirements for utilities.\nPrioritizes grid modernization projects.",
            'status' => Bill::STATUS_ACTIVE,
        ]);

        $longSummary = str_repeat("This bill funds clean energy upgrades and requires public progress updates. \n", 12);
        $longImpact = str_repeat("It could expand local energy projects, change reporting work for agencies, and affect utility planning. \n", 10);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode([
                                    'ai_summary_plain' => $longSummary,
                                    'ai_bill_impact' => $longImpact,
                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson("/api/bills/{$bill->id}");

        $response->assertOk()
            ->assertJsonPath('bill.id', $bill->id);

        $summary = (string) $response->json('bill.ai_summary_plain');
        $impact = (string) $response->json('bill.ai_bill_impact');

        $this->assertNotSame('', $summary);
        $this->assertNotSame('', $impact);
        $this->assertLessThanOrEqual(500, mb_strlen($summary));
        $this->assertLessThanOrEqual(500, mb_strlen($impact));
        $this->assertStringNotContainsString("\n", $summary);
        $this->assertStringNotContainsString("\n", $impact);

        $bill->refresh();

        $this->assertNotNull($bill->getRawOriginal('ai_summary_plain'));
        $this->assertNotNull($bill->getRawOriginal('ai_bill_impact'));

        Http::assertSent(function ($request) use ($bill) {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.com/v1/responses'
                && ($payload['model'] ?? null) === 'gpt-5.4-mini'
                && str_contains(json_encode($payload['input'] ?? []), $bill->title);
        });
    }

    public function test_generate_bill_ai_command_processes_missing_bills_only_by_default(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.bill_model' => 'gpt-5.4-mini',
            'services.openai.timeout_seconds' => 30,
        ]);

        $missingBill = Bill::create([
            'external_id' => 'HR-600-119',
            'jurisdiction_id' => $this->federalJurisdiction()->id,
            'number' => 'HR 600',
            'title' => 'Water System Transparency Act',
            'summary' => 'Requires public dashboards for water system upgrades.',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        $existingBill = Bill::create([
            'external_id' => 'HR-601-119',
            'jurisdiction_id' => $this->federalJurisdiction()->id,
            'number' => 'HR 601',
            'title' => 'Existing AI Bill',
            'summary' => 'Already has AI content.',
            'ai_summary_plain' => 'Existing plain summary.',
            'ai_bill_impact' => 'Existing impact summary.',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode([
                                    'ai_summary_plain' => 'This bill creates public reporting for water infrastructure work.',
                                    'ai_bill_impact' => 'Residents could get clearer progress updates while agencies take on new disclosure duties.',
                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('demos:generate-bill-ai')
            ->assertExitCode(Command::SUCCESS);

        $missingBill->refresh();
        $existingBill->refresh();

        $this->assertSame(
            'This bill creates public reporting for water infrastructure work.',
            $missingBill->ai_summary_plain
        );
        $this->assertSame(
            'Existing plain summary.',
            $existingBill->ai_summary_plain
        );

        Http::assertSentCount(1);
    }

    private function federalJurisdiction(): Jurisdiction
    {
        return Jurisdiction::firstOrCreate(
            ['type' => 'federal', 'code' => 'US'],
            ['name' => 'Federal']
        );
    }
}
