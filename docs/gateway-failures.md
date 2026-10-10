# How a failed SMS is treated

Source of the codes: the provider's own table, https://kavenegar.com/rest.html (read on 2026-10-10). Nothing here is guessed
from experience; a code that is not in that table is treated as **ambiguous**.

| What the gateway said | Scope | Contact status | What the campaign does |
|---|---|---|---|
| 200 | sent | `ok` | counted, customer is contacted |
| **411** receptor invalid (also our own phone check) | recipient | `rejected` | set aside until a person presses "try again" for that campaign, or 30 days. Does not touch the circuit breaker. A corrected number is a different customer key (`p:<phone>`), so it is served at once. |
| **413** empty / too long, **422** unsuitable characters | message | `nobuild` | same as an unbuildable message: set aside 6 h or until the campaign / settings are saved. 10 in a row stop the run. |
| 400 401 403 404 405 406 407 412 414 417 418 424 426 428 431 432 607 (account, key, credit, sender, IP access, malformed request) | fatal | `failed` | the run stops at the first one. The customer is not penalised and is retried at the next run. |
| **402** "operation failed" (no reason given) | uncertain | `retry` | set aside 6 h or until saved; counts for the circuit breaker (3 in a row). |
| network failure before the request left (DNS, connect, TLS) | gateway | `failed` | three in a row stop the run (a success resets the count); nobody is penalised; retried at the next run. |
| timeout while waiting, HTTP 5xx, unreadable answer, **409** "try later", any code not in the table | ambiguous | `unknown` | **never retried**, counts as contacted (cooldown, daily cap). The run stops. |
| plugin "WooCommerce Persian SMS" answers with a text | uncertain | `retry` | that plugin gives no code, so a bad number cannot be told from a broken account. Timeout-like texts are ambiguous. |

`failed`, `nobuild`, `retry`, `rejected` are all "nothing was sent": none of them is a contact (`CRDR_Send_Rules::NOT_CONTACT`).

## Limits worth knowing
* 409 ("server cannot answer, try later") is **not** assumed to mean "not processed": it stays ambiguous, so that message is never
  retried and the customer is counted as contacted. This favours never sending twice over delivering every message.
* A permanent rejection is only recognised when the provider says so (411). Through the Persian-WooCommerce-SMS plugin it cannot be.
* Stored errors are our own wording plus the provider code. The provider's text is never stored or shown, and API keys and
  phone numbers are removed from every error text (`CRDR_Send_Rules::redact`).

## What a stored status means (and what it does not)
The plugin has **no delivery receipts** (no status polling, no webhook, no message id is kept). So the most it can ever say is
"the SMS service accepted the request"; it never says "delivered". The wording shown to the owner comes from one place,
`CRDR_Send_Rules::outcome()`.

| Status | Group | Persian label | Contact (cooldown) | Uses the daily cap | In the campaign result |
|---|---|---|---|---|---|
| `pending` | attempting | در حال ارسال | yes | yes | no |
| `ok` | accepted | پذیرفته‌شده توسط سرویس پیامک | yes | yes | **treated group** |
| `unknown` | unknown | نتیجه نامشخص | yes (no duplicate) | yes | **no**, reported as "N left out" |
| `failed` / `retry` / `nobuild` | failed | ارسال نشد … | no | no | no |
| `rejected` | rejected | شماره رد شد | no | no | no |
| `holdout` | control | گروه شاهد | yes | no | **control group** |

`unknown` is deliberately a contact for cooldown and the daily cap (so nobody gets a second SMS), but it is never counted as a
success: it is not in the treated group, not in the revenue, not in "messages sent in total".
