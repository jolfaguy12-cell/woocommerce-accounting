<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;

/**
 * Refuses schema-destroying commands against the production database, checked by
 * `DB_IS_PRODUCTION` rather than `APP_ENV` — the 2026-07-14 incident happened
 * exactly because a checkout with `APP_ENV=local` had its `.env` pointed at the
 * live production database. `APP_ENV` describes which copy of the CODE is
 * running; it says nothing about which DATABASE it talks to, and only the
 * database identity is what a `migrate:fresh` actually destroys. `DB_IS_PRODUCTION`
 * is set to `true` in exactly one place: the production deployment's own `.env`,
 * as a second, independent signal that cannot be spoofed by a stray `--env` flag
 * or a copy-pasted `.env` file.
 *
 * There is no override flag. A command that must run against production goes
 * through `wc_prod_deploy` (which has DDL rights `wc_prod_app` does not) run
 * manually by an operator who has already read this file — not a CLI flag that
 * can be typed in a hurry.
 */
class ProductionDatabaseGuard
{
    private const BLOCKED_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, self::BLOCKED_COMMANDS, true)) {
                return;
            }

            if (! $this->isProductionDatabase()) {
                return;
            }

            $database = config('database.connections.'.config('database.default').'.database');

            throw new RuntimeException(sprintf(
                "REFUSED: '%s' cannot run against the production database (DB_IS_PRODUCTION=true, database: %s). ".
                'This command drops or truncates the schema. If production genuinely needs a schema change, '.
                'use the wc_prod_deploy credentials and a normal `migrate` (never `migrate:fresh`/`db:wipe`) under a maintenance-mode freeze. '.
                'See CLAUDE.md — Database Environment Isolation, and the 2026-07-14 incident record.',
                $event->command,
                $database,
            ));
        });
    }

    private function isProductionDatabase(): bool
    {
        // config(), not env() — this must still work when config is cached
        // (the normal state in production), where env() calls return null.
        return (bool) config('database.is_production', false);
    }
}
