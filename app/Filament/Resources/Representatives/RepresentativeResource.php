<?php

namespace App\Filament\Resources\Representatives;

use App\Filament\Resources\Representatives\Pages\ManageRepresentatives;
use App\Models\Representative;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RepresentativeResource extends Resource
{
    protected static ?string $model = Representative::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Legislation';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('external_id')
                    ->required()
                    ->maxLength(255),
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                Select::make('party')
                    ->options([
                        'Democratic' => 'Democratic',
                        'Republican' => 'Republican',
                        'Independent' => 'Independent',
                        'Other' => 'Other',
                    ])
                    ->searchable(),
                Select::make('chamber')
                    ->options([
                        'house' => 'House',
                        'senate' => 'Senate',
                        'assembly' => 'Assembly',
                    ])
                    ->required()
                    ->searchable(),
                TextInput::make('district')
                    ->maxLength(255),
                Select::make('jurisdiction_id')
                    ->relationship('jurisdiction', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('photo_url')
                    ->url()
                    ->maxLength(2048),
                TextInput::make('years_in_office_start')
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue(2100),
                TextInput::make('years_in_office_end')
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue(2100),
                KeyValue::make('contact_info')
                    ->keyLabel('Field')
                    ->valueLabel('Value')
                    ->columnSpanFull(),
                TagsInput::make('committee_assignments')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('party')
                    ->badge(),
                TextColumn::make('chamber')
                    ->badge(),
                TextColumn::make('district')
                    ->searchable(),
                TextColumn::make('jurisdiction.name')
                    ->label('Jurisdiction')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('party')
                    ->options(fn (): array => Representative::query()
                        ->whereNotNull('party')
                        ->pluck('party', 'party')
                        ->all()),
                SelectFilter::make('chamber')
                    ->options(fn (): array => Representative::query()
                        ->whereNotNull('chamber')
                        ->pluck('chamber', 'chamber')
                        ->all()),
                SelectFilter::make('jurisdiction_id')
                    ->relationship('jurisdiction', 'name')
                    ->label('Jurisdiction'),
            ])
            ->recordActions([
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
            'index' => ManageRepresentatives::route('/'),
        ];
    }
}