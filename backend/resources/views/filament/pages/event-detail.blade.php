<x-filament-panels::page>
    <div class="fw-console" wire:poll.2s>
        @if ($found === null)
            <section class="fw-card"><div class="fw-empty">No event with id <span class="mono">{{ $this->event }}</span> in either outbox.</div></section>
        @else
            @php($env = $envelope)
            <div class="fw-split">
                <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
                    <section class="fw-card">
                        <header class="fw-card-h">
                            <h3>Delivery attempts</h3>
                            <span class="sub">{{ $attempts->count() }} hops recorded in <span class="mono">ops.delivery_attempts</span></span>
                        </header>
                        <div class="fw-tl">
                            @forelse ($attempts as $attempt)
                                @php([$lane, $tone] = \App\Filament\Support\EventLookup::lane($attempt->outcome, $attempt->consumer))
                                <div class="fw-tl-ev {{ $tone }}">
                                    <span class="node"></span>
                                    <div class="fw-tl-head">
                                        <span class="fw-pill {{ $tone }}">{{ \Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryOutcome::from($attempt->outcome)->label() }}{{ $attempt->tier ? ' · '.$attempt->tier : '' }}</span>
                                        <span class="mono" style="color:var(--ink-2)">{{ $attempt->consumer }}</span>
                                        @if ($attempt->is_replay)<span class="fw-pill info">x-replay</span>@endif
                                        <span style="flex:1"></span>
                                        <span class="mono" style="color:var(--ink-3);font-size:11px">attempt {{ $attempt->attempt }}{{ $attempt->duration_ms !== null ? ' · '.$attempt->duration_ms.' ms' : '' }} · {{ substr($attempt->created_at, 11, 12) }}</span>
                                    </div>
                                    @if ($attempt->error)
                                        <span class="fw-err" style="margin-top:6px;white-space:normal">{{ $attempt->error }}</span>
                                    @endif
                                </div>
                            @empty
                                <div class="fw-empty">Not relayed yet. The outbox relay publishes committed rows within a second.</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="fw-card">
                        <header class="fw-card-h"><h3>Payload</h3><span class="sub mono">{{ $env->routingKey() }}</span></header>
                        <div class="fw-card-b"><pre class="fw-json">{!! $payloadHtml !!}</pre></div>
                    </section>
                </div>

                <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
                    <section class="fw-card">
                        <header class="fw-card-h"><h3>Envelope</h3><span class="sp"></span><span class="fw-pill {{ $found['row']->published_at ? 'ok' : 'warn' }}"><i></i>{{ $found['row']->published_at ? 'published' : 'in outbox' }}</span></header>
                        <div class="fw-card-b">
                            <dl class="fw-kv">
                                <dt>event_id</dt><dd>{{ $env->eventId }}</dd>
                                <dt>event_name</dt><dd>{{ $env->eventName }}</dd>
                                <dt>event_version</dt><dd>{{ $env->eventVersion }}</dd>
                                <dt>occurred_at</dt><dd>{{ $env->occurredAt->format('Y-m-d H:i:s.v') }}</dd>
                                <dt>aggregate</dt><dd>{{ $env->aggregateType }} · {{ $env->aggregateId }}</dd>
                                <dt>aggregate_version</dt><dd>{{ $env->aggregateVersion }}</dd>
                                <dt>correlation_id</dt><dd>{{ $env->correlationId }}</dd>
                                <dt>causation_id</dt><dd>{{ $env->causationId ?? 'null' }}</dd>
                                <dt>producer</dt><dd>{{ $env->producer }}</dd>
                                <dt>outbox</dt><dd>{{ $found['context'] }}.outbox_messages</dd>
                                <dt>published_at</dt><dd>{{ $found['row']->published_at ?? '—' }}</dd>
                            </dl>
                        </div>
                    </section>
                    <section class="fw-card">
                        <header class="fw-card-h"><h3>AMQP</h3></header>
                        <div class="fw-card-b">
                            <dl class="fw-kv">
                                <dt>exchange</dt><dd>fellwise.events</dd>
                                <dt>routing key</dt><dd>{{ $env->routingKey() }}</dd>
                                <dt>delivery_mode</dt><dd>2 (persistent)</dd>
                                <dt>message_id</dt><dd>{{ $env->eventId }}</dd>
                                <dt>content_type</dt><dd>application/json</dd>
                                <dt>queue</dt><dd>{{ str_starts_with($env->eventName, 'bookings.') ? 'cms.bookings' : 'core.catalogue' }}</dd>
                            </dl>
                        </div>
                    </section>
                    <section class="fw-card">
                        <header class="fw-card-h"><h3>Correlation chain</h3><span class="sub">{{ $chain->count() }} event(s)</span></header>
                        <div class="fw-list">
                            @foreach ($chain as $item)
                                <a class="fw-row" href="{{ \App\Filament\Pages\EventDetail::getUrl(['event' => $item['envelope']->eventId]) }}" @if($item['envelope']->eventId === $env->eventId) style="background:var(--surface-2)" @endif>
                                    <span class="bullet {{ $item['context'] === 'cms' ? 'ok' : 'info' }}"></span>
                                    <span class="grow ev">{{ $item['envelope']->routingKey() }}</span>
                                    <span class="meta">{{ $item['context'] }}{{ $item['envelope']->causationId ? ' · caused by …'.substr($item['envelope']->causationId, -6) : '' }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
