# BEE-OCH Authorize.Net Diagnostics

**Version:** 1.0.0  
**Author:** BEE-OCH  
**License:** Proprietary

---

## What It Does

A lightweight, read-only diagnostic logger for the WooCommerce **Authorize.Net CIM** gateway (plugin `woocommerce-gateway-authorize-net-cim`). It hooks into the gateway's own action hooks to capture every transaction-level API call and track whether the resulting charge was fully registered in WooCommerce — without touching the gateway or WooCommerce core.

The primary risk it guards against is the **"approved but not registered"** scenario: Authorize.Net approves and charges the card, but a PHP fatal error, database failure, or race condition prevents the transaction ID from being saved or `payment_complete()` from being called. This leaves the customer charged with no corresponding WooCommerce order in a paid state.

---

## Requirements

| Dependency | Minimum Version |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce | 7.0 |
| WooCommerce Authorize.Net CIM gateway | any recent version |
| Action Scheduler | optional (wp-cron fallback included) |

---

## Installation

1. Upload the `beeoch-authorize-net-diagnostics` folder to `wp-content/plugins/`.
2. Activate via **Plugins > Installed Plugins**. Both WooCommerce and the Authorize.Net CIM gateway must be active when you activate this plugin.
3. On activation the plugin:
   - Creates the `{prefix}beeoch_authnet_diagnostics` database table.
   - Schedules a daily cleanup job via Action Scheduler (or wp-cron as a fallback) at 02:00 UTC.
4. Navigate to **WooCommerce > Authorize.Net Diag** to confirm the Health Check shows all green.

**Deactivation** removes the scheduled cleanup job but does not drop the database table or settings. Re-activating recreates any missing infrastructure.

---

## Architecture

```
beeoch-authorize-net-diagnostics/
├── beeoch-authorize-net-diagnostics.php   Bootstrap, constants, activation hooks
└── includes/
    ├── class-plugin.php          Singleton bootstrap; wires all components
    ├── class-installer.php       DB schema, activation, deactivation, upgrade
    ├── class-repository.php      All database reads and writes
    ├── class-settings.php        Read/write plugin settings (single WP option)
    ├── class-api-listener.php    Hooks wc_authorize_net_cim_credit_card_api_request_performed
    ├── class-payment-listener.php Tracks transaction-saved and payment_complete events
    ├── class-shutdown-handler.php PHP shutdown guard for mid-payment fatal errors
    ├── class-classifier.php      2-minute post-approval verification job
    ├── class-cleanup.php         Daily retention-based record deletion
    ├── class-admin-page.php      WP admin submenu page (list, detail, health, settings)
    └── class-list-table.php      WP_List_Table implementation
```

### Event lifecycle

```
Checkout submitted
    │
    ▼
wc_authorize_net_cim_credit_card_api_request_performed
    │  ApiListener inserts a diagnostic row (status: pending / declined / api_error / network_error)
    │  If status = pending → registers ShutdownHandler + schedules verification job (+2 min)
    │
    ▼  (same PHP request, if no fatal)
wc_payment_gateway_authorize_net_cim_credit_card_add_transaction_data
    │  PaymentListener sets transaction_saved = 1
    │
    ▼
wc_payment_gateway_authorize_net_cim_credit_card_payment_processed
    │  PaymentListener sets payment_completed = 1, status = completed
    │  ShutdownHandler is told: nothing to do
    │
    ▼  (~2 minutes later, via Action Scheduler or wp-cron)
beeoch_authnet_verify_pending
    └── Classifier reads the WC order to confirm both flags are set
        → completed / approved_not_registered / transaction_saved_not_completed
```

### Diagnostic statuses

| Status | Meaning |
|---|---|
| `pending` | Approved charge; waiting for 2-minute verification |
| `completed` | Transaction ID saved and `payment_complete()` called |
| `declined` | Authorize.Net declined the card |
| `api_error` | AN returned a non-OK result or HTTP 4xx/5xx |
| `network_error` | No HTTP response (timeout / DNS failure) |
| `approved_not_registered` | **Critical** — AN charged the card; WC never recorded it |
| `transaction_saved_not_completed` | Transaction ID saved but `payment_complete()` not called |
| `fatal_error` | PHP fatal error caught during the payment request |
| `on_hold` | Order transitioned to on-hold during the payment flow |
| `failed` | Order transitioned to failed during the payment flow |

---

## Database Table

Table name: `{wpdb->prefix}beeoch_authnet_diagnostics`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | Auto-increment |
| `correlation_key` | varchar(100) | SHA-256 hash; unique per event |
| `order_id` | bigint | WC internal order ID |
| `order_number` | varchar(100) | Public-facing order number |
| `transaction_id` | varchar(100) | Authorize.Net transaction ID |
| `request_type` | varchar(100) | XML root element of the API request |
| `diagnostic_status` | varchar(50) | See status table above |
| `http_status` | smallint | HTTP response code |
| `api_response_code` | varchar(100) | AN result/message code |
| `api_response_message` | text | AN human-readable message |
| `request_duration` | decimal(10,5) | Round-trip time in seconds |
| `request_body` | longtext | Sanitized XML request (already scrubbed by gateway) |
| `response_body` | longtext | Sanitized XML response |
| `transaction_saved` | tinyint(1) | 1 when transaction ID persisted to order |
| `payment_completed` | tinyint(1) | 1 when `payment_complete()` called |
| `final_order_status` | varchar(50) | WC order status at resolution time |
| `fatal_error` | longtext | Fatal error message if caught |
| `database_error` | longtext | DB error or gateway order note at failure |
| `created_at_gmt` | datetime | UTC timestamp of API event |
| `updated_at_gmt` | datetime | UTC timestamp of last row update |
| `resolved_at_gmt` | datetime | UTC timestamp when status became terminal |

---

## Settings (WP Option)

Stored as a single non-autoloaded option: `beeoch_authnet_diag_settings`.

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Master on/off switch |
| `short_retention_hours` | `24` | Hours to keep `completed` records |
| `long_retention_days` | `45` | Days to keep error/suspicious records |
| `enable_alerts` | `false` | Send email on suspicious events |
| `alert_email` | `''` | Recipient; falls back to site admin email |

---

## Hooks Used (read-only)

The plugin **only listens** — it never modifies gateway or WooCommerce data.

| Hook | Source |
|---|---|
| `wc_authorize_net_cim_credit_card_api_request_performed` | AN CIM gateway — fires after every API call |
| `wc_payment_gateway_authorize_net_cim_credit_card_add_transaction_data` | AN CIM gateway — fires when transaction ID is written to order |
| `wc_payment_gateway_authorize_net_cim_credit_card_payment_processed` | AN CIM gateway — fires when `payment_complete()` is called |
| `woocommerce_order_status_failed` | WooCommerce core |
| `woocommerce_order_status_on-hold` | WooCommerce core |

---

## Alert Emails

When alerts are enabled and a suspicious outcome is classified, `wp_mail()` is called with:

- **Subject:** `[{Site Name}] Authorize.Net Payment Alert: {Status Label} – Order {Order #}`
- **Body:** Status, order number, transaction ID, diagnostic record ID, created timestamp, and a direct link to the admin detail page.

Alerts fire only for: `approved_not_registered`, `transaction_saved_not_completed`, `fatal_error`.

---

## Cleanup Schedule

The `beeoch_authnet_daily_cleanup` job runs at 02:00 UTC (Action Scheduler preferred; wp-cron fallback). It deletes:

- `completed` records older than `short_retention_hours` (default 24 h).
- All error/suspicious records older than `long_retention_days` (default 45 days).
- Non-transaction API call records older than 4 hours.
- `pending` records older than 1 hour (unresolved verification).

---

## HPOS Compatibility

Declares `custom_order_tables` compatibility via `FeaturesUtil::declare_compatibility()`. All order reads use `wc_get_order()` rather than direct post-table queries.

---

## Safety Guarantees

- Every public hook callback is wrapped in `try/catch(\Throwable)`. This plugin **cannot** throw an exception into the payment flow.
- The plugin never modifies order data, gateway settings, or WooCommerce state.
- Database writes use `$wpdb->insert()` / `$wpdb->update()` with fully prepared queries.
- Request and response bodies are passed through as-is from the gateway (the gateway itself sanitizes credentials before firing the hook).
