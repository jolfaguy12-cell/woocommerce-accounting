# Deployment — Party identity (Commits 1–3)

Rollout for the multi-role Party work: `party_roles`, identity fields,
`party_bank_accounts`, role profiles, `PartyLedgerService`, `/parties`.

**Not yet deployed.** All migrations so far have run only against the isolated
`wc_accounting_stage` database. Production's schema is untouched.

## Why sync and the queue must be paused

Two entry points create parties on their own, without a human:

| Entry point | How it runs |
|---|---|
| Hub webhook (`POST /webhooks/hub`) | persists a `webhook_events` row, then dispatches `ProcessWebhookEvent` to the queue — **all real work happens on the queue worker** (`accounting-queue.service`) |
| Pollers / backfills (`acc:sync:poll-orders` every 15 min, nightly `backfill-orders`) | `schedule:run`, from **www-data's crontab** (`* * * * * cd /var/www/accounting && php artisan schedule:run`) |

Both land in `CustomerResolver`, which creates customer parties.

The hazardous window is between `migrate` and the new code going live: the **old**
code is still running against the **new** schema, and it creates parties with a
`parties.type` and no `party_roles` row. Under the new code those parties are
invisible to `Party::withRole()` — a customer who exists but cannot be found,
which is how order sync starts minting duplicates.

The backfills are idempotent precisely so they can close this window, but they
only help if they run **after** the new code is live and **while nothing is
creating parties behind them**. So: stop the writers first, backfill last.

Note the queue worker only needs stopping — the webhook endpoint itself can stay
up. It merely persists the raw event; nothing is processed until the worker is
back, so no event is lost and the hub never sees a 503.

## Sequence

### 1. Back up first — the dev checkout and production share one database

```bash
mysqldump -u root --single-transaction --routines woocommerce_accounting \
  > /root/backups/accounting-$(date +%F-%H%M).sql
```

Never run `php artisan migrate` from `/root/woocommerce-accounting` with the
default `.env`: it points at the production database. Migrations run from
`/var/www/accounting` only.

### 2. Pause the writers

```bash
systemctl stop accounting-queue.service          # webhook work stops draining
crontab -u www-data -l > /root/backups/www-data-cron.bak
crontab -u www-data -l | sed 's|^\* \* \* \* \* cd /var/www/accounting|#&|' | crontab -u www-data -
```

Verify nothing is still running:

```bash
systemctl is-active accounting-queue.service     # → inactive
pgrep -af "artisan (queue:work|schedule:run|acc:sync)"   # → no output
```

### 3. Deploy code + migrations

```bash
cd /var/www/accounting
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # new Blade needs a CSS rebuild, or dark mode breaks
php artisan migrate --force
```

### 4. Backfill (the whole reason for the pause)

```bash
php artisan parties:backfill-roles --json
php artisan parties:sync-profiles --json
```

Re-run both until every counter is `0`. They only insert what is missing and
never overwrite a profile the UI has since edited, so re-running is free.

### 5. Verify before resuming

```sql
-- every party has exactly its legacy role, and nothing is orphaned
SELECT (SELECT COUNT(*) FROM parties)                                        AS parties,
       (SELECT COUNT(*) FROM party_roles)                                    AS roles,
       (SELECT COUNT(*) FROM party_roles pr JOIN parties p ON p.id = pr.party_id
         WHERE pr.role <> p.type)                                            AS mismatched,
       (SELECT COUNT(*) FROM parties p
         WHERE NOT EXISTS (SELECT 1 FROM party_roles r WHERE r.party_id = p.id)) AS without_role;

-- every role has its profile (a role without one is invisible to whereHas filters)
SELECT COUNT(*) AS customers_without_profile
  FROM party_roles r
  WHERE r.role = 'customer' AND r.is_active = 1
    AND NOT EXISTS (SELECT 1 FROM customer_profiles cp WHERE cp.party_id = r.party_id);

-- the ledger still balances, and no party lost its history
SELECT SUM(debit) - SUM(credit) AS must_be_zero FROM journal_lines;
```

`mismatched`, `without_role`, `customers_without_profile` and `must_be_zero`
must all be `0`, and `parties` must equal `roles`.

```bash
php artisan route:cache && php artisan config:cache && php artisan view:clear
php artisan route:list | grep parties     # parties/duplicates must precede parties/{party}
```

### 6. Resume the writers

```bash
crontab -u www-data /root/backups/www-data-cron.bak
systemctl start accounting-queue.service
```

Then watch the webhook backlog drain and confirm it created nothing stale:

```bash
journalctl -u accounting-queue.service -f --since "5 min ago"
php artisan parties:backfill-roles --json     # expect {"roles_created":0,...}
```

New parties created after this point get their role and profile from the model
itself (`Party::created` → `activateRole` → `profileFor`, in one transaction), so
this last run should always report zeros. If it does not, sync resumed before the
backfill finished — investigate before trusting any customer list.

## Rollback

Every migration in Commits 1–3 is additive. Rolling back the **code** alone is
safe: the legacy columns are still on `parties` and still populated.

The one caveat: role data written **after** the deploy (a wholesale label, a
credit limit, a supplier's shop name) lives only in the profile tables. A code
rollback would leave those edits unread — the pre-deploy values would come back.
Restore from the §1 dump if that matters, and re-apply by hand otherwise.

Do not drop `party_roles` or the profile tables without first restoring the old
code: the new code reads roles from nowhere else.
