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
