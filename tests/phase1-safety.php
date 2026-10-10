<?php
/**
 * Phase 1 regression tests: template safety, server-side sending rules, duplicate-send prevention.
 *
 * DESTRUCTIVE for the plugin's own data (truncates the customer and contact tables, rewrites the plugin settings).
 * It refuses to run unless the site is on localhost AND the option `crdr_test_shop` is "1" (set it by hand, once,
 * in a throwaway shop):     wp option update crdr_test_shop 1
 * Run:                      wp eval-file wp-content/plugins/customer-radar/tests/phase1-safety.php
 *
 * No SMS is ever sent: the Kavenegar API is replaced by tests/phase1-mock.php.
 */
if ( 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) || '1' !== get_option( 'crdr_test_shop' ) ) {
	echo "REFUSING TO RUN: this is not the marked throwaway test shop (needs host 'localhost' and option crdr_test_shop=1).\n";
	return;
}
require_once __DIR__ . '/phase1-mock.php';
crdr_t_install_mock();
global $wpdb;

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

/* ------------------------------------------------------------------ helpers */

function crdr_t_cfg( array $pb = array(), array $set = array(), array $mock = array( 'mode' => 'ok' ) ) {
	update_option( 'crdr_settings', array_merge( CRDR_Settings::defaults(), array( 'sms_provider' => 'kavenegar', 'kavenegar_key' => 'TESTKEY', 'send_from' => 0, 'send_to' => 24 ), $set ) );
	update_option( CRDR_Playbooks::OPTION, array( 'dormant' => array_merge( array(
		'name' => 'خواب', 'mode' => 'manual', 'holdout_pct' => 0, 'daily_cap' => 100, 'window_days' => 14,
		'offer' => array( 'type' => 'none', 'percent' => 0, 'days' => 0 ),
		'template' => 'سلام {name}', 'audience' => array( 'segments' => array( 'hibernating' ) ),
	), $pb ) ) );
	update_option( 'crdr_t_mock', wp_json_encode( $mock ) );
}

function crdr_t_reset( $customers = 10 ) {
	global $wpdb;
	$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::customers() );
	$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
	delete_option( CRDR_Send::LOG_OPTION );
	@unlink( crdr_t_log_path() );
	wp_set_current_user( 1 );
	$now = time();
	for ( $i = 1; $i <= $customers; $i++ ) {
		$phone = sprintf( '09120000%03d', $i );
		$wpdb->insert( CRDR_DB::customers(), array(
			'ckey' => 'p:' . $phone, 'name' => 'مشتری ' . $i, 'phone' => $phone, 'email' => '', 'orders_count' => 1, 'total_spent' => 100000,
			// customer 1 has the highest priority, so campaigns reach customers in a predictable order 1, 2, 3 ...
			'first_ts' => $now - 90 * 86400, 'last_ts' => $now - 90 * 86400, 'segment' => 'hibernating', 'priority' => $customers - $i + 1,
		) );
	}
}

function crdr_t_rows( $where = '1=1' ) {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . CRDR_DB::contacts() . " WHERE {$where} ORDER BY id", ARRAY_A );
}

function crdr_t_count( $where = '1=1' ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() . " WHERE {$where}" );
}

/** Run several worker processes at the same moment. @return array decoded JSON line of each worker */
function crdr_t_parallel( array $jobs, $lead = 6 ) {
	$t0    = microtime( true ) + $lead;
	$procs = array();
	foreach ( $jobs as $i => $job ) {
		$cmd = array_merge( array( PHP_BINARY, $_SERVER['argv'][0], '--path=' . ABSPATH, 'eval-file', __DIR__ . '/phase1-child.php' ), $job, array( (string) $t0 ) );
		$p   = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$procs[ $i ] = array( $p, $pipes );
	}
	$out = array();
	foreach ( $procs as $i => $pp ) {
		$stdout = stream_get_contents( $pp[1][1] );
		$stderr = stream_get_contents( $pp[1][2] );
		proc_close( $pp[0] );
		$lines    = array_filter( explode( "\n", trim( $stdout ) ) );
		$last     = $lines ? json_decode( end( $lines ), true ) : null;
		$out[ $i ] = is_array( $last ) ? $last : array( 'raw' => $stdout, 'err' => $stderr );
	}
	return $out;
}

/** Call an admin-ajax handler in-process and return its JSON answer. */
class CRDR_T_Done extends Exception {}
function crdr_t_ajax( $callable, array $post, $user = 1 ) {
	wp_set_current_user( $user );
	$_POST = $_REQUEST = array_merge( array( 'nonce' => wp_create_nonce( CRDR_Actions::NONCE ) ), $post );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$h = function () {
		return function () {
			throw new CRDR_T_Done();
		};
	};
	add_filter( 'wp_die_ajax_handler', $h );
	ob_start();
	try {
		call_user_func( $callable );
	} catch ( CRDR_T_Done $e ) {
		// wp_send_json_* ended the request, as intended.
	}
	$raw = ob_get_clean();
	remove_filter( 'wp_doing_ajax', '__return_true' );
	remove_filter( 'wp_die_ajax_handler', $h );
	$j = json_decode( $raw, true );
	return is_array( $j ) ? $j : array( 'success' => null, 'raw' => $raw );
}

$h_now = (int) wp_date( 'G' );
// A window that certainly does NOT contain the current hour.
$closed = $h_now < 12 ? array( 'send_from' => $h_now + 2, 'send_to' => $h_now + 4 ) : array( 'send_from' => $h_now - 4, 'send_to' => $h_now - 2 );

/* ============================================================ 0. schema */
echo "== schema and migration\n";
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . CRDR_DB::contacts(), 0 );
t( 'new columns exist', ! array_diff( array( 'run_id', 'localid', 'claim_key' ), $cols ), implode( ',', $cols ) );
$idx = $wpdb->get_results( 'SHOW INDEX FROM ' . CRDR_DB::contacts() . " WHERE Key_name = 'claim_key'", ARRAY_A );
t( 'claim_key is a UNIQUE index', $idx && 0 === (int) $idx[0]['Non_unique'] );
t( 'schema version is 3', '3' === get_option( 'crdr_db_version' ) );

// Upgrade from the previous schema: old table shape + one legacy row survive and gain the new columns.
$wpdb->query( 'ALTER TABLE ' . CRDR_DB::contacts() . ' DROP INDEX claim_key, DROP COLUMN claim_key, DROP COLUMN localid, DROP COLUMN run_id' );
update_option( 'crdr_db_version', '2' );
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
$wpdb->query( 'INSERT INTO ' . CRDR_DB::contacts() . " (ckey,kind,playbook,grp,status,created_ts) VALUES ('p:09129999999','sms','legacy','treated','ok'," . time() . ')' );
CRDR_DB::maybe_upgrade();
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . CRDR_DB::contacts(), 0 );
t( 'upgrade from v2 adds the columns', ! array_diff( array( 'run_id', 'localid', 'claim_key' ), $cols ) );
$legacy = crdr_t_rows( "playbook = 'legacy'" );
t( 'upgrade keeps the legacy row untouched', 1 === count( $legacy ) && 'ok' === $legacy[0]['status'] && '' === $legacy[0]['run_id'] && null === $legacy[0]['claim_key'] );
t( 'upgrade re-creates the unique index', 1 === count( $wpdb->get_results( 'SHOW INDEX FROM ' . CRDR_DB::contacts() . " WHERE Key_name = 'claim_key'" ) ) );
t( 'legacy row still blocks the customer (cooldown)', 'cooldown' === CRDR_DB::blocked_reason( 'p:09129999999' ) );

/* ============================================ A. campaign mode + sending hours */
echo "== server-side mode and sending hours\n";
crdr_t_cfg( array( 'mode' => 'off' ) );
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'OFF campaign: manual run refused', 'mode_off' === $r['blocked'] && 0 === $r['sent'], wp_json_encode( $r ) );
t( 'OFF campaign: nothing reached the gateway', 0 === count( crdr_t_requests() ) );
t( 'OFF campaign: nothing written to contacts', 0 === crdr_t_count() );
t( 'OFF campaign: Persian reason returned', false !== mb_strpos( $r['error'], 'غیرفعال' ) );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'manual', 'override_window' => true ) );
t( 'OFF campaign: the override flag cannot switch it on', 'mode_off' === $r['blocked'] && 0 === count( crdr_t_requests() ) );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'cron' ) );
t( 'OFF campaign: cron refused too', 'mode_off' === $r['blocked'] && 0 === count( crdr_t_requests() ) );

crdr_t_cfg( array( 'mode' => 'manual' ), $closed );
crdr_t_reset( 6 );
t( 'in_send_window() is false for a window that excludes now', false === CRDR_Playbooks::in_send_window() );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'outside the window: manual run refused by default', 'outside_window' === $r['blocked'] && 0 === $r['sent'], wp_json_encode( $r ) );
t( 'outside the window: nothing reached the gateway', 0 === count( crdr_t_requests() ) );
$log = get_option( CRDR_Send::LOG_OPTION, array() );
t( 'a refused manual click is recorded in the run log', 1 === count( $log ) && 'blocked:outside_window' === $log[0]['result'] && 1 === (int) $log[0]['user'] );

wp_set_current_user( 0 );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'manual', 'override_window' => true ) );
t( 'override requested without permission is refused', 'override_forbidden' === $r['blocked'] && 0 === count( crdr_t_requests() ), wp_json_encode( $r ) );
$shop_manager = wp_insert_user( array( 'user_login' => 'crdr_t_shopmgr', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
if ( is_wp_error( $shop_manager ) ) {
	$shop_manager = get_user_by( 'login', 'crdr_t_shopmgr' )->ID;
}
wp_set_current_user( $shop_manager );
t( 'shop manager can run campaigns but is not allowed to override the hours', current_user_can( 'manage_woocommerce' ) && ! CRDR_Send::can_override_window() );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'manual', 'override_window' => true ) );
t( 'override by a shop manager is refused', 'override_forbidden' === $r['blocked'] && 0 === count( crdr_t_requests() ) );

wp_set_current_user( 1 );
crdr_t_cfg( array( 'mode' => 'auto' ), $closed ); // an AUTO campaign, outside its hours (settings only; customers and log are kept)
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'cron', 'override_window' => true ) );
t( 'cron can never override the hours (even for an administrator)', 'outside_window' === $r['blocked'] && 0 === count( crdr_t_requests() ), wp_json_encode( $r ) );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'manual', 'override_window' => true ) );
t( 'override by an administrator is allowed', '' === $r['blocked'] && 6 === $r['sent'], wp_json_encode( $r ) );
$log = get_option( CRDR_Send::LOG_OPTION, array() );
$ran = array_values( array_filter( $log, function ( $e ) {
	return 'ran' === $e['result'];
} ) );
t( 'the override is recorded: who, when, that hours were bypassed', 1 === count( $ran ) && true === $ran[0]['override'] && false === $ran[0]['in_window'] && 1 === (int) $ran[0]['user'] && $ran[0]['ts'] > time() - 60 );
t( 'every SMS of that run carries the run id from the log', 6 === crdr_t_count( "run_id = '" . esc_sql( $ran[0]['run'] ) . "'" ) );

crdr_t_cfg( array( 'mode' => 'manual' ) );  // window 0-24, open
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'inside the window: manual run goes ahead', '' === $r['blocked'] && 6 === $r['sent'], wp_json_encode( $r ) );
$ran = array_values( array_filter( get_option( CRDR_Send::LOG_OPTION, array() ), function ( $e ) {
	return 'ran' === $e['result'];
} ) );
t( 'a normal run is logged without an override', 1 === count( $ran ) && false === $ran[0]['override'] );

crdr_t_cfg( array( 'mode' => 'manual' ) );
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'cron' ) );
t( 'cron refuses a campaign that is only in manual mode', 'not_auto' === $r['blocked'] && 0 === count( crdr_t_requests() ) );
crdr_t_cfg( array( 'mode' => 'auto' ), $closed );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'cron' ) );
t( 'cron refuses an auto campaign outside the hours', 'outside_window' === $r['blocked'] && 0 === count( crdr_t_requests() ) );
// the hourly entry point itself
CRDR_Playbooks::hourly();
t( 'hourly(): outside the hours nothing is sent', 0 === count( crdr_t_requests() ) );
crdr_t_cfg( array( 'mode' => 'auto' ) );
CRDR_Playbooks::hourly();
t( 'hourly(): inside the hours the auto campaign sends', 6 === count( crdr_t_requests() ), (string) count( crdr_t_requests() ) );
t( 'hourly(): releases its lock', false === get_transient( CRDR_Playbooks::LOCK ) );

// preview mirrors the server verdict
crdr_t_cfg( array( 'mode' => 'manual' ), $closed );
$pv = CRDR_Playbooks::preview( 'dormant' );
t( 'preview: reports outside_window, overridable only by an administrator', 'outside_window' === $pv['blocked'] && true === $pv['can_override'] && ! empty( $pv['blocked_message'] ) );
crdr_t_cfg( array( 'mode' => 'off' ) );
$pv = CRDR_Playbooks::preview( 'dormant' );
t( 'preview: an OFF campaign is not overridable', 'mode_off' === $pv['blocked'] && false === $pv['can_override'] );

// the window uses the SITE timezone (Asia/Tehran here) and the original [from, to) semantics
update_option( 'timezone_string', 'Asia/Tehran' );
crdr_t_cfg( array( 'mode' => 'manual' ), array( 'send_from' => 9, 'send_to' => 21 ) );
t( 'site tz: 20:59:59 Tehran is inside', true === CRDR_Playbooks::in_send_window( gmmktime( 17, 29, 59, 10, 8, 2026 ) ) );
t( 'site tz: 21:00:00 Tehran is outside', false === CRDR_Playbooks::in_send_window( gmmktime( 17, 30, 0, 10, 8, 2026 ) ) );
t( 'site tz: 09:00:00 Tehran is inside', true === CRDR_Playbooks::in_send_window( gmmktime( 5, 30, 0, 10, 8, 2026 ) ) );
t( 'site tz: 08:59:59 Tehran is outside', false === CRDR_Playbooks::in_send_window( gmmktime( 5, 29, 59, 10, 8, 2026 ) ) );
update_option( 'timezone_string', 'UTC' );
t( 'site tz switched to UTC: the same instant (17:30Z) is now inside', true === CRDR_Playbooks::in_send_window( gmmktime( 17, 30, 0, 10, 8, 2026 ) ) );
update_option( 'timezone_string', 'Asia/Tehran' );

/* ============================================================ B. templates */
echo "== template safety\n";
// existing built-in campaigns and the default manual-SMS texts must stay valid (nothing is broken by the new rules)
foreach ( CRDR_Playbooks::defaults() as $pid => $pbd ) {
	t( "built-in campaign template is valid: {$pid}", array() === CRDR_Playbooks::template_errors( $pbd ), wp_json_encode( CRDR_Playbooks::template_errors( $pbd ), JSON_UNESCAPED_UNICODE ) );
}
t( 'blank custom campaign template is valid', array() === CRDR_Playbooks::template_errors( CRDR_Playbooks::blank() ) );
t( 'default manual-SMS texts are valid', array() === CRDR_Settings::template_errors( CRDR_Settings::defaults() ) );

// a legacy campaign that would have sent the raw "{coupon}" is now stopped before anything is sent
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name}، کد شما {coupon}' ) );
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'invalid stored template: run refused, nothing sent', 'template' === $r['blocked'] && 0 === $r['sent'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count(), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'invalid stored template: the Persian reason names the token', false !== mb_strpos( $r['error'], '{coupon}' ) );
$pv = CRDR_Playbooks::preview( 'dormant' );
t( 'preview lists the template errors', ! empty( $pv['template_errors'] ) );
t( 'turning an invalid campaign on is refused', is_array( CRDR_Playbooks::set_mode( 'dormant', 'auto' ) ) );
t( 'turning it off is always possible', true === CRDR_Playbooks::set_mode( 'dormant', 'off' ) );

// ... and a valid template with an offer gets a real coupon in the text
crdr_t_cfg( array( 'mode' => 'manual', 'offer' => array( 'type' => 'percent', 'percent' => 10, 'days' => 5 ), 'template' => 'سلام {name}، {discount}٪ تخفیف با کد {coupon} تا {expire}' ) );
crdr_t_reset( 3 );
$r    = CRDR_Playbooks::run( 'dormant', 3 );
$reqs = crdr_t_requests();
t( 'offer campaign: sends', 3 === $r['sent'], wp_json_encode( $r ) );
t( 'offer campaign: no placeholder reaches the gateway', 3 === count( $reqs ) && 0 === count( array_filter( $reqs, function ( $q ) {
	return false !== strpos( $q['message'], '{' ) || false !== strpos( $q['message'], '}' );
} ) ) );
$codes = array_column( crdr_t_rows( "status = 'ok'" ), 'coupon' );
t( 'offer campaign: each SMS carries a real WooCommerce coupon code', 3 === count( array_filter( $codes ) ) && 3 === count( array_filter( $codes, 'wc_get_coupon_id_by_code' ) ) );
t( 'offer campaign: the coupon code is inside the sent text', 3 === count( array_filter( $reqs, function ( $q ) use ( $codes ) {
	foreach ( $codes as $c ) {
		if ( false !== strpos( $q['message'], $c ) ) {
			return true;
		}
	}
	return false;
} ) ) );

// last line of defence: a value that cannot be produced at send time (empty store name) -> nothing is sent
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} از {store}' ) );
crdr_t_reset( 6 );
$blogname = get_option( 'blogname' );
update_option( 'blogname', '' );
$r = CRDR_Playbooks::run( 'dormant', 6 );
update_option( 'blogname', $blogname );
t( 'unfillable placeholder at send time: nothing sent', 0 === $r['sent'] && 0 === count( crdr_t_requests() ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'unfillable placeholder: counted as failed, run stops after 3', 3 === $r['failed'] );
t( 'unfillable placeholder: rows say failed and name the token', 3 === crdr_t_count( "status = 'failed' AND error LIKE '%{store}%'" ) );
t( 'unfillable placeholder: the claim was released (claim_key cleared)', 0 === crdr_t_count( 'claim_key IS NOT NULL' ) );
crdr_t_cfg( array( 'mode' => 'manual', 'cross_sell' => true, 'template' => 'پیشنهاد {product}' ) );
$pbx = CRDR_Playbooks::get( 'dormant' );
$cu  = CRDR_Actions::customer_by_key( 'p:09120000001' );
$cmp = CRDR_Playbooks::compose( $pbx, $cu, false, null );
t( 'compose: missing {product} value -> not ok', false === $cmp[2]['ok'] && array( '{product}' ) === $cmp[2]['missing'] );

// saving
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name}' ) );
$before = get_option( CRDR_Playbooks::OPTION );
$r      = CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'auto', 'template' => 'کد {coupon}', 'offer_type' => 'none' ) );
t( 'save(): unknown/unavailable token -> errors returned', is_array( $r ) && count( $r ) >= 1 && false !== mb_strpos( $r[0], '{coupon}' ) );
t( 'save(): nothing was stored when invalid', $before === get_option( CRDR_Playbooks::OPTION ) );
$d = CRDR_Playbooks::take_draft( 'dormant' );
t( 'save(): the rejected values are kept as a one-shot draft', is_array( $d ) && 'کد {coupon}' === $d['cfg']['template'] && 'auto' === $d['cfg']['mode'] && ! empty( $d['errors'] ) );
t( 'save(): the draft is consumed by reading it', null === CRDR_Playbooks::take_draft( 'dormant' ) );
$r = CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'سلام {nmae}' ) );
t( 'save(): misspelt token rejected', is_array( $r ) );
$r = CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'سلام {name' ) );
t( 'save(): unclosed brace rejected', is_array( $r ) );
$r = CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => str_repeat( 'ا', 401 ) ) );
t( 'save(): over-long text rejected instead of silently cut', is_array( $r ) );
$r = CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'سلام {name} از {store} {optout}', 'offer_type' => 'none', 'daily_cap' => 10 ) );
t( 'save(): a valid template is stored', true === $r && 'سلام {name} از {store} {optout}' === get_option( CRDR_Playbooks::OPTION )['dormant']['template'] );
$r = CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'کد {coupon}', 'offer_type' => 'percent', 'offer_percent' => 10, 'offer_days' => 5 ) );
t( 'save(): {coupon} is accepted once the offer is a discount code', true === $r );
t( 'save(): unknown campaign id', false === CRDR_Playbooks::save( 'nope', array() ) );

// settings (manual SMS texts)
$s = CRDR_Settings::sanitize( array( 'sms_template' => 'سلام {name} کد {coupon}', 'sms_template_coupon' => 'سلام {name}، کد {coupon} تا {expire}' ) );
$e = CRDR_Settings::template_errors( $s );
t( 'settings: {coupon} in the plain text is rejected, in the coupon text it is accepted', 1 === count( $e ) && 0 === mb_strpos( $e[0], 'متن پیامک ساده' ) );
t( 'settings: sanitize() stores nothing', get_option( CRDR_Settings::OPTION ) !== $s );

// the single-customer SMS buttons (call list)
crdr_t_cfg( array( 'mode' => 'off' ), array( 'sms_template' => 'سلام {name}، کد {coupon}' ) );
crdr_t_reset( 2 );
$cid = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . ' ORDER BY id LIMIT 1' );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'call list SMS: invalid manual text is refused', false === $j['success'] && false !== mb_strpos( $j['data']['message'], '{coupon}' ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
t( 'call list SMS: nothing claimed, nothing sent', 0 === crdr_t_count() && 0 === count( crdr_t_requests() ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cid, 'coupon' => 0 ) );
t( 'call list preview: shows the problem instead of a raw {coupon}', false === $j['success'] );
crdr_t_cfg( array( 'mode' => 'off' ), array( 'sms_template' => 'سلام {name}' ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'call list SMS: valid text is sent', true === $j['success'] && 1 === count( crdr_t_requests() ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'call list SMS: a second click is refused (no duplicate)', false === $j['success'] && 1 === count( crdr_t_requests() ) && 1 === crdr_t_count( "kind = 'sms'" ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_pb_run' ), array( 'id' => 'dormant' ) );
t( 'ajax_pb_run reports the refusal (campaign is off) as data for the UI', true === $j['success'] && 'mode_off' === $j['data']['blocked'] );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_pb_mode' ), array( 'id' => 'dormant', 'mode' => 'manual' ) );
t( 'ajax_pb_mode: valid template can be switched on', true === $j['success'] );
crdr_t_cfg( array( 'mode' => 'off', 'template' => 'کد {coupon}' ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_pb_mode' ), array( 'id' => 'dormant', 'mode' => 'manual' ) );
t( 'ajax_pb_mode: invalid template cannot be switched on', false === $j['success'] && false !== mb_strpos( $j['data']['message'], '{coupon}' ) );

/* ============================== A2. single-customer (one-off) SMS and the sending hours */
echo "== one-off SMS: sending hours\n";
// This action is promotional only: its text comes from the two win-back templates in Settings, it is offered on the
// call list of lapsed customers, and it obeys opt-out and cooldown. Transactional SMS (orders) never go through it.
$coupons = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
};
$h   = (int) wp_date( 'G' );
$cfg = function ( $from, $to ) {
	crdr_t_cfg( array( 'mode' => 'off' ), array( 'send_from' => $from, 'send_to' => $to, 'sms_template' => 'سلام {name}', 'sms_template_coupon' => 'سلام {name} کد {coupon} تا {expire}' ) );
	crdr_t_reset( 3 );
	return (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . ' ORDER BY id LIMIT 1' );
};

// outside the hours
$cid    = $cfg( $closed['send_from'], $closed['send_to'] );
$coup0  = $coupons();
$j      = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off outside hours: refused', false === $j['success'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
t( 'one-off outside hours: Persian reason that names the hours', false !== mb_strpos( $j['data']['message'], 'ساعت مجاز' ) && false !== mb_strpos( $j['data']['message'], CRDR_Jalali::digits( (string) $closed['send_from'] ) ), $j['data']['message'] ?? '' );
t( 'one-off outside hours: nothing claimed, nothing reached the gateway', 0 === crdr_t_count() && 0 === count( crdr_t_requests() ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms_coupon' ) );
t( 'one-off outside hours: coupon SMS refused too', false === $j['success'] && 0 === crdr_t_count() && 0 === count( crdr_t_requests() ) );
t( 'one-off outside hours: no discount code is created for a blocked coupon SMS', $coup0 === $coupons() );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms', 'override' => 1, 'override_window' => 1 ) );
t( 'one-off outside hours: an administrator cannot override it (no such option)', false === $j['success'] && 0 === count( crdr_t_requests() ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cid, 'coupon' => 0 ) );
t( 'one-off outside hours: the preview already says so (no pointless confirm box)', false === $j['success'] && false !== mb_strpos( $j['data']['message'], 'ساعت مجاز' ) );
foreach ( array( 'called', 'skip', 'optout' ) as $type ) {
	$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => $type, 'note' => 'x' ) );
	t( "one-off outside hours: '$type' is not an SMS and still works", true === $j['success'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}
$cid2 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . ' ORDER BY id LIMIT 1 OFFSET 1' );
$j    = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid2, 'type' => 'sms' ) );
t( 'one-off outside hours: a refused attempt leaves no trace, so the customer is not "contacted"', false === $j['success'] && 0 === crdr_t_count( "ckey = 'p:09120000002'" ) );

// a blocked click must not use up the cooldown: once the window opens, the same customer can be messaged
$cid = $cfg( $closed['send_from'], $closed['send_to'] );
crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
crdr_t_cfg( array( 'mode' => 'off' ), array( 'send_from' => 0, 'send_to' => 24, 'sms_template' => 'سلام {name}' ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off: after a blocked click, the same customer can be messaged once the window opens', true === $j['success'] && 1 === count( crdr_t_requests() ) && 1 === crdr_t_count( "kind = 'sms' AND status = 'ok'" ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );

// boundaries around "now"
$cid = $cfg( $h, $h + 1 ); // [h, h+1): the start is inclusive
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off boundary: window starting at the current hour is open', true === $j['success'] && 1 === count( crdr_t_requests() ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$cid = $cfg( 0, $h ); // [0, h): the end is exclusive (for h = 0 this is an empty window)
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off boundary: window ending at the current hour is closed', false === $j['success'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count() );
$cid = $cfg( $h, $h ); // from == to is always closed (unchanged behaviour)
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off boundary: from == to is closed', false === $j['success'] && 0 === count( crdr_t_requests() ) );
$cid = $cfg( 0, 24 );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms_coupon' ) );
t( 'one-off boundary: 0..24 is always open, coupon SMS goes out', true === $j['success'] && 1 === count( crdr_t_requests() ) && 1 === crdr_t_count( "coupon <> '' AND status = 'ok'" ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );

// overnight window: not supported, so it blocks every hour (same as campaigns); the message shows the hours so it is discoverable
$cid = $cfg( 22, 6 );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off overnight window (22..6): blocked at any hour, hours shown in the message', false === $j['success'] && 0 === count( crdr_t_requests() ) && false !== mb_strpos( $j['data']['message'], CRDR_Jalali::digits( '22' ) ) && false !== mb_strpos( $j['data']['message'], CRDR_Jalali::digits( '6' ) ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );

// existing protections are untouched: capability, nonce, opt-out
$cid = $cfg( 0, 24 );
$uid = wp_insert_user( array( 'user_login' => 'crdr_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ), $uid );
t( 'one-off: a user without manage_woocommerce is refused (403)', false === $j['success'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count(), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms', 'nonce' => 'bad' ) );
t( 'one-off: a bad nonce is refused', true !== ( $j['success'] ?? null ) && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count() );
crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'optout' ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off: opt-out is still respected inside the hours', false === $j['success'] && 0 === count( crdr_t_requests() ) );
wp_delete_user( $uid );
crdr_t_reset( 0 );

/* ====================================================== C. duplicate sends */
echo "== duplicate-send prevention\n";
crdr_t_cfg();
crdr_t_reset( 4 );
$ck = 'p:09120000001';
$c1 = CRDR_Send::claim( $ck, 'dormant', 'run-a' );
$c2 = CRDR_Send::claim( $ck, 'dormant', 'run-b' );
t( 'claim: the first caller wins', isset( $c1['id'] ) );
t( 'claim: a second caller for the same customer is told "busy"', isset( $c2['skip'] ) && 'busy' === $c2['skip'], wp_json_encode( $c2 ) );
t( 'claim: only one row exists, and it is pending with the claim key', 1 === crdr_t_count( "ckey = '" . esc_sql( $ck ) . "'" ) && 1 === crdr_t_count( "status = 'pending' AND claim_key IS NOT NULL" ) );
t( 'claim: other customers are unaffected', isset( CRDR_Send::claim( 'p:09120000002', 'dormant', 'run-b' )['id'] ) );
$wpdb->show_errors( false );
t( 'claim: the database itself rejects a duplicate claim_key', false === $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . CRDR_DB::contacts() . " (ckey,kind,status,claim_key,created_ts) VALUES (%s,'sms','pending',%s,%d)", $ck, $ck, time() ) ) );
CRDR_Send::finalize( $c1['id'], 'ok' );
t( 'finalize: clears the claim key', 0 === crdr_t_count( "id = {$c1['id']} AND claim_key IS NOT NULL" ) );
$c3 = CRDR_Send::claim( $ck, 'dormant', 'run-c' );
t( 'claim after a finished send: refused by the cooldown re-check', isset( $c3['skip'] ) && 'cooldown' === $c3['skip'] );
t( 'claim: a refused claim leaves no row behind', 1 === crdr_t_count( "ckey = '" . esc_sql( $ck ) . "'" ) );
CRDR_DB::add_contact( 'p:09120000003', 'optout', array( 'playbook' => 'manual' ) );
t( 'claim: opted-out customer is refused', 'optout' === CRDR_Send::claim( 'p:09120000003', 'dormant', 'x' )['skip'] );
CRDR_DB::add_contact( 'p:09120000004', 'called', array( 'playbook' => 'manual' ) );
t( 'claim: a recent call blocks with the default cooldown', 'cooldown' === CRDR_Send::claim( 'p:09120000004', 'dormant', 'x' )['skip'] );
$c4 = CRDR_Send::claim( 'p:09120000004', 'dormant', 'x', 'treated', 0 );
t( 'claim: with cooldown 0 a call no longer blocks (only SMS get the 24 h floor)', isset( $c4['id'] ) );
CRDR_Send::finalize( $c4['id'], 'ok' );
t( 'claim: with cooldown 0 an SMS from the last 24 h still blocks', 'cooldown' === CRDR_Send::claim( 'p:09120000004', 'dormant', 'x', 'treated', 0 )['skip'] );
CRDR_DB::add_contact( 'p:09120000005', 'sms', array( 'playbook' => 'dormant', 'status' => 'failed', 'error' => 'x' ) );
t( 'claim: a FAILED earlier attempt never blocks', isset( CRDR_Send::claim( 'p:09120000005', 'dormant', 'x' )['id'] ) );

// repeated execution, with the cooldown setting at 0 (previously every run messaged everyone again)
crdr_t_cfg( array( 'cooldown' => 0 ) );
crdr_t_reset( 8 );
$a = CRDR_Playbooks::run( 'dormant', 8 );
$b = CRDR_Playbooks::run( 'dormant', 8 );
$c = CRDR_Playbooks::run( 'dormant', 8 );
t( 'repeat run, cooldown 0: first run sends to everyone', 8 === $a['sent'], wp_json_encode( $a ) );
t( 'repeat run, cooldown 0: later runs send nothing', 0 === $b['sent'] && 0 === $c['sent'], wp_json_encode( array( $b, $c ) ) );
t( 'repeat run, cooldown 0: the gateway saw each customer exactly once', 8 === count( crdr_t_requests() ) && 8 === count( array_unique( array_column( crdr_t_requests(), 'receptor' ) ) ) );
crdr_t_cfg( array( 'cooldown' => 60 ) );
crdr_t_reset( 8 );
CRDR_Playbooks::run( 'dormant', 8 );
$b = CRDR_Playbooks::run( 'dormant', 8 );
t( 'repeat run, default cooldown: second run sends nothing', 0 === $b['sent'] && 8 === count( crdr_t_requests() ) );
$q = crdr_t_requests();
t( 'every request carries a 19-digit numeric localid, all different', 8 === count( array_filter( array_column( $q, 'localid' ), function ( $l ) {
	return 1 === preg_match( '/^\d{19}$/', (string) $l );
} ) ) && 8 === count( array_unique( array_column( $q, 'localid' ) ) ) );
$stored = array_column( crdr_t_rows(), 'localid' );
$sent   = array_column( $q, 'localid' );
sort( $stored );
sort( $sent );
t( 'the localid sent to the gateway equals the one stored on the row', 8 === crdr_t_count( "localid <> ''" ) && $stored === $sent );
t( 'HTTP redirects are disabled for the send (a redirect cannot replay the POST)', 0 === $q[0]['redirect'] );

// real concurrency: three OS processes with their own DB connections
echo "   (starting parallel workers, ~10 s)\n";
crdr_t_cfg( array( 'mode' => 'auto' ), array(), array( 'mode' => 'ok', 'delay_ms' => 120 ) );
crdr_t_reset( 12 );
$res = crdr_t_parallel( array( array( 'run', 'cron', '12' ), array( 'run', 'manual', '12' ), array( 'run', 'manual', '12' ) ) );
$sent_total = array_sum( array_column( $res, 'sent' ) );
$blocked    = array_column( $res, 'blocked' );
$q          = crdr_t_requests();
t( 'parallel cron + 2 manual runs: gateway got exactly one SMS per customer', 12 === count( $q ) && 12 === count( array_unique( array_column( $q, 'receptor' ) ) ), count( $q ) . ' requests: ' . wp_json_encode( $res ) );
t( 'parallel runs: the reported "sent" adds up to the customers', 12 === $sent_total, (string) $sent_total );
t( 'parallel runs: at least one extra run was told the campaign is busy', count( array_filter( $blocked, function ( $b ) {
	return 'busy' === $b;
} ) ) >= 1, wp_json_encode( $blocked ) );
t( 'parallel runs: one sms row per customer, all ok, no claim left over', 12 === crdr_t_count( "kind = 'sms' AND status = 'ok'" ) && 12 === (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT ckey) FROM ' . CRDR_DB::contacts() ) && 0 === crdr_t_count( 'claim_key IS NOT NULL' ) );
t( 'parallel runs: the run lock was released afterwards', 'got' === CRDR_Send::run_lock( 'dormant' ) );
CRDR_Send::run_unlock( 'dormant' );

// claim layer alone (no run lock at all): three processes try to claim and send every customer
crdr_t_cfg( array( 'mode' => 'auto' ), array(), array( 'mode' => 'ok', 'delay_ms' => 100 ) );
crdr_t_reset( 12 );
$res = crdr_t_parallel( array( array( 'claimsend' ), array( 'claimsend' ), array( 'claimsend' ) ) );
$q   = crdr_t_requests();
t( 'claim-only race: exactly one SMS per customer even without the run lock', 12 === count( $q ) && 12 === count( array_unique( array_column( $q, 'receptor' ) ) ), count( $q ) . ' requests ' . wp_json_encode( $res ) );
t( 'claim-only race: the losers were turned away (busy or cooldown)', 24 === array_sum( array_map( function ( $r ) {
	return ( isset( $r['busy'] ) ? $r['busy'] : 0 ) + ( isset( $r['cooldown'] ) ? $r['cooldown'] : 0 );
}, $res ) ), wp_json_encode( $res ) );
t( 'claim-only race: nothing is left pending', 0 === crdr_t_count( "status = 'pending'" ) );

// the daily cap holds under concurrency
crdr_t_cfg( array( 'mode' => 'auto', 'daily_cap' => 5 ), array(), array( 'mode' => 'ok', 'delay_ms' => 100 ) );
crdr_t_reset( 12 );
$res = crdr_t_parallel( array( array( 'run', 'cron', '12' ), array( 'run', 'manual', '12' ), array( 'run', 'manual', '12' ) ) );
t( 'daily cap of 5 is never exceeded by parallel runs', 5 === count( crdr_t_requests() ) && 5 === CRDR_Playbooks::sent_today( 'dormant' ), count( crdr_t_requests() ) . ' ' . wp_json_encode( $res ) );

// holdout customers are claimed once too, and are never messaged
crdr_t_cfg( array( 'holdout_pct' => 50 ) );
crdr_t_reset( 40 );
CRDR_Playbooks::run( 'dormant', 50 );
$ho = crdr_t_count( "grp = 'holdout' AND status = 'holdout'" );
$tr = crdr_t_count( "grp = 'treated' AND status = 'ok'" );
t( 'holdout: some customers held back, the rest messaged', $ho > 5 && $tr > 5 && 40 === $ho + $tr, "$ho/$tr" );
t( 'holdout: no held-back customer reached the gateway', 0 === count( array_intersect( array_column( crdr_t_requests(), 'receptor' ), array_map( function ( $r ) {
	return substr( $r['ckey'], 2 );
}, crdr_t_rows( "grp = 'holdout'" ) ) ) ) );
t( 'holdout: one row per customer, no claim left over', 40 === (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT ckey) FROM ' . CRDR_DB::contacts() ) && 0 === crdr_t_count( 'claim_key IS NOT NULL' ) );

/* ============================================ D. timeouts and failures */
echo "== timeouts, ambiguous results and definite failures\n";
crdr_t_cfg( array(), array(), array( 'mode' => 'ok', 'by_phone' => array( '09120000001' => 'timeout' ) ) );
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'timeout: the run stops at the uncertain SMS and says so', 1 === $r['unknown'] && 0 === $r['sent'] && '' !== $r['error'] && false !== mb_strpos( $r['error'], 'ممکن است پیامک ارسال شده باشد' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$u = crdr_t_rows( "status = 'unknown'" );
t( 'timeout: the row is "unknown" (not failed), keeps message + localid, claim cleared', 1 === count( $u ) && '' !== $u[0]['localid'] && 'سلام مشتری 1' === $u[0]['message'] && null === $u[0]['claim_key'] );
t( 'timeout: the request really was made once', 1 === count( crdr_t_requests() ) );
t( 'timeout: the customer now counts as contacted', 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000001' ) );
t( 'timeout: ... and uses up the daily cap', 1 === CRDR_Playbooks::sent_today( 'dormant' ) );
$r2 = CRDR_Playbooks::run( 'dormant', 6 );
$by = array_count_values( array_column( crdr_t_requests(), 'receptor' ) );
t( 'timeout: the next run carries on with OTHER customers', 5 === $r2['sent'], wp_json_encode( $r2 ) );
t( 'timeout: the uncertain customer was never retried', 1 === $by['09120000001'] && 6 === count( $by ), wp_json_encode( $by ) );
t( 'late answer: an unknown row can still be corrected to ok', true === CRDR_Send::finalize( $u[0]['id'], 'ok' ) && 'ok' === crdr_t_rows( "id = {$u[0]['id']}" )[0]['status'] );

crdr_t_cfg( array(), array(), array( 'mode' => 'http500' ) );
crdr_t_reset( 4 );
$r = CRDR_Playbooks::run( 'dormant', 4 );
t( 'HTTP 500: treated as uncertain (the gateway may have queued it)', 1 === $r['unknown'] && 0 === $r['failed'] && 1 === count( crdr_t_requests() ) );

crdr_t_cfg( array(), array(), array( 'mode' => 'connect_fail' ) );
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'cannot reach the gateway (DNS): definite failure, stops after 3', 3 === $r['failed'] && 0 === $r['unknown'] && 0 === $r['sent'], wp_json_encode( $r ) );
t( 'definite failure: rows are "failed", claims released, nothing counted as contacted', 3 === crdr_t_count( "status = 'failed'" ) && 0 === crdr_t_count( 'claim_key IS NOT NULL' ) && 0 === CRDR_Playbooks::sent_today( 'dormant' ) );
update_option( 'crdr_t_mock', wp_json_encode( array( 'mode' => 'ok' ) ) );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'definite failure: those customers CAN be tried again later (nothing was sent)', 6 === $r['sent'], wp_json_encode( $r ) );

crdr_t_cfg( array(), array(), array( 'mode' => 'credit' ) );
crdr_t_reset( 5 );
$r = CRDR_Playbooks::run( 'dormant', 5 );
t( 'gateway says "no credit" (418): definite failure, not "unknown"', 3 === $r['failed'] && 0 === $r['unknown'] && false !== strpos( $r['error'], '418' ) );

// a claim whose database record cannot be written must not send anything
crdr_t_cfg();
crdr_t_reset( 2 );
$claim = CRDR_Send::claim( 'p:09120000001', 'dormant', 'x' );
$wpdb->query( 'DELETE FROM ' . CRDR_DB::contacts() . ' WHERE id = ' . (int) $claim['id'] );
$out = CRDR_Send::send_claimed( $claim['id'], '09120000001', 'سلام' );
t( 'if the message cannot be recorded first, it is not sent', 'failed' === $out['state'] && 0 === count( crdr_t_requests() ) );

/* ================================================ E. recovery after a crash */
echo "== recovery after partial failure\n";
crdr_t_cfg();
crdr_t_reset( 4 );
$fresh = CRDR_Send::claim( 'p:09120000001', 'dormant', 'x' );
t( 'recover_stale leaves a fresh claim alone', 0 === CRDR_Send::recover_stale() && 'busy' === CRDR_Send::claim( 'p:09120000001', 'dormant', 'y' )['skip'] );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 3600 ) . ' WHERE id = ' . (int) $fresh['id'] );
t( 'recover_stale converts an abandoned claim to "unknown"', 1 === CRDR_Send::recover_stale() );
$row = crdr_t_rows( "id = {$fresh['id']}" )[0];
t( 'recovered row: unknown, claim key freed, explanation stored', 'unknown' === $row['status'] && null === $row['claim_key'] && '' !== $row['error'] );
t( 'recovered customer is NOT eligible for another send', 'cooldown' === CRDR_Send::claim( 'p:09120000001', 'dormant', 'z' )['skip'] );

// a real crash: the worker talks to the gateway and dies before recording the outcome
crdr_t_cfg( array(), array(), array( 'mode' => 'ok' ) );
crdr_t_reset( 4 );
$res = crdr_t_parallel( array( array( 'crash', 'p:09120000002' ) ), 4 );
t( 'crash worker: SMS left, row stayed pending', ! empty( $res[0]['claimed'] ) && 1 === crdr_t_count( "ckey = 'p:09120000002' AND status = 'pending' AND claim_key IS NOT NULL" ) && 1 === count( crdr_t_requests() ), wp_json_encode( $res ) );
$r = CRDR_Playbooks::run( 'dormant', 10 );
$by = array_count_values( array_column( crdr_t_requests(), 'receptor' ) );
t( 'after the crash: a new run does not resend to that customer, the others are served', 3 === $r['sent'] && 1 === $by['09120000002'], wp_json_encode( array( $r, $by ) ) );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 3600 ) . " WHERE ckey = 'p:09120000002' AND status = 'pending'" );
$r = CRDR_Playbooks::run( 'dormant', 10 );
$by = array_count_values( array_column( crdr_t_requests(), 'receptor' ) );
t( 'after recovery: still not resent; the row is now "unknown"', 0 === $r['sent'] && 1 === $by['09120000002'] && 1 === crdr_t_count( "ckey = 'p:09120000002' AND status = 'unknown'" ), wp_json_encode( array( $r, $by ) ) );
t( 'after the crash: no claim is left blocking anything', 0 === crdr_t_count( 'claim_key IS NOT NULL' ) );

/* ============================================ F. the pages I touched still render */
echo "== pages render without PHP warnings\n";
require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button() etc. are not loaded outside wp-admin
function crdr_t_page( $callable, array $get ) {
	wp_set_current_user( 1 );
	$_GET = $_REQUEST = $get;
	ob_start();
	call_user_func( $callable );
	return ob_get_clean();
}
$clean = function ( $html ) {
	return ! preg_match( '/(Warning|Notice|Deprecated|Fatal error)\s*:/', $html ) && false === strpos( $html, 'Stack trace' );
};
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name}' ) );
crdr_t_reset( 3 );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign list renders and mentions the hours apply to manual runs too', $clean( $html ) && false !== mb_strpos( $html, 'ساعت مجاز ارسال (خودکار و دستی)' ) );
CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'auto', 'template' => 'کد {coupon}', 'offer_type' => 'none' ) ); // rejected -> draft
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns', 'edit' => 'dormant', 'invalid' => 1 ) );
t( 'edit form after a rejected save: explains what is wrong, in Persian', $clean( $html ) && false !== mb_strpos( $html, 'کمپین ذخیره نشد' ) && false !== mb_strpos( $html, 'notice-error' ) );
t( 'edit form after a rejected save: keeps what the person typed', false !== mb_strpos( $html, '>کد {coupon}</textarea>' ) );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns', 'edit' => 'dormant' ) );
t( 'edit form opened normally: stored template, no stale error', $clean( $html ) && false !== mb_strpos( $html, '>سلام {name}</textarea>' ) && false === mb_strpos( $html, 'کمپین ذخیره نشد' ) );
set_transient( CRDR_Settings::draft_key(), array( 'values' => CRDR_Settings::sanitize( array( 'sms_template' => 'مرحله {coupon}' ) ), 'errors' => array( 'خطای آزمایشی' ) ), 300 );
$html = crdr_t_page( array( 'CRDR_Page_Settings', 'render' ), array( 'page' => 'customer-radar-settings', 'invalid' => 1 ) );
t( 'settings after a rejected save: error shown and typed text kept', $clean( $html ) && false !== mb_strpos( $html, 'خطای آزمایشی' ) && false !== mb_strpos( $html, 'مرحله {coupon}' ) );
$html = crdr_t_page( array( 'CRDR_Page_Settings', 'render' ), array( 'page' => 'customer-radar-settings' ) );
t( 'settings page opened normally renders', $clean( $html ) && false !== mb_strpos( $html, 'ارسال پیامک‌های تبلیغاتی (کمپین خودکار، کمپین دستی و پیامک تکی فهرست تماس) فقط از ساعت' ) );
CRDR_DB::add_contact( 'p:09120000001', 'sms', array( 'playbook' => 'dormant', 'status' => 'unknown', 'error' => 'timeout', 'message' => 'سلام' ) );
$cid  = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$html = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid ) );
t( 'customer page shows an uncertain SMS as such', $clean( $html ) && false !== mb_strpos( $html, 'نتیجه نامشخص' ) );

/* ----------------------------------------------------------------- done */
printf( "\nphase 1 safety: %d passed, %d failed\n", $GLOBALS['P'], $GLOBALS['F'] );
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::halt( $GLOBALS['F'] ? 1 : 0 );
}
