<x-filament-panels::page>
    <div class="fw-console" wire:poll.2s>
        @if ($cms === null)
            <section class="fw-card"><div class="fw-empty">No experience <span class="mono">{{ $this->aggregate }}</span> in the Catalogue.</div></section>
        @else
            @php($inSync = $core !== null && $core->sync_hash === $cms->sync_hash)
            <div class="fw-hashes">
                <div class="fw-hash">
                    <div class="l">CMS · Catalogue</div>
                    <div class="v">v{{ $cms->version }} · {{ $cms->status }} · £{{ number_format($cms->price_minor / 100, 2) }}</div>
                    <div class="v" style="color:var(--ink-3)">{{ substr($cms->sync_hash, 0, 16) }}</div>
                </div>
                <div class="fw-hash" style="{{ $inSync ? '' : 'border-color:var(--bad);background:var(--bad-soft)' }}">
                    <div class="l" style="display:flex;justify-content:space-between">Core · Bookings <span class="fw-pill {{ $inSync ? 'ok' : 'bad' }}"><i></i>{{ $inSync ? 'in sync' : ($core === null ? 'missing in Core' : 'diverged') }}</span></div>
                    @if ($core)
                        <div class="v">v{{ $core->version }} · {{ $core->status }} · £{{ number_format($core->price_minor / 100, 2) }}</div>
                        <div class="v" style="color:var(--ink-3)">{{ substr($core->sync_hash, 0, 16) }} · synced {{ substr($core->synced_at, 11, 8) }}</div>
                    @else
                        <div class="v">—</div><div class="v" style="color:var(--ink-3)">never projected</div>
                    @endif
                </div>
            </div>

            <div class="fw-split">
                <section class="fw-card">
                    <header class="fw-card-h">
                        <h3>Event timeline</h3>
                        <span class="sub">{{ $events->count() }} events in the CMS outbox · CMS → broker → Core</span>
                    </header>
                    <div class="fw-tl">
                        @foreach ($events as $event)
                            @php($hops = $attempts->get($event->id, collect()))
                            @php($last = $hops->whereNotIn('consumer', ['relay', 'outbox'])->last())
                            @php($tone = match ($last->outcome ?? null) { 'processed' => 'ok', 'duplicate' => 'info', 'retry_scheduled' => 'warn', 'parked' => 'bad', default => $event->published_at ? 'info' : 'warn' })
                            <div class="fw-tl-ev {{ $tone }} {{ $selected === $event->id ? 'selected' : '' }}" wire:click="select('{{ $event->id }}')" style="cursor:pointer">
                                <span class="node"></span>
                                <div class="fw-tl-head">
                                    <span class="ev">{{ $event->event_name }}</span>
                                    <span class="fw-pill">v{{ $event->event_version }}</span>
                                    @if ($event->event_version < 2 && $event->event_name === 'catalogue.experience.published')
                                        <span class="fw-pill info" title="Core upcasts v1 (float price) to v2 (amount_minor)">upcast v1 → v2</span>
                                    @endif
                                    <span class="mono" style="color:var(--ink-3);font-size:11px">aggregate v{{ $event->aggregate_version }}</span>
                                    <span style="flex:1"></span>
                                    <span class="mono" style="color:var(--ink-3);font-size:11px">{{ substr($event->occurred_at, 0, 19) }}</span>
                                </div>
                                <div class="fw-hops">
                                    <div class="fw-hop"><span class="t">{{ substr($event->occurred_at, 11, 12) }}</span><span class="lane cms">CMS</span><span>Saved with aggregate · outbox row</span><span></span></div>
                                    @forelse ($hops as $hop)
                                        @php([$lane, $hopTone] = \App\Filament\Support\EventLookup::lane($hop->outcome, $hop->consumer))
                                        <div class="fw-hop">
                                            <span class="t">{{ substr($hop->created_at, 11, 12) }}</span>
                                            <span class="lane {{ $lane }}">{{ $lane === 'cms' ? 'CMS' : ($lane === 'broker' ? 'Broker' : 'Core') }}</span>
                                            <span>
                                                @switch($hop->outcome)
                                                    @case('published') Published · broker confirmed @break
                                                    @case('replayed') Replayed from outbox · x-replay @break
                                                    @case('processed') Inbox claimed · projected · acked @break
                                                    @case('duplicate') Duplicate event_id · skipped by inbox @break
                                                    @case('retry_scheduled') Failed attempt {{ $hop->attempt }} → {{ $hop->consumer }}.retry.{{ $hop->tier }} @break
                                                    @case('parked') Failed attempt {{ $hop->attempt }} → parking lot @break
                                                    @case('unparked') Unparked → fellwise.requeue @break
                                                @endswitch
                                                @if ($hop->error)<span class="fw-err" title="{{ $hop->error }}">{{ $hop->error }}</span>@endif
                                            </span>
                                            <span class="fw-pill {{ $hopTone }}">{{ $hop->duration_ms !== null ? $hop->duration_ms.' ms' : \Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryOutcome::from($hop->outcome)->label() }}</span>
                                        </div>
                                    @empty
                                        <div class="fw-hop"><span class="t">—</span><span class="lane broker">Broker</span><span style="color:var(--ink-3)">{{ $event->published_at ? 'Delivered before telemetry was recorded (2025 launch)' : 'Waiting for the relay' }}</span><span></span></div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="fw-card" style="position:sticky;top:80px">
                    @if ($selectedEnvelope)
                        <header class="fw-card-h">
                            <h3 class="mono" style="font-size:12.5px">{{ $selectedEnvelope->routingKey() }}</h3>
                            <span class="sp"></span>
                            <button type="button" class="fw-btn primary" wire:click="replayEvent('{{ $selectedEnvelope->eventId }}')">Replay this event</button>
                        </header>
                        <div class="fw-card-b" style="display:flex;flex-direction:column;gap:14px">
                            <dl class="fw-kv">
                                <dt>event_id</dt><dd><a href="{{ \App\Filament\Pages\EventDetail::getUrl(['event' => $selectedEnvelope->eventId]) }}" style="text-decoration:underline">{{ $selectedEnvelope->eventId }}</a></dd>
                                <dt>aggregate_version</dt><dd>{{ $selectedEnvelope->aggregateVersion }}</dd>
                                <dt>correlation_id</dt><dd>{{ $selectedEnvelope->correlationId }}</dd>
                                <dt>causation_id</dt><dd>{{ $selectedEnvelope->causationId ?? 'null' }}</dd>
                                <dt>producer</dt><dd>{{ $selectedEnvelope->producer }}</dd>
                                <dt>published_at</dt><dd>{{ $selectedRow->published_at ?? 'not yet' }}</dd>
                            </dl>
                            <pre class="fw-json">{!! $payloadHtml !!}</pre>
                        </div>
                    @else
                        <div class="fw-empty">This experience has never been published, so it has no events yet.</div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
