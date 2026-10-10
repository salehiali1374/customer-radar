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
    wp eval-file wp-content/plugins/customer-radar/tests/phase1-safety.php      # expect: 370 passed, 0 failed

The suite pins the clock (03:00 Asia/Tehran, via the `crdr_send_window_now` filter) and sets the site timezone itself, so it gives the same
result at any time of day and on repeated runs.

`tests/integration.php` needs the seeded shop (`gen2.php`) and the `pwsms-stub.php` mu-plugin as before.

Remove everything when done:

    docker rm -f crdr-wp crdr-db crdr-seed; docker volume rm crdr_db crdr_html; docker network rm crdr_net
