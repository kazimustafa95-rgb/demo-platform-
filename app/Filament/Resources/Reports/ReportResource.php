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
                TextInput::make('reportable_type')
                    ->required(),
                TextInput::make('reportable_id')
                    ->required()
                    ->numeric(),
                Select::make('reason')
                    ->options([
                        'spam' => 'Spam',
                        'offensive' => 'Offensive',
                        'joke' => 'Joke / Non-serious',
                        'duplicate' => 'Duplicate',
                        'other' => 'Other',
                    ])
                    ->required(),
                Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'reviewed' => 'Reviewed',
                        'dismissed' => 'Dismissed',
                        'action_taken' => 'Action Taken',
                    ])
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
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
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
                        'pending' => 'warning',
                        'reviewed' => 'success',
                        'dismissed' => 'gray',
                        'action_taken' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->options([
                        'spam' => 'Spam',
                        'offensive' => 'Offensive',
                        'joke' => 'Joke / Non-serious',
                        'duplicate' => 'Duplicate',
                        'other' => 'Other',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'reviewed' => 'Reviewed',
                        'dismissed' => 'Dismissed',
                        'action_taken' => 'Action Taken',
                    ]),
            ])
            ->recordActions([
                Action::make('markReviewed')
                    ->label('Mark Reviewed')
                    ->color('success')
                    ->visible(fn (Report $record): bool => $record->status !== 'reviewed')
                    ->action(fn (Report $record) => $record->update(['status' => 'reviewed'])),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->color('gray')
                    ->visible(fn (Report $record): bool => $record->status !== 'dismissed')
                    ->action(fn (Report $record) => $record->update(['status' => 'dismissed'])),
                Action::make('hideContent')
                    ->label('Hide Content')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(function (Report $record): bool {
                        $reportable = $record->reportable;

                        return $reportable && $reportable->isFillable('hidden') && !((bool) data_get($reportable, 'hidden'));
                    })
                    ->action(function (Report $record): void {
                        $reportable = $record->reportable;

                        if (!$reportable || !$reportable->isFillable('hidden')) {
                            return;
                        }

                        $reportable->update(['hidden' => true]);
                        $record->update(['status' => 'action_taken']);
                    }),
                Action::make('unhideContent')
                    ->label('Unhide Content')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(function (Report $record): bool {
                        $reportable = $record->reportable;

                        return $reportable && $reportable->isFillable('hidden') && ((bool) data_get($reportable, 'hidden'));
                    })
                    ->action(function (Report $record): void {
                        $reportable = $record->reportable;

                        if (!$reportable || !$reportable->isFillable('hidden')) {
                            return;
                        }

                        $reportable->update(['hidden' => false]);
                        $record->update(['status' => 'reviewed']);
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