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

Remove everything when done:

    docker rm -f crdr-wp crdr-db crdr-seed; docker volume rm crdr_db crdr_html; docker network rm crdr_net
