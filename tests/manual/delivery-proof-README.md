# Private delivery proof

This phase closes the normal picked_up → delivered path behind a valid product
photo and a six-digit OTP. Legacy Courier and administrative status transitions
cannot bypass the proof service. Pickup fulfillment never issues an OTP.

## Authority and storage

Pickup issues the OTP authority inside the same transaction as Delivery/tracking.
A 256-bit random generation context and a site-keyed HMAC PRF with rejection sampling
produce the six digits, including leading zeros. Only the verification HMAC is
stored as the OTP, alongside context/version, UTC issue/expiry/consumption and failed
attempts. The context is not an encrypted or plaintext OTP. Redisplay requires the
site secret and the authenticated customer's ownership check. Changing site salts
invalidates existing OTPs; there is no regeneration/backfill in this phase.

The code expires after two hours and locks after five failed validations. Failed
attempts commit under the row lock without changing Delivery, Order or evidence.
Only the owned purchase detail includes a live code; Courier/admin/store projections
do not expose it. Delivered, expired and locked codes are hidden.

Photos are mandatory on a new confirmation. The Courier declares whether the
recipient is visible and records explicit consent if so. There is no face/content
recognition; the UI instructs the Courier to photograph the delivered products.
The mobile capture attribute prefers the camera but does not prevent gallery use.

Native image content, decoder support and the actual 8 MiB size limit are checked.
The WordPress editor applies EXIF orientation, bounds the image to 1600 px and
encodes JPEG at quality 82. APP1–APP15/COM metadata is stripped from the output
(including EXIF/XMP/GPS/profiles), then WordPress reopens the result. A random
storage key and the final SHA-256 are persisted.

Files live under WP_CONTENT_DIR/veciahorra-private-delivery, outside the Media
Library. Immutable deny-all .htaccess and defensive index.php must be present.
Before any photo is staged, a synthetic local-site HTTP probe must receive 403
without disclosing its body. Redirects, 200, unavailable loopback or incompatible
protection fail closed. No production probe was executed during development.
The Apache behavior was exercised with real HTTP against disposable local files;
LiteSpeed was not independently exercised.

Confirmation owns its top-level SQL transaction (nested callers are rejected).
A prepared private temporary JPEG precedes locks on Courier, Delivery and OTP.
The transaction inserts pending evidence, renames within the same directory,
CAS-updates Delivery and paid Order, consumes OTP, completes evidence and tracks
the actor. Every expected write must affect exactly one row. Exceptions roll back
SQL and compensate only the fresh files owned by that attempt. Existing files are
never compensation targets. Concurrent coherent replays return success and discard
their own temporary file, without adding evidence or tracking.

GET admin-post.php?action=veciahorra_delivery_evidence&delivery_id=... streams only
to the owning customer or manage_options. Other customers, Couriers, stores and
anonymous callers receive the same 404 for foreign and nonexistent evidence.
Responses use private/no-store, nosniff and a fixed inline JPEG filename. Physical
paths/storage keys never appear in public API data. No editing/deletion UI exists.

## Interfaces and contract

Courier POST /courier/deliveries/{id}/delivered uses multipart fields:
expected_version, otp, photo, recipient_visible (0/1), recipient_consent (0/1).
Courier identity comes exclusively from WordPress. A coherent completed replay
requires the same actor and original expected_version. Missing/expired/locked OTP,
invalid photo or operational failure leaves the delivery picked_up. Exceptional
delivery handling is outside scope, including historical picked_up rows without
an OTP. No historical authorities are fabricated.

Purchase detail supplies delivery_proofs only after ownership validation; the
Courier list never does. The admin Courier page lists completed proof states and
authorized viewing links. The minimarket retains its existing status-only view.

## Reproducible isolated verification

Set VA_PROOF_TEST=1 and VA_TERRITORY_WP_ROOT to local WordPress core, then execute
php tests/manual/delivery-proof-mysql-test.php. It uses native wpdb, GD and WP
image editors without wp-load/wp-config, a unique va_proof_test_<hex> InnoDB database
and disposable files under artifacts. Apache must serve localhost. Optional DB
credentials: VA_TERRITORY_DB_USER / VA_TERRITORY_DB_PASSWORD; host is fixed local.
VA_PROOF_PLUGIN_ROOT selects an extracted package; VA_PROOF_COMPOSER=1 selects
its real Composer loader. SQL-trigger failures, actual rename failure and
overlapping processes exercise compensation, atomicity and idempotency.

VA_PROOF_MUTATIONS=1 python tests/manual/delivery-proof-mutations.py detects
OTP, photo bypass, authorization, transaction, privacy and compensation mutants,
restoring exact bytes in finally. Native HTTP streaming uses explicitly simulated
identities; it is not WordPress login/browser certification.

delivery-proof-panel-test.js exercises Courier multipart/consent behavior and the
customer proof section in V8/Node with DOM/network doubles. The continuity suite
now rejects the legacy delivered command; the new proof suite owns coverage of
the completed transition. The territorial suite remains a regression check.

No payments, Webpay, reservations, BusinessCompletion, zones, cron, historical
Orders 18–22, notifications or deployment are changed.
