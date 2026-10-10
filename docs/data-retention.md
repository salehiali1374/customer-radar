# What the plugin keeps about messages (and why)

Everything is in the table `{prefix}crdr_contacts` (one row per contact attempt) plus a few options. Only an administrator
(capability `manage_woocommerce`) can see any of it.

## Who reads the stored message text (`contacts.message`)
| Reader | What it shows | Needs the text? |
|---|---|---|
| Campaign page → "آخرین ارسال‌ها" | status, reason, and the message column | only for rows that may have been sent |
| Customer page → contact history | each contact with its text under it | only for rows that may have been sent |
| Export (CSV) | customers only, **no contacts** | – |
| Run log / cron log / health state | counts and reason codes only, never text (whitelisted in `CRDR_Send_Rules::log_clean`) | – |
| Analytics, results, cooldown, daily cap | use status and time only | no |

## Decision, by status
| Status | Nothing/something was sent | Text kept? | Why |
|---|---|---|---|
| `ok` | accepted by the SMS service | **yes** | the record of what the customer was sent (customer-service questions) |
| `unknown` | may have gone out | **yes** | troubleshooting: the only way to know what may have been sent |
| `pending` | in flight | yes, until the outcome is recorded | the row must be writable before sending (a safety rule), and a crashed run becomes `unknown` |
| `failed`, `retry`, `nobuild`, `rejected` | **nothing was sent** | **no, cleared** | the reason is enough to act on; a text nobody received is not needed |
| `holdout`, `norec` | nothing was sent | never stored | – |

Also kept, deliberately: the discount code (`coupon`) of a message that was not sent, so the unused code can be found and
removed in WooCommerce. It is a short code, not message content.

Errors (`contacts.error`) are the plugin's own Persian wording plus the provider's status code. The provider's raw answer,
raw error text of another plugin, the API key and phone numbers are never stored; every error passes through
`CRDR_Send_Rules::redact()`. A network error is reduced to its cURL number.

Not changed (and why): the customer key is `p:<phone number>` for customers with a phone, so a phone number is part of every
row's identity. Removing it would break the customer identity itself; it is out of scope here.

## Upgrade (database version 3 → 4)
* **No schema change.** Only data is touched, once, on the first request after the update (`CRDR_DB::maybe_upgrade()`
  → `install()` → `minimize_stored()`); the statements are idempotent, so running them again changes nothing.
* It **clears `message` on rows whose status is `failed`, `retry`, `nobuild` or `rejected`** (SMS that were never sent; version
  1.0.0 stored their text) and **normalises `error` texts** of at most the 5,000 newest rows that have one: old
  "کاوه‌نگار (411): <provider text>" becomes the plugin's own wording, and keys / phone numbers are removed.
* This is **irreversible**: the cleared texts cannot be restored from the plugin. It does not touch `ok`, `unknown` or `pending`
  rows, counts, times, customers, orders, coupons or settings.
* Going back to an older plugin version works: it simply finds empty text on those rows.
* The tests run it only on disposable data. Nothing is run against a real store by the test suite.

## Uninstall
Deleting the plugin from the Plugins screen drops all `crdr_*` tables (including `crdr_contacts`, i.e. every stored message) and
all `crdr_*` options (settings, logs `crdr_run_log` / `crdr_cron_log`, the counter `crdr_run_log_lost`, health state, the
set-aside times). WooCommerce coupons that were already created are left in place on purpose. Deactivating the plugin keeps all data.

## Not done (recommendation)
Texts of `ok` and `unknown` rows are kept indefinitely. If the shop's privacy policy needs a limit, a scheduled purge of
`message` older than N days is a small follow-up (the results and cooldown do not use the text). It was not added because the
right period is a decision for the store owner.
