# Fellwise: Filament CMS ↔ Core API, event-driven over RabbitMQ

A working reference implementation of a **Filament CMS that stays consistent with a core API through domain events on RabbitMQ**. It covers a transactional outbox, idempotent inbox, TTL retry tiers, a parking lot, replay, event upcasting, a consistency checker, a strangler-fig migration off a legacy platform, and CQRS reporting on MySQL.

*Fellwise* is an invented UK marketplace for guided outdoor experiences. The **CMS** (Catalogue context) owns editorial listings; the **Core API** (Bookings context) owns bookings and reporting; **TrailDesk** is the legacy PHP 5 platform being retired.

**Live demo: https://fellwise.davidmansaray.com** (click *View demo*; shared sample data, faults switch off after 3 minutes, rebuilt nightly)

Laravel 13 · Filament 5.10 · PHP 8.5 · RabbitMQ 4.1 (quorum queues) · MySQL 9 · PHPStan level 8 · Deptrac · PHPUnit 12 (175 tests + 3 against a real broker)

---

## The 3-minute tour

Open the live demo and click **View demo**, or run it locally (below) and sign in as `ops@fellwise.co.uk` / `fellwise` with `composer workers` running.

1. **Mission Control.** A live topology of the pipeline. Every dot is a real hop recorded in `ops.delivery_attempts`. Queue depths come from the RabbitMQ management API.
2. **Edit an experience** (Experiences → any → change the price → Save). The aggregate and its outbox row commit in one transaction; the relay publishes with publisher confirms; Core projects it in under a second. The sync panel shows both sides' fingerprints match.
3. **Break it.** Toggle **Fail Core consumer**, then **Publish 20 changes**. Messages move through `retry.5s → 15s → 45s` (real TTL queues) into the **parking lot**. The main queue keeps draining, bookings keep flowing on their own queue, and the consistency checker reports **20 experiences diverged**.
4. **Repair it.** Toggle the fault off → **Unpark all**: parked messages return through `fellwise.requeue` and drift goes to 0. Then **Replay from outbox**: the same 20 event ids are re-published with `x-replay`, and Core's inbox **skips all 20 as duplicates**. Nothing is applied twice.
5. **Forensics.** Click any drifted experience (or *Event timeline* on an experience): every hop of every event with real timestamps, the `SQLSTATE` error each failed attempt saw, the v1 → v2 upcast, and **Replay this event**.
6. **Migrate.** *More → Import TrailDesk batch (500)*: legacy rows go through an anti-corruption layer and the same outbox as `legacy.experience.imported.v1`. The cursor advances in the same transaction, so a crash resumes exactly where it stopped.
7. **Reporting.** Revenue by region, bookings over time, top experiences and lead time over CQRS read models built from ~95k bookings. Each card's query badge opens the real SQL and `EXPLAIN`.

## Architecture

```
            ┌──────────── CMS (Catalogue context · schema fellwise_cms) ────────────┐
Filament ──▶│ ReviseExperience / PublishExperience → Experience aggregate            │
            │   └─ one MySQL tx: experiences row + outbox_messages row               │
            └──────────────────────────────┬────────────────────────────────────────┘
                              outbox:relay │ FOR UPDATE SKIP LOCKED · publisher confirms
                                           ▼
             fellwise.events (topic) ── catalogue.# legacy.# ──▶ core.catalogue (quorum)
                  ▲      └──────────── bookings.# ──────────────▶ cms.bookings  (quorum)
                  │                                                   │
   Core outbox ───┘   failure ─▶ fellwise.retry ─▶ {q}.retry.5s|15s|45s (TTL)
                                  ttl expiry ─▶ fellwise.requeue ─▶ {q} (owner only)
                      after 3 ─▶ fellwise.parking ─▶ {q}.parking-lot (no TTL)
                                           ▼
            ┌──────────── Core (Bookings context · schema fellwise_core) ───────────┐
            │ inbox_messages INSERT IGNORE + version-guarded projector, one tx       │
            │ bookings → rm_experience_performance, rm_lead_time (CQRS read models)  │
            └────────────────────────────────────────────────────────────────────────┘
```

- **Hexagonal, per context:** `src/{Catalogue,Bookings}/{Domain,Application,Infrastructure}`, shared kernel in `src/Shared`, legacy ACL in `src/Migration`, drift detection in `src/Operations`. Domain layers are plain PHP. The panel (`app/`) is the composition root.
- **Boundaries enforced:** `deptrac.yaml` forbids Catalogue ↔ Bookings imports and framework imports in domain layers (0 violations in CI).
- **Topology as code:** `infra/rabbitmq/definitions.json`. The app never declares queues.

### Guarantees and where they live

| Concern | Implementation |
|---|---|
| No dual writes | `OutboxEventRecorder` throws outside a transaction; the relay stamps a batch only after broker confirms and rolls the whole batch back if a channel drops mid-batch (ADR-002) |
| Exactly-once effects | `Inbox::claim` (`event_id, consumer` PK) in the handler's transaction; duplicates are acked and counted |
| Ordering | RabbitMQ orders per queue only and retries reorder, so projectors guard on `aggregate_version`. An unpublish that overtakes its publish leaves a tombstone the late publish can fill but not resurrect |
| Retries without `sleep()` | Republish to the next TTL tier with a confirm, then ack; tiers dead-letter back only to the owning queue (ADR-003) |
| Poison messages | Contract violations (`UnprocessableMessage`, `TypeError`) park on the first attempt; malformed bodies → `nack(requeue=false)` → DLX → parking lot; `x-delivery-limit: 10` caps crash loops |
| Replay | From the outbox (the event log) by time, event name, aggregate or event id, with `x-replay: true` |
| Schema evolution | Versioned routing keys (`name.vN`), `UpcasterChain` (v1 float price → v2 `amount_minor`), JSON Schemas in `docs/events/` with contract tests |
| Drift detection | Stored generated `sync_hash` columns (indexed) on both sides, compared by `consistency:check` |
| Legacy migration | `TrailDeskTranslator` ACL + cursor in the same transaction (ADR-004) |
| Reporting | Read models updated in the booking transaction; covering indexes; `EXPLAIN` in the UI (ADR-005) |

## Running it

Requirements: PHP 8.4+ (`sockets`, `pdo_mysql`, `pcntl`), MySQL 8+, a RabbitMQ 4.x broker loaded with `infra/rabbitmq/definitions.json`.

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate   # set DB_* and RABBITMQ_*
for db in fellwise_cms fellwise_core fellwise_ops; do mysql -uroot -e "CREATE DATABASE $db"; done

# Broker: RABBITMQ_PASS=change-me docker compose -f infra/rabbitmq/docker-compose.yml up -d
# (or on a remote host: export BROKER_SSH_HOST=<ssh host> and the tunnel below forwards it)

php artisan fellwise:install --force   # schemas + seed through the real pipeline + 18 months of bookings
composer workers                       # tunnel, relay, both consumers, checker, booking traffic
php artisan serve                      # or any local PHP server
```

### Operator commands

```bash
php artisan outbox:relay [cms|core] [--once]
php artisan messaging:consume core.catalogue|cms.bookings [--exit-when-idle=N]
php artisan messaging:fault core.catalogue on|off
php artisan messaging:unpark core.catalogue [--aggregate=ID]
php artisan messaging:replay cms --since="-1 hour" --name="catalogue.experience.*" [--dry-run]
php artisan consistency:check [--watch]
php artisan legacy:backfill [--chunk=500] [--all]
php artisan bookings:simulate [--per-minute=3]
```

## Quality

```bash
composer quality        # pint --test · phpstan (level 8) · deptrac
php artisan test        # 175 tests, against dedicated *_test schemas
scripts/broker-test-vhost.sh   # once: isolated fellwise_test vhost with the same topology
BROKER_TEST_PORT=5682 BROKER_TEST_PASSWORD=… vendor/bin/phpunit --group broker --fail-on-skipped --fail-on-empty-test-suite   # retry topology, unpark, confirms on a real RabbitMQ
```

The broker group proves ADR-003 on a real RabbitMQ: a failed message is acked only after it is confirmed in `retry.5s` with `x-retry-count=1`, its TTL dead-letters it back to the owning queue, and unpark resets the budget. The main suite covers the domain (aggregates, money, lead-time buckets), the retry policy, upcasting, JSON-Schema contract tests for every event version and for what actually leaves the outbox, outbox atomicity and relay failure modes, the processor end to end (duplicates, fault injection, parking, out-of-order and tombstone cases, malformed messages), contract violations parking on the first attempt, projector idempotency and ordering guards, the consistency checker, the TrailDesk backfill and the reporting queries.

`bitbucket-pipelines.yml` runs quality and tests in parallel against MySQL and RabbitMQ service containers, then offers a manual CodeDeploy step (zip → S3 → `aws deploy`).

## Decisions

`docs/adr/`: 001 one repo, two bounded contexts · 002 outbox and inbox · 003 retry topology and parking lot · 004 strangler-fig migration · 005 CQRS read models for reporting.

**Deliberately not built** (natural next steps for production): Elasticsearch fed from the same events for catalogue search, a GraphQL read API, sagas or process managers, per-region cutover flags for the TrailDesk read path, and monthly partitioning of the read models.

## GitHub checks

The public repository's [Application checks](../.github/workflows/ci.yml) run quality, normal tests, explicit real-broker tests and a locked frontend build with disposable MySQL/RabbitMQ services. PHPUnit's test-schema guards remain enabled; broker tests use only `fellwise_test`. No live provider credentials or deployment access are required. [Latest runs](https://github.com/davidmansaray/fellwise/actions/workflows/ci.yml).
