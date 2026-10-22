<?php

declare(strict_types=1);

namespace App\Filament\Resources\Experiences\Pages;

use App\Filament\Pages\AggregateTimeline;
use App\Filament\Resources\Experiences\ExperienceResource;
use Fellwise\Catalogue\Application\PublishExperience;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Fellwise\Catalogue\Application\ReviseExperience;
use Fellwise\Catalogue\Application\ReviseExperienceHandler;
use Fellwise\Catalogue\Application\UnpublishExperience;
use Fellwise\Catalogue\Application\UnpublishExperienceHandler;
use Fellwise\Catalogue\Domain\ExperienceStatus;
use Fellwise\Catalogue\Infrastructure\Persistence\ExperienceRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property ExperienceRecord $record
 */
final class EditExperience extends EditRecord
{
    protected static string $resource = ExperienceResource::class;

    /** @var list<string> fields that cross the wire and go through the aggregate */
    private const array CONTRACT_FIELDS = ['title', 'slug', 'region_id', 'guide_id', 'price_minor'];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timeline')
                ->label('Event timeline')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn () => AggregateTimeline::getUrl(['aggregate' => $this->record->id])),
            Action::make('unpublish')
                ->color('gray')
                ->icon(Heroicon::OutlinedEyeSlash)
                ->visible(fn () => $this->record->status === ExperienceStatus::Published)
                ->schema([
                    Select::make('reason')->options([
                        'seasonal' => 'Out of season',
                        'guide_unavailable' => 'Guide unavailable',
                        'safety_review' => 'Safety review',
                        'retired' => 'Retired',
                    ])->required(),
                ])
                ->action(function (array $data, UnpublishExperienceHandler $unpublish): void {
                    $unpublish(new UnpublishExperience($this->record->id, (string) $data['reason']));
                    $this->refreshRecord('Unpublished', 'catalogue.experience.unpublished.v1 recorded in the outbox.');
                }),
            Action::make('publish')
                ->label(fn () => $this->record->status === ExperienceStatus::Published ? 'Republish' : 'Publish')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->action(function (PublishExperienceHandler $publish): void {
                    $version = $publish(new PublishExperience($this->record->id));
                    $this->refreshRecord('Published as v'.$version, 'catalogue.experience.published.v2 is in the outbox and on its way to Core.');
                }),
        ];
    }

    /**
     * Presentation-only fields are written directly. Contract fields go
     * through the aggregate, which records an event if the listing is live.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ExperienceRecord $record */
        // The handler's unit of work nests inside this transaction, so a
        // deadlock must retry the whole edit here, not the inner savepoint.
        DB::connection('cms')->transaction(function () use ($record, $data): void {
            $record->fill(array_diff_key($data, array_flip(self::CONTRACT_FIELDS)))->save();

            app(ReviseExperienceHandler::class)(new ReviseExperience(
                $record->id,
                (string) $data['title'],
                (string) $data['slug'],
                (string) $data['region_id'],
                isset($data['guide_id']) ? (string) $data['guide_id'] : null,
                (int) $data['price_minor'],
            ));
        }, attempts: 3);

        return $record->refresh();
    }

    protected function getSavedNotificationTitle(): string
    {
        return $this->record->status === ExperienceStatus::Published ? 'Saved and republished to Core' : 'Draft saved';
    }

    private function refreshRecord(string $title, string $body): void
    {
        $this->record->refresh();
        $this->fillForm();
        Notification::make()->success()->title($title)->body($body)->send();
    }
}
