# Test environment (throwaway, local only)

Containers: `crdr-db` (MariaDB), `crdr-wp` (WordPress 7.1.3 + WooCommerce 11.1.2), listening on 127.0.0.1:8091.
Data: 2,000 generated customers / 3,258 orders / 37 partial refunds (volumes `crdr_db`, `crdr_html`).

    docker start crdr-db crdr-wp            # bring it back after a reboot
    http://localhost:8091/wp-admin          # user: tester
    cat /home/ali/.cache/crdr-test/.wppass  # throwaway password
    /home/ali/.cache/crdr-test/wpc ...      # wp-cli wrapper, e.g. ./wpc plugin list

Note: the WooCommerce files used by the test shop live in /home/ali/.cache/crdr-test/woocommerce
(copied read-only from the carabin container). Do not delete that folder while the container exists:
docker would re-create it empty and root-owned.

The mu-plugins `rtl.php` (forces RTL) and `pwsms-stub.php` (fake Persian WooCommerce SMS) exist only in the test shop.

Unit tests (no WordPress needed):

    docker run --rm -v "$PWD":/p:ro wordpress:6-php8.3-apache sh -c 'cd /p && php tests/unit.php'

## Phase 1 (campaign safety) tests

Pure logic, same command as above (`php tests/unit.php`): template validation, sending-hours rules, gateway-answer
classification, `localid`.

WordPress level, `tests/phase1-safety.php` (real database, real parallel processes, **no real SMS**: the Kavenegar API is
replaced by `tests/phase1-mock.php`). It truncates the plugin's customer/contact tables and rewrites its settings, so it
refuses to run unless the site host is `localhost` AND the option `crdr_test_shop` is `1`. Use a shop of its own, not the
one above if you want to keep its data (the plugin folder is bind-mounted, so the schema upgrade 2 -> 3 runs there on the
next page load):

    docker network create crdr_p1_net
    docker run -d --name crdr-p1-db --network crdr_p1_net -e MARIADB_ROOT_PASSWORD=throwaway -e MARIADB_DATABASE=wp mariadb:11
    docker run -d --name crdr-p1-wp --network crdr_p1_net -e WORDPRESS_DB_HOST=crdr-p1-db -e WORDPRESS_DB_USER=root \
      -e WORDPRESS_DB_PASSWORD=throwaway -e WORDPRESS_DB_NAME=wp \
      -v "$PWD":/var/www/html/wp-content/plugins/customer-radar:ro \
      -v /home/ali/.cache/crdr-test/woocommerce:/var/www/html/wp-content/plugins/woocommerce:ro wordpress:6-php8.3-apache
    # WooCommerce 11 needs WordPress >= 7.0: copy wp-admin/ wp-includes/ from the shop above if the image is older.
    # then, with a wp-cli wrapper like `wpc` (pointing at crdr_p1_net / crdr-p1-wp / crdr-p1-db):
    wp core install --url=http://localhost --title=P1 --admin_user=tester --admin_password=<throwaway> --admin_email=t@example.invalid
    wp plugin activate woocommerce customer-radar
    wp option update crdr_test_shop 1
    wp eval-file wp-content/plugins/customer-radar/tests/phase1-safety.php      # expect: 542 passed, 0 failed

The whole suite also passes (541, 0 failed) on MySQL 8.0.46, MySQL 5.7.44 and MariaDB 10.6.28 (`mkshop`-style disposable shops with that database image; see docs/customer-identity.md, "Database compatibility").
The same suite also runs with WooCommerce's high-performance order storage (HPOS) switched on (`wp wc hpos enable`, in the same
disposable shop; 541 passed, 0 failed), and it drives the nightly rebuild through the real Action Scheduler queue runner ("action
scheduler chain" section).

The suite pins the clock (03:00 Asia/Tehran, via the `crdr_send_window_now` filter) and sets the site timezone itself, so it gives the same
result at any time of day and on repeated runs.

`tests/integration.php` needs the seeded shop (`gen2.php`, kept outside the repository in `/home/ali/.cache/crdr-test/`) and the `pwsms-stub.php` mu-plugin as before.

**Prerequisite found in Phase 4.2: the seeded shop must use HPOS.** `gen2.php` writes the final order statuses straight into the HPOS table. On a shop that still uses the
legacy post storage every generated order stays "pending", the plugin correctly counts none of them, and the test fails for a reason that has nothing to do with the plugin
(the first time this was met, the seeded orders were all pending). Order of operations on a new shop: install WooCommerce, `wp wc hpos sync`, `wp wc hpos enable`, **then** run
`gen2.php`, then the suite. `tests/integration.php` now refuses to run on legacy storage and says why. The other WordPress-level suites create their orders through the
WooCommerce API and run on either storage (they were run on both).

Remove everything when done:

    docker rm -f crdr-wp crdr-db crdr-seed; docker volume rm crdr_db crdr_html; docker network rm crdr_net

## Suites that need no WordPress, WooCommerce or database

    docker run --rm --network none -v "$PWD":/p:ro wordpress:6-php8.3-apache sh -c 'cd /p && php tests/unit.php'            # 399 passed
    docker run --rm --network none -v "$PWD":/p:ro wordpress:6-php8.3-apache sh -c 'cd /p && php tests/analytics.php'       # PASS 143, CHAR 48, XFAIL 0
    docker run --rm --network none -v "$PWD":/p:ro wordpress:6-php8.3-apache sh -c 'cd /p && php tests/sync-guard.php'      # PASS 158, FAIL 0
    docker run --rm --network none -v "$PWD":/p:ro wordpress:6-php8.3-apache sh -c 'cd /p && php tests/identity.php'        # PASS 91, FAIL 0

`tests/analytics.php` (see `tests/analytics/README.md`) pins the analytics numbers and lists the known defects as expected failures.
`tests/identity.php` tests the rules that decide who is one customer (docs/customer-identity.md); the SQL that applies them is tested in the "customer identity" section of `tests/phase1-safety.php`.
`tests/sync-guard.php` tests the rebuild guard on stand-ins. The same guard is tested against a real database, real WooCommerce
orders and real parallel processes in the "sync guard" section of `tests/phase1-safety.php` (disposable shop only).

## Release ZIP (Phase 4.1)

    python3 bin/build-zip.py --out dist/customer-radar-1.0.1-rc1.zip       # build from an ALLOWLIST; refuses to overwrite
    python3 bin/build-zip.py --verify dist/customer-radar-1.0.1-rc1.zip    # self-consistency + "is it what the working tree produces today?"
    python3 tests/build-zip-test.py                                        # 12 tests of the build script itself (python3 only)

Only `customer-radar.php`, `readme.txt`, `uninstall.php`, `assets/*.css|js` and `includes/class-crdr-*.php` can enter the archive; tests, fixtures,
docs, `bin/`, `dist/`, `.git`, the listing draft and any local file are never read. Entries are sorted, mode 0644, with a fixed timestamp
(`SOURCE_DATE_EPOCH`), so the same tree gives the same bytes. The build fails (and leaves no file) if an include required by
`customer-radar.php` is missing, a file in `includes/` is not required, the header version, `CRDR_VERSION` and the readme `Stable tag` disagree, or a PHP file
does not start with `<?php`. `--verify` also reports an archive that is self-consistent but **stale** (an old build still passes its own checks).
A ZIP is only a release candidate after it has been installed and activated on a clean disposable shop (a new database, not one that has seen an older build)
and the suites have been run against that install; rebuild it after any source change.

## Phase 4.1 suites
    wp eval-file wp-content/plugins/customer-radar/tests/release-safety.php      # disposable shop only (host localhost, crdr_test_shop=1): 333 passed, 0 failed
    python3 tests/build-zip-test.py                                               # 12 tests of bin/build-zip.py (python3 only)
`release-safety.php` covers the last opt-out check before the gateway, the customer file, the schema checks, uninstall (default / full erasure / reinstall),
privacy export and erasure, promotional-template rules and the first-rebuild window after an update. Never run two WordPress-level suites at the same time
on one shop (they share the sync state, the lock and the contact table).

## Phase 4.2 validation matrix (version 1.0.1-rc1)
Every WordPress-level run below installed the plugin **from `dist/customer-radar-1.0.1-rc1.zip`** into a new shop (new volume, new database) and overlaid only `tests/` from the repository.
Each shop uses WordPress core / WooCommerce as stated; "WP 7.1.3 / WC 11.1.2" is the newest pair available locally. Never run two WordPress-level suites at the same time on one shop.

| Shop | PHP | WordPress / WooCommerce | Database | Order storage | release-safety | phase1-safety | integration |
|---|---|---|---|---|---|---|---|
| z1 | 8.3.35 | 7.1.3 / 11.1.2 | MariaDB 11.8.9 | legacy posts | 333/0 | 543/0 | |
| z2 | 8.3.35 | 7.1.3 / 11.1.2 | MariaDB 11.8.9 | HPOS | 333/0 | 543/0 | |
| z3 | 8.3.35 | 7.1.3 / 11.1.2 | MariaDB 11.8.9 | HPOS, seeded (gen2.php: 3,258 orders) | | | 46/0 |
| z4 | 7.4.32 | 7.1.3 / 11.1.2 | MariaDB 10.6.28 | legacy posts | 333/0 | 543/0 | |
| z5 | 7.4.32 | 7.1.3 / 11.1.2 | MySQL 5.7.44 | legacy posts | 333/0 | 543/0 | |
| z6 | 8.3.35 | 7.1.3 / 11.1.2 | MySQL 8.0.46 | legacy posts | 333/0 | 543/0 | |
| z7 | 8.3.35 | 7.1.3 / 11.1.2 | MariaDB 11.8.9 + **persistent object cache** (Redis 7, test-double drop-in) | legacy posts | 333/0 | 543/0 (after one test fix, below; first run 542/1) | |
| z8 | 8.2.28 | 7.1.3 / 11.1.2 | MariaDB 10.6.28 | legacy posts | 333/0 | 543/0 | |
| m1 | 7.4.32 | **6.0.3 / 7.0.0** (declared minimums) | MySQL 5.7.44 | legacy posts | 333/0 | 543/0 | |
| m2 | 7.4.32 | **6.0.3 / 7.0.0** (declared minimums) | MariaDB 10.6.28 | legacy posts | 333/0 | 543/0 | |
The PHP 7.4 shops run WP-CLI (`wp.phar`) inside the 7.4 container, so the suites themselves execute on 7.4. Shops with an old `wordpress:*` image have their core replaced by the
newer one only where WooCommerce 11 requires it (z-shops); m1 / m2 keep the image's own WordPress 6.0.3.
**Not covered, because the files are not available locally:** a WooCommerce 8.x-10.x run (the "middle" corner), WordPress 6.1-6.9, MariaDB 10.4 / 10.5 / 11.4 LTS, MySQL 8.4, PHP 8.0 / 8.1 / 8.4,
a production Redis object-cache plugin (the drop-in used is a ~200-line test double, `tests/support/redis-object-cache.php`, that speaks the Redis protocol; it proves "no stale reads between requests", not the behaviour of any particular product).
**Persistent object cache finding:** with a persistent cache WordPress stores transients only in the cache; one test deleted a worker's transient through `wp_cache_delete` and failed (a test artefact: the plugin was right).
It now forgets only its own process copy (`wp_cache_flush_runtime`). The plugin's lock, state and settings code passed unchanged.

**Real upgrade:** the original `customer-radar-1.0.0.zip` (schema 2) was installed on a clean shop, 3 orders / an opt-out / an SMS row were created, the plugin folder was replaced with 1.0.1-rc1:
schema 2 -> 6 on load, no schema problem, `crdr_rebuild_required` set (sending blocked with the explanatory message), opt-out and history kept, the first rebuild completed (3 customers, net sales 300,000),
sending released, the opt-out still protected the number.

**Redis helper:** `docker run -d --name crdr-p6-redis --network crdr_p6_net redis:7-alpine`, then copy `tests/support/redis-object-cache.php` (the test double; it connects to the host `crdr-p6-redis`) as `wp-content/object-cache.php`; check `wp_using_ext_object_cache()` and `redis-cli dbsize` > 0 after a run.

## Release freeze validation (version 1.0.1, `dist/customer-radar-1.0.1.zip`)
Archive: `dist/customer-radar-1.0.1.zip`, SHA-256 `47cda05e2830f3aabdda794451bdf6770528951f7b30ca276a27de880b05fe5b`, `python3 bin/build-zip.py --verify` OK (allowlist, version consistency, matches the working tree).
Each WordPress-level run installed the plugin **from that ZIP** into a new shop (new database; MariaDB 11.8.9, PHP 8.3, WordPress 7.1.3, WooCommerce 11.1.2) and overlaid only `tests/`.

| Shop | Storage | release-safety | phase1-safety | integration |
|---|---|---|---|---|
| zl | legacy posts | 391 / 0 | 542 / 0 | |
| zh | HPOS | 391 / 0 | 542 / 0 | |
| zi | HPOS, seeded by `gen2.php` (+ `pwsms-stub.php`) | | | 46 / 0 |

No-WordPress suites (PHP 8.3): unit 405 / 0, analytics PASS 149 / CHAR 48 / XFAIL 0, sync-guard 158 / 0, identity 91 / 0; `python3 tests/build-zip-test.py` 14 tests OK. PHP 7.4 (`php:7.4-cli`): lint clean for every file; unit 404 / 1 (the known "Tehran has no DST" artefact of that image's old tzdata), identity 91 / 0, sync-guard 158 / 0.
New in this freeze: fee-item refund regression (analytics pure tests + a real WooCommerce refund in release-safety), Kavenegar 501 and the 402 rule (unit table + phase1 "unclear failure (402)"), clone-send authorisation (release-safety section I: shop manager, administrator without / with invalid / replayed / expired / wrong-user / wrong-target / wrong-address tokens, atomic spend, cron and unknown triggers, unconfirmed identity, one-off SMS entry point), privacy disclosure checks, `outcome()['measured']` aligned with ITT, currency-entity regression.
Mutation check of the new tests: disabling `CRDR_Site::manual_block` and the fee-refund line made 20 tests fail; both were restored.
**Not covered in this freeze:** WooCommerce 8.x-10.x and WP 6.1-6.9 corners, MySQL 5.7/8.0 and MariaDB 10.6 re-runs of the final ZIP (they were green for the previous candidate; the changes since are in the areas above), live Kavenegar / Persian-WooCommerce-SMS.
