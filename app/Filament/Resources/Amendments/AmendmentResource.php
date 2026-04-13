<?php

namespace App\Filament\Resources\Amendments;

use App\Filament\Resources\Amendments\Pages\ManageAmendments;
use App\Models\Amendment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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

class AmendmentResource extends Resource
{
    protected static ?string $model = Amendment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('bill_id')
                    ->relationship('bill', 'number')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('category')
                    ->required()
                    ->maxLength(255),
                TextInput::make('support_count')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Toggle::make('threshold_reached')
                    ->inline(false),
                Toggle::make('hidden')
                    ->inline(false),
                Textarea::make('amendment_text')
                    ->required()
                    ->rows(5)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bill.number')
                    ->label('Bill #')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Proposer')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('category')
                    ->searchable(),
                TextColumn::make('support_count')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('threshold_reached')
                    ->boolean(),
                IconColumn::make('hidden')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn (): array => Amendment::query()
                        ->whereNotNull('category')
                        ->pluck('category', 'category')
                        ->all()),
                TernaryFilter::make('hidden'),
                TernaryFilter::make('threshold_reached'),
            ])
            ->recordActions([
                Action::make('hide')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Amendment $record): bool => !$record->hidden)
                    ->action(fn (Amendment $record) => $record->update(['hidden' => true])),
                Action::make('unhide')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Amendment $record): bool => (bool) $record->hidden)
                    ->action(fn (Amendment $record) => $record->update(['hidden' => false])),
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
            'index' => ManageAmendments::route('/'),
        ];
    }
}