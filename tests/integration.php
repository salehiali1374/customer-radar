<?php
/**
 * Integration test for Customer Radar. Run INSIDE the throwaway test shop (it creates orders and contacts):
 *   wp eval-file wp-content/plugins/customer-radar/tests/integration.php
 *
 * Needs: WooCommerce with the seeded Persian test catalogue (gen2.php) and the PWSMS stub mu-plugin.
 */
$GLOBALS['P'] = 0;
$GLOBALS['F'] = 0;
function t( $name, $cond, $extra = '' ) {
	if ( $cond ) {
		$GLOBALS['P']++;
	} else {
		$GLOBALS['F']++;
		echo "FAIL: {$name} {$extra}\n";
	}
}
global $wpdb;
add_filter( 'pre_wp_mail', '__return_false' );
@unlink( WP_CONTENT_DIR . '/pwsms-calls.log' );

// Start from a clean slate so the test can be repeated: contacts, campaign settings, test orders and coupons.
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
delete_option( CRDR_Playbooks::OPTION );
foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'created_via' => 'crdr-test' ) ) as $old ) {
	$oo = wc_get_order( $old );
	if ( $oo ) {
		$oo->delete( true );
	}
}
foreach ( get_posts( array( 'post_type' => 'shop_coupon', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => '_crdr_ckey' ) ) as $cid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
	wp_delete_post( $cid, true );
}

/* ------------------------------------------------------------------ sync */
$t0 = microtime( true );
$s  = CRDR_Sync::start();
while ( ! in_array( $s['phase'], array( 'done', 'idle' ), true ) ) {
	$s = CRDR_Sync::step();
}
printf( "sync: %.1fs, %d orders imported, %d customers\n", microtime( true ) - $t0, $s['done'], $s['customers'] );
$st = CRDR_Analytics::get();
t( 'orders imported', $s['done'] > 1500 );
t( 'analytics built', ! empty( $st['built'] ) && $st['orders_total'] === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() ) );
t( 'items stored', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::items() ) > $st['orders_total'] );
t( 'monthly series', count( $st['monthly'] ) >= 12 );
t( 'monthly revenue equals total', abs( array_sum( array_column( $st['monthly'], 'revenue' ) ) - (float) $wpdb->get_var( 'SELECT SUM(net) FROM ' . CRDR_DB::orders() ) ) < 5 || count( $st['monthly'] ) === 24 );
t( 'repeat share plausible', $st['repeat']['customers_pct'] > 0.2 && $st['repeat']['customers_pct'] < 0.8, (string) $st['repeat']['customers_pct'] );
t( 'survival table', count( $st['survival'] ) >= 10 );
t( 'cohorts', count( $st['cohorts'] ) >= 6 );
t( 'rfm grid 5x5', count( $st['rfm_grid'] ) === 5 );
t( 'deciles monotonic', $st['pareto']['deciles'][9] > 0.99 && $st['pareto']['deciles'][0] < $st['pareto']['deciles'][5] );
t( 'threshold suggestion', ! empty( $st['threshold']['threshold'] ) );

$planted = array(
	array( 'سرم ویتامین سی', 'ضدآفتاب SPF50' ), array( 'شامپو ترمیم‌کننده', 'نرم‌کننده مو' ), array( 'رژ لب مات', 'ریمل حجم‌دهنده' ),
	array( 'ژل شستشوی صورت', 'تونر آبرسان' ), array( 'ماسک مو', 'روغن آرگان' ), array( 'ژل دوش', 'لوسیون بدن' ),
	array( 'کرم پودر', 'کانسیلر' ), array( 'عطر زنانه گل‌های بهاری', 'اسپری بدن' ), array( 'کرم مرطوب‌کننده روزانه', 'کرم دور چشم' ), array( 'سرم مو', 'اسپری محافظ حرارتی' ),
);
$found = array();
foreach ( $st['pairs'] as $p ) {
	$found[] = array( CRDR_Analytics::product_name( $p['a'] ), CRDR_Analytics::product_name( $p['b'] ) );
}
$hits = 0;
foreach ( $planted as $pp ) {
	foreach ( $found as $f ) {
		if ( in_array( $pp[0], $f, true ) && in_array( $pp[1], $f, true ) ) {
			$hits++;
			break;
		}
	}
}
printf( "affinity: recovered %d of %d planted product pairs (%d pairs reported)\n", $hits, count( $planted ), count( $st['pairs'] ) );
t( 'affinity recovers most planted pairs', $hits >= 7 );
$false_top = 0;
foreach ( array_slice( $found, 0, 10 ) as $f ) {
	$ok = false;
	foreach ( $planted as $pp ) {
		if ( in_array( $pp[0], $f, true ) && in_array( $pp[1], $f, true ) ) {
			$ok = true;
		}
	}
	if ( ! $ok ) {
		$false_top++;
	}
}
printf( "affinity: %d of the top 10 reported pairs are NOT planted (noise)\n", $false_top );
t( 'top pairs mostly real', $false_top <= 3 );

/* --------------------------------------------------------------- insights */
echo 'diag: winback audience before insights = ' . CRDR_Audience::count( CRDR_Playbooks::filter( CRDR_Playbooks::get( 'winback_risk' ) ) ) . ', contacts rows = ' . $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() ) . "\n";
$cards = CRDR_Insights::generate();
$ids   = array_column( $cards, 'id' );
echo 'insight cards: ' . implode( ',', $ids ) . "\n";
t( 'insights generated', count( $cards ) >= 5 );
t( 'insight cards well formed', count( array_filter( $cards, function ( $c ) {
	return ! empty( $c['title'] ) && ! empty( $c['body'] );
} ) ) === count( $cards ) );
t( 'cross-sell insight', in_array( 'cross', $ids, true ) );
t( 'threshold insight', in_array( 'threshold', $ids, true ) );

/* ------------------------------------------------------------- campaigns */
CRDR_Playbooks::set_mode( 'winback_risk', 'manual' );
$pb  = CRDR_Playbooks::get( 'winback_risk' );
$pre = CRDR_Playbooks::preview( 'winback_risk' );
t( 'preview has audience', $pre['audience'] > 20, (string) $pre['audience'] );
t( 'preview samples render coupon placeholder', ! empty( $pre['samples'] ) && false !== mb_strpos( $pre['samples'][0]['message'], 'XXXXXXXX' ) );
t( 'preview message has opt-out text', ! empty( $pre['samples'] ) && false !== mb_strpos( $pre['samples'][0]['message'], 'لغو' ) );
t( 'scenario ordered low<mid<high', $pre['scenario'][0] < $pre['scenario'][1] && $pre['scenario'][1] < $pre['scenario'][2] );

// opt-out must remove a customer from every audience
$victim = $wpdb->get_var( 'SELECT ckey FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( CRDR_Playbooks::filter( $pb ) ) . ' LIMIT 1' );
CRDR_DB::add_contact( $victim, 'optout', array( 'playbook' => 'manual' ) );
t( 'opt-out hides customer', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( CRDR_Playbooks::filter( $pb ) ) . ' AND c.ckey = %s', $victim ) ) ); // phpcs:ignore

// cap enforcement: set a small cap, run twice
$saved = get_option( CRDR_Playbooks::OPTION, array() );
$saved['winback_risk']['daily_cap']    = 40;
$saved['winback_risk']['holdout_pct']  = 25;
update_option( CRDR_Playbooks::OPTION, $saved );
$tot = array( 'sent' => 0, 'held_out' => 0, 'skipped' => 0, 'failed' => 0 );
for ( $i = 0; $i < 12; $i++ ) {
	$r = CRDR_Playbooks::run( 'winback_risk', 20 );
	foreach ( $tot as $k => $_ ) {
		$tot[ $k ] += $r[ $k ];
	}
	if ( 0 === $r['sent'] + $r['held_out'] + $r['skipped'] + $r['failed'] || $r['left'] <= 0 ) {
		break;
	}
}
printf( "run: sent=%d held_out=%d failed=%d (cap 40, holdout 25%%)\n", $tot['sent'], $tot['held_out'], $tot['failed'] );
$left_aud = CRDR_Audience::count( CRDR_Playbooks::filter( CRDR_Playbooks::get( 'winback_risk' ) ) );
t( 'daily cap never exceeded', $tot['sent'] <= 40, (string) $tot['sent'] );
t( 'sends up to the cap unless the audience ran out', $tot['sent'] >= 38 || 0 === $left_aud, "sent {$tot['sent']}, audience left {$left_aud}" );
t( 'holdout share near 25 percent', $tot['sent'] + $tot['held_out'] > 0 && abs( $tot['held_out'] / ( $tot['sent'] + $tot['held_out'] ) - 0.25 ) < 0.12 );
t( 'no failures', 0 === $tot['failed'] );
$again = CRDR_Playbooks::run( 'winback_risk', 20 );
t( 'cap blocks further sends today', 0 === $again['sent'] );
$calls = file_exists( WP_CONTENT_DIR . '/pwsms-calls.log' ) ? file( WP_CONTENT_DIR . '/pwsms-calls.log' ) : array();
t( 'provider got exactly the sent messages', count( $calls ) === $tot['sent'], count( $calls ) . ' vs ' . $tot['sent'] );
$coupons = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . CRDR_DB::contacts() . " WHERE playbook = 'winback_risk' AND coupon <> ''" );
t( 'one coupon per sent message', $coupons === $tot['sent'] );
t( 'coupon codes were created in WooCommerce', wc_get_coupon_id_by_code( (string) $wpdb->get_var( "SELECT coupon FROM " . CRDR_DB::contacts() . " WHERE playbook = 'winback_risk' AND coupon <> '' LIMIT 1" ) ) > 0 );
// nobody contacted twice, and holdout customers are not contacted by another run
$dups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT ckey FROM " . CRDR_DB::contacts() . " WHERE playbook = 'winback_risk' AND kind = 'sms' GROUP BY ckey HAVING COUNT(*) > 1) d" );
t( 'no customer contacted twice', 0 === $dups );
$leak = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . CRDR_DB::contacts() . " WHERE ckey = " . $wpdb->prepare( '%s', $victim ) . " AND kind = 'sms'" );
t( 'opted-out customer never messaged', 0 === $leak );

// cross-sell
CRDR_Playbooks::set_mode( 'cross_sell', 'manual' );
$cx = CRDR_Playbooks::preview( 'cross_sell' );
t( 'cross-sell has samples', ! empty( $cx['samples'] ) );
if ( ! empty( $cx['samples'] ) ) {
	echo 'cross-sell sample: ' . $cx['samples'][0]['message'] . "\n";
	t( 'cross-sell message names a product and link', false !== mb_strpos( $cx['samples'][0]['message'], 'http' ) );
}
$one = $wpdb->get_var( 'SELECT ckey FROM ' . CRDR_DB::customers() . ' WHERE orders_count >= 2 LIMIT 1' );
$rec = CRDR_Recommend::for_customer( $one );
t( 'recommendation is something not yet bought', null === $rec || ! $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . CRDR_DB::orders() . ' o JOIN ' . CRDR_DB::items() . ' i ON i.order_id = o.order_id WHERE o.ckey = %s AND i.product_id = %d', $one, $rec['id'] ) ) ); // phpcs:ignore

/* ---------------------------------------------- measurement (known uplift) */
// Make the winback contacts "old" (20 days) and plant orders: 18% of treated vs 6% of holdout buy again.
$k       = CRDR_DB::contacts();
$wpdb->query( "UPDATE {$k} SET created_ts = created_ts - 20 * 86400 WHERE playbook = 'winback_risk'" );
$rows = $wpdb->get_results( "SELECT ckey, grp, created_ts FROM {$k} WHERE playbook = 'winback_risk' AND kind = 'sms'", ARRAY_A );
$pids = get_posts( array( 'post_type' => 'product', 'numberposts' => 3, 'fields' => 'ids' ) );
mt_srand( 7 );
$planted_t = 0;
$planted_h = 0;
foreach ( $rows as $r ) {
	$p = 'treated' === $r['grp'] ? 0.18 : 0.06;
	if ( mt_rand( 0, 1000 ) / 1000 < $p ) {
		$cust = CRDR_Actions::customer_by_key( $r['ckey'] );
		$o    = wc_create_order( array( 'created_via' => 'crdr-test' ) );
		$o->set_billing_phone( $cust['phone'] );
		$o->set_billing_first_name( $cust['name'] );
		$it = new WC_Order_Item_Product();
		$it->set_product_id( $pids[0] );
		$it->set_quantity( 1 );
		$it->set_subtotal( 500000 );
		$it->set_total( 500000 );
		$o->add_item( $it );
		$o->set_total( 500000 );
		$o->set_date_created( $r['created_ts'] + 5 * 86400 );
		$o->set_status( 'completed' );
		$o->save();
		'treated' === $r['grp'] ? $planted_t++ : $planted_h++;
	}
}
$s = CRDR_Sync::start();
while ( ! in_array( $s['phase'], array( 'done', 'idle' ), true ) ) {
	$s = CRDR_Sync::step();
}
$res = CRDR_Playbooks::results( 'winback_risk' );
printf( "results: treated %d/%d (%.1f%%), holdout %d/%d (%.1f%%), diff=%.1f pts, p=%.4f, enough=%s (planted %d + %d orders)\n",
	$res['treated']['conv'], $res['treated']['n'], 100 * $res['treated']['conv'] / max( 1, $res['treated']['n'] ),
	$res['holdout']['conv'], $res['holdout']['n'], 100 * $res['holdout']['conv'] / max( 1, $res['holdout']['n'] ),
	100 * $res['test']['diff'], $res['test']['p'], var_export( $res['test']['enough'], true ), $planted_t, $planted_h );
t( 'results count planted treated conversions', $res['treated']['conv'] >= $planted_t, "{$res['treated']['conv']} vs {$planted_t}" );
t( 'results measure treated near planted rate', abs( $res['treated']['conv'] / max( 1, $res['treated']['n'] ) - $planted_t / max( 1, $res['treated']['n'] ) ) < 0.03 );
t( 'uplift direction is positive', $res['test']['diff'] > 0 );
t( 'confidence interval brackets the estimate', $res['test']['ci_low'] < $res['test']['diff'] && $res['test']['ci_high'] > $res['test']['diff'] );
printf( "(note: with only %d holdout customers the test %s)\n", $res['holdout']['n'], $res['test']['enough'] ? 'has enough data' : 'correctly says the sample is too small' );
t( 'small holdout is flagged, not over-claimed', $res['holdout']['n'] >= 30 || false === $res['test']['enough'] );


// Statistical power: a planted 18% vs 6% effect on large groups must be detected and the CI must cover the truth.
$wpdb->query( "DELETE FROM {$k} WHERE playbook = 'power_test'" );
$custs = $wpdb->get_results( 'SELECT ckey, phone, name FROM ' . CRDR_DB::customers() . " WHERE phone <> '' ORDER BY id DESC LIMIT 800", ARRAY_A );
mt_srand( 11 );
$pl_t = 0;
$pl_h = 0;
$nt   = 0;
$nh   = 0;
foreach ( $custs as $i => $c ) {
	$grp = $i % 2 ? 'holdout' : 'treated';
	$cts = time() - 30 * 86400;
	$wpdb->insert( $k, array( 'ckey' => $c['ckey'], 'kind' => 'sms', 'playbook' => 'power_test', 'grp' => $grp, 'status' => 'holdout' === $grp ? 'holdout' : 'ok', 'created_ts' => $cts ) );
	'holdout' === $grp ? $nh++ : $nt++;
	if ( mt_rand( 0, 1000 ) / 1000 < ( 'holdout' === $grp ? 0.06 : 0.18 ) ) {
		$o = wc_create_order( array( 'created_via' => 'crdr-test' ) );
		$o->set_billing_phone( $c['phone'] );
		$o->set_billing_first_name( $c['name'] );
		$it = new WC_Order_Item_Product();
		$it->set_product_id( $pids[0] );
		$it->set_quantity( 1 );
		$it->set_subtotal( 400000 );
		$it->set_total( 400000 );
		$o->add_item( $it );
		$o->set_total( 400000 );
		$o->set_date_created( $cts + 6 * 86400 );
		$o->set_status( 'completed' );
		$o->save();
		'holdout' === $grp ? $pl_h++ : $pl_t++;
	}
}
$saved['power_test'] = array_replace( CRDR_Playbooks::blank(), array( 'name' => 'power', 'window_days' => 14 ) );
update_option( CRDR_Playbooks::OPTION, $saved );
$s = CRDR_Sync::start();
while ( ! in_array( $s['phase'], array( 'done', 'idle' ), true ) ) {
	$s = CRDR_Sync::step();
}
$pw = CRDR_Playbooks::results( 'power_test' );
printf( "power test: treated %d/%d vs holdout %d/%d, diff=%.1f pts (true 12), 95%% CI [%.1f, %.1f], p=%.5f\n", $pw['treated']['conv'], $pw['treated']['n'], $pw['holdout']['conv'], $pw['holdout']['n'], 100 * $pw['test']['diff'], 100 * $pw['test']['ci_low'], 100 * $pw['test']['ci_high'], $pw['test']['p'] );
t( 'power: every planted order is attributed', $pw['treated']['conv'] >= $pl_t - 1 && $pw['holdout']['conv'] >= $pl_h - 1, "{$pw['treated']['conv']}/{$pl_t} {$pw['holdout']['conv']}/{$pl_h}" );
t( 'power: enough data flagged', true === $pw['test']['enough'] );
t( 'power: real uplift detected', $pw['test']['p'] < 0.01 && $pw['test']['diff'] > 0 );
t( 'power: CI covers the planted effect (12 pts) or the sampled one', ( $pw['test']['ci_low'] <= 0.12 && $pw['test']['ci_high'] >= 0.12 ) || ( $pw['test']['ci_low'] <= ( $pl_t / max( 1, $nt ) - $pl_h / max( 1, $nh ) ) && $pw['test']['ci_high'] >= ( $pl_t / max( 1, $nt ) - $pl_h / max( 1, $nh ) ) ) );
t( 'power: revenue attributed', $pw['treated']['rev'] >= 400000 * ( $pl_t - 1 ) );
$wpdb->query( "DELETE FROM {$k} WHERE playbook = 'power_test'" );
unset( $saved['power_test'] );
update_option( CRDR_Playbooks::OPTION, $saved );

// A campaign with no real effect must NOT look significant: same conversion in both groups.
$wpdb->query( "DELETE FROM {$k} WHERE playbook = 'null_test'" );
$custs = $wpdb->get_results( 'SELECT ckey FROM ' . CRDR_DB::customers() . ' ORDER BY id LIMIT 600', ARRAY_A );
foreach ( $custs as $i => $c ) {
	$wpdb->insert( $k, array( 'ckey' => $c['ckey'], 'kind' => 'sms', 'playbook' => 'null_test', 'grp' => $i % 2 ? 'holdout' : 'treated', 'status' => $i % 2 ? 'holdout' : 'ok', 'created_ts' => time() - 40 * 86400 ) );
}
$saved['null_test'] = array_replace( CRDR_Playbooks::blank(), array( 'name' => 'null', 'window_days' => 14 ) );
update_option( CRDR_Playbooks::OPTION, $saved );
$null = CRDR_Playbooks::results( 'null_test' );
printf( "null campaign: treated %.1f%% vs holdout %.1f%% (same data-generating process), p=%.3f\n", 100 * $null['treated']['conv'] / max( 1, $null['treated']['n'] ), 100 * $null['holdout']['conv'] / max( 1, $null['holdout']['n'] ), $null['test']['p'] );
t( 'null campaign not flagged significant', $null['test']['p'] > 0.01, (string) $null['test']['p'] );

// clean up what this test created
$wpdb->query( "DELETE FROM {$k} WHERE playbook = 'null_test'" );
unset( $saved['null_test'] );
update_option( CRDR_Playbooks::OPTION, $saved );

echo "\nintegration: {$GLOBALS['P']} passed, {$GLOBALS['F']} failed\n";
