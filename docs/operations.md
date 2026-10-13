# Operational edge cases (reviewed)

## 1. Sending hours that cross midnight (e.g. 22:00–06:00)
* **Behaviour:** a window must start before it ends (`from <= hour < to`). A window such as 22→6 is not supported and never opens, so
  nothing is sent. Every path says so: a manual run and the one-off SMS answer with the hours and the reason, and a campaign in
  "auto" mode shows a persistent warning (`window_invalid`).
* **Risk:** an owner who wants "night sending" saves 22→6 and nothing goes out. Wrapping would be worse: a swapped pair (21→9) would
  silently start sending promotional SMS overnight.
* **Decision:** keep failing closed. The Settings form now **refuses** such a window when it is saved, explains why, keeps what was
  typed, and suggests ending at 24 (`CRDR_Settings::window_errors`). A value saved by an earlier version is not touched; it keeps
  failing safe and explaining itself. Real overnight support would need an explicit product decision (and a night-time
  restriction for promotional SMS in the owner's country).

## 2. Claims left `pending` and recovered after 10 minutes
* **Behaviour:** every SMS first *claims* its customer (a unique key, so two processes can never both send). A claim older than 600 s
  that is still `pending` is recovered (`CRDR_Send::recover_stale`, called at the start of each run and each cron tick).
* **Why 600 s:** the real work between claiming and finishing is a coupon, one HTTP call (20 s timeout) and a database write, i.e.
  well under a minute; PHP's own limit is usually 30–60 s. Ten minutes leaves a wide margin before a claim is called abandoned.
  An answer that arrives late can still turn `unknown` into `ok`.
* **Risk (before):** a claim that died *before* the gateway was ever called was also turned into `unknown`, so that customer silently
  counted as contacted and never got the message.
* **Decision:** the text and the `localid` are recorded *before* the gateway is called, so a claim without a `localid` never
  reached it. Those become `failed` (nothing was sent; the customer may be tried again). Everything else stays `unknown` (never
  retried, counts as contacted). A slow process whose claim was recovered fails its own "record the text" step and sends nothing.
  The 600 s value is unchanged and its boundary is tested (599 s untouched, 601 s recovered).

## 3. WP-Cron does not run
* **Behaviour (before):** the automatic campaigns run from the hourly WordPress event `crdr_hourly`. If the scheduler does not
  run (WP-Cron switched off without a real cron, blocked loopback requests, a site nobody visits), nothing is recorded, because
  nothing runs: the campaign card just shows an old "last automatic run" date and no SMS goes out.
* **Risk:** high for an owner who relies on "auto": silent, and common on small hosts.
* **Decision:** WordPress keeps the time the next run was due; if that time stays in the past the scheduler is not running.
  The persistent warning (dashboard and plugin pages, administrators only, only when an automatic campaign exists) now says so:
  - `DISABLE_WP_CRON` is set (the host must run it): warn when 3 hours late;
  - WP-Cron: it needs visits, so a quiet shop is legitimately late for hours and the admin's own visit runs it; warn only when
    48 hours late.
  The text tells the owner what to set up (a real cron calling `wp-cron.php` every hour). No heartbeat or extra write is added.
* **Limits:** it cannot warn before the thresholds, and a site on WP-Cron with very low traffic may send late (no visits, no run);
  that is how WP-Cron works, and the 48-hour rule only reports a scheduler that visits do not wake up.

## 4. `crdr_run_log_lost` (what it means, how long it lives)
* **Meaning:** a running total of *override* entries (a person sent outside the sending hours) that had to be dropped because the
  audit class was full (300 entries). It is 0 on every normal shop. Routine cron activity can no longer push such entries out; only
  300 newer overrides can.
* **Retention:** it is never reset and never expires; it is deleted only on uninstall. It holds a number, no personal data.
* **Realistic risk:** low. One browser run writes one entry per step (a step is 20 customers), so 300 entries are about 6,000
  customers messaged outside the hours. The larger gap is that **nothing in the admin shows the run log or this counter at all**:
  the audit trail exists but is readable only in the database.
* **Decision:** no code change; a viewer is a UI feature, not a small fix. Recommended follow-up: a read-only "manual runs" page
  (who, when, which campaign, outside the hours or not, counts) that also shows this counter when it is above 0, and writing one
  entry per override *run* instead of one per step.

## 5. The customer-data rebuild ("تحلیل سفارش‌ها") and everything that sends
* **What it does:** `CRDR_Sync::start()` empties the customer, order and item tables and refills them over several requests
  (200 orders per step), then scores the customers and builds the analytics. While that runs the tables are incomplete, and
  customer row ids are handed out again.
* **The guard:** `CRDR_Sync::guard()` is *on* for the whole rebuild: phases `starting`, `orders`, `finalize`, `stats`, and also
  `failed`. Only `idle` (never run) and `done` are safe. The state is saved as "starting" **before** anything is emptied, and `done`
  is written only by the last successful step. While the guard is on, these are refused on the server: every campaign run
  (manual, cron), the campaign preview, every one-off SMS action (also opt-out / "called" / "skip", because they find the customer
  by row id), the call list, the customer list and customer page, and the CSV export. `CRDR_Send::claim()` and `send_claimed()`
  re-check it, so a rebuild that starts in the middle of a run stops that run at the next customer. `CRDR_SMS::deliver()` has exactly
  one caller (`send_claimed`), which a test enforces.
* **Failure:** an exception in any step, or a step found half applied later, turns the rebuild into `failed`: still blocking, never
  retried by itself, never "done". Recovery is always the same: press «تحلیل سفارش‌ها» (or wait for the nightly run), which rebuilds
  from scratch. A rebuild with no progress for 30 minutes is "stalled": it also still blocks, and the administrator sees a persistent
  warning.
* **One step at a time, and who owns the lock:** the lock is a row in the options table, created with `INSERT IGNORE`, and holds
  `time:token` (the token belongs to one acquisition). A running step **renews** it (`checkpoint()`: before every order, before every
  scoring chunk, before every table is emptied) whenever it is a minute old, so a legitimate step may run for hours and is never
  taken over; only a lock nobody renewed for 10 minutes (a dead step) can be taken over, by a compare-and-swap on the exact old
  value. Every later statement names the token: releasing deletes only our own value, a renewal fails if the value changed, and the
  rebuild state is written by one statement that also tests "is the lock still mine?", so a process that lost the lock cannot mark a
  rebuild done, nor undo the `failed` the new owner recorded. It stops (`CRDR_Sync_Lost`) at its next checkpoint, i.e. after at most the
  order or chunk it was in. Not guaranteed: a single unit that runs longer than ~9 minutes without a checkpoint (one order, one 500-row
  scoring chunk, or the analytics build, which only writes the derived statistics) can still be overtaken; the database then refuses
  its state write, and the other step simply repeats the idempotent phase. The lock times are PHP clocks: servers of one site must
  agree on the time to within a few minutes.
* **In flight:** a page is also marked "in flight" while it is being imported: a process that dies in the middle leaves the marker, and
  the next step then fails the rebuild instead of importing the page twice.
* **Orders:** pages are read in order of order ID (they were ordered by date, and orders created in the same second came back
  in a different order on each page, so one could be read twice and another never), and an order that comes back is not counted twice.
* **Orders that change while the rebuild runs:** pages are read by offset, so when an *earlier* order leaves or enters the counted
  statuses, everything after it moves one place and one eligible order is never read (reproduced on a real shop: 205 orders, one of
  the first cancelled between the two pages, the 201st was silently missing). Before anybody is scored, the `finalize` phase now reads
  the whole list of eligible order ids in ONE query and the list of imported ids, and imports what is missing (200 per step, at most
  25 steps). It guarantees: *when it finds nothing missing, every order that was eligible at the moment it read the list is in*. It
  does **not** give a snapshot: an order that is cancelled or refunded after it was imported stays counted until the next rebuild,
  and orders that change after the check are not in. If it cannot add an eligible order (one that cannot be loaded) or the store keeps
  gaining orders faster than it can check, the rebuild still ends, and the administrator is told that the numbers may be slightly low
  (`unresolved` / `unverified` in the state). Memory: the check holds all eligible order ids in memory (about 16 bytes each).
* **Customer ids:** every rebuild hands the customer row ids out again, so an open page can carry an id that now belongs to somebody
  else. Every per-customer button now sends, with the id, a *stable identity* (an HMAC of the customer key with the site's salt; no
  phone or e-mail appears in the page), and the server compares it with the identity of the row that id points to *now*. Anything
  else (no token, a made-up token, another customer, a customer that is gone, or a changed key) is refused with HTTP 409 and the
  message to reload the page, before anything is claimed, created or sent. This covers the one-off SMS (with and without a coupon),
  the preview, "called", "skip" and opt-out. A page opened before this change has no token and must be reloaded once.
* **Limits:** the rebuild still empties the tables first, so lists and dashboards are unavailable while it runs (shadow tables
  would fix that; not done). A person looking at the page of a customer opened from an old link sees whoever has that id now, but
  its buttons act on exactly that customer (the page carries the identity of the row it shows).

## 6. Who is one customer (identity), in one paragraph
Orders are first keyed one by one (phone, else email, else account, else the order itself; the erased-customer placeholder `deleted@site.invalid`
and all-zero phones count as "nothing"). In the `finalize` phase of every rebuild, customers shown to be the same person are **joined** (same
registered user, or the same non-shared email) and their orders and contact history (opt-outs, cooldowns) move to the joined customer's key, which
follows the latest phone. Joins are remembered in the option `crdr_identity_links` and never silently undone. Orders nobody can be identified from
stay in every revenue and order total, count as one-order customers, and cannot be messaged (no phone). The full rules, the ambiguous cases and the
fallback (`crdr_identity_email_max_phones` = 1) are in `docs/customer-identity.md`. After the first rebuild on this version, customers who were split
(one person under two or three keys) become one; their customer ids and keys can change, so an admin page left open across that rebuild is refused
as "stale" until it is reloaded.

## 7. The last opt-out check, and what a database error looks like (Phase 4.1)
* **Opt-out at the send boundary.** `CRDR_Send::claim()` checks opt-out and cooldown right after the customer is claimed. The message and the discount code
  are built *after* that (other plugins hook into coupon creation, which can be slow), and a person may click «پیام نده» in that gap.
  `CRDR_Send::send_claimed()` therefore asks the database once more, by the claimed row, immediately before `CRDR_SMS::deliver()`:
  - opted out -> the gateway is **not** called, the claim row is deleted (it was never a contact), the discount code made for that message is deleted
    (only a code this plugin made, for that very customer, never a used one), and a campaign counts the customer as *skipped*, not as a failure;
  - the database cannot answer -> the claim ends as `failed` (not a contact) and the run stops with `db_error`: *unknown* is never treated as *not opted out*.
  The same fail-closed rule applies at claim time (`CRDR_DB::blocked_reason()` returns `dberror`).
  What is not closed: an opt-out written after that last check and before the HTTP request leaves the server (milliseconds). No database can close that.
* **Database errors are shown, not hidden.** A run whose audience query fails returns `blocked = db_error` with a message (cron: a persistent notice and the
  campaign card), instead of "0 customers". The CSV export refuses to produce a file when the customer query or the opt-out lookup fails.
* **Schema checks** (`CRDR_DB::schema_problems()`): every table and column the plugin reads or writes, and the UNIQUE index on `crdr_contacts.claim_key`
  (the index is what makes "one send per customer" true). `install()` records the schema version **only** when all of it exists (a refused `ALTER` is
  silent in `dbDelta`). A failed attempt is remembered in `crdr_schema_error` and retried at most every 5 minutes, not on every request.
  A campaign run, a one-off SMS claim and a rebuild refuse to start on an incomplete schema, naming the missing `table.column` (no SQL), and the administrator
  sees a persistent notice on the plugin's screens. A rebuild that cannot start does not empty anything.
* **A lost contact table is never re-created empty by the plugin on its own** (not by a run, not by an update check): that would silently forget every opt-out.
  The message tells the owner to restore the database backup; deactivating and activating the plugin (an explicit act) creates an empty table, and the owner
  must then re-enter the people who do not want messages. The customer, order and item tables are derived data and *are* re-created (then rebuilt).
* **CSV export.** Every text column (name, phone, e-mail, segment) is defused against spreadsheet formulas, and the last column
  «عدم تمایل به پیام (نباید پیامک شود)» marks customers who opted out. They stay in the file on purpose (it is also used for analysis); filter on the column
  before using the file in an SMS panel. The export button says so.
* `CRDR_SMS::send()` (an old two-value wrapper that called the gateway without a claim, the rebuild guard or the opt-out check) was removed; it had no callers.

## 8. Opt-out phrase, inbound replies, and the first rebuild after an update (Phase 4.1)
* **Every promotional text must contain `{optout}`.** The campaign editor, the two call-list texts in Settings, "turn campaign on" and every send enforce it
  (`CRDR_Template::validate` with `require_optout`; the unit tests and `tests/release-safety.php` pin it). An empty opt-out phrase in Settings is refused when saved
  and, for a value saved by an older version, stops campaigns and one-off SMS *before anybody is claimed* (it is "a value that cannot be built", not an optional one).
  The default phrase is «لغو۱۱» (the Persian wording many SMS panels expect for promotional messages; **check the rule of your own SMS panel/operator**: the plugin
  cannot know it). Built-in campaign texts already contain it. Texts that were saved earlier without it are not rewritten; they stop sending until fixed.
* **What happens when a customer replies "لغو۱۱" (or any other text): the plugin does not see it.** It has no inbound endpoint, no webhook and no polling of the
  provider; it learns nothing from replies. Whether the reply is honoured is up to the SMS provider / operator (many panels handle the cancellation code on the sender line
  and stop further messages from that line to that number; that is the provider's behaviour, not something this plugin verifies or controls). If your provider reports
  such cancellations, **enter them yourself** with «پیام نده» on the customer (call list or customer page) so that the plugin stops too; it is written for every
  joined key of that person. The plugin's own «پیام نده» list only contains people somebody recorded there.
* **First rebuild after an update.** Updating from schema < 5 (every 1.0.0-era install) sets the option `crdr_rebuild_required`. While it is set,
  `CRDR_Sync::send_guard()` blocks everything that *sends or prepares a send*: campaign runs (manual and cron), the campaign preview, one-off SMS and its preview,
  and `CRDR_Send::claim()` / `send_claimed()`. Lists, customer pages, "called", "not today" and «پیام نده» keep working (an opt-out never waits). The administrator sees a
  persistent notice that says exactly what to press («تحلیل سفارش‌ها» in the dashboard; the nightly 03:00 run also does it). The flag is cleared only by a rebuild that
  **started after** the update was recorded and finished (a rebuild that began under the old version imported orders without the identity evidence, and a failed one clears nothing).
  Why it is needed: until then one person can still be two customers (two phones sharing an e-mail), and an opt-out recorded on one key has not been copied to the other.
  A first installation, and re-running the update, do not set it.

## 9. A copy of the shop must not send the real shop's automatic campaigns (Phase 4.2)
* **The risk.** A staging, local or restored-backup copy of the database carries this plugin's tables, its settings **with the SMS provider's API key** and any
  "automatic" campaign. WP-Cron on that copy would then send real SMS to real customers every hour.
* **What is recorded.** The site's own address, normalised by `CRDR_Site::normalize()` (host in lower case without `www.`, non-default port, path without a trailing
  slash; **the scheme is ignored**, so switching to https is not a move; query and fragment are dropped), in the option `crdr_site_identity` with who and when. It is written when the
  plugin is installed or updated on a site that has none (`CRDR_DB::install()`), and when an administrator confirms (below). **Nothing else ever writes it**: not a request, not
  a mismatch, not cron. An update installed on a copy *before* it was ever installed on the real site would record the copy's address; install the update on the real shop first.
* **What a mismatch does.** Automatic (cron) campaign runs are refused (`CRDR_Playbooks::run` for any trigger other than a person's click, and `hourly()`), the campaign
  is recorded as blocked with the reason `site_changed`, and an administrator notice (dashboard and the plugin's screens) shows both addresses and explains. **An identity
  that was never recorded counts as a mismatch** ("unconfirmed"): the safe side. No key, token or setting is shown in the notice or stored in the logs.
* **Confirming a legitimate move.** The notice carries a form with a button; it is an `admin-post` action (`crdr_confirm_site`) that needs **POST, a logged-in user with
  `manage_options` (an Administrator, not a shop manager) and a valid nonce**. It records the current address (with who, when, and the previous address) and re-enables automatic campaigns at
  the next tick. If this is a copy, do nothing: the pause stays.
* **Manual sending on a site whose address does not match needs a server-checked acknowledgement** (release freeze; before it, only the browser's `confirm()` stood in the way, which
  is not authorisation: anyone with `manage_woocommerce` could call `admin-ajax.php` directly). A manual send now needs **all** of: the `manage_options` capability (an Administrator; a shop
  manager is refused), the plugin's AJAX nonce, and a **one-time token** that the server issued to that user, for that one campaign (`pb:<id>`) or customer (`c:<identity>`), together with the
  preview that shows the warning (`CRDR_Site::issue_ack`). The token is random, only its SHA-256 is the key of a row in the options table (`crdr_ack_<hash>`), valid 10 minutes, bound to the user,
  the target and the site address, and **spent by one `DELETE`** right before the send (after every other check has passed, so a refusal for another reason does not use it up); a replay finds
  nothing to delete and is refused. A campaign runs in steps of 20 customers: each answer carries the next token. Both entry points enforce it: `crdr_pb_run` (through `CRDR_Playbooks::run`) and
  `crdr_contact` (`ajax_contact`); the two previews issue the tokens (a shop manager's preview is refused with the reason and receives none). **Cron and any trigger other than a person's click
  never accept a token** and stay refused. Recording an opt-out, a call or "not today" is not a send and is not affected. **Every other guard still applies** (opt-out, schema, rebuild,
  sending hours, daily cap). There is no reset of the stored identity: the only way to make a site "the recorded one" is still the administrator's confirmation below.
* **Limits.** Not detected: a copy reached through the *same* address (a local copy using the hosts file), and an identity edited by hand. **Multisite is not supported**
  by this plugin; the identity is the current site's own address and a network activation is not tested.

## 10. Backups, restores and moving a site (Phase 4.2)
What lives where, so a backup or a move does not surprise anybody:

| What | Where | Note |
|---|---|---|
| customers, orders, order items | tables `crdr_customers`, `crdr_orders`, `crdr_order_items` | **derived** from WooCommerce orders: the rebuild ("تحلیل سفارش‌ها") recreates them. Losing them loses nothing that WooCommerce does not hold. |
| SMS history, calls, "not today", **opt-outs** | table `crdr_contacts` | **not derivable.** This is the only table that is the single copy of something: who asked not to be messaged, and what was sent when (the cooldown and the campaign results). |
| settings (incl. the SMS provider key), campaigns, site identity | WordPress options | restored with the database. |
| plugin coupons | WooCommerce coupons (posts) | restored with the database. |

* **Back up the database, not the plugin folder.** The ZIP contains code only. A restore of the plugin files alone brings back nothing.
* **Restoring an older database loses opt-outs recorded after that backup.** Customers who unsubscribed after the backup are messageable again, and messages sent after it are
  forgotten by the cooldown. Before restoring over a live shop: (1) export the customer file from the Customers page (the last column marks the people who opted out) and keep it;
  (2) after the restore, record those people again as "do not message" before sending anything, and keep automatic campaigns paused until then. The plugin cannot do this by itself.
* **Two different protections, do not confuse them.**
  1. *Same-version restore (a backup of the same plugin version put back):* **there is no guard.** The plugin does not notice that a database was restored. It does not block sending, it does not
     ask for a rebuild, and it cannot know which opt-outs were recorded after the backup. Everything in the previous point applies: **opt-outs recorded after the backup are lost** and those
     customers can be messaged again until somebody records them again. The customer table restored from the backup is as old as the backup; run the rebuild ("تحلیل سفارش‌ها") yourself
     before sending.
  2. *Update from a legacy schema (before schema 5, i.e. every 1.0.0-era install):* the plugin sets `crdr_rebuild_required` and blocks everything that sends or prepares a send until a
     rebuild that started after the update has finished (section 8). That guard exists only for this upgrade path: it reacts to a recorded schema version below 5, nothing else. It is **not**
     triggered by restoring a backup of the current version (the schema version in that backup is already current).
  The one thing that does react to a restore is the site address (section 9), and only if the restored copy runs on a different address.
* **Moving the shop to a new address or host** (domain change, new server): move the whole database, then open the plugin. Automatic campaigns stay paused until an
  Administrator confirms the new address (section 9). Opt-outs, history and settings come with the database. Run the rebuild once. If the old site still exists and is online,
  **disable its SMS key or deactivate the plugin there**: two live shops sharing one history is the one situation this plugin cannot detect.
* **A copy for testing** (staging, local) is protected by the same pause (section 9), but also **replace the API key in its settings** with a test key or none: the copy holds the real one.
* **Opt-outs are never removed by the plugin** (not by a rebuild, not by the retention sweep, not by uninstall, not by the privacy eraser unless the filter `crdr_privacy_keep_optout`
  says otherwise). They are stored as the raw customer key `p:<phone>`; this was kept for this release as a provisional decision (docs/data-retention.md, "Suppression list").
* **Copies the plugin cannot reach.** Database backups, WordPress exports and the SMS provider's own logs keep what they kept when they were made. With the free
  Persian-WooCommerce-SMS plugin, every message is also copied into **its** table `woocommerce_ir_sms_archive` (recipient and text, no limit, no eraser); see docs/data-retention.md,
  "Copies outside this plugin". Erasing a person here does not erase those copies.
