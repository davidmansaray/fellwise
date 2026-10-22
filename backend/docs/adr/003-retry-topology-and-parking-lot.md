# ADR-003: Retry topology with TTL tiers and a parking lot

**Status:** accepted · 2026-10

## Context
Transient failures (a database blip, a downstream timeout) must not block a queue, must not spin in `nack(requeue=true)` loops, and must never `sleep()` inside a consumer. Permanent failures must be kept for inspection, not dropped.

## Decision
Topology lives in `infra/rabbitmq/definitions.json` (quorum queues), never in consumer code:

| Exchange | Type | Purpose |
|---|---|---|
| `fellwise.events` | topic | All integration events, routing key = `name.vN` |
| `fellwise.retry` | direct | Routes to `{queue}.retry.{5s,15s,45s}` |
| `fellwise.requeue` | direct | TTL expiry dead-letters back to **only** the owning queue |
| `fellwise.parking` | direct | Routes to `{queue}.parking-lot` |

- On failure the consumer republishes the original message to the next tier with `x-retry-count`, `x-last-error` and `x-first-failed-at`, **waits for the publisher confirm**, then acks. A tier is a TTL queue whose DLX is `fellwise.requeue` with `x-dead-letter-routing-key = {queue}`, so a retry never fans out to other subscribers.
- After 3 retries the message goes to `{queue}.parking-lot` (no TTL, no DLX: evidence is kept).
- Main queues also set `x-dead-letter-exchange: fellwise.parking` and `x-delivery-limit: 10`, so poison messages and consumer crashes still end up parked.
- Why republish rather than rely on `x-death`? `nack` cannot choose a tier; explicit republish with confirms gives per-tier delays and precise headers without losing at-least-once.

## Operations
- `messaging:unpark {queue} [--aggregate=]` moves parked messages back via `fellwise.requeue` with a fresh budget.
- `messaging:replay {context} --since= --name=` re-publishes from the outbox with `x-replay: true`. Inboxes skip what was applied; handlers suppress side effects on replays.
- `messaging:fault {queue} on|off` injects a failure to rehearse all of the above.

## Permanent vs transient failures
Retrying cannot fix a contract violation. Handlers validate required fields and throw `UnprocessableMessage`; that, `TypeError`, `ValueError` and `JsonException` **park on the first attempt** instead of spending 65 s in the tiers. Infrastructure errors (DB down, timeouts) go through the tiers.

## Proven against a real broker
`RetryTopologyBrokerTest` (group `broker`, isolated `fellwise_test` vhost created by `scripts/broker-test-vhost.sh`) asserts that the original is acked only after the message is in `retry.5s` with `x-retry-count=1`, that the TTL dead-letters it back to the owning queue, and that unpark resets the budget.

## Follow-up
Unpark and replay currently run inside the operator's web request (batched confirms, so 20 messages take well under a second). For thousands, dispatch them as queued jobs and stream progress to the console.
