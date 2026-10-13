# Customer Radar | رادار مشتری

WooCommerce plugin (Persian / RTL, Jalali dates) that analyses customers with RFM, tells you **who to contact today**, and can run safe SMS campaigns with a control group.

افزونه ووکامرس برای تحلیل RFM مشتریان، «فهرست تماس امروز» و کمپین پیامکی ایمن با گروه شاهد.

![Dashboard](docs/screenshots/dashboard-top.png)

## Features
- Nine RFM customer segments, rebuilt nightly (and on demand)
- Daily call list based on each customer's own purchase interval
- Persian dashboard: average basket, repeat customers, monthly sales (new vs returning), concentration, retention
- Campaigns (off / manual / automatic) with preview, holdout group and intention-to-treat result reporting
- SMS via Kavenegar (API key) or the free "Persian WooCommerce SMS" plugin; one-time discount codes
- Safety: opt-out checked right before every send, duplicate-send protection, allowed hours, daily cap, clone/staging-site guard
- CSV export, WordPress privacy export/erase tools, HPOS compatible
- "Net" sales: refunds are subtracted; shipping, tax and fees are not counted as product sales

## Requirements
WordPress 6.0+, PHP 7.4+, WooCommerce 7.0+.

## Install
Download the plugin folder (or a release ZIP), upload it via *Plugins → Add New → Upload*, activate, then press **تحلیل سفارش‌ها** (Analyse orders) once. Campaigns are **off by default**; nothing is sent until you choose a mode.

## Documentation
- Persian user guide: [docs/user-guide-fa.md](docs/user-guide-fa.md)
- [Gateway failure handling](docs/gateway-failures.md) · [Operations](docs/operations.md) · [Data retention & privacy](docs/data-retention.md) · [Customer identity](docs/customer-identity.md)
- Running the test suites safely: [docs/test-environment.md](docs/test-environment.md)

## Honest limits
- "Accepted" means the SMS service accepted the request; there are no delivery receipts.
- Replies such as "لغو۱۱" are not received by the plugin; the SMS provider/operator handles them.
- Multisite is not supported or tested.
- Tests that touch WordPress must only run in a disposable shop (see test-environment doc). Never test with a real API key.

## Building a ZIP
`python3 bin/build-zip.py` (allow-list build; `--verify` checks a ZIP).

## Contributing & support
Issues and pull requests are welcome. This is a hobby/open-source project, supported on a best-effort basis.

## License
GPL-2.0-or-later — see [license.txt](license.txt). No warranty. You are responsible for complying with the laws on promotional SMS in your country.
