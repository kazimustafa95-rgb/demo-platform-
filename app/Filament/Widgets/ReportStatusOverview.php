<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReportStatusOverview extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Report Status';

    protected ?string $description = 'Moderation progress across all user reports.';

    protected function getStats(): array
    {
        return [
            Stat::make('Pending', number_format(Report::query()->where('status', 'pending')->count()))
                ->description('Needs moderator review')
                ->icon(Heroicon::OutlinedClock)
                ->color('warning')
                ->url(ReportResource::getUrl()),
            Stat::make('Reviewed', number_format(Report::query()->where('status', 'reviewed')->count()))
                ->description('Resolved without dismissal')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url(ReportResource::getUrl()),
            Stat::make('Dismissed', number_format(Report::query()->where('status', 'dismissed')->count()))
                ->description('Closed by moderators')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('gray')
                ->url(ReportResource::getUrl()),
            Stat::make('Action Taken', number_format(Report::query()->where('status', 'action_taken')->count()))
                ->description('Content or author action')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('danger')
                ->url(ReportResource::getUrl()),
        ];
    }
}
