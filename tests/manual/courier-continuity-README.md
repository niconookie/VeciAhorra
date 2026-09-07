# Courier continuity (0.3.18 / schema 0.35.0)

Run only in a disposable checkout. The PHP suite requires `VA_CONTINUITY_TEST=1`
and `VA_TERRITORY_WP_ROOT` pointing to local WordPress core. It loads native wpdb,
never wp-load or wp-config, and connects only to 127.0.0.1:3306. Optional credentials:
`VA_TERRITORY_DB_USER`, `VA_TERRITORY_DB_PASSWORD`. It creates and removes a unique
`va_continuity_test_<16 hex>` database; fixtures never use Orders 18–22.

`php tests/manual/courier-continuity-mysql-test.php` exercises real repositories,
services, InnoDB transactions, SQL-trigger write failures and two overlapping
processes for reassignment. WordPress identity, routing and form functions are
doubles. This is not web certification. `VA_TERRITORY_PLUGIN_ROOT` can select an
extracted package; `VA_CONTINUITY_USE_COMPOSER=1` uses that package's actual loader.

Run `courier-continuity-mutations.py` with `VA_CONTINUITY_MUTATIONS=1` and
`VA_TEST_PHP` pointing to PHP. It detects missing revision CAS, territorial listing,
territorial write checks and transaction rollback, restoring exact bytes in finally.
The older `courier-territory-mysql-test.php` remains the territorial regression suite.

`courier-continuity-panel-test.js` is a pure JavaScript DOM/API-double test (Node or
V8). Its exported top-level function `testCourierPanel(source)` verifies assigned-only
release, reason bounds, cancellation, confirmation, revision payload and refresh.

## Command contract

Courier POST `/courier/deliveries/{id}/accept`, `/picked-up`, `/delivered`, `/abandon`
require integer `expected_version` from the delivery's `transition_version`.
Abandon additionally requires `reason` (1–500 Unicode characters after trimming).
Courier identity is resolved from the authenticated WordPress association.

Administrative PATCH `/deliveries/{id}/assign` requires `expected_version` and
`courier_id`. Initial assignment omits reason; reassignment supplies reason;
unassignment supplies reason and courier_id=0. PATCH `/status` accepts picked_up
or delivered using the same domain service and expected_version. The unsafe direct
cancel/status-assigned path is closed; assigned work is explicitly released instead.
Administrative transition events cannot be fabricated via POST `/tracking`.
Existing location_update behavior is unchanged.

Admin forms on Repartidores use the same service, manage_options and nonces. They
list only assigned deliveries and offer eligible same-zone replacement couriers.
Suspension holds the Courier lock used by acceptance/pickup, rejects picked_up work,
and releases all assigned work and changes Courier status within one transaction.
Released legacy/null-zone or otherwise ineligible deliveries remain unavailable.

Delivery revision increments are checked in SQL. Tracking has one unique event per
delivery/revision, actor, old/new state and courier, reason and existing UTC created_at.
These audit columns are nullable for historical events; there is no audit backfill.
Replays require a matching latest transition and resulting state; stale revisions
conflict. Clients refresh before a new action. No automatic timeout/retry worker,
physical transfer after pickup, payment, notification or production deployment is added.
