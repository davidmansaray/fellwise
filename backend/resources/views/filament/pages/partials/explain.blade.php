<div class="fw-console" style="gap:14px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <span class="fw-qbadge">query <b>{{ $result->elapsedMs }} ms</b></span>
        <span class="fw-qbadge">index <b>{{ $result->indexUsed() ?? 'none' }}</b></span>
        <span class="fw-qbadge">{{ count($result->rows) }} rows returned</span>
    </div>
    <pre class="fw-json">{{ $result->sql }}

-- bindings: {{ implode(', ', $result->bindings) }}</pre>
    <div class="fw-card fw-explain">
        <table class="fw-table">
            @php($plan = $result->explain())
            <thead><tr>@foreach (array_keys($plan[0] ?? []) as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($plan as $row)
                    <tr>@foreach ($row as $value)<td>{{ $value ?? 'NULL' }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
