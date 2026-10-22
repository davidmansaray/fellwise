@php
    $money = fn (int $minor) => '£'.number_format($minor / 100, 0);
    $badge = fn (string $key, $result) => '<button type="button" class="fw-qbadge" wire:click="mountAction(\'explain\', { report: \''.$key.'\' })" title="Show SQL and EXPLAIN">query <b>'.$result->elapsedMs.' ms</b> · read model</button>';
@endphp
<x-filament-panels::page>
    <div class="fw-console">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                @foreach (\App\Filament\Pages\Reporting::RANGES as $key => $label)
                    <button type="button" class="fw-btn {{ $this->range === $key ? 'primary' : '' }}" wire:click="$set('range', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
            <span style="flex:1"></span>
            <div style="display:flex;gap:6px">
                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label)
                    <button type="button" class="fw-btn {{ $this->grain === $key ? 'primary' : '' }}" wire:click="$set('grain', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="fw-kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
            <div class="fw-kpi"><div class="l">Revenue</div><div class="v">{{ $money($kpi['revenue']) }}</div><div class="d">{{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}</div></div>
            <div class="fw-kpi"><div class="l">Bookings</div><div class="v">{{ number_format($kpi['bookings']) }}</div><div class="d">{{ number_format($kpi['guests']) }} guests</div></div>
            <div class="fw-kpi"><div class="l">Average order</div><div class="v">£{{ number_format($kpi['aov'] / 100, 2) }}</div><div class="d">per booking</div></div>
            <div class="fw-kpi"><div class="l">Read model</div><div class="v">{{ number_format($rows['rm']) }}<small>rows</small></div><div class="d">from {{ number_format($rows['bookings']) }} bookings</div></div>
        </div>

        <div class="fw-report-grid">
            <section class="fw-card">
                <header class="fw-card-h"><h3>Revenue by region</h3><span class="sp"></span>{!! $badge('region', $byRegion) !!}</header>
                <div class="fw-card-b" style="display:flex;flex-direction:column;gap:12px">
                    @forelse ($byRegion->rows as $row)
                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:12.5px"><span>{{ $regions[$row['region_id']] ?? $row['region_id'] }}</span><span class="mono">{{ $money($row['revenue_minor']) }} · {{ number_format($row['bookings']) }} bookings</span></div>
                            <div class="fw-bar"><i style="width:{{ round($row['revenue_minor'] / $maxRegion * 100, 1) }}%"></i></div>
                        </div>
                    @empty
                        <div class="fw-empty">No bookings in this range.</div>
                    @endforelse
                </div>
            </section>

            <section class="fw-card">
                <header class="fw-card-h"><h3>Bookings over time</h3><span class="sub">by {{ $this->grain }}</span><span class="sp"></span>{!! $badge('time', $overTime) !!}</header>
                @php
                    $points = $overTime->rows; $n = count($points); $w = 560; $h = 200; $pad = 44;
                    $maxB = $maxTime;
                    $xy = []; foreach ($points as $i => $p) { $xy[] = [$pad + ($n > 1 ? $i / ($n - 1) : .5) * ($w - $pad * 2), $h - $pad - ($p['bookings'] / $maxB) * ($h - $pad * 2)]; }
                    $line = implode(' ', array_map(fn ($p, $i) => ($i ? 'L' : 'M').round($p[0], 1).' '.round($p[1], 1), $xy, array_keys($xy)));
                @endphp
                <div class="fw-chart">
                    @if ($n)
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" width="100%" height="100%" preserveAspectRatio="none" role="img" aria-label="Bookings over time">
                            @foreach ([0, .5, 1] as $g)
                                <line x1="{{ $pad }}" x2="{{ $w - $pad }}" y1="{{ $h - $pad - $g * ($h - $pad * 2) }}" y2="{{ $h - $pad - $g * ($h - $pad * 2) }}" stroke="var(--border)" />
                                <text x="{{ $pad - 6 }}" y="{{ $h - $pad - $g * ($h - $pad * 2) + 3 }}" text-anchor="end" style="font:10px var(--mono);fill:var(--ink-3)">{{ number_format($maxB * $g) }}</text>
                            @endforeach
                            <path d="{{ $line }} L{{ round(end($xy)[0], 1) }} {{ $h - $pad }} L{{ round($xy[0][0], 1) }} {{ $h - $pad }} Z" fill="var(--brand-soft)" opacity=".8"/>
                            <path d="{{ $line }}" fill="none" stroke="var(--brand)" stroke-width="2" vector-effect="non-scaling-stroke"/>
                            @foreach ($points as $i => $p)
                                @if ($n <= 24 || $i % (int) ceil($n / 12) === 0)
                                    <text x="{{ round($xy[$i][0], 1) }}" y="{{ $h - 8 }}" text-anchor="middle" style="font:10px var(--mono);fill:var(--ink-3)">{{ $this->grain === 'month' ? \Carbon\Carbon::parse($p['period'].'-01')->format('M y') : \Carbon\Carbon::parse($p['period'])->format('j M') }}</text>
                                @endif
                            @endforeach
                        </svg>
                    @else
                        <div class="fw-empty">No bookings in this range.</div>
                    @endif
                </div>
            </section>

            <section class="fw-card">
                <header class="fw-card-h"><h3>Top experiences</h3><span class="sp"></span>{!! $badge('top', $top) !!}</header>
                <table class="fw-table">
                    <thead><tr><th>Experience</th><th>Region</th><th class="num">Bookings</th><th class="num">Revenue</th></tr></thead>
                    <tbody>
                        @foreach ($top->rows as $row)
                            <tr>
                                <td><a href="{{ \App\Filament\Pages\AggregateTimeline::getUrl(['aggregate' => $row['experience_id']]) }}">{{ $row['title'] }}</a></td>
                                <td style="color:var(--ink-3)">{{ $regions[$row['region_id']] ?? $row['region_id'] }}</td>
                                <td class="num">{{ number_format($row['bookings']) }}</td>
                                <td class="num mono">{{ $money($row['revenue_minor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="fw-card">
                <header class="fw-card-h"><h3>Booking lead time</h3><span class="sub">days between booking and departure</span><span class="sp"></span>{!! $badge('lead', $lead) !!}</header>
                <div class="fw-chart" style="display:flex;align-items:flex-end;gap:10px;padding-top:24px">
                    @foreach ($lead->rows as $row)
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end;min-width:0">
                            <span class="mono" style="font-size:10.5px;color:var(--ink-3)">{{ number_format($row['bookings']) }}</span>
                            <div style="width:100%;max-width:56px;border-radius:6px 6px 2px 2px;background:var(--info);opacity:.85;height:{{ max(2, round($row['bookings'] / $maxLead * 150)) }}px"></div>
                            <span style="font-size:11px;color:var(--ink-2);white-space:nowrap">{{ $row['bucket'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
        <p class="fw-note">Reports read <span class="mono">rm_experience_performance</span>, a CQRS read model keyed <span class="mono">(day, experience_id)</span> and updated in the same transaction as each booking, so they never aggregate the bookings table on request (ADR-005).</p>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
