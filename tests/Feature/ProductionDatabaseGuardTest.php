<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 2026-07-14 incident: `migrate:fresh` intended for an isolated staging database
 * silently ran against the live production database instead, because the only
 * signal separating "safe to wipe" from "do not touch" was APP_ENV — and the
 * checkout's APP_ENV (local) said nothing about which database its .env actually
 * pointed at. These tests pin down the fix: DB_IS_PRODUCTION, a second signal
 * independent of APP_ENV, must block every schema-destroying command outright.
 *
 * Dispatched directly rather than via Artisan::call() — Artisan::call() does not
 * fire CommandStarting inside Pest's console test harness (confirmed separately:
 * a real `php artisan migrate:fresh` process does fire it and is blocked
 * correctly; this is a harness quirk, not a gap in the guard). Dispatching the
 * event directly exercises the exact same listener the real CLI process runs.
 */
function fireCommandStarting(string $command): void
{
    Event::dispatch(new CommandStarting($command, new StringInput(''), new NullOutput));
}

it('refuses migrate:fresh when the database is marked production', function () {
    config(['database.is_production' => true]);

    expect(fn () => fireCommandStarting('migrate:fresh'))->toThrow(RuntimeException::class, 'REFUSED');
});

it('refuses db:wipe when the database is marked production', function () {
    config(['database.is_production' => true]);

    expect(fn () => fireCommandStarting('db:wipe'))->toThrow(RuntimeException::class, 'REFUSED');
});

it('refuses migrate:refresh and migrate:reset when the database is marked production', function () {
    config(['database.is_production' => true]);

    expect(fn () => fireCommandStarting('migrate:refresh'))->toThrow(RuntimeException::class, 'REFUSED');
    expect(fn () => fireCommandStarting('migrate:reset'))->toThrow(RuntimeException::class, 'REFUSED');
});

it('does not interfere with a normal migrate on a production-marked database', function () {
    config(['database.is_production' => true]);

    fireCommandStarting('migrate');
})->throwsNoExceptions();

it('leaves every command alone when the database is not marked production', function () {
    config(['database.is_production' => false]);

    fireCommandStarting('migrate:fresh');
    fireCommandStarting('db:wipe');
})->throwsNoExceptions();
