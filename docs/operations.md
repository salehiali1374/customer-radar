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
