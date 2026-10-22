<?php

declare(strict_types=1);

namespace App\Filament\Resources\Experiences\Schemas;

use App\Filament\Support\SyncState;
use Fellwise\Catalogue\Infrastructure\Persistence\ExperienceRecord;
use Fellwise\Catalogue\Infrastructure\Persistence\GuideRecord;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class ExperienceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Listing')
                    ->description('Title, slug, region, guide and price are part of the integration contract and are published to Core.')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state, ?ExperienceRecord $record) => $record === null ? $set('slug', Str::slug((string) $state)) : null)
                            ->columnSpanFull(),
                        TextInput::make('slug')
                            ->required()
                            ->prefix('fellwise.co.uk/e/')
                            ->unique(ignoreRecord: true)
                            ->columnSpanFull(),
                        Select::make('region_id')
                            ->label('Region')
                            ->relationship('region', 'name')
                            ->required(),
                        Select::make('guide_id')
                            ->label('Lead guide')
                            ->options(fn () => GuideRecord::query()->orderBy('display_name')->pluck('display_name', 'id'))
                            ->searchable(),
                        TextInput::make('price_minor')
                            ->label('Price per person')
                            ->prefix('£')
                            ->numeric()
                            ->minValue(1)
                            ->step('0.01')
                            ->required()
                            ->formatStateUsing(fn (?int $state) => $state !== null ? number_format($state / 100, 2, '.', '') : null)
                            ->dehydrateStateUsing(fn (string|int|float|null $state) => (int) round(((float) $state) * 100)),
                        Select::make('activity')
                            ->options(array_combine(self::ACTIVITIES, array_map('ucfirst', self::ACTIVITIES)))
                            ->required(),
                        Select::make('difficulty')
                            ->options(['easy' => 'Easy', 'moderate' => 'Moderate', 'challenging' => 'Challenging'])
                            ->required(),
                        TextInput::make('duration_hours')
                            ->label('Duration')
                            ->suffix('hours')
                            ->numeric()
                            ->step('0.5')
                            ->required(),
                        TextInput::make('max_group_size')
                            ->label('Max group size')
                            ->numeric()
                            ->default(8)
                            ->required(),
                        Textarea::make('summary')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Sync')
                    ->columnSpan(1)
                    ->visible(fn (?ExperienceRecord $record) => $record !== null)
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (ExperienceRecord $record) => $record->status->label())
                            ->color(fn (ExperienceRecord $record) => match ($record->status->value) {
                                'published' => 'success',
                                'unpublished' => 'gray',
                                default => 'warning',
                            }),
                        TextEntry::make('sync_state')
                            ->label('Core')
                            ->badge()
                            ->state(fn (ExperienceRecord $record) => SyncState::label(SyncState::for($record->id, $record->version)))
                            ->color(fn (ExperienceRecord $record) => SyncState::color(SyncState::for($record->id, $record->version))),
                        TextEntry::make('version')
                            ->label('Aggregate version')
                            ->fontFamily('mono'),
                        TextEntry::make('sync_hash')
                            ->label('Fingerprint')
                            ->fontFamily('mono')
                            ->formatStateUsing(fn (string $state) => substr($state, 0, 12)),
                        TextEntry::make('legacy_id')
                            ->label('TrailDesk id')
                            ->fontFamily('mono')
                            ->visible(fn (ExperienceRecord $record) => $record->legacy_id !== null),
                        TextEntry::make('id')
                            ->label('Aggregate id')
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
            ]);
    }

    /** @var list<string> */
    private const array ACTIVITIES = [
        'fell walk', 'guided walk', 'scramble', 'ghyll scramble', 'gorge scramble', 'rock climbing', 'winter skills',
        'skills course', 'trek', 'wild swimming', 'sea kayaking', 'coasteering', 'packrafting', 'paddleboarding',
        'surfing', 'caving',
    ];
}
