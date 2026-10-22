# ADR-001: One repository, two bounded contexts, broker-only integration

**Status:** accepted · 2026-10

## Context
The CMS (Catalogue) and the core API (Bookings) must stay consistent but evolve independently. In production they are separate deployables; for this codebase we want the same guarantees without doubling CI, Docker and panel setup.

## Decision
- One Laravel application, with each context under `src/<Context>/{Domain,Application,Infrastructure}` and its **own MySQL schema** (`cms`, `core`). Operational telemetry lives in a third schema (`ops`).
- Contexts never import each other's classes or read each other's tables. They communicate **only** through RabbitMQ events. This is enforced in CI by `deptrac.yaml` (0 violations) and reviewed against `src/` imports.
- The Filament panel (`app/`) is the composition root and the only place allowed to see both contexts.
- Domain layers are plain PHP (no Illuminate imports, also enforced by Deptrac).

## Consequences
- Splitting into two services later is mechanical: move `src/Bookings` and its migrations; nothing else changes.
- One extra hop of eventual consistency inside a single app, which is deliberate: it is the production topology.

## Operational tooling is outside both contexts
`src/Operations` (consistency checker) and the console's read services in `app/Filament/Support` read **both** schemas, read-only, by design: drift detection has to look at both sides. They never write to a context's tables and no context depends on them. In a two-service split they become an ops service with read replicas of each schema.

## Known follow-ups
- Move the pure `RetryPolicy`/`RetryDecision` and the `MessagePublisher` port from `Shared/Infrastructure/Messaging/Rabbit` to `Shared/Application/Messaging`; move `Envelope` JSON (de)serialisation into infrastructure.
- Inject a handler map into `MessageProcessor` instead of resolving handlers from the container.
- `FaultInjector` is a demo/rehearsal switch; in production make it a decorating handler that is only bound outside `production`.
