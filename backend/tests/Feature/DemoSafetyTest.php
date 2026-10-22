<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Support\DemoActions;
use App\Filament\Support\ResetDemo;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxTable;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshContexts;
use Tests\Support\Envelopes;
use Tests\TestCase;

final class DemoSafetyTest extends TestCase
{
    use RefreshContexts;

    public function test_faults_expire_after_three_minutes_without_browser_activity(): void
    {
        $this->freezeTime();
        config(['demo.enabled' => true]);
        app(FaultInjector::class)->set('core.catalogue', true);
        $this->travel(179)->seconds();
        $this->assertTrue((new FaultInjector(DB::connection('ops')))->isFailing('core.catalogue'));
        $this->travel(1)->seconds();
        $this->artisan('demo:expire-faults')->assertSuccessful();
        $this->assertDatabaseHas('ops_flags', ['key' => 'fault.core.catalogue', 'enabled' => false], 'ops');
    }

    public function test_local_faults_do_not_expire(): void
    {
        $this->freezeTime();
        config(['demo.enabled' => false]);
        app(FaultInjector::class)->set('core.catalogue', true);
        $this->travel(4)->minutes();
        $this->assertTrue((new FaultInjector(DB::connection('ops')))->isFailing('core.catalogue'));
    }

    public function test_cooldowns_are_shared_across_visitors_and_recover(): void
    {
        $this->freezeTime();
        config(['demo.enabled' => true]);
        $calls = 0;
        $action = function () use (&$calls): int {
            return ++$calls;
        };
        $this->assertSame(1, (new DemoActions)->run('publish', $action));
        $this->assertNull((new DemoActions)->run('publish', $action));
        $this->assertSame(1, $calls);
        $this->travel(16)->seconds();
        $this->assertSame(2, (new DemoActions)->run('publish', $action));
    }

    public function test_a_running_action_prevents_other_heavy_actions(): void
    {
        config(['demo.enabled' => true]);
        $lock = Cache::lock('demo:heavy-action', 300);
        $lock->get();
        try {
            $this->assertNull((new DemoActions)->run('import', fn () => 'should not run'));
        } finally {
            $lock->release();
        }
    }

    public function test_reset_disables_faults_clears_both_parking_lots_and_replays_latest_state(): void
    {
        config(['demo.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:1/api/queues/fellwise?*' => Http::response([]),
            'http://127.0.0.1:1/api/queues/fellwise/core.catalogue.parking-lot/contents' => Http::response('', 204),
            'http://127.0.0.1:1/api/queues/fellwise/cms.bookings.parking-lot/contents' => Http::response('', 204),
        ]);
        $publisher = $this->fakePublisher();
        app(FaultInjector::class)->set('core.catalogue', true);
        $old = Envelopes::experiencePublished('01K74ZQ9M2H3J4K5M6N7P8Q9RS', 1);
        $latest = Envelopes::experiencePublished('01K74ZQ9M2H3J4K5M6N7P8Q9RS', 2);
        OutboxTable::insert(DB::connection('cms'), $old);
        OutboxTable::insert(DB::connection('cms'), $latest);
        DB::connection('cms')->table('outbox_messages')->update(['published_at' => now()]);
        $this->assertSame(1, app(ResetDemo::class)->run());
        $this->assertDatabaseHas('ops_flags', ['key' => 'fault.core.catalogue', 'enabled' => false], 'ops');
        $this->assertSame([$latest->eventId], $publisher->confirmedEventIds());
        Http::assertSentCount(3);
    }
}
