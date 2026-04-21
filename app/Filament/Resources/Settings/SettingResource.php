<?php

namespace App\Filament\Resources\Settings;

use App\Filament\Resources\Settings\Pages\ManageSettings;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('key')
                    ->options(Setting::options())
                    ->searchable()
                    ->live()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('value')
                    ->label('Value')
                    ->helperText(fn (Get $get): ?string => Setting::descriptionFor((string) $get('key'))
                        ?? 'Use plain numeric values for thresholds and hours. Boolean-style settings may use 1/0 or true/false.')
                    ->rules(fn (Get $get): array => Setting::validationRulesFor((string) $get('key')))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Setting')
                    ->formatStateUsing(fn (string $state): string => Setting::labelFor($state))
                    ->description(fn (Setting $record): ?string => Setting::descriptionFor($record->key))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('setting_group')
                    ->label('Group')
                    ->state(fn (Setting $record): string => Setting::groupFor($record->key))
                    ->badge(),
                TextColumn::make('value')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('key')
                    ->options(Setting::options()),
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
            'index' => ManageSettings::route('/'),
        ];
    }
}
