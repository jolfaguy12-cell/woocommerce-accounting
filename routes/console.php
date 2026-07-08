<?php

use Illuminate\Support\Facades\Schedule;

// Reconciliation fallback behind the hub's push webhooks.
Schedule::command('acc:sync:poll-orders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('acc:sync:poll-products')->everyThirtyMinutes()->withoutOverlapping();
