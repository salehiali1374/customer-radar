# Analytics characterization and regression tests

    php tests/analytics.php

No WordPress, no WooCommerce, no database, no network, nothing is written. In the Docker image used for the other suites:

    docker run --rm --network none -v "$PWD":/p:ro wordpress:6-php8.3-apache sh -c 'cd /p && php tests/analytics.php'

Developed and run on PHP 8.3 with `mbstring` (the plugin itself declares PHP 7.4+; the suite has not been run on 7.4). Needs timezone data from 2022b or newer (Asia/Tehran has had no daylight saving since 2022; the
suite checks this first). Exit code 0 means: no `FAIL` and no `XPASS`.

## The five labels

| Label | Meaning |
|---|---|
| `PASS` | An ordinary check of behaviour that is correct, or of the harness and fixtures. |
| `CHAR` | **Characterization.** Pins what the plugin does *today*, even where that is wrong. The name of each pin says what is wrong. If it fails, behaviour changed: update the pin only if the change was intended. |
| `XFAIL` | **Known defect (`DEFECT`) or open product decision (`DECISION`).** Asserts the *correct* behaviour and is **expected to fail today**. Listed at the end of every run with the value seen today. |
| `XPASS` | An `XFAIL` test that now passes: the defect was fixed. The run fails until the test is moved to `check()` (the matching `CHAR` pins that described the old behaviour then need updating too). |
| `FAIL` | Anything else. |

`DEFECT` = the audit confirmed a bug. `DECISION` = the right answer depends on a product decision (revenue basis, customer
identity policy, measurement method); the test asserts the *recommended* option and may be changed once the owner decides.

## What is real and what is a stand-in (`harness.php`)

- **Real:** all plugin classes the tests call (`CRDR_Sync::extract`, `CRDR_Analytics::build`, `CRDR_RFM`, `CRDR_Stats`,
  `CRDR_Playbooks::results` / `is_holdout`, `CRDR_Insights::generate`, `CRDR_Jalali`) and, when its source is available
  (the wordpress Docker image), WordPress core's own `is_email()`. The suite prints which one it used; it passes with either.
- **Stand-ins:** `WC_Order` (only the getters `extract()` reads), `wp_date`, options, and `$wpdb`. The `$wpdb` stand-in serves
  rows the test gives it and **records SQL without running it**. A test that talks about a SQL statement checks its *text*
  and says so in its name (`[SQL text]`). `[static]` marks tests that read plugin source text.
- **Injected values:** the insight tests start from the real `build()` output and inject the few values the small dataset
  cannot produce (a product pair, a threshold, gap statistics). The test name says what was injected.

## Fixtures (`fixtures/`)

| File | What |
|---|---|
| `reference-dataset.json` | 18 synthetic orders (statuses, refunds, shipping + tax, guests, erased customer data, malformed data, period boundaries). Site timezone Asia/Tehran, currency IRT, as-of 2026-10-10 12:00. No real data. |
| `reference-expected.json` | Answers computed **independently in Python** (not with plugin code) under the plugin's *own* definitions, for the 9 PEOPLE of the ground truth (Phase 3A: grouped by person, no longer by the plugin's old per-order keys). `CHAR` tests compare the plugin to this file. |
| `reference-expected.before-identity.json` | The same file as it was before Phase 3A (customers grouped by the old per-order keys: 10 "customers", erased orders merged). Kept for provenance; no test reads it. |
| `reference-ground-truth.json` | What is actually *true* about the same orders (9 people, not 10 keys; net sales 4,450,000; ...). `XFAIL` tests assert these. Kept apart so "what the plugin says" and "what is true" are never mixed. |
| `survival-scenario.json` | 9 customers observed for 400 days, 4 never return: the re-purchase-curve bias. |
| `generate_reference.py` | Provenance only: how the two reference JSON files were produced. The PHP tests never run it. |

## Defect register (what each `XFAIL` is about)

`ID-1..5` identity (split people, erased orders merged; **fixed in Phase 3A and promoted to PASS**, see docs/customer-identity.md) - `REV-1..3` revenue bases and refunds - `CURVE-1..4` customers who never
return are missing from the re-purchase curve, the second-purchase median, and the repeat share - `RFM-1` relative recency labels
year-old customers as recent - `CAMP-1..2` campaign groups - `REBUILD-1` (fixed in Phase 2 and promoted to a PASS; see `tests/sync-guard.php`) -
`AFF-1..2` lift denominator (**fixed in Phase 4.1, promoted to PASS**) - `INS-1..5` insight wording (**fixed in Phase 4.1**) - `INS-6` timing wording (**decided and fixed in Phase 4.2**, see below).

### Phase 4.1: what was fixed, what was left, and why
| ID | Class | State | Change |
|---|---|---|---|
| AFF-1, AFF-2 | DEFECT | **fixed** | lift and the "two or more products" share are computed over ALL orders (`orders_total`), not over the orders that happen to contain one of the 300 listed products (`CRDR_Stats::affinity($all_orders)`). `basket_orders` (328) keeps its meaning. |
| CURVE-1, CURVE-2 | DEFECT | **fixed** | the re-purchase curve now includes customers who ordered once and have not come back, as open intervals (`reorder_prob($open_ages)`). An open interval counts as a non-return only if it was watched for the whole window (`age >= d + window`): a customer from 10 days ago is not a "miss" yet. Independent truth: 33% at 0 and 180 days. |
| INS-1..4 | DEFECT | **fixed** | wording only: "most" only above 50%; no "less than 10%" for a group that is >= 10%; "of N orders that contain A" instead of "people"; "up to 25% below" instead of "exactly". |
| INS-5 | DEFECT | **fixed** | the suggested sentence uses the store's currency symbol instead of the hard-coded word "تومان". |
| REV-2 | DEFECT | *open in 4.1, resolved in 4.2* | product revenue ignores refunds (5,050,000 vs net sales 4,450,000). Reconciling needs refunds *per line item*; the fixture only has the refund per order, and WooCommerce often records a refund as an amount without choosing lines. Any allocation (pro rata, by line, none) is a product choice, and it changes what the Products page shows. Not guessed. |
| REV-1, REV-3, CURVE-3, CURVE-4, RFM-1, CAMP-1, CAMP-2, INS-6 | DECISION | unchanged in 4.1; decided in 4.2 (next section) | product definitions (revenue basis, who is a customer, median over returners or everybody, measurement unit). Left as asserted-recommendation XFAILs. |
Characterization (`CHAR`) pins that recorded a fixed defect's old, wrong value were replaced (each replacement carries a "PHASE 4.1" comment in `tests/analytics.php`); no fixture was edited and no
expected value was redefined to fit the code (the independently computed `truth()` of the survival scenario and `net_sales_excl_tax_shipping` are unchanged). Still not fixed in CURVE: repeat customers' open
interval after their LAST order is not included (only never-returned customers are), so the curve is less biased, not unbiased.

### Phase 4.2: the approved definitions (each one is pinned; changed pins carry a "PHASE 4.2" comment in `tests/analytics.php`)
| ID | State | Definition now in force |
|---|---|---|
| REV-1 | **resolved** | Revenue everywhere = **net sales**: order lines minus refunds. Shipping, tax and fees are not revenue. (`CRDR_Sales`, integer-cent allocation.) The ground-truth net sales (4,450,000) now equals what the dashboard adds up. |
| REV-2 | **resolved** | Refunds are applied **per line** when WooCommerce recorded them per line (`get_total_refunded_for_item`); the part not recorded per line (a refund by amount, minus shipping/tax refunds) is spread **in proportion to the remaining line values**. A refund that cannot be explained by the order (more refunded than the lines can absorb) is NOT folded into any figure: it is stored in `crdr_orders.refund_gap` and shown on the dashboard. Product revenue therefore equals order revenue. Fixtures: `refund-scenarios.json` (line-level, order-level, mixed, over-refund), computed by `generate_refund_scenarios.py` without plugin code; real WooCommerce refunds (`wc_create_refund`) in `tests/release-safety.php` section J. |
| REV-3 | **resolved** | An order whose net sales are <= 0 (fully refunded, or no lines) is **void**: it is stored with an empty customer key, makes no customer, counts in no order total, no cohort and no RFM row. Its status does not matter (a shop may keep "completed" after a full refund). |
| CURVE-3 | **resolved** | The second-order median stays "among customers who came back" and says so; next to it the proportions that returned within 30 / 60 / 90 days, with censoring (a customer too young for N days and not back yet is left out of that N). |
| CURVE-4 | **resolved** | Two repeat rates are shown: the raw one (all customers) and the one among customers whose first order is at least 90 days old. |
| RFM-1 | **resolved** | Recency stays relative to the store, but a customer whose last order is older than `lost_days` is "lost" whatever the quintile says (absolute guard). |
| CAMP-1 | **resolved** | Campaign results are **intention to treat**: every customer chosen for the message at their first assignment counts, whatever happened to the SMS afterwards. The accepted-only group is a secondary, labelled subset. Denominators are customers, not rows. |
| CAMP-2 | **resolved** | The customer is the unit; first assignment wins (a later row never moves them); the holdout draw is stable within a campaign wave (calendar month by default) and a control stays a control for its own measurement window. Behaviour proven on the real database in `tests/release-safety.php` section J. |
| INS-6 | **resolved** | The timing insight reports what was observed and no longer claims that reminding before the median is ineffective; it points to a holdout campaign as the way to find out. |

The three independent fixtures (`reference-dataset.json`, `reference-ground-truth.json`, `survival-scenario.json`) are unchanged. `reference-expected.json` was regenerated by `generate_expected_phase42.py` (separate Python code, reads the dataset read-only); the previous file is kept as `reference-expected.before-phase4.2.json`.

## NOT covered (and why)

- **Real SQL semantics.** Audience filters, cooldown `NOT EXISTS`, the joins in `Playbooks::results()`, the `INSERT ... ON DUPLICATE KEY`
  in sync: not executed here (no database). The WordPress-level suite (`tests/phase1-safety.php`, disposable shop) covers the
  sending side; the analytics SQL is covered only by its text.
- **Rebuild safety as behaviour.** Here it is a static check. The behavioural proof is `tests/sync-guard.php` (stand-ins) and the "sync guard" section of `tests/phase1-safety.php` (real database).
- **Real WooCommerce:** HPOS vs legacy storage, real `WC_Order` getters, how refunds and status changes are produced, the
  privacy eraser itself (only the *values* it leaves behind are used), Subscriptions, multi-currency plugins.
- **Sync pagination under concurrent orders** (possible duplicate or skipped orders; duplicated item rows) - needs a live store.
- **Performance and memory** at hundreds of thousands of orders.
- **Daylight-saving time zones.** Only Asia/Tehran (fixed offset) and UTC are exercised; hour-of-day / weekday charts and the
  "best send time" insight have no tests.
- Gap histogram, threshold suggestion numbers, the seasonality card, the segment table / donut on the dashboard, and the rendered
  HTML of any page.
- **Cohort cells after the last month with data** depend on today's date (`time()`), so they are not pinned.
- The repeat-purchase tests on `survival-scenario.json` treat "never returned within 400 days" as not returned.
