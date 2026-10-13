# How a failed SMS is treated

Source of the codes: the provider's own "return codes" table, https://kavenegar.com/rest.html (read on 2026-10-10 and again on 2026-10-12 for Phase 4.2).
A code that is not in that table is treated as **ambiguous**.

**What that table does and does not say.** It gives a meaning for each code. It does **not** say, for any code, whether the message was or was not processed
(only 409 carries retry advice: "try later"). Calling 416 / 420 / 427 / 429 / 451 "nothing was sent" therefore rests on what they are (authorisation,
content-policy and rate-limit rejections, which a gateway applies before it accepts a message): an **inference**, tested only against a mock. Nothing here was
exercised against the live provider. To confirm: a sandbox account, or the provider's support.

| What the gateway said | Scope | Contact status | What the campaign does |
|---|---|---|---|
| 200 | sent | `ok` | counted, customer is contacted |
| **411** receptor invalid (also our own phone check) | recipient | `rejected` | set aside until a person presses "try again" for that campaign, or 30 days. Does not touch the circuit breaker. A corrected number is a different customer key (`p:<phone>`), so it is served at once. |
| **413** empty / too long, **422** unsuitable characters | message | `nobuild` | same as an unbuildable message: set aside 6 h or until the campaign / settings are saved. 10 in a row stop the run. |
| 400 401 403 404 405 406 407 412 414 417 418 424 426 428 431 432 607, and **416** source IP does not match the panel's settings, **427** this line needs an access level set up, **429** IP restricted (account, key, credit, sender line, IP access, malformed request) | fatal | `failed` | the run stops at the first one. The customer is not penalised and is tried again at the next run. The administrator gets a Persian explanation for the code. |
| **420** links in the message text are restricted for this account | message | `nobuild` | the TEXT is refused, not the customer: set aside 6 h or until the campaign / settings are saved; 10 in a row stop the run. Matters for the cross-sell campaign, whose text carries a `{url}` link. The explanation tells the owner to remove the link or ask the provider to enable links. |
| **451** too many calls from this IP in a time window | rate | `failed` | the provider is throttling this server: nothing is wrong with the customer or the text. The run stops at once (sending more would only prolong the limit); nobody is penalised; the customer is tried again at the next run. |
| **402** "operation failed" (no reason given) | ambiguous | `unknown` | **never retried**, counts as contacted (cooldown, daily cap). The run stops. See "Why 402 is not retried" below. |
| **501** test account: messages go only to the account owner's number | fatal | `failed` | the run stops at the first one (every other customer would be refused the same way). Added in the release freeze; the Persian reason says the account is in test mode. |
| network failure before the request left (DNS, connect, TLS) | gateway | `failed` | three in a row stop the run (a success resets the count); nobody is penalised; retried at the next run. |
| timeout while waiting, HTTP 5xx, unreadable answer, **409** "try later", any code not in the table | ambiguous | `unknown` | **never retried**, counts as contacted (cooldown, daily cap). The run stops. |
| plugin "Persian WooCommerce SMS" answers `true` | sent | `ok` | counted. |
| that plugin answers with one of the two texts it gives **before** it calls any gateway ("the number is empty/invalid", "the text is empty") | recipient / message | `rejected` / `nobuild` | definite: nothing was sent. |
| that plugin answers with **any other text**, or `false`/`null`/an array, or throws | ambiguous | `unknown` | **never retried**, counts as contacted. That plugin catches its gateway's exceptions and returns their text, so a text says that something went wrong, not *when*: a timeout or a parse error can come after the gateway accepted the message. The administrator sees a masked excerpt of the text (no links, keys, long tokens or phone numbers; `CRDR_Send_Rules::safe_excerpt`). |

`failed`, `nobuild`, `retry`, `rejected` are all "nothing was sent": none of them is a contact (`CRDR_Send_Rules::NOT_CONTACT`). (`retry` is no longer written by any current code path; the label stays so that a row written by a development build is still described correctly.)

## Why 402 is not retried (release freeze)
Until the release freeze, 402 ("operation failed", no reason) was treated as "nothing was sent" and the customer was tried again after six hours with a **new** `localid`. That is unsafe:
the provider's table does not say that 402 means "not processed", and a new `localid` defeats the provider's only duplicate check (a repeated `localid` is not sent twice). If the first
request had in fact been accepted, the customer would receive the same SMS twice. 402 is therefore **ambiguous**, exactly like 409 or an undocumented code: `unknown`, counted as contacted,
never retried automatically. The cost is that a customer hit by a genuine, harmless 402 is not served by that campaign until the cooldown ends; the benefit is that nobody gets a duplicate.
A safer classification needs documented evidence (the provider saying 402 is always raised before anything is queued); none exists in the table read on 2026-10-12.
Covered by `tests/unit.php` (scope and provider-table tests) and `tests/phase1-safety.php` ("unclear failure (402)": one request, the run stops, no retry after saving the campaign or after six hours).

## Limits worth knowing
* 409 ("server cannot answer, try later") is **not** assumed to mean "not processed": it stays ambiguous, so that message is never
  retried and the customer is counted as contacted. This favours never sending twice over delivering every message.
* A permanent rejection is only recognised when the provider says so (411). Through the Persian-WooCommerce-SMS plugin it cannot be, except for its two pre-gateway texts.
* That plugin also keeps its own copy of every SMS it sends (recipient and message text) in its table `woocommerce_ir_sms_archive`, with no retention limit and, as far as its published source shows, no WordPress exporter or eraser. This plugin's uninstall and eraser cannot reach it (docs/data-retention.md).
* Codes 415 and 419 are in the provider's table but belong to list / array endpoints; a single send cannot produce them, and if it ever does they stay ambiguous.
* Stored errors are our own wording plus the provider code. The provider's text is never stored or shown, and API keys and
  phone numbers are removed from every error text (`CRDR_Send_Rules::redact`).

## What a stored status means (and what it does not)
The plugin has **no delivery receipts** (no status polling, no webhook, no message id is kept). So the most it can ever say is
"the SMS service accepted the request"; it never says "delivered". The wording shown to the owner comes from one place,
`CRDR_Send_Rules::outcome()`.

| Status | Group | Persian label | Contact (cooldown) | Uses the daily cap | In the campaign result |
|---|---|---|---|---|---|
| `pending` | attempting | در حال ارسال | yes | yes | no |
| `ok` | accepted | پذیرفته‌شده توسط سرویس پیامک | yes | yes | **treated group** (primary) and the secondary "accepted" row |
| `unknown` | unknown | نتیجه نامشخص | yes (no duplicate) | yes | **treated group** (primary, by assignment); counted separately as "unknown"; **not** in the secondary "accepted" row |
| `failed` / `retry` / `nobuild` | failed | ارسال نشد … | no | no | **treated group** (primary, by assignment) if it was the customer's first row; not in "accepted" |
| `rejected` | rejected | شماره رد شد | no | no | **treated group** (primary, by assignment) if it was the customer's first row; not in "accepted" |
| `holdout` | control | گروه شاهد | yes | no | **control group** |
| `norec` | skipped | رد شد (محصول مکمل پیدا نشد) | – | no | no: nobody was assigned |

**Reporting definition: intention to treat (ITT).** The campaign result (`CRDR_Playbooks::results`) counts each customer once, in the group of their **first assignment** in that campaign, whatever
happened to the SMS afterwards. The primary comparison is "everybody first assigned to the message" against "everybody first assigned to the holdout": both groups were drawn the same way, so
failures, rejected numbers and unknown results stay on the treated side instead of being filtered out of one group only. The secondary row ("accepted") restricts the treated side to customers
whose SMS the service accepted; it shows how well a message works when it is accepted, but it removes unreachable numbers from one side only, so read it next to the primary. The flag
`CRDR_Send_Rules::outcome()['measured']` is derived from the same list (`CRDR_Send_Rules::ASSIGNED`) that the query uses: it means "can be a first assignment", not "was accepted".

`unknown` is deliberately a contact for cooldown and the daily cap (so nobody gets a second SMS), but it is never counted as a
success: it is not in the secondary "accepted" row and not in "messages sent in total". By assignment it is part of the primary (ITT) treated group, like every other customer who was first assigned to the message.
