# What the plugin keeps about messages (and why)

The plugin keeps personal data in five places (the table below). Message history is in `{prefix}crdr_contacts` (one row per contact
attempt). Only an administrator (capability `manage_woocommerce`, which a shop manager also has) can see any of it. This document describes what the
code does; it is not legal advice and it does not claim that any law is met.

## Where personal data is stored (complete list)
| Where | What | Created by | Removed by |
|---|---|---|---|
| `crdr_customers` | name, phone, e-mail, order count, spend, dates (one row per customer) | every rebuild (derived from WooCommerce orders; emptied and refilled each time) | rebuild; uninstall; privacy eraser |
| `crdr_orders` | order id, customer key, date, net amount, **billing e-mail and account id** of the order (the evidence for joining the same person, since schema 5) | rebuild | rebuild; uninstall; privacy eraser |
| `crdr_order_items` | product ids per order (no personal data by itself) | rebuild | rebuild; uninstall; privacy eraser |
| `crdr_contacts` | every SMS (message text incl. the customer's name, discount code, status), call note, "not today", opt-out; keyed by **customer key** `p:<phone>` / `e:<e-mail>` / `u:<user id>` / `o:<order id>`; `user_id` = the staff member who acted; `orphan_ts` = bookkeeping of the retention sweep | sending, buttons, rebuild (opt-out copies) | **retention sweep** (text only, after 90 days without a customer); uninstall (see below); privacy eraser (opt-out rows stay by default) |
| option `crdr_identity_links` / `crdr_identity_apart` | pairs of customer keys (phones / e-mails) that were joined, or kept apart | rebuild; `unjoin()` | uninstall; privacy eraser |
| WooCommerce coupons made by the plugin | the **customer key in the coupon description and hidden meta `_crdr_ckey`**, and the customer's e-mail as the coupon's e-mail restriction | sending with a discount code | privacy eraser; uninstall leaves them (see below) |
| options: settings, sync state, logs, health | the Kavenegar API key (settings); the rest are counters and codes only, never phone numbers or text | settings, runs | uninstall |
Not stored by the plugin: card data, passwords, IP addresses, the SMS provider's raw answers.

**Where data is sent:** to the SMS service you chose. With Kavenegar (direct) the **recipient's phone number and the message text** go to Kavenegar
(`api.kavenegar.com`); with the free "Persian WooCommerce SMS" plugin they go to whatever service that plugin is configured with. The plugin makes no
other outgoing request. (Data does not leave the host when no SMS is sent.)

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

## Records whose customer is gone: the retention sweep (Phase 4.2)
**The problem.** WooCommerce can anonymise old orders by itself (WooCommerce > Settings > Accounts & Privacy). That runs on its own schedule and does
**not** call the WordPress privacy hooks. After the next rebuild the customer of those orders disappears from this plugin, but the rows of
`crdr_contacts` written for that customer (the SMS text with the person's name, the discount code, the staff id) used to stay for ever, and no screen
could reach them. It was reproduced on a scratch shop before it was fixed.

**What happens now** (`CRDR_Retention`, called at the end of a rebuild that finished successfully and nowhere else):
1. *Mark.* A contact row (any kind except an opt-out) whose customer key has no row in `crdr_customers` gets `orphan_ts` = the time that was first seen.
   If the customer comes back (a new order with the same phone / e-mail) the mark is removed.
2. *Sweep.* Rows marked for **90 days** or more lose **message, error text, discount code, staff id, run id and gateway id**. Nothing else changes.
3. A rebuild that fails, is interrupted or stalls never reaches this step. A rebuild that finds **no customer at all** (an empty store, or order statuses that
   count nothing) skips it, so an empty table can never turn every row into an "orphan". A database error inside the sweep is recorded as a code in the
   option `crdr_retention_state` and does not fail the rebuild. A very large backlog is finished by the following rebuilds (5,000 rows per statement, 40 statements per rebuild).

**What stays on a swept row, and for how long.** The customer key, kind, campaign, group, status and time. **For a customer with a phone the key is
`p:<phone number>`, so the phone number stays in the row.** It stays because the cooldown ("recently contacted", up to 365 days) and the campaign results are computed from
these columns, and because the person may order again with the same number, when the cooldown must still hold. These rows are **not** aged out automatically; they
go with the privacy eraser (when the e-mail can be tied to them), with the full-erasure uninstall, or by hand. **Opt-out rows are never marked, swept or changed.**
Coupons are WooCommerce objects and are not touched by the sweep: a plugin coupon keeps its description and hidden customer key until the person is erased or the
coupon is deleted.

**Privacy eraser: what it reports (Phase 4.2).** It answers with what it located and then verified, not with what it hoped to do:
* `items_removed` is true only if rows or coupons it located for that person were really deleted or scrubbed;
* `items_retained` is true if it kept something on purpose (opt-outs, a send in flight, a phone number shared with another customer), if a row it deleted is still
  there afterwards, if a database statement failed, **and if it could not attribute anything to the address while contact rows of customers who no longer exist are
  around** (it says how many, and that their text is minimised by the sweep after 90 days);
* the person is found from the orders, from the keys joined to them, and from the plugin's own discount codes that are restricted to that e-mail (a code carries the
  customer key it was made for, which is how a phone key can still be tied to an e-mail after the orders were anonymised). With no code and no order, nothing can tie an e-mail
  to a phone key, and the eraser says so instead of claiming success.

## Copies outside this plugin (Phase 4.2)
This plugin deletes only what it wrote. These copies exist elsewhere and are **not** reached by uninstall, the retention sweep or the privacy eraser:
| Copy | Holds | Who can remove it |
|---|---|---|
| **Persian-WooCommerce-SMS** table `woocommerce_ir_sms_archive` (only when messages are sent through that plugin) | recipient number and full message text of **every** SMS it sent, with no retention limit; as far as its published source shows, no WordPress exporter or eraser | the site owner, with that plugin's own tools or directly in the database. Include it in any erasure request and in the shop's privacy policy. |
| **SMS provider** (Kavenegar or the service behind the other plugin) | numbers, texts, delivery reports, under the provider's terms | the provider (account panel / support) |
| **Database backups**, server snapshots, exported dumps | everything above, as of the backup date | the owner; erased data reappears if an old backup is restored (docs/operations.md section 10) |
| **WooCommerce orders** | the orders themselves | WooCommerce's own eraser and retention settings; this plugin only mirrors them at the next rebuild |
The suggested text this plugin adds to WordPress's Privacy Policy Guide (`CRDR_Privacy::policy_text`) says, in plain words (release freeze): numbers and texts go to the chosen SMS service; **the other SMS plugin archives recipient and text itself, and this plugin neither reads, erases nor controls that archive**; swept contact rows (text gone) may still hold the phone number; restoring an older backup brings back people who opted out afterwards; the CSV export holds at most 50,000 customers and includes opted-out people (marked in the last column, no filter to drop them). The same facts are in `readme.txt` (privacy summary and FAQ) and in the marketplace listing. It still does not describe the provider's own logs; whether the shop's own policy covers them is the owner's call, and the text is marked as a starting point, not legal advice.

## Uninstall (Phase 4.1: the default changed)
Deleting a plugin is not a request to forget people. Before, deleting the plugin dropped every table, including the list of people who asked
not to be messaged; a shop that "updated" by deleting the plugin and uploading a new ZIP started messaging them again. Now:

| | Default (nothing ticked) | After you tick "erase everything" in Settings > "حذف افزونه و داده‌ها" **and save, before deleting** |
|---|---|---|
| Customer, order and item tables | removed | removed |
| `crdr_contacts` | **kept, but only the opt-out rows**, each reduced to *who* (customer key) and *when* (no text, no staff id, no coupon, no error) | removed |
| SMS history, call notes, cooldowns | removed | removed |
| Options (settings with the Kavenegar key, remembered joins, logs, state), transients, scheduled and queued work | removed | removed |
| WooCommerce coupons the plugin made | **untouched** (customers may still hold the code); they still carry the customer key in the description and hidden meta | description and hidden customer key are removed; code, amount, expiry and e-mail restriction stay (they make the code work) |

Why the opt-out rows stay: they are the only record that stops an SMS to a person who asked not to get one. What they contain: the customer key
(`p:<phone number>` for a customer with a phone, so **a phone number remains in the database**), the time, and the word "opt-out". Whether that
representation should be replaced by a keyed hash is evaluated below and is **waiting for your decision**; until then this is the behaviour.

**To erase everything on purpose**
1. *While the plugin is still installed:* Settings > "حذف افزونه و داده‌ها" > tick the box > save > delete the plugin. Everything above is removed and the opt-out list cannot be recovered.
2. *After a default uninstall* (the opt-out table is still there): either re-install the plugin, tick the box, delete it again; or, without the plugin, run in the database (replace `wp_` with your prefix):

       DROP TABLE IF EXISTS wp_crdr_contacts;

   (WP-CLI: `wp db query "DROP TABLE IF EXISTS wp_crdr_contacts"`.) Take a backup first: this is permanent.
3. Coupons: delete the discount codes in WooCommerce if you want them gone too; or let the full-erasure path (1) scrub their description and hidden key.

**After a default uninstall, re-installing**: activate the plugin again; the kept opt-out rows are enforced at once (sending, audience, the CSV
column). Settings, templates, campaigns and the Kavenegar key must be entered again. Run «تحلیل سفارش‌ها» before sending.

Deactivating the plugin keeps all data. Multisite is **not supported or tested**: uninstall acts on the current site only; do not network-activate.

## Privacy: export and erasure (WordPress Tools > Export / Erase Personal Data)
Registered exporter and eraser "رادار مشتری" (`CRDR_Privacy`). The person is found by the e-mail address: the customer keys of the orders that carry that
billing e-mail (or that registered account), the keys joined to them by the identity rules, `e:<address>` and `u:<user id>`. A key that other
people's orders were also attributed to (the same phone number used with another e-mail) is **shared and is left alone** (reported as retained).

| | Exporter | Eraser (exclusive keys) |
|---|---|---|
| customer row, orders, items | listed | deleted at once (not only at the next rebuild) |
| contact rows (SMS with the text, calls, "not today") | listed | deleted, except a send that is still in flight (reported; run again) |
| opt-out rows | listed | **kept by default**, reduced to who and when; filter `crdr_privacy_keep_optout` = false deletes them (the number can then be messaged again) |
| remembered joins / kept-apart keys | listed | removed (other members of a group stay joined) |
| plugin coupons | listed | unused: deleted; used: kept for the accounting without description, hidden key, e-mail restriction and this person in "used by" |
| coupons the shop made by hand | not listed | not touched |
It refuses (and says so) while a rebuild is running or another step holds the sync lock. WooCommerce's own eraser decides what happens to the orders:
if WooCommerce keeps the orders, the next rebuild recreates the customer from them (this plugin mirrors what WooCommerce keeps), without the deleted history;
the opt-out stays either way. Erasing again is harmless. The aggregated statistics (`crdr_stats`) are recomputed at the next rebuild and hold no personal data.

## Suppression list: should the opt-out be a keyed hash instead of the phone number? (evaluation)
**Decision (maintainer, Phase 4.1 review): Option A, keep the raw customer key `p:<phone>` in the opt-out rows, for this release candidate. This is a provisional privacy decision, not approval to release; HMAC is not implemented.**
Asked: can an opt-out be enforced without keeping the raw phone number, using HMAC(site secret, normalised phone)?
Prototype results (`CRDR_Identity::phone()` + `hash_hmac('sha256')`):
* **Enforcement works for phone numbers**: every format of a number (`09…`, `+98…`, Persian digits, `0098…`) normalises to one id; different numbers give different ids.
  The recipient of an SMS *is* a phone number, so a phone-keyed suppression is the right unit.
* **It is pseudonymous, not anonymous.** The Iranian mobile space is 10^9 numbers; one HMAC takes about 2 microseconds on one core, so whoever holds the key can
  test every number in about half an hour on one core (seconds on a GPU). The key (`wp_salt`) sits in `wp-config.php` next to the database password. The hash
  therefore only protects a *database-only* leak (a backup, an SQL injection that cannot read files); it does not make the number unrecoverable.
* **Key management is the real policy decision.** If the id is derived from `wp_salt()`, a salt rotation (common after a security incident) or restoring the
  database on another site silently turns every stored id into noise: *all opt-outs would stop working*. That can be detected (store a check value and refuse to
  send when it no longer matches), but then the list is unusable and has to be re-entered. If instead the plugin generates and stores its own key, it survives
  rotation, but it then lives in the same database as the hashes, so a database leak reveals both and the hash protects nothing.
* **Non-phone keys:** an opt-out recorded under `e:<e-mail>` or `u:<id>` has no phone to hash. To keep protecting a person who is later joined to a phone, the
  e-mail (or its hash) must be kept as well.
* **Code impact (not done):** opt-outs are matched by customer key in SQL (`CRDR_DB::exclusions_sql`, `blocked_reason`, `optout_keys`, the identity step's
  opt-out propagation, the CSV column). HMAC cannot be computed in SQL, so the customer table would need a stored hash column (itself pseudonymous personal data) or
  the audience would be filtered in PHP.
**Conclusion:** safe enforcement without the raw number is possible, but it needs an explicit choice of key custody (fail-closed on a lost salt vs. a stored key
that protects nothing), a schema change and a rewrite of the opt-out matching. That is a privacy-policy decision, so it was **not** implemented and the
raw customer key stays in the opt-out rows (minimised as described above). Options: (A) keep as is; (B) HMAC from `wp_salt` with a fail-closed check;
(C) HMAC from a plugin-stored key. Say which and the change is a contained piece of work with its own tests.

## Not done (recommendation)
Texts of `ok` and `unknown` rows of customers who still exist are kept indefinitely (until the privacy eraser, or an uninstall, removes them). If the shop's
privacy policy needs a limit, a scheduled purge of `message` older than N days is a small follow-up (the results and cooldown do not use the text). It was not
added because the right period is a decision for the store owner. Rows of customers who no longer exist are minimised after 90 days (section above).
