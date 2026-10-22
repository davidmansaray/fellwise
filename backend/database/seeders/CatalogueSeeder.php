<?php

declare(strict_types=1);

namespace Database\Seeders;

use Fellwise\Catalogue\Application\PublishExperience;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catalogue as it stood after the 2025 launch: every live listing was first
 * published under the v1 contract (float price). Today's publish goes out
 * through the real outbox as v2, so Core is built entirely from events.
 */
final class CatalogueSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> id => [name, TrailDesk code] */
    public const array REGIONS = [
        'lake-district' => ['Lake District', 'LAKES'],
        'snowdonia' => ['Eryri (Snowdonia)', 'SNOW'],
        'peak-district' => ['Peak District', 'PEAK'],
        'cairngorms' => ['Cairngorms', 'CAIRN'],
        'pembrokeshire' => ['Pembrokeshire Coast', 'PEMB'],
        'yorkshire-dales' => ['Yorkshire Dales', 'DALES'],
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: float, 5: int}> title, region, activity, difficulty, hours, price (pence) */
    private const array EXPERIENCES = [
        ['Helvellyn via Striding Edge', 'lake-district', 'scramble', 'challenging', 7, 9500],
        ['Dawn wild swim on Buttermere', 'lake-district', 'wild swimming', 'easy', 2.5, 4500],
        ['Scafell Pike from Wasdale Head', 'lake-district', 'fell walk', 'challenging', 6.5, 8500],
        ['Ghyll scrambling in Stoneycroft', 'lake-district', 'ghyll scramble', 'moderate', 3, 6500],
        ['Catbells sunset ridge walk', 'lake-district', 'fell walk', 'easy', 3, 4800],
        ['Navigation skills on the Langdale Pikes', 'lake-district', 'skills course', 'moderate', 7, 12000],
        ['Sharp Edge & Blencathra traverse', 'lake-district', 'scramble', 'challenging', 6, 9800],
        ['Tryfan North Ridge scramble', 'snowdonia', 'scramble', 'challenging', 6, 11000],
        ['Gorge scrambling in Snowdonia', 'snowdonia', 'gorge scramble', 'moderate', 4, 7500],
        ['Yr Wyddfa by the Watkin Path', 'snowdonia', 'fell walk', 'moderate', 7, 8000],
        ['Crib Goch with a mountain leader', 'snowdonia', 'scramble', 'challenging', 7.5, 14500],
        ['Llyn Padarn open-water swim', 'snowdonia', 'wild swimming', 'easy', 2, 4500],
        ['Cadair Idris sunrise summit', 'snowdonia', 'fell walk', 'moderate', 6, 9000],
        ['Kinder Scout plateau & Downfall', 'peak-district', 'fell walk', 'moderate', 5, 6000],
        ['Stanage Edge intro to climbing', 'peak-district', 'rock climbing', 'easy', 4, 9500],
        ['Dovedale stepping stones & Thorpe Cloud', 'peak-district', 'guided walk', 'easy', 3, 4500],
        ['Mam Tor to Lose Hill ridge', 'peak-district', 'fell walk', 'easy', 4, 5000],
        ['Caving in Peak Cavern', 'peak-district', 'caving', 'moderate', 3.5, 8500],
        ['Coire an t-Sneachda winter skills', 'cairngorms', 'winter skills', 'challenging', 7, 24000],
        ['Cairn Gorm & the northern corries', 'cairngorms', 'fell walk', 'moderate', 7, 11500],
        ['Packraft the River Spey', 'cairngorms', 'packrafting', 'moderate', 5, 13500],
        ['Loch Morlich paddleboard morning', 'cairngorms', 'paddleboarding', 'easy', 2.5, 5500],
        ['Lairig Ghru through-hike', 'cairngorms', 'trek', 'challenging', 9, 16500],
        ['Ryvoan Pass wildlife walk', 'cairngorms', 'guided walk', 'easy', 3, 4800],
        ['Sea kayaking the Pembrokeshire coast', 'pembrokeshire', 'sea kayaking', 'moderate', 4, 8500],
        ['Coasteering at Abereiddy Blue Lagoon', 'pembrokeshire', 'coasteering', 'moderate', 3, 6500],
        ['St Davids Head coast path', 'pembrokeshire', 'guided walk', 'easy', 4, 5200],
        ['Skomer puffin paddle', 'pembrokeshire', 'sea kayaking', 'moderate', 5, 11000],
        ['Surf foundations at Whitesands', 'pembrokeshire', 'surfing', 'easy', 2.5, 5000],
        ['Malham Cove limestone & Gordale Scar', 'yorkshire-dales', 'guided walk', 'moderate', 5, 5800],
        ['Yorkshire Three Peaks challenge', 'yorkshire-dales', 'trek', 'challenging', 11, 12500],
        ['Ingleton waterfalls trail', 'yorkshire-dales', 'guided walk', 'easy', 3.5, 4500],
        ['Caving in Long Churn', 'yorkshire-dales', 'caving', 'moderate', 3, 7500],
        ['Ribblehead viaduct & Whernside', 'yorkshire-dales', 'fell walk', 'moderate', 6, 7000],
    ];

    /** Drafts not yet live, for publishing from the panel. */
    private const array DRAFTS = [
        ['Haystacks & Innominate Tarn', 'lake-district', 'fell walk', 'moderate', 5, 6800],
        ['Glyderau traverse via Bristly Ridge', 'snowdonia', 'scramble', 'challenging', 7, 12500],
        ['Ben Macdui by starlight', 'cairngorms', 'fell walk', 'challenging', 9, 17500],
        ['Stack Rocks coasteer & cliff camp', 'pembrokeshire', 'coasteering', 'moderate', 6, 14000],
        ['Janet\'s Foss dawn dip', 'yorkshire-dales', 'wild swimming', 'easy', 2, 4000],
    ];

    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    private const array GUIDES = [
        ['Rhiannon Pritchard', 'snowdonia', ['Mountain Leader', 'RCI']],
        ['Callum MacLeod', 'cairngorms', ['Winter Mountain Leader', 'MIC']],
        ['Hannah Thwaite', 'lake-district', ['Mountain Leader', 'Open Water Coach']],
        ['Dev Mistry', 'peak-district', ['Rock Climbing Instructor']],
        ['Owain Jenkins', 'pembrokeshire', ['BCU Sea Kayak Leader', 'NCC Coasteering']],
        ['Isla Fraser', 'cairngorms', ['Mountain Leader', 'Paddlesport Instructor']],
        ['Tom Ashworth', 'yorkshire-dales', ['Cave Leader', 'Hill & Moorland Leader']],
        ['Grace Holloway', 'lake-district', ['Mountain Leader']],
        ['Niamh O\'Connell', 'pembrokeshire', ['ISA Surf Coach']],
        ['Ewan Graham', 'yorkshire-dales', ['Mountain Leader']],
    ];

    public function run(PublishExperienceHandler $publish): void
    {
        $cms = DB::connection('cms');
        $launch = Carbon::parse('2025-04-01 09:00:00');

        foreach (self::REGIONS as $id => [$name, $code]) {
            $cms->table('regions')->insert(['id' => $id, 'name' => $name, 'legacy_code' => $code]);
        }

        $guidesByRegion = [];
        foreach (self::GUIDES as [$name, $region, $certs]) {
            $id = (string) Str::ulid();
            $cms->table('guides')->insert([
                'id' => $id, 'display_name' => $name, 'home_region_id' => $region,
                'bio' => "Qualified {$certs[0]} guiding in ".self::REGIONS[$region][0].' since '.random_int(2009, 2019).'.',
                'certifications' => json_encode($certs), 'created_at' => $launch, 'updated_at' => $launch,
            ]);
            $guidesByRegion[$region][] = $id;
        }

        $live = [];
        foreach (self::EXPERIENCES as $i => [$title, $region, $activity, $difficulty, $hours, $price]) {
            $id = (string) Str::ulid($launch->copy()->addMinutes($i));
            $publishedAt = $launch->copy()->addDays($i * 3);
            $guide = $guidesByRegion[$region][$i % count($guidesByRegion[$region] ?? [null])] ?? null;

            $cms->table('experiences')->insert([
                'id' => $id, 'title' => $title, 'slug' => Str::slug($title), 'region_id' => $region, 'guide_id' => $guide,
                'summary' => $this->summary($title, $region, $hours), 'activity' => $activity, 'difficulty' => $difficulty,
                'duration_hours' => $hours, 'max_group_size' => 8, 'price_minor' => $price, 'currency' => 'GBP',
                'status' => 'published', 'version' => 1, 'published_at' => $publishedAt,
                'created_at' => $publishedAt, 'updated_at' => $publishedAt,
            ]);

            // The 2025 launch event, under the original v1 contract.
            $cms->table('outbox_messages')->insert([
                'id' => (string) Str::ulid($publishedAt), 'event_name' => 'catalogue.experience.published', 'event_version' => 1,
                'aggregate_type' => 'experience', 'aggregate_id' => $id, 'aggregate_version' => 1,
                'correlation_id' => (string) Str::ulid($publishedAt), 'causation_id' => null, 'producer' => 'fellwise-cms',
                'payload' => json_encode([
                    'experience_id' => $id, 'slug' => Str::slug($title), 'title' => $title, 'region_id' => $region,
                    'price' => $price / 100, 'published_at' => $publishedAt->toAtomString(),
                ]),
                'occurred_at' => $publishedAt->format('Y-m-d H:i:s.u'), 'published_at' => $publishedAt->format('Y-m-d H:i:s.u'),
                'publish_attempts' => 1,
            ]);
            $live[] = $id;
        }

        foreach (self::DRAFTS as $i => [$title, $region, $activity, $difficulty, $hours, $price]) {
            $cms->table('experiences')->insert([
                'id' => (string) Str::ulid(), 'title' => $title, 'slug' => Str::slug($title), 'region_id' => $region,
                'guide_id' => $guidesByRegion[$region][0] ?? null, 'summary' => $this->summary($title, $region, $hours),
                'activity' => $activity, 'difficulty' => $difficulty, 'duration_hours' => $hours, 'max_group_size' => 8,
                'price_minor' => $price, 'currency' => 'GBP', 'status' => 'draft', 'version' => 0,
                'created_at' => now()->subDays(5 - $i), 'updated_at' => now()->subDays(5 - $i),
            ]);
        }

        // Today's republish under the v2 contract, through the real outbox.
        foreach ($live as $id) {
            $publish(new PublishExperience($id));
        }
    }

    private function summary(string $title, string $region, float $hours): string
    {
        return sprintf(
            '%s with a qualified local guide in the %s. Around %s hours including stops; small groups of up to eight, kit list sent on booking.',
            $title, self::REGIONS[$region][0], rtrim(rtrim(number_format($hours, 1), '0'), '.'),
        );
    }
}
