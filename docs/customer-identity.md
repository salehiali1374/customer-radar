# Who is "one customer"? (Phase 3A)

An order does not say "this is customer #17". The plugin has to decide, from the billing phone, the billing email and the
WordPress account of each order, which orders belong to the same person. Everything that counts customers (repeat rate, RFM,
cohorts, Pareto, "customers who bought once"), everything that remembers a contact (opt-out, cooldown, results of a campaign) and every
SMS target depends on that decision. Code: `includes/class-crdr-identity.php` (the rules, pure functions) and
`CRDR_Sync::resolve_identities()` (applies them to the tables). Tests: `tests/identity.php`, `tests/analytics.php` (ground truth),
`tests/phase1-safety.php` section "customer identity" (real database).

## Step 1: what one order says (`CRDR_Sync::extract`)

| Identifier | Usable when | Otherwise |
|---|---|---|
| phone | it normalises to a mobile number `09xxxxxxxxx` (`+98`, `0098`, Persian digits, spaces and dashes are accepted) | missing |
| email | a valid address, lower-cased and trimmed, and **not an anonymisation placeholder** | missing |
| account | the order has a WordPress user id | missing |

**Placeholders are "no identity".** `deleted@site.invalid` (what WordPress and WooCommerce write when they erase a person), any address under the
reserved `.invalid` top-level domain, and the all-zero phone the eraser leaves are treated exactly like an empty field. The list can be
extended with the `crdr_placeholder_emails` filter. A malformed address (`not-an-email`) is never evidence of anything, even when two orders
carry the same text.

The order gets a **provisional key** from the first usable identifier: `p:<phone>`, else `e:<email>`, else `u:<user id>`, else `o:<order id>`.
Two orders with the same phone are therefore already one customer (a number is what an SMS is sent to: one number is one recipient, whoever
typed it, even two different accounts).

## Step 2: which provisional customers are one person (`CRDR_Identity::resolve`, phase "finalize")

Run once, after every order is in and before anybody is scored. It only ever **joins**; it never splits.

| Rule | Evidence | Kind |
|---|---|---|
| R1 | the same registered user id | deterministic: an account is one person |
| R2 | the same valid email address | probable, joined only when the address is not ambiguous (below) |
| R3 | a join that would put two *different* registered users in one customer is refused | guard |
| (sticky) | a join made by an earlier rebuild stays | guard, see below |

An email is **ambiguous, and never links anybody**, when
* two or more different registered users used it (it is "contested"), or
* it appears with **more than two** different phone numbers (a shared mailbox: an office, an agency, the shop's own address typed for phone
  orders). The limit is `CRDR_Identity::EMAIL_MAX_PHONES`, filter `crdr_identity_email_max_phones`. **Setting it to 1 is the safe fallback**:
  an email then never joins two phones (only an account does), at the price of one person who changed numbers staying two customers.

**Not evidence** (never used): the same name, the same address, the same IP, similar numbers, the same *malformed* text.

The joined customer keeps the strongest kind of key (phone, then email, then account), and among equals the one with the **most recent order**, so
the key follows the person's current number. Order counts, spend and dates are added up; name, email and account come from the newest order that has
them. The customer's orders **and contact history** (opt-outs, cooldowns, campaign results) are re-keyed to the joined key, so a person who
opted out under an old number stays opted out under the new one.

### Joins are sticky
"More than two phones means a shared mailbox" depends on the whole order history. Without memory, a person who gets a *third* phone would flip from
one customer back to three, and the old numbers would reappear as customers **with no opt-out and no cooldown**. So every join that was applied is stored
in the option `crdr_identity_links` (member key => joined key) and applied first on every later rebuild. Only *new* joins follow the rules above.
The option is read defensively: anything that is not a valid `key => key` pair (an array or number as a value, an unknown kind of key, a key joined to itself, an option that
is not an array at all) is dropped and counted (`links_dropped` in the sync state) instead of breaking the rebuild; the option is rewritten clean.

### An opt-out belongs to the person, not to one key
* "Do not message" on a joined customer is written for **every key** the customer was joined from (`CRDR_Actions::ajax_contact`), and a rebuild copies an opt-out to every key of a joined
  person (old keys and new ones) **before** it moves anything else. Opt-out rows are only ever added: never moved, never deleted by the plugin.
  So a person stays opted out if the key that held the opt-out stops being a customer (its newest order is cancelled), if the remembered joins are lost or repaired, or if a rebuild fails half way.
* Precedence when the contact history of the joined keys disagrees: **opt-out beats everything** (any key opted out => all keys opted out); the cooldown is the most recent contact among all
  keys (rows are moved to the joined key, nothing is overwritten); a customer who was never contacted under one key simply has no rows there.
* Copies are recognisable: their message is `CRDR_DB::OPTOUT_COPY_NOTE`.
* A **database error while writing an opt-out is never reported as "saved"** (the button shows an error; asking again only adds what is missing).
* What is **not** protected: a new phone number that nothing connects to the person (no shared email, no account). That is the limit of the data, not of the code.

### Repairing a wrong join (supported procedure)
Do **not** just delete `crdr_identity_links`. Tested effect (tests/phase1-safety.php, "identity integrity"): every join the rules no longer support (for example an email that now has three phones)
falls apart at the next rebuild, the cooldown/contact history stays with the joined key (the other keys start with none), and only the opt-out survives (because of the copies above). Also an opt-out
recorded since the last rebuild only on the joined key would not reach the others.

Instead, name the keys that are not the same person:

    wp eval 'var_dump( CRDR_Sync::unjoin( array( "p:09121110001" ) ) );'     # the key shown in the customer list, or an old key that was joined into it
    # then run «تحلیل سفارش‌ها» (or wait for the nightly rebuild) and check the customer list

`unjoin()` refuses while a rebuild is running or another step holds the lock. Before it changes anything it copies the opt-outs to every key of the people involved; it removes the
remembered joins of those keys and puts the keys on a **kept-apart list** (option `crdr_identity_apart`), so neither the email rule nor the account rule joins them again. The contact history that
already moved (cooldowns, campaign results) stays with the joined key; **an opt-out that was copied stays on both** (the owner cannot tell from the data which of the two people it belonged to). If the
owner is sure a copy is wrong, it can be removed by hand: `DELETE FROM wp_crdr_contacts WHERE kind = 'optout' AND ckey = '<key>' AND message = '<the copy note>'`.
`CRDR_Sync::rejoin( array( ... ) )` lifts the separation. Deleting `crdr_identity_apart` is always safe (it only allows the rules to join again).

## What happens to orders nobody can be identified from
An order with no usable phone, email or account (`o:<order id>`, for example an erased order, or malformed billing data) is **its own one-order customer**, never merged
with another:
* it stays in every **order and revenue total**;
* it counts as one **customer** in the customer-level figures (customers, repeat rate, cohorts, Pareto, RFM population) as a one-time buyer. This is
  the same as the ground truth of the test dataset (9 people: each erased order is a different unknown person). It is a deliberate, conservative
  choice: if several of them were really the same returning person, the repeat rate is a little low, never invented;
* it has no phone, so **no campaign or SMS can reach it** (campaign audiences require a phone number).

## What cannot be known (the ambiguous cases)

| Situation | What the plugin does | Why |
|---|---|---|
| Same email, **two** different phones, no account | one person (R2) | indistinguishable from one person who changed number; two people sharing a mailbox would be wrongly joined. Filter `crdr_identity_email_max_phones` = 1 turns this off. |
| Same email, **three or more** phones | nobody joined | a shared mailbox is more likely than a person with three numbers; one person with three numbers stays split (their opt-out does not travel to a number the plugin cannot connect). |
| Phone **and** email both changed, no account | two customers | nothing in the order connects them |
| Two accounts with the same phone | one customer | one number, one recipient |
| Two accounts with the same email | two customers | an email used by two users is contested |
| A guest order with the email of account A and another with the email of account B | joins A (the first, in sorted order); the link to B is refused and counted (`refused` in the sync state) | never merge two registered users |
| Fake but well-formed numbers (`09123456789`) typed by many people | joined by phone | a number cannot be told from a real one |
| An order that was cancelled or refunded after it was imported | stays counted until the next rebuild | see docs/operations.md, "Orders that change while the rebuild runs" |

The result of the last rebuild is in the sync state (`identity`: `joined`, `people`, `emails_shared`, `emails_contested`, `refused`, `unidentified`).

## Interactions
* **Stale-page protection.** The identity token on a customer row is derived from the customer key. After a join, the key of a person may change; a page drawn before the
  rebuild then carries the old key's token and its buttons are refused with "reload the page", never applied to the joined customer.
* **Cooldown / opt-out / claims.** Re-keying happens inside the rebuild, while all sending is blocked (the rebuild guard), under the sync lock, with an in-flight marker: a rebuild that dies in the middle of it
  is failed, not resumed, and a new rebuild starts from the orders again (and repeats the re-keying of the contact history, which is idempotent).
* **Schema.** Version 5: the order table gets `uid` and `email` (the evidence; the table is rebuilt from WooCommerce every time, so nothing is lost).

## What is atomic, what is not, and how a failure recovers
Nothing in the rebuild is wrapped in a database transaction (the table emptying is DDL and cannot be; a hosting with MyISAM tables would ignore a transaction anyway). Safety comes from
ordering and from fail-closed state instead. Tested by making a real database error and, separately, killing the PHP process at **each** of the 14 writes of the "finalize" step
(tests/phase1-safety.php, "identity integrity"): sending stays blocked, every key that was opted out stays opted out at every moment, and a new rebuild always ends exactly like a clean one.

| Boundary | Atomic? | If it fails or the process dies here |
|---|---|---|
| schema 4 -> 5 (`dbDelta`: add `uid`, `email` to the order table) | no (DDL); the version option is written **last** | the migration simply runs again on the next request; nothing else depends on the columns until a rebuild |
| emptying the three tables | no (DDL) | the state was saved as "starting" *before*, so sending is already blocked; the next step fails the rebuild |
| each order write | one statement each; a database error stops the step (`false` is never skipped) | rebuild "failed"; in-flight marker for a page |
| state / remembered-joins write | one statement that also tests "do I still own the lock?" | a process that lost the lock writes nothing |
| `crdr_identity_links` | one fenced statement; written **before** the joins are applied | the decision is repeated by the next rebuild |
| opt-out copies | rows are only added, one by one, **before** any other change | protection can only grow; a copy that cannot be written fails the step |
| orders / contacts / customers re-keying | one statement per table per batch of 200 people; idempotent | in-flight marker "resolve": the next step fails the rebuild, a new one starts from the orders again |
| final "done" | one fenced state write | only the last successful step writes it |

While any of this is unfinished the state is "finalize" or "failed", and every sending path is refused (docs/operations.md, section 5). A half-applied join therefore cannot affect campaign
eligibility; and even the contact history that was already moved is only ever moved to a customer that the same rebuild's data supports.

## Scale (measured, with its limits)
Identity step only (`resolve_identities`), real tables, synthetic data (about 30% registered, 6% of customers sharing an email, 0.5% on shared mailboxes, 2 orders per customer,
about 5% of customers joined), local Docker database, no other load, `memory_limit` unlimited:

| customers | orders | time | extra peak memory | queries |
|---|---|---|---|---|
| 20,000 | 40,000 | 0.5 s | 5 MB | 48 |
| 50,000 | 100,000 | 2.0 s | 13 MB | 107 |
| 100,000 | 200,000 | 5.4 s | 26 MB | 195 |
| 200,000 | 400,000 | 6.4 s | 51 MB | 372 |
| 500,000 | 1,000,000 | 21.5 s | 126 MB | 927 |

For comparison, the existing steps at 100,000 customers: scoring 6.7 s / +110 MB, analytics 6.1 s / +221 MB. The identity step used to be 19 s / +237 MB at 100,000 (it loaded every customer and
wrote four statements per joined person); it now loads only customers that share an email or an account with another customer, or are in a remembered join, and writes per batch of 200.
The result is identical (checked on random stores against the resolver run on all the data). What this does **not** show: anything above 500,000 customers, a slow or shared database host, a store with
far more sharing than the sample, or the whole rebuild (the analytics build is the heavier step and unchanged). The order table has no index on `email` or `uid`: the step does two scans of it.
`CRDR_Identity::resolve()` itself is O(n log n) and was benchmarked without a database up to 500,000 customers (3.5 s, about 2.3 KB per customer of input and working memory).

## Database compatibility
Verified: the **whole** real-database suite (541 checks, including every statement of the identity step and the fenced writes), legacy WooCommerce storage, WooCommerce 11.1.2, on
**MySQL 8.0.46, MySQL 5.7.44, MariaDB 10.6.28 and MariaDB 11**; HPOS on MariaDB 11. All default SQL modes (so `ONLY_FULL_GROUP_BY` on 5.7 and 8.0), default InnoDB tables.

Assumptions the code makes (each exercised by the runs above, none verified on other engines): InnoDB row locking for the lock row and the fenced `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE`;
`GROUP BY ... HAVING COUNT(DISTINCT ...)` and a join against a derived table; multi-row `CASE ... WHEN` updates (a batch of 200 people is about 100 KB, far below the default `max_allowed_packet`);
case-insensitive default collations (all email keys are stored lower-case, so PHP and SQL comparisons agree).

**Not verified, so not claimed:** MySQL 8.4 and 9.x, MariaDB 10.4/10.5/10.11, Percona, Aurora and other managed or clustered servers (Galera: the lock row relies on one writer), non-InnoDB tables,
`utf8mb4` collations with PAD SPACE behaviour, `max_allowed_packet` below 1 MB, HPOS on MySQL (HPOS ran on MariaDB 11 only). To close that: run `wp eval-file wp-content/plugins/customer-radar/tests/phase1-safety.php` in a
disposable shop on that server (docs/test-environment.md); it must end with "0 failed".


## Uninstall, reinstall and erasure (Phase 4.1)
* **Default uninstall** removes the remembered joins (`crdr_identity_links`, `crdr_identity_apart`), the customer / order tables and all history, and **keeps only the opt-out rows**. Because
  an opt-out is written for *every* key of a joined person (see "An opt-out belongs to the person"), each key still has its own row after the joins are gone, so after a re-install nobody who opted
  out becomes messageable again, whether or not the first rebuild re-joins them. Copies are reduced like the originals (no text, no staff id).
* **Full erasure** (the box in Settings) removes the opt-out rows as well; nothing about identity remains.
* **Privacy eraser** (`CRDR_Privacy`): the keys of the erased person are taken out of the remembered joins and the kept-apart list (`CRDR_Sync::forget_keys_locked`); the other members of a group stay joined
  to each other, and opt-outs are copied across the whole group *before* anything is removed. Keys other people's orders also carry (a shared phone number) are never erased. Joins are not "split back".
* **First rebuild after an update** from a schema older than 5 sets `crdr_rebuild_required`: sending waits until a rebuild that started after the update has finished (docs/operations.md, section 8).
