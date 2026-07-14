# Durable fulfillment authority 28.7.4.6.5.1

The global fulfillment decision is authorized by the backend and inserted with
the Checkout. `pickup` and `delivery` are the only accepted values. Delivery is
allowed only when the persisted Checkout total satisfies RB-CHK-001, whose
single configuration source remains the
`veciahorra_minimum_delivery_amount` filter. The frontend only submits the
selection.

Checkout stores `fulfillment_method`, its stable owner/idempotency key, and a
canonical request fingerprint containing fulfillment, currency, total, and the
ordered set of Orders. There is no update operation. The value is therefore
immutable from the Checkout INSERT, before a PaymentSession can exist. A replay
with the same owner/key and method returns the existing Checkout; a different
method is an idempotency conflict.

Business Completion copies the method and all authorized Orders inside its
existing transaction and lease. `business_completion_orders` stores one row per
Order with unique constraints on both the pair and `order_id`. Completion cannot
close while `fulfillment_method` is null, and the repository verifies the exact
sorted Order set before closing.

All new columns on existing tables are nullable for migration compatibility.
Historical rows remain null and are classified as `legacy_fulfillment_missing`;
they are never inferred as pickup or delivery. No Delivery, Tracking, courier,
notification, or Delivery Completion authority is created by this milestone.
