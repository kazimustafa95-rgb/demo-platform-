<?php

namespace App\Filament\Resources\ManagedContents;

use App\Filament\Resources\ManagedContents\Pages\ManageManagedContents;
use App\Models\ManagedContent;
use BackedEnum;
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
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManagedContentResource extends Resource
{
    protected static ?string $model = ManagedContent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Content';

    protected static ?string $pluralModelLabel = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(ManagedContent::typeOptions())
                    ->required(),
                Select::make('audience')
                    ->options(ManagedContent::audienceOptions())
                    ->required()
                    ->default(ManagedContent::AUDIENCE_GLOBAL),
                TextInput::make('slug')
                    ->helperText('Optional page slug for direct mobile access, for example privacy-policy.')
                    ->maxLength(160)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? Str::slug((string) $state) : null)
                    ->rule(fn (?ManagedContent $record) => Rule::unique('managed_contents', 'slug')->ignore($record?->id))
                    ->columnSpanFull(),
                TextInput::make('title')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->columnSpanFull(),
                Textarea::make('summary')
                    ->maxLength(1000)
                    ->rows(3)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->required()
                    ->minLength(20)
                    ->maxLength(20000)
                    ->rows(10)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->columnSpanFull(),
                TextInput::make('display_order')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(9999)
                    ->default(0),
                DateTimePicker::make('published_at'),
                Toggle::make('is_published')
                    ->inline(false)
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ManagedContent::typeOptions()[$state] ?? ucfirst($state)),
                TextColumn::make('audience')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ManagedContent::audienceOptions()[$state] ?? ucfirst(str_replace('_', ' ', $state))),
                IconColumn::make('is_published')
                    ->boolean(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('display_order')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(ManagedContent::typeOptions()),
                SelectFilter::make('audience')
                    ->options(ManagedContent::audienceOptions()),
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
            'index' => ManageManagedContents::route('/'),
        ];
    }
}
