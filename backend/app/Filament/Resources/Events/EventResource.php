<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use App\Filament\Pages\EventDetail;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\DeliveryAttempt;
use BackedEnum;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryOutcome;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every hop every message has taken (ops.delivery_attempts), newest first.
 */
final class EventResource extends Resource
{
    protected static ?string $model = DeliveryAttempt::class;

    protected static ?string $slug = 'events';

    protected static ?string $modelLabel = 'delivery';

    protected static ?string $navigationLabel = 'Event log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Event sync';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('3s')
            ->recordUrl(fn (DeliveryAttempt $record) => EventDetail::getUrl(['event' => $record->event_id]))
            ->columns([
                TextColumn::make('created_at')->label('At')->dateTime('H:i:s.v')->fontFamily('mono')->size('xs')->color('gray')->sortable(),
                TextColumn::make('event_name')->label('Event')->fontFamily('mono')->searchable()->weight('medium')
                    ->description(fn (DeliveryAttempt $record) => $record->aggregate_type.' · '.$record->aggregate_id),
                TextColumn::make('outcome')->badge()
                    ->formatStateUsing(fn (string $state, DeliveryAttempt $record) => DeliveryOutcome::from($state)->label().($record->tier ? ' · '.$record->tier : ''))
                    ->color(fn (string $state) => DeliveryOutcome::from($state)->color()),
                TextColumn::make('consumer')->fontFamily('mono')->size('xs'),
                TextColumn::make('attempt')->label('#')->alignEnd()->fontFamily('mono'),
                TextColumn::make('duration_ms')->label('Handler')->suffix(' ms')->alignEnd()->placeholder('—'),
                TextColumn::make('error')->limit(60)->tooltip(fn (DeliveryAttempt $record) => $record->error)->color('danger')->placeholder('')->toggleable(),
                TextColumn::make('correlation_id')->label('Correlation')->fontFamily('mono')->size('xs')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('outcome')->multiple()->options(collect(DeliveryOutcome::cases())->mapWithKeys(fn (DeliveryOutcome $o) => [$o->value => $o->label()])),
                SelectFilter::make('consumer')->options(['relay' => 'relay', 'core.catalogue' => 'core.catalogue', 'cms.bookings' => 'cms.bookings', 'outbox' => 'outbox (replay)']),
                Filter::make('failures')->label('Failures only')->query(fn (Builder $query) => $query->whereIn('outcome', ['retry_scheduled', 'parked'])),
                Filter::make('replays')->label('Replays only')->query(fn (Builder $query) => $query->where('is_replay', true)),
            ])
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListEvents::route('/')];
    }
}
