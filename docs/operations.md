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
