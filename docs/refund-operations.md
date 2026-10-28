# Refund initiation contract (Draft implementation)

Task 13 comes from the 2026-10-28 WooCommerce opportunity audit: refund synchronization
already existed, but the gateway did not expose initiation from an order. This change
uses WooCommerce's existing refund form and order actions. It adds no separate screen.

## Behavior

The native **Reembolsar vía Recurrente** form sends an explicit customer amount in cents.
The plugin requires a uniquely linked, confirmed original `pa_` PaymentIntent and its
saved TEST/LIVE binding. It never chooses the latest subscription payment or infers
an old order's environment from today's gateway setting. Orders without that evidence,
non-card intent prefixes and shared charges spanning several Woo orders require manual
reconciliation. A renewal is eligible only if its own original charge/environment are
present and unique; the current renewal writer does not persist all of that evidence.
Legacy TEST hosted checkouts are simulated: `TestCheckoutWebhookSerializer` uses
`MockPaymentIntent` IDs such as `pa_test_…` without a persisted refundable intent. Those
IDs fail the guard before HTTP. The TEST-key integration cases below prove routing for
a hypothetical persisted TEST intent, not refund support for simulated hosted checkouts.

An operation UUID survives response loss in the browser's session storage. Before HTTP,
the order journal stores `woo_refund_<UUID>`, immutable request JSON, original intent,
mode, currency, amount, provisional Woo refund ID and selected accounting lines. It
contains no API credentials. Once journaled, reusing a UUID, including after completion
or with changed parameters, makes no additional money request. A newly acknowledged form submission can
create a separate equal partial refund. After an unresolved result, consult the existing
operation before requesting another. The script stops submission if it cannot retain
identity. A form rejected before journaling can be corrected while retaining its UUID.
The server rejects changes or repeats of an already journaled operation, including when
the browser lost its response; browser validation never decides whether money was sent.

Woo REST/programmatic callers must send `X-Recurrente-Refund-Operation: <UUID v4>` and
keep that UUID across retries. A direct `process_refund` call without the native
`wc_create_refund(..., refund_payment: true)` context is rejected. Existing integrations
that call the gateway directly or omit the UUID must adopt this contract. Standard Woo
admin nonce / REST permissions and `edit_shop_order` authorization still apply.

Only an identified `succeeded` refund for the exact customer amount and currency returns
gateway success. The actual account credit can differ because of fees; it is never used
as the customer amount. A `pending`, missing/malformed resource, transport error or
unlinked rejection returns `WP_Error`, so Woo deletes its provisional accounting and
does not restock or mark the order refunded. A `422` with `refund_id` is resolved by GET,
never interpreted from error text. Confirmed `failed`/`voided` resources are terminal
without accounting; unknown outcomes continue blocking other operations.

**Consultar reembolso de Recurrente** in the order actions reads a known refund ID and
records its status in private notes. A confirmed pending operation is mirrored through
the existing inbound sync with `refund_payment: false`, preserving selected lines and
restock choice. Independent inbound refunds remain independent. There is no background
poller: signed successful callbacks and the explicit order action perform reconciliation.

## Races and recovery

The existing order lock serializes journal updates and HTTP initiation. A second MySQL
advisory lock spans the entire native request, until WordPress shutdown, including Woo's
success accounting or provisional-refund rollback after the gateway returns. Callback
attempts during that interval get 503, remain unprocessed, and can be redelivered. Locks
fail closed when unavailable; database disconnect releases them after a process crash.

Remote IDs are written before interpreting the resource. While an outbound operation has
no remote ID, potentially related incoming refunds return 503 rather than being guessed
from amount. Once known, the original intent and exact refund ID correlate the echo.
Native Woo refunds and inbound mirrors both persist `_recurrente_refund_id` on the refund
record, before accounting hooks. A crash after creating the mirror but before saving the
order's applied-ID list therefore recovers the same accounting record.

An unresolved operation owns the payment even after a lock/process ends. An operation
without a remote ID may retry only its original body/key within **23 hours of the first
attempt**; its clock never resets. After that it remains blocked for support reconciliation,
because Recurrente expires idempotency records at 24h. A known ID uses GET even after that
window. Never delete the journal, rotate an uncertain key or invent a refund ID to unblock it.
There is no pre-plugin key transition: this gateway did not initiate refunds previously.

If a process died after Woo's provisional row was saved but before it acquired remote
identity, that row can require manual investigation. A confirmed native record is reused;
an unconfirmed leftover record is not deleted automatically. Recovery after a synchronous
confirmation disables automatic restocking because another Woo hook might already have
restocked before failing; support must verify inventory in that case. Deletion of confirmed
Woo accounting by another plugin/operator is outside automatic repair. Do not delete a
confirmed refund to try to send money again.

CPT queries use Woo's `meta_key`/`meta_value`; HPOS uses `meta_query`. The refundable balance
is read through Woo's data store without populating the parent total-refunded cache with
the provisional row that Woo may shortly delete. No direct post/order-table writes occur.

## Recurrente and Stripe

Verified against `recurrente-app`'s public API specification and implementation:

- [`POST /refunds` / `GET /refunds/{id}`](https://github.com/recurrente/recurrente-app/blob/master/docs/api/openapi.yaml),
  [`Api::RefundsController`](https://github.com/recurrente/recurrente-app/blob/master/app/controllers/api/refunds_controller.rb),
  [`RefundSerializer`](https://github.com/recurrente/recurrente-app/blob/master/app/serializers/refund_serializer.rb).
- [`Api::Idempotency`](https://github.com/recurrente/recurrente-app/blob/master/app/controllers/concerns/api/idempotency.rb)
  retains uncertain/error reservations rather than replaying every executed error;
  [`IdempotencyKey`](https://github.com/recurrente/recurrente-app/blob/master/app/models/idempotency_key.rb)
  expires at 24h. A stable key alone is insufficient after that boundary.
- [`Intent#resolve_pending_refund`](https://github.com/recurrente/recurrente-app/blob/master/app/models/intent.rb)
  may reuse an equal pending refund across different keys. New operations persist
  `require_new_refund: true` so a server implementing that contract rejects an unrelated
  pending refund instead of assigning it to WooCommerce. Per-payment exclusion and remote-ID
  deduplication alone cannot distinguish an independent pending operation.
- Unlike [Stripe's partial refunds](https://docs.stripe.com/api/refunds/create), Recurrente
  restricts partial support to documented providers (CyberSource / Visa CyberSource / cash).
  This gateway uses the original card PaymentIntent alias, explicit `amount_in_cents`,
  and `refund_application_fee: false`. It does not widen provider capabilities.
- [Stripe API v1 idempotency](https://docs.stripe.com/api/idempotent_requests) replays executed
  responses including errors. That behavior is not assumed here. Both APIs have non-final
  [refund states](https://docs.stripe.com/refunds); accepted/pending never means local success.

**Release blocker:** Rails support for `require_new_refund` from
[#7311](https://github.com/recurrente/recurrente-app/pull/7311) must be deployed and verified
before this plugin change is released. Current legacy servers silently ignore the new field;
sending it is not capability negotiation. A distinct idempotency key does not prevent legacy
pending-refund reuse. Keep this PR Draft and do not release it independently. Verify rejection
of an independently created pending partial and same-key replay in an authorized test environment.

No provider contact, live refund or backend deployment was performed by this PR.
Actual provider support, credentials and an accessible signed webhook endpoint remain runtime
requirements. Visual review of the native admin flow is **pending**.

## Integration with task 7

Payment recovery [PR #20](https://github.com/recurrente/recurrente-woocommerce/pull/20)
is independent. Suggested order: integrate task 7, then this PR; preserve both additions to
the gateway, bootstrap, README and translation catalog. The API client's fourth `request`
argument conflicts: task 7 uses a timeout; this PR uses extra headers. Resolve deliberately
with a combined options argument (or separate timeout/header arguments) and update every
call site. A numeric timeout must never replace refund idempotency headers. Run both PHP
regression sets, JavaScript tests and the CPT/HPOS integration tests on the combined tree.
The CPT `meta_query` limitation found here also affects older generic charge-link lookups;
this PR fixes only its refund eligibility query, without changing task 7's reconciliation.

## Validation

`php tests/run.php` runs the existing dependency-free regressions. `npm install --ignore-scripts`
then `npm test` checks request identity across HTTP loss, reload, confirmation, parameter
changes and unavailable storage. The additional integration test drives real Woo objects,
native refund hooks, the gateway/client and signed callbacks; only external HTTP is mocked.

Use a dedicated disposable WordPress/WooCommerce installation with this plugin activated,
a fixture admin named `woo13`, and `define('RECURRENTE_REFUND_TEST_STORE', true)` in its config.
Block external HTTP/mail/cron at the runtime level. Run both storage modes in separate PHP
processes, syncing the fixture store before switching:

```sh
wp wc hpos sync
wp option update woocommerce_custom_orders_table_enabled no
wp eval-file /path/to/plugin/tests/integration/refund-operations.php
python3 /path/to/plugin/tests/integration/refund-concurrency.py -- wp --path=/fixture-store
wp wc hpos sync
wp option update woocommerce_custom_orders_table_enabled yes
wp option update woocommerce_hpos_datastore_caching_enabled yes
wp eval-file /path/to/plugin/tests/integration/refund-operations.php
python3 /path/to/plugin/tests/integration/refund-concurrency.py -- wp --path=/fixture-store
```

The test creates fictional orders/products in that store and restores its gateway/signing
settings. Never run it against a real merchant store. There is no database drop/reset.
