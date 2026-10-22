<x-filament-panels::page>
    <div
        class="fw-console"
        wire:ignore
        data-event-url="{{ \App\Filament\Pages\EventDetail::getUrl(['event' => '__ID__']) }}"
        data-timeline-url="{{ \App\Filament\Pages\AggregateTimeline::getUrl(['aggregate' => '__ID__']) }}"
        x-data
        x-init="const go = () => window.fwMissionControl ? window.fwMissionControl($el, $wire) : setTimeout(go, 30); go()"
    >
        @if (config('demo.enabled'))
            <section class="fw-card">
                <header class="fw-card-h"><h3>Try this · a three-minute tour</h3><span class="sub">Shared sample data · faults expire after 3 minutes</span></header>
                <div class="fw-card-b fw-tour">
                    <div><strong>1. Break the sync</strong><p>Switch on <b>Fail Core consumer</b>, then click <b>Publish 20 changes</b>. Watch the messages move through the retry queues for about 65 seconds and land in the parking lot.</p></div>
                    <div><strong>2. Repair it</strong><p>Switch the fault off and click <b>Unpark all</b>. Watch the parking lot empty and the consistency checker return to zero drift.</p></div>
                    <div><strong>3. Prove it is safe</strong><p>Click <b>Replay from outbox</b> and choose <b>Last 5 minutes</b>. Duplicate deliveries are skipped by Core's inbox. Nothing is applied twice.</p></div>
                </div>
                <footer class="fw-topo-foot">Use Reset demo to recover at any time. Heavy controls have shared cooldowns. Original sample data is restored nightly at 04:00 UTC.</footer>
            </section>
        @endif
        <div class="fw-kpis" data-kpis></div>

        <section class="fw-card">
            <header class="fw-card-h">
                <h3>Pipeline topology</h3>
                <span class="sub mono">rabbitmq 4.1 · vhost /{{ config('messaging.connection.vhost') }}</span>
                <span class="fw-pill" data-broker><i></i> connecting…</span>
                <button type="button" class="fw-switch" role="switch" aria-checked="false" data-fault title="Make the Core consumer's handlers throw">
                    <span class="track"></span> Fail Core consumer
                </button>
                <span class="sp"></span>
                <div class="fw-legend" aria-label="Legend">
                    <span><i style="background:var(--ok)"></i>delivered</span>
                    <span><i style="background:var(--warn)"></i>retrying</span>
                    <span><i style="background:var(--bad)"></i>parked</span>
                    <span><i style="background:var(--info)"></i>replay</span>
                    <span><i style="background:var(--legacy)"></i>legacy</span>
                    <span><i style="background:var(--brand)"></i>bookings</span>
                </div>
            </header>
            <div class="fw-topo-wrap" data-topo-wrap><div class="fw-topo" data-topo></div></div>
            <footer class="fw-topo-foot">
                <span>Every dot is a real message hop from <span class="mono">ops.delivery_attempts</span>. Retry tiers are the real TTLs.</span>
                <span class="sp"></span>
                <span>Click any node for detail</span>
            </footer>
        </section>

        <div class="fw-grid3">
            <section class="fw-card">
                <header class="fw-card-h">
                    <h3>Consistency checker</h3>
                    <span class="sub" data-check-meta></span>
                    <span class="sp"></span>
                    <button type="button" class="fw-btn" data-run-check>Run now</button>
                </header>
                <div class="fw-big ok" data-check-big></div>
                <div class="fw-list" data-check-list></div>
            </section>

            <section class="fw-card">
                <header class="fw-card-h">
                    <h3>Legacy backfill</h3>
                    <span class="sub mono">TrailDesk → legacy.experience.imported.v1</span>
                </header>
                <div class="fw-card-b">
                    <div class="fw-stat-row" data-backfill-stat></div>
                    <div class="fw-progress"><i data-backfill-bar style="width:0%"></i></div>
                    <div style="margin-top:14px"><button type="button" class="fw-btn" data-backfill>Import TrailDesk batch (500)</button></div>
                    <p class="fw-note">Chunked by <span class="mono">lst_id</span>. The cursor advances in the same transaction as the outbox rows, so a crash resumes exactly where it stopped. Rows the translator can't map (no area, POA prices, blank titles) are counted, never guessed.</p>
                </div>
            </section>

            <section class="fw-card">
                <header class="fw-card-h">
                    <h3>Live events</h3>
                    <span class="sub">newest first</span>
                    <span class="sp"></span>
                    <a class="fw-btn" href="{{ \App\Filament\Resources\Events\EventResource::getUrl() }}">All events</a>
                </header>
                <div class="fw-list" data-stream style="max-height:330px"></div>
            </section>
        </div>

        <aside class="fw-panel" data-panel aria-label="Node detail">
            <div class="fw-panel-h"><h3 data-panel-title></h3><span class="sp"></span><button type="button" class="fw-btn" data-panel-close>Close</button></div>
            <div class="fw-panel-b" data-panel-body></div>
        </aside>
    </div>
</x-filament-panels::page>
