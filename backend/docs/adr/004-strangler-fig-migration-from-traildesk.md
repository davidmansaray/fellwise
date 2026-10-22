# ADR-004: Strangler-fig migration off TrailDesk

**Status:** accepted · 2026-10

## Context
Listings still live in TrailDesk (legacy PHP 5, `tbl_listing`, prices as strings, area codes). A big-bang cutover is too risky.

## Decision
- `legacy:backfill --chunk=500` reads TrailDesk in `lst_id` order. `TrailDeskTranslator` (the anti-corruption layer) maps each row to a Catalogue command. Untranslatable rows are counted and skipped, never guessed.
- Each chunk imports into the Catalogue aggregate, which records `legacy.experience.imported.v1` through the **same outbox and broker** as live edits, and advances `migration_cursors` in the same transaction. Crash-safe and resumable, with no double imports.
- Core consumes legacy events exactly like CMS events, so reporting includes migrated listings immediately.
- Cutover per region: once the cursor passes and the consistency checker is green, the read path flips behind a feature flag, and TrailDesk becomes read-only and is then retired.
