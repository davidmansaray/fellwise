# Fellwise: event-driven Filament CMS

A working reference implementation of a **Filament CMS kept consistent with a core API through domain events on RabbitMQ**. It covers the parts that are hard to get right in production: a transactional outbox, an idempotent inbox, TTL retry tiers with a parking lot, replay, event versioning and upcasting, drift detection, a strangler-fig migration off a legacy platform, and CQRS reporting on MySQL.

**Live demo: https://fellwise.davidmansaray.com** (click *View demo*, no sign-up. It runs on shared sample data, faults switch off after 3 minutes, and the data is rebuilt nightly.)

Laravel 13 · Filament 5 · PHP 8.5 · RabbitMQ 4.1 (quorum queues) · MySQL · PHPStan level 8 · Deptrac · PHPUnit 12

## The scenario

*Fellwise* is an invented UK marketplace for guided outdoor experiences, split into two bounded contexts:

- **CMS (Catalogue context):** the Filament panel where editors write and publish experience listings.
- **Core API (Bookings context):** owns bookings, availability and reporting, and keeps its own projection of the catalogue.
- **TrailDesk:** the legacy PHP 5 platform being retired. Its data moves across through the same event pipeline.

Neither context reads the other's tables or imports the other's classes. They communicate only through versioned events.

## What to try

Open the live demo, or run it locally (see below):

1. **Break the sync.** Toggle *Fail Core consumer*, then click *Publish 20 changes*. Messages step through the `5s → 15s → 45s` retry queues into the parking lot, and the consistency checker reports 20 experiences drifted.
2. **Repair it.** Turn the fault off and click *Unpark all*. Drift returns to zero.
3. **Prove it's safe.** Click *Replay from outbox*. The same event ids are re-published, and Core's inbox skips every one as a duplicate, so nothing is applied twice.
4. **Inspect any event:** every hop with real timestamps, the error each failed attempt saw, the v1 → v2 upcast, and per-event replay.
5. **Reporting:** revenue, bookings and lead time from CQRS read models over ~95k bookings. Each card can show its SQL and `EXPLAIN` output.

## How it works

```
Filament ─▶ Experience aggregate ─▶ one MySQL tx: row + outbox row
                                          │ outbox:relay (publisher confirms)
                                          ▼
              fellwise.events (topic) ─▶ core.catalogue ─▶ Core inbox + projector (one tx)
                                     ─▶ cms.bookings   ─▶ CMS booking stats
                 on failure ─▶ retry.5s | 15s | 45s (TTL) ─▶ back to the owning queue
                 after 3    ─▶ parking-lot (operator unparks or replays)
```

| Concern | How it's handled |
|---|---|
| Dual writes | Aggregate and event are committed in one transaction (transactional outbox); the relay marks rows published only after broker confirms |
| Duplicates | `(event_id, consumer)` inbox claim inside the handler's transaction |
| Ordering | Projectors guard on `aggregate_version`; a late publish cannot resurrect an unpublished experience |
| Retries | TTL queue tiers dead-letter back to the owning queue only; no `sleep()` in consumers |
| Poison messages | Contract violations park immediately; malformed bodies dead-letter to the parking lot |
| Schema evolution | Versioned routing keys, an upcaster chain, and JSON Schemas with contract tests |
| Drift | Indexed `sync_hash` columns on both sides, compared by `consistency:check` |
| Legacy migration | Anti-corruption layer and a resumable cursor in the same transaction |

## Repository layout

```
backend/
  src/
    Catalogue/   CMS context: Domain · Application · Infrastructure
    Bookings/    Core context: Domain · Application · Infrastructure
    Shared/      Envelope, outbox, inbox, RabbitMQ, retry policy, upcasting
    Migration/   TrailDesk anti-corruption layer and backfill
    Operations/  Consistency checker
  app/           Filament panel and artisan commands (composition root)
  docs/adr/      Architecture decision records
  docs/events/   JSON Schemas for every event version
  infra/rabbitmq Broker definitions (topology as code) and docker compose
  tests/         Unit, feature, contract and real-broker tests
```

Layer boundaries are enforced by Deptrac: Catalogue and Bookings can't import each other, and domain layers can't import the framework.

## Running it locally

Requirements: PHP 8.4+ (`sockets`, `pdo_mysql`, `pcntl`), MySQL 8+, Node, and Docker for RabbitMQ.

```bash
cd backend
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate        # set DB_* and RABBITMQ_*
for db in fellwise_cms fellwise_core fellwise_ops; do mysql -uroot -e "CREATE DATABASE $db"; done
RABBITMQ_PASS=change-me docker compose -f infra/rabbitmq/docker-compose.yml up -d

php artisan fellwise:install --force   # schemas, seed data through the real pipeline, 18 months of bookings
composer workers                       # relay, both consumers, consistency checker, booking traffic
php artisan serve
```

Sign in as `ops@fellwise.co.uk` / `fellwise`.

## Quality

```bash
composer quality   # Pint, PHPStan level 8, Deptrac
php artisan test   # unit, feature and contract tests
```

A separate `broker` test group runs against a real RabbitMQ vhost to prove the retry topology, unparking and publisher confirms. `bitbucket-pipelines.yml` runs quality and tests in parallel against MySQL and RabbitMQ service containers.

## Further reading

- [`backend/README.md`](backend/README.md): full walkthrough, operator commands and the guarantees table
- [`backend/docs/adr/`](backend/docs/adr/): 001 one repo, two bounded contexts · 002 outbox and inbox · 003 retry topology and parking lot · 004 strangler-fig migration · 005 CQRS read models
