# Business completion 28.7.4.6.5

`business_completions` is the durable authority for internal materialization. It
is deliberately separate from `payment_reconciliations`: a financial
reconciliation may be `completed` while business completion remains `pending`,
`processing`, `retryable`, `manual_review`, or `permanent_failure`.

The idempotency key is SHA-256 of the versioned purpose, reconciliation primary
key, and durable financial fingerprint. Unique indexes on reconciliation,
idempotency key, Payment reconciliation, Payment session, and Payment–Order
relationships enforce the invariant in the database.

The processor first claims the completion row with a compare-and-set. The claim
has an owner, expiry, and monotonically increasing version, preventing ABA.
Inside one InnoDB transaction it locks in this order: BusinessCompletion,
PaymentReconciliation, Checkout, PaymentSession, Payment, PaymentOrders, and
Orders sorted by ascending ID. It then creates or recognizes the Payment,
transitions the existing Payment state `pending -> paid` (the module's approved
state), creates or recognizes every Payment–Order link, transitions eligible
Orders `reserved -> paid`, and closes BusinessCompletion.

All affected plugin tables use the same WordPress database connection and
InnoDB in supported installations, so this is the real transaction boundary.
If the process dies before commit, the transaction rolls back and the expiring
claim permits a retry. If the client times out after commit, the next execution
recognizes `completed`. No destructive compensation is performed. Delivery,
WooCommerce, Webpay calls, hooks, network clients, and return endpoints are not
dependencies of this processor.
