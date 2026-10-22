<?php

declare(strict_types=1);

namespace App\Filament\Resources\Experiences\Tables;

use App\Filament\Pages\AggregateTimeline;
use App\Filament\Support\SyncState;
use Fellwise\Catalogue\Application\PublishExperience;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Fellwise\Catalogue\Domain\ExperienceStatus;
use Fellwise\Catalogue\Infrastructure\Persistence\ExperienceRecord;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ExperiencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['region', 'guide', 'stats']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (ExperienceRecord $record) => trim(($record->region->name ?? '').' · '.($record->guide->display_name ?? 'Unassigned'), ' ·')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ExperienceStatus $state) => $state->label())
                    ->color(fn (ExperienceStatus $state) => match ($state) {
                        ExperienceStatus::Published => 'success',
                        ExperienceStatus::Unpublished => 'gray',
                        ExperienceStatus::Draft => 'warning',
                    }),
                TextColumn::make('sync')
                    ->label('Core')
                    ->badge()
                    ->state(fn (ExperienceRecord $record) => SyncState::label(SyncState::for($record->id, $record->version)))
                    ->color(fn (ExperienceRecord $record) => SyncState::color(SyncState::for($record->id, $record->version))),
                TextColumn::make('price_minor')
                    ->label('Price')
                    ->money('GBP', divideBy: 100)
                    ->sortable(),
                TextColumn::make('stats.bookings_count')
                    ->label('Bookings')
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('version')
                    ->label('v')
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('legacy_id')
                    ->label('Source')
                    ->badge()
                    ->state(fn (ExperienceRecord $record) => $record->legacy_id !== null ? 'TrailDesk' : 'CMS')
                    ->color(fn (ExperienceRecord $record) => $record->legacy_id !== null ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ExperienceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                SelectFilter::make('region_id')->label('Region')->relationship('region', 'name'),
                SelectFilter::make('source')
                    ->options(['cms' => 'CMS', 'traildesk' => 'TrailDesk'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'cms' => $query->whereNull('legacy_id'),
                        'traildesk' => $query->whereNotNull('legacy_id'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('timeline')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->iconButton()
                    ->tooltip('Event timeline')
                    ->url(fn (ExperienceRecord $record) => AggregateTimeline::getUrl(['aggregate' => $record->id])),
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkAction::make('republish')
                    ->label('Republish selected')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Each experience is republished at a new version: one outbox row and one catalogue.experience.published.v2 event each.')
                    ->action(function (Collection $records, PublishExperienceHandler $publish): void {
                        $count = 0;
                        foreach ($records as $record) {
                            if ($record instanceof ExperienceRecord) {
                                $publish(new PublishExperience($record->id));
                                $count++;
                            }
                        }
                        Notification::make()->success()->title("{$count} experiences republished")->body('Events are in the outbox; the relay publishes them within a second.')->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
