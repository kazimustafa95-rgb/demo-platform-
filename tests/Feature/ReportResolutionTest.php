<?php

namespace Tests\Feature;

use App\Models\CitizenProposal;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_legacy_reportable_types_resolve_safely(): void
    {
        $reporter = User::factory()->create();

        DB::table('reports')->insert([
            'user_id' => $reporter->id,
            'reportable_type' => '2',
            'reportable_id' => 9999,
            'reason' => Report::REASON_SPAM,
            'status' => Report::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = Report::query()->firstOrFail();

        $this->assertNull($report->resolvedReportable());
        $this->assertSame('Unknown', $report->reportableTypeLabel());

        $report->update(['status' => Report::STATUS_REVIEWED]);

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'reportable_type' => '2',
            'status' => Report::STATUS_REVIEWED,
        ]);
    }

    public function test_legacy_proposal_aliases_still_resolve_to_citizen_proposals(): void
    {
        $author = User::factory()->create();
        $reporter = User::factory()->create();

        $proposal = CitizenProposal::create([
            'user_id' => $author->id,
            'title' => 'Broadband for every neighborhood',
            'content' => 'This proposal would expand broadband infrastructure and affordability across underserved communities.',
            'category' => 'Infrastructure',
            'jurisdiction_focus' => 'federal',
            'support_count' => 0,
            'threshold_reached' => false,
            'hidden' => false,
        ]);

        DB::table('reports')->insert([
            'user_id' => $reporter->id,
            'reportable_type' => 'proposal',
            'reportable_id' => $proposal->id,
            'reason' => Report::REASON_OTHER,
            'description' => 'Legacy alias row',
            'status' => Report::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = Report::query()->firstWhere('reportable_id', $proposal->id);

        $this->assertNotNull($report);
        $this->assertInstanceOf(CitizenProposal::class, $report->resolvedReportable());
        $this->assertSame('Citizen Proposal', $report->reportableTypeLabel());
    }
}
