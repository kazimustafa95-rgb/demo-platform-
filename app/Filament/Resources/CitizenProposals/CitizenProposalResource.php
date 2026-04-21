<?php

namespace App\Filament\Resources\CitizenProposals;

use App\Filament\Resources\CitizenProposals\Pages\ManageCitizenProposals;
use App\Models\CitizenProposal;
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
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CitizenProposalResource extends Resource
{
    protected static ?string $model = CitizenProposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static string|\UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->exists(table: \App\Models\User::class, column: 'id'),
                TextInput::make('title')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null),
                TextInput::make('category')
                    ->required()
                    ->minLength(2)
                    ->maxLength(100)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null),
                Select::make('jurisdiction_focus')
                    ->options(CitizenProposal::focusOptions())
                    ->required(),
                TextInput::make('support_count')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(1000000)
                    ->required(),
                Toggle::make('threshold_reached')
                    ->inline(false),
                Toggle::make('is_duplicate')
                    ->inline(false),
                Toggle::make('hidden')
                    ->inline(false),
                Textarea::make('content')
                    ->required()
                    ->minLength(30)
                    ->maxLength(5000)
                    ->rows(8)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('user.name')
                    ->label('Proposer')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('category')
                    ->searchable(),
                TextColumn::make('jurisdiction_focus')
                    ->badge(),
                TextColumn::make('support_count')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('threshold_reached')
                    ->boolean(),
                IconColumn::make('is_duplicate')
                    ->boolean(),
                IconColumn::make('hidden')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn (): array => CitizenProposal::query()
                        ->whereNotNull('category')
                        ->pluck('category', 'category')
                        ->all()),
                SelectFilter::make('jurisdiction_focus')
                    ->options(CitizenProposal::focusOptions()),
                TernaryFilter::make('hidden'),
                TernaryFilter::make('threshold_reached'),
            ])
            ->recordActions([
                Action::make('hide')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (CitizenProposal $record): bool => !$record->hidden)
                    ->action(fn (CitizenProposal $record) => $record->update(['hidden' => true])),
                Action::make('approveAndRestore')
                    ->label('Approve & Restore')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CitizenProposal $record): bool => (bool) $record->hidden)
                    ->action(fn (CitizenProposal $record) => $record->update(['hidden' => false])),
                Action::make('suspendProposer')
                    ->label('Suspend Proposer')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->rows(4)
                            ->columnSpanFull(),
                        DateTimePicker::make('suspension_ends_at')
                            ->helperText('Leave blank for a permanent suspension.'),
                    ])
                    ->visible(fn (CitizenProposal $record): bool => (bool) $record->user && !$record->user->isSuspended())
                    ->action(function (CitizenProposal $record, array $data): void {
                        $record->user?->suspend($data['reason'] ?? null, $data['suspension_ends_at'] ?? null);
                    }),
                Action::make('restoreProposer')
                    ->label('Restore Proposer')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CitizenProposal $record): bool => (bool) $record->user?->isSuspended())
                    ->action(fn (CitizenProposal $record) => $record->user?->clearSuspension()),
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
            'index' => ManageCitizenProposals::route('/'),
        ];
    }
}
