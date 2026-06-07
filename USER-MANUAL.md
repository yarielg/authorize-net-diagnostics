# BEE-OCH Authorize.Net Diagnostics — User Manual

**Plugin version:** 1.0.0  
**Audience:** Store administrators, site managers

---

## Overview

This plugin adds a diagnostic log to your WooCommerce store that records every Authorize.Net credit card transaction attempt. It tells you exactly what happened at each step of the payment flow — whether the charge was approved, whether WooCommerce received and saved the transaction ID, and whether the order was properly marked as paid.

The most important thing it detects is an **"Approved / Not Registered"** event: a situation where Authorize.Net charges the customer's card but WooCommerce never records the payment, leaving the customer charged with no fulfilled order.

The plugin is **passive** — it only observes. It never modifies orders, payment settings, or the gateway.

---

## Finding the Plugin

In your WordPress admin dashboard, go to:

**WooCommerce > Authorize.Net Diag**

The page has three tabs: **Records**, **Health Check**, and **Settings**.

---

## The Records Tab

This is the main log view. Each row represents one Authorize.Net API transaction attempt.

### Columns

| Column | What it shows |
|---|---|
| **Date (GMT)** | When the API event was captured. Hover to see the exact UTC timestamp. |
| **Order** | WooCommerce order ID. Click to open the order. |
| **Order #** | Public-facing order number shown to the customer. |
| **Transaction ID** | Authorize.Net transaction ID, when available. |
| **Request Type** | The type of API call (typically `createTransactionRequest`). |
| **HTTP** | HTTP response code. Green = 200 OK; red = error. "Network Error" means no response was received. |
| **AN Result** | Authorize.Net result code and message (e.g., `I00001 — This transaction has been approved`). |
| **Saved** | Checkmark if the transaction ID was written to the WooCommerce order. |
| **Paid** | Checkmark if WooCommerce's `payment_complete()` was called for the order. |
| **Status** | The diagnostic classification (see Status Reference below). |

### Status Reference

| Status | Color | What it means |
|---|---|---|
| **Completed** | Green | Payment fully successful — transaction saved and order marked paid. |
| **Pending** | Grey | Approved charge captured; waiting for 2-minute background verification. Normal — should resolve quickly. |
| **Declined** | Orange | The card was declined by Authorize.Net. No charge was made. |
| **API Error** | Red | Authorize.Net returned an error code or an HTTP 4xx/5xx response. |
| **Network Error** | Red | The store received no response from Authorize.Net (timeout, DNS failure, etc.). |
| **On Hold** | Orange | The order was placed on hold during the payment flow. |
| **Failed** | Red | The order was marked failed during the payment flow. |
| **Approved / Not Registered** | Red | **Critical.** Authorize.Net charged the card, but WooCommerce did not save the transaction ID or mark the order paid. Requires immediate investigation. |
| **Saved / Not Completed** | Orange | The transaction ID was saved to the order, but `payment_complete()` was never called. The order may be stuck in a non-paid status. Investigate manually. |
| **Fatal Error** | Red | A PHP fatal error occurred during the payment request. |

### Filtering

Above the list, click any status label to filter to that category. The most important filter to monitor regularly is **Approved Not Registered**.

### Searching

Use the search box to find records by:
- WooCommerce order ID (e.g., `1234`)
- Public order number (e.g., `ORD-1234`)
- Authorize.Net transaction ID

### Sorting

Click the **Date**, **Order**, **Transaction ID**, or **HTTP** column headers to sort ascending or descending.

### Bulk Delete

Check rows and select **Delete** from the bulk actions dropdown to remove records manually. Records are also cleaned up automatically on a daily schedule.

---

## The Detail View

Click the **Details** link under any order ID to open the full record for that transaction. This page shows:

- **Diagnostic Status** badge
- **Timeline** — a chronological list of the events that occurred: when the API event was recorded, whether the transaction ID was saved, whether `payment_complete()` was called, and when the record was resolved.
- **Details table** — every field, including the internal order ID, public order number, transaction ID, request type, HTTP status, round-trip duration, Authorize.Net result code and message, and timestamps.
- **Sanitized Request Body** — the XML sent to Authorize.Net (credentials have already been removed by the gateway before this plugin ever sees the data).
- **Sanitized Response Body** — the XML returned by Authorize.Net.
- **Fatal Error** — if a PHP fatal error was caught, the error message appears here in red.
- **Database Error** — any database error captured during the transaction, or the gateway error note added to the order.

---

## The Health Check Tab

This tab gives you a real-time snapshot of whether the plugin is working correctly. Review it after first installing the plugin and whenever something seems wrong.

### Sections

**Plugin Status**
- *Diagnostics enabled* — confirms the master on/off switch is active.
- *API capture hook registered* — confirms the plugin is listening to the gateway's API hook. If this shows an error, the Authorize.Net CIM gateway may be inactive. Deactivate and reactivate this plugin while both WooCommerce and the gateway are running.

**WooCommerce & Gateway**
- *WooCommerce active* — WooCommerce is loaded.
- *AN Gateway class exists* — the `woocommerce-gateway-authorize-net-cim` plugin is active.
- *AN Credit Card gateway enabled in WC* — the gateway is turned on in WooCommerce > Settings > Payments.

**Database**
- *Diagnostics table exists* — the plugin's database table was created successfully.
- *Total rows / Rows in last 24h / Rows in last 7 days* — record volume at a glance.
- *Last event recorded* — when the most recent transaction was captured and what its status was.

**Queue & Scheduling**
- *Action Scheduler available* — the preferred scheduling system (included with WooCommerce) is active. If not, the plugin falls back to wp-cron.
- *Pending verification jobs* — the number of approved charges currently waiting for their 2-minute verification. A non-zero count is normal during active checkouts; a large or growing number suggests cron/Action Scheduler is not running.
- *Next daily cleanup* — when the next automatic record cleanup will run.

**Suspicious Events**
- *Unresolved suspicious records* — count of "Approved / Not Registered" records. Any number above zero is a direct link to those records and warrants immediate review.
- *Records stuck in pending (> 5 min)* — approved charges whose verification job has not run after 5 minutes. This indicates a scheduling problem (Action Scheduler queue stalled or wp-cron not executing).

### Database Write Self-Test

Click **Run DB Self-Test** to insert a synthetic test row. If the test passes, the database write path is confirmed working. The test row is labelled `SELFTEST` and will be removed automatically on the next daily cleanup run.

### Interpretation Guide

The table at the bottom of the Health Check page explains each error condition and what to do about it:

| Symptom | Action |
|---|---|
| Hook not registered | Deactivate and reactivate this plugin while WooCommerce and the AN gateway are both active. |
| No records after a transaction | Run the DB Self-Test. If it passes but real records don't appear, the hook fired but no DB write happened — check that diagnostics are enabled in Settings. |
| Records stuck in "pending" | Action Scheduler or wp-cron is not processing jobs. Check WooCommerce > Status > Scheduled Actions or your hosting cron configuration. |
| `transaction_saved = ✓`, `payment_completed = ✗` | The transaction ID was saved but `payment_complete()` was not called. Investigate the order manually — it may be stuck. |
| "Approved Not Registered" | Authorize.Net charged the card but WooCommerce has no record of it. Check the order in WooCommerce, contact Authorize.Net if a refund is needed, and investigate PHP error logs for the fatal or database error that caused the gap. |
| Self-test passes but no real records | DB writes work but the gateway hook is not firing. Confirm the AN gateway is the active payment method and that real checkout attempts are being made. |

---

## The Settings Tab

### Enable Diagnostics

Master on/off switch. When disabled, no new records are created. Existing records remain in the database.

### Keep Successful Records

Number of **hours** to retain `completed` (fully successful) records. Default: **24 hours**.

Increase this if you want a longer window to review successful transactions. Decrease it if you want the log to stay small.

### Keep Suspicious/Error Records

Number of **days** to retain records with an error or suspicious status (`Approved Not Registered`, `Fatal Error`, API errors, declined, etc.). Default: **45 days**.

These records are kept much longer than successful ones because they may be evidence of a billing problem.

### Enable Alert Emails

When checked, the plugin sends an email notification whenever a suspicious outcome is detected: `Approved / Not Registered`, `Saved / Not Completed`, or `Fatal Error`.

### Alert Recipient

The email address to send alerts to. Leave blank to use the site's admin email address (the one set in **Settings > General**).

---

## Automatic Record Cleanup

Every day at approximately 02:00 UTC, the plugin automatically deletes old records according to your retention settings:

- **Successful records** (`completed`) older than the "Keep Successful Records" setting (default: 24 h)
- **Error and suspicious records** older than the "Keep Suspicious/Error Records" setting (default: 45 days)
- **Non-transaction API calls** older than 4 hours
- **Stuck pending records** older than 1 hour (verification job never ran)

You can also delete records manually from the Records tab at any time.

---

## Alert Emails

If alert emails are enabled, you will receive a message like this for any suspicious event:

```
Subject: [Your Store] Authorize.Net Payment Alert: Approved Not Registered – Order 1234

A payment event requires your attention.

Status:          Approved Not Registered
Order Number:    1234
Transaction ID:  60179204556
Diagnostic ID:   42
Created (GMT):   2026-06-07 14:23:11

Review details: https://yourstore.com/wp-admin/admin.php?page=beeoch-authnet-diagnostics&record_id=42

-- Your Store
This is an automated alert from the BEE-OCH Authorize.Net Diagnostics plugin.
```

Click the "Review details" link to go directly to the diagnostic record.

---

## What to Do When You See "Approved / Not Registered"

This status means Authorize.Net successfully charged the customer's card, but WooCommerce did not record the payment. The customer has been charged but their order may not be fulfilled.

**Immediate steps:**

1. Open the WooCommerce order linked in the diagnostic record. Check its current status.
2. If the order is in a pending or failed state but the customer was charged, you can manually mark it as paid from the order edit screen.
3. Open the diagnostic record detail page and look at the **Fatal Error** and **Database Error** fields for clues about what went wrong.
4. Check your PHP error logs for any fatal error that occurred around the same time.
5. If you cannot confirm the charge was reversed by Authorize.Net, contact Authorize.Net support with the Transaction ID to verify the charge status and issue a refund if necessary.

---

## Frequently Asked Questions

**Does this plugin slow down checkout?**  
The overhead per transaction is very small — two XML parses (under 2 ms each) and one database insert (approximately 3 ms). All callbacks are non-blocking and wrapped in try/catch to guarantee they cannot delay or fail a payment.

**Does this plugin store credit card numbers?**  
No. The gateway scrubs credentials and card data before firing the hook this plugin listens to. The request and response bodies stored in the log contain only transaction metadata.

**Can I use this without WooCommerce Action Scheduler?**  
Yes. Action Scheduler is included with WooCommerce 3.6+ and is preferred because it is more reliable. If it is unavailable, the plugin automatically falls back to wp-cron for both the verification job and the daily cleanup.

**What happens if I deactivate the plugin?**  
The scheduled cleanup job is removed. The database table and all existing records are preserved. Reactivating restores all functionality.

**What happens if I delete the plugin?**  
The plugin does not include an uninstall hook that drops the table. To fully remove the plugin and its data: deactivate it, then manually drop the `{prefix}beeoch_authnet_diagnostics` table and delete the `beeoch_authnet_diag_settings` and `beeoch_authnet_diag_db_version` options from your database.

**The Health Check shows "Hook not registered." What should I do?**  
The Authorize.Net CIM gateway was not active when this plugin loaded. Deactivate this plugin, make sure WooCommerce and the `woocommerce-gateway-authorize-net-cim` plugin are both active, then reactivate this plugin.
