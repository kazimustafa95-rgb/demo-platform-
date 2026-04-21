<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ManageReports;
use App\Models\Report;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user_id')
                    ->required()
                    ->numeric(),
                Select::make('reportable_type')
                    ->options(Report::reportableTypeOptions())
                    ->required(),
                TextInput::make('reportable_id')
                    ->required()
                    ->numeric(),
                Select::make('reason')
                    ->options(Report::reasonOptions())
                    ->required(),
                Select::make('status')
                    ->options(Report::statusOptions())
                    ->required(),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Reporter')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reportable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (mixed $state, Report $record): string => $record->reportableTypeLabel())
                    ->badge(),
                TextColumn::make('reportable_id')
                    ->label('Content ID')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reason')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Report::STATUS_PENDING => 'warning',
                        Report::STATUS_REVIEWED => 'success',
                        Report::STATUS_DISMISSED => 'gray',
                        Report::STATUS_ACTION_TAKEN => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->options(Report::reasonOptions()),
                SelectFilter::make('status')
                    ->options(Report::statusOptions()),
            ])
            ->recordActions([
                Action::make('markReviewed')
                    ->label('Mark Reviewed')
                    ->color('success')
                    ->visible(fn (Report $record): bool => $record->status !== Report::STATUS_REVIEWED)
                    ->action(fn (Report $record) => $record->update(['status' => Report::STATUS_REVIEWED])),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->color('gray')
                    ->visible(fn (Report $record): bool => $record->status !== Report::STATUS_DISMISSED)
                    ->action(fn (Report $record) => $record->update(['status' => Report::STATUS_DISMISSED])),
                Action::make('hideContent')
                    ->label('Hide Content')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(function (Report $record): bool {
                        $reportable = $record->resolvedReportable();

                        return $reportable && $reportable->isFillable('hidden') && !((bool) data_get($reportable, 'hidden'));
                    })
                    ->action(function (Report $record): void {
                        $reportable = $record->resolvedReportable();

                        if (!$reportable || !$reportable->isFillable('hidden')) {
                            return;
                        }

                        $reportable->update(['hidden' => true]);
                        $record->update(['status' => Report::STATUS_ACTION_TAKEN]);
                    }),
                Action::make('restoreContent')
                    ->label('Approve & Restore')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(function (Report $record): bool {
                        $reportable = $record->resolvedReportable();

                        return $reportable && $reportable->isFillable('hidden') && ((bool) data_get($reportable, 'hidden'));
                    })
                    ->action(function (Report $record): void {
                        $reportable = $record->resolvedReportable();

                        if (!$reportable || !$reportable->isFillable('hidden')) {
                            return;
                        }

                        $reportable->update(['hidden' => false]);
                        $record->update(['status' => Report::STATUS_REVIEWED]);
                    }),
                Action::make('deleteContent')
                    ->label('Delete Content')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Report $record): bool => $record->resolvedReportable() !== null)
                    ->action(function (Report $record): void {
                        $reportable = $record->resolvedReportable();

                        if (!$reportable) {
                            return;
                        }

                        $reportable->delete();
                        $record->update(['status' => Report::STATUS_ACTION_TAKEN]);
                    }),
                Action::make('suspendAuthor')
                    ->label('Suspend Author')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->rows(4)
                            ->columnSpanFull(),
                        DateTimePicker::make('suspension_ends_at')
                            ->helperText('Leave blank for a permanent suspension.'),
                    ])
                    ->visible(function (Report $record): bool {
                        $author = $record->resolvedReportable()?->user;

                        return (bool) $author && !$author->isSuspended();
                    })
                    ->action(function (Report $record, array $data): void {
                        $author = $record->resolvedReportable()?->user;

                        if (!$author) {
                            return;
                        }

                        $author->suspend($data['reason'] ?? null, $data['suspension_ends_at'] ?? null);
                        $record->update(['status' => Report::STATUS_ACTION_TAKEN]);
                    }),
                Action::make('restoreAuthor')
                    ->label('Restore Author')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Report $record): bool => (bool) $record->resolvedReportable()?->user?->isSuspended())
                    ->action(function (Report $record): void {
                        $author = $record->resolvedReportable()?->user;

                        if (!$author) {
                            return;
                        }

                        $author->clearSuspension();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReports::route('/'),
        ];
    }
}
