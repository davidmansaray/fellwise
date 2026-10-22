# ADR-005: CQRS read models for reporting

**Status:** accepted · 2026-10

## Decision
- Reporting never aggregates the `bookings` write table on request. `rm_experience_performance(day, experience_id, region_id, bookings, guests, revenue_minor)` is maintained in the same transaction as each booking (same context), and rebuilt from source with one `INSERT … SELECT … GROUP BY`.
- Primary key `(day, experience_id)` makes date-range reports a range scan; `(region_id, day)` and `(experience_id, day)` serve the drill-downs. Lead time is computed at write time so the histogram is an index range scan on `confirmed_at`, not a `DATEDIFF` over every row.
- The panel shows each report's SQL, timing and `EXPLAIN` next to the chart.
- Next steps at scale: monthly partitioning of the read model, and moving ad-hoc search to Elasticsearch fed from the same events.
