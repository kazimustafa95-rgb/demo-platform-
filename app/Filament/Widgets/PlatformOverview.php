<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Amendments\AmendmentResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\CitizenProposals\CitizenProposalResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Representatives\RepresentativeResource;
use App\Models\Amendment;
use App\Models\Bill;
use App\Models\CitizenProposal;
use App\Models\Report;
use App\Models\Representative;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Platform Totals';

    protected ?string $description = 'Quick access to the main admin modules.';

    protected function getStats(): array
    {
        $billCount = Bill::count();
        $activeBills = Bill::query()->where('status', 'active')->count();

        $amendmentCount = Amendment::count();
        $thresholdAmendments = Amendment::query()->where('threshold_reached', true)->count();

        $proposalCount = CitizenProposal::count();
        $thresholdProposals = CitizenProposal::query()->where('threshold_reached', true)->count();

        $reportCount = Report::count();
        $pendingReports = Report::query()->where('status', 'pending')->count();

        $representativeCount = Representative::count();
        $chambersCovered = Representative::query()
            ->whereNotNull('chamber')
            ->distinct()
            ->count('chamber');

        return [
            Stat::make('Bills', number_format($billCount))
                ->description(number_format($activeBills) . ' active')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('primary')
                ->url(BillResource::getUrl()),
            Stat::make('Amendments', number_format($amendmentCount))
                ->description(number_format($thresholdAmendments) . ' reached threshold')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->url(AmendmentResource::getUrl()),
            Stat::make('Citizen Proposals', number_format($proposalCount))
                ->description(number_format($thresholdProposals) . ' reached threshold')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('success')
                ->url(CitizenProposalResource::getUrl()),
            Stat::make('Reports', number_format($reportCount))
                ->description(number_format($pendingReports) . ' pending review')
                ->icon(Heroicon::OutlinedFlag)
                ->color('danger')
                ->url(ReportResource::getUrl()),
            Stat::make('Representatives', number_format($representativeCount))
                ->description(number_format($chambersCovered) . ' chambers covered')
                ->icon(Heroicon::OutlinedUsers)
                ->color('gray')
                ->url(RepresentativeResource::getUrl()),
        ];
    }
}
