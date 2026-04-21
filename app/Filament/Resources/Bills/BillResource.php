<?php

namespace App\Filament\Resources\Bills;

use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Models\Bill;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BillResource extends Resource
{
    protected static ?string $model = Bill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Legislation';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('external_id')
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null),
                Select::make('jurisdiction_id')
                    ->relationship('jurisdiction', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->exists(table: \App\Models\Jurisdiction::class, column: 'id'),
                TextInput::make('number')
                    ->required()
                    ->minLength(1)
                    ->maxLength(100)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null),
                Textarea::make('title')
                    ->required()
                    ->minLength(5)
                    ->maxLength(5000)
                    ->rows(3)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->columnSpanFull(),
                Textarea::make('summary')
                    ->maxLength(20000)
                    ->rows(6)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(Bill::statusOptions())
                    ->required(),
                DateTimePicker::make('introduced_date')
                    ->seconds(false),
                DateTimePicker::make('official_vote_date')
                    ->seconds(false)
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $introducedDate = $get('introduced_date');

                            if (blank($value) || blank($introducedDate)) {
                                return;
                            }

                            if (strtotime((string) $value) < strtotime((string) $introducedDate)) {
                                $fail('Official vote date must be on or after the introduced date.');
                            }
                        },
                    ]),
                DateTimePicker::make('voting_deadline')
                    ->seconds(false)
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $introducedDate = $get('introduced_date');

                            if (blank($value) || blank($introducedDate)) {
                                return;
                            }

                            if (strtotime((string) $value) < strtotime((string) $introducedDate)) {
                                $fail('Voting deadline must be on or after the introduced date.');
                            }
                        },
                    ]),
                TextInput::make('bill_text_url')
                    ->url()
                    ->maxLength(2048)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Bill #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(70)
                    ->tooltip(fn (Bill $record): string => $record->title),
                TextColumn::make('jurisdiction.name')
                    ->label('Jurisdiction')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'voting_closed' => 'warning',
                        'passed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('official_vote_date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('voting_deadline')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Bill::statusOptions()),
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
            'index' => ManageBills::route('/'),
        ];
    }
}
