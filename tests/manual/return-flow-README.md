# Failed delivery and controlled return

Courier `POST /courier/deliveries/{id}/return` takes JSON `expected_version`,
`reason`, `observation`, `confirmed: true`. It never accepts an OTP or image as
incident evidence. The domain derives the assigned Courier from the WP session.
Both Order and Delivery move to `return_pending` with append-only tracking and
an incident record in one top-level transaction. Verification hash/context are
cleared and OTP is consumed/invalidated without increasing attempts.

Only the original operational minimarket's authorized owner may use
`POST /minimarket/returns/{id}/receive` with `expected_version`, `condition`, and
`observation`. Successful receipt changes Delivery to `returned_to_store` and
Order to `incident_review` atomically. Courier linkage remains for history;
custody is defined by `return_pending` and ends only with successful receipt.
Suspension does not release that Delivery. New acceptance and administrative
assignment to a Courier with pending return custody are blocked under the same
Courier lock. Normal delivery, abandonment and reassignment reject return states.

Codes are closed lists. Notes are trimmed, valid UTF-8, 1–500 characters, with
control characters rejected. Exact actor/version/code/note replays are idempotent;
different commands conflict. No payment, reservation, inventory or final incident
resolution is performed. Historical records are not backfilled. No normal proof
photo is reused. Existing private evidence authorization remains in place.

The customer projection recognizes the new states without exposing internal
notes, actors or tracking. A separate read-only administration view shows the
incident and receipt. The Courier can read their return instructions even when
suspended; this does not restore normal operational permissions. The minimarket
uses its existing operational StoreContext authorization (including active and
approved status), so a non-operational store cannot confirm receipt.

## Isolated tests

Run `VA_PROOF_TEST=1 php tests/manual/return-flow-mysql-test.php` from a disposable
checkout under the local workspace artifacts directory. MariaDB 127.0.0.1:3306,
native wpdb and WP/GD image editor are used without wp-load/wp-config. Each run
creates and removes a unique `va_proof_test_<hex>` database and temporary files.
Orders start at 1001. Native WP request/response and escaped administration HTML
are exercised; WP identities/cache configuration are simulated. Apache loopback
proves the existing image privacy gate. No real WP login or physical mobile
camera is certified.

Four races use distinct processes/connections held behind a Courier row lock.
Both worker lock requests must be active in the live MariaDB PROCESSLIST while
the parent still owns the InnoDB row lock, before release; the normal
delivery worker first signals completion of native image preparation. Injections
at Delivery, Order, tracking, incident and OTP writes verify rollback.

`VA_RETURN_MUTATIONS=1 python tests/manual/return-flow-mutations.py` detects ten
focused mutants and restores exact source bytes. The completion mutant tests a
false success response after an incident; normal-vs-incident races separately
verify real committed database outcomes.

`return-flow-panel-test.js` exports async `testReturnPanel(source)` and
`testStoreReturnPanel(source)` for V8/Node DOM/network doubles. Existing normal
photo and customer panel suites remain regressions. `VA_PROOF_PLUGIN_ROOT` plus
`VA_PROOF_COMPOSER=1` run the native backend suite against an extracted package.
