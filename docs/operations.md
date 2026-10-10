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
