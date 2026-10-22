# ADR-002: Transactional outbox and idempotent inbox

**Status:** accepted · 2026-10

## Context
Writing to MySQL and publishing to RabbitMQ are two systems; any "save then publish" sequence loses events on crash (dual write). RabbitMQ delivers at-least-once, so consumers will see duplicates.

## Decision
- **Outbox.** `OutboxEventRecorder` appends the event to `outbox_messages` in the same transaction as the aggregate and throws if called outside one. The table is append-only and is our event log.
- **Relay.** `outbox:relay` claims unpublished rows with `FOR UPDATE SKIP LOCKED` (safe to run several), publishes persistent + mandatory messages on a confirm-mode channel, and stamps `published_at` only after broker confirms. A crash between confirm and stamp re-publishes, which is safe because of the inbox.
- **Inbox.** Each consumer `INSERT IGNORE`s `(event_id, consumer)` in the same transaction as its handler. A duplicate is acked without side effects and counted. A failed handler rolls the claim back.
- **Ordering.** RabbitMQ orders per queue only, and retries reorder. Projectors therefore guard on `aggregate_version` (`IF(incoming.version > version, …)`) so older or replayed events never overwrite newer state.
- **Envelope.** CloudEvents-flavoured: ULID `event_id`, versioned name, `aggregate_version`, `correlation_id`, `causation_id`, `producer`. Consumers propagate correlation, and set causation to the event being handled.

## Consequences
Exactly-once *effects* on top of at-least-once delivery, an audit trail per aggregate, and replay for free.

## Relay failure semantics
- A batch is all-or-nothing: if any publish fails mid-batch, the whole transaction rolls back and nothing is stamped. The error is recorded on the failing row afterwards, outside that transaction. Rows already sent are re-sent on the next poll and the inbox absorbs them.
- The publisher tracks unconfirmed publishes per channel and **refuses to open a fresh channel while confirms are outstanding**. A dropped connection therefore fails the batch instead of letting a new channel report "all confirmed" for publishes it never saw (found in review, regression-tested).
- The claim transaction spans the broker round trip (publish + confirm, 5 s budget, batch of 100). That is acceptable with `SKIP LOCKED`, since other relays skip the locked rows. At higher volume, switch to a short claim transaction (`claimed_until` lease) followed by a separate stamp.
- CLI workers register a `PCNTLHeartbeatSender`, so an idle relay or consumer is not dropped by the broker.

## Event-carried state
`catalogue.experience.published` carries the full listing snapshot at the new version, and is emitted both on first publish and on any contract-field edit to a live listing. Consumers treat it as a versioned upsert, which is what makes replay and out-of-order delivery safe. A finer-grained `experience.revised` event would add nothing Core needs today.
