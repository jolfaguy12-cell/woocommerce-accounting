<?php

use Illuminate\Support\Facades\Schedule;

// Reconciliation fallback behind the hub's push webhooks.
Schedule::command('acc:sync:poll-orders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('acc:sync:poll-products')->everyThirtyMinutes()->withoutOverlapping();

// Safety net: the changed-feed poll above can still miss orders (e.g. a
// webhook + poll both landing outside their overlap window). Nightly full
// reconciliation catches anything that slipped through; cheap when nothing
// is missing since it only re-fetches orders absent locally.
Schedule::command('acc:sync:backfill-orders')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('acc:sync:backfill-products')->dailyAt('04:00')->withoutOverlapping();

// Order items normalized before their product ever synced are stuck unlinked
// (product_mirror_id is resolved once, not retroactively) — catch up nightly
// after the product backfill above has had a chance to fill the gap.
Schedule::command('acc:sync:relink-order-items')->dailyAt('04:30')->withoutOverlapping();

// Exact on-hand stock units + their sell-price value across every product —
// too expensive to recompute on every dashboard refresh (dashboard/reports
// just read the latest snapshot instead, see InventorySnapshotService).
Schedule::command('acc:products:snapshot-inventory')->everyFourHours()->withoutOverlapping();

// Flags purchase invoices still not fully received 5 days after invoice_date
// (or after expected_delivery_date) — business hours, not the 3am sync slot,
// since this alerts staff who need to act on it the same day.
Schedule::command('acc:purchases:detect-overdue-receipts')->dailyAt('09:00')->withoutOverlapping();

// Safety net: re-queues any telegram alert deliveries stuck pending/failed
// (queue worker downtime, or a transient API error that exhausted the job's
// own retries) — same dual pattern as the poll+backfill pairs above.
Schedule::command('acc:alerts:retry-telegram')->everyFifteenMinutes()->withoutOverlapping();
