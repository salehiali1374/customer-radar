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
		'template' => 'سلام {name} {optout}', 'audience' => array( 'segments' => array( 'hibernating' ) ),
	), $pb ) ) );
	update_option( 'crdr_t_mock', wp_json_encode( $mock ) );
}

function crdr_t_reset( $customers = 10 ) {
	global $wpdb;
	$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::customers() );
	$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
	delete_option( CRDR_Send::LOG_OPTION );
	delete_option( CRDR_Send::CRON_LOG_OPTION );
	delete_option( CRDR_Send::LOST_OPTION );
	delete_option( CRDR_Send::EPOCH_OPTION );         // "lift the set-aside" times belong to the scenario that set them
	delete_option( CRDR_Send::REJECT_EPOCH_OPTION );
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
	// The one-off handlers now need the identity token the page carries next to the row id (section H tests that rule itself). Tests
	// that are about something else behave like a fresh page: they send the token of whoever the id points to right now. A test
	// that wants to send no token, or a wrong one, passes 'k' itself ('k' => null sends none).
	if ( is_array( $callable ) && in_array( $callable[1], array( 'ajax_contact', 'ajax_preview' ), true ) && isset( $post['id'] ) && ! array_key_exists( 'k', $post ) ) {
		$row = CRDR_Actions::customer( (int) $post['id'] );
		if ( $row ) {
			$post['k'] = CRDR_Actions::identity( $row['ckey'] );
		}
	}
	if ( array_key_exists( 'k', $post ) && null === $post['k'] ) {
		unset( $post['k'] );
	}
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

// A pinned clock: the whole suite runs at a fixed time of day, in a fixed site timezone, so no test depends on the hour
// (or on the timezone a previous run left in the database). The plugin reads the time through the
// 'crdr_send_window_now' filter in CRDR_Playbooks::in_send_window(). Worker processes started by the concurrency tests
// do not have this filter; they use windows that are always open (0..24).
update_option( 'timezone_string', 'Asia/Tehran' );
$GLOBALS['crdr_t_clock'] = null;
function crdr_t_pin( $hms ) {
	if ( $GLOBALS['crdr_t_clock'] ) {
		remove_filter( 'crdr_send_window_now', $GLOBALS['crdr_t_clock'] );
		$GLOBALS['crdr_t_clock'] = null;
	}
	if ( null !== $hms ) {
		$ts                      = ( new DateTime( '2026-10-08 ' . $hms, new DateTimeZone( 'Asia/Tehran' ) ) )->getTimestamp();
		$GLOBALS['crdr_t_clock'] = function () use ( $ts ) {
			return $ts;
		};
		add_filter( 'crdr_send_window_now', $GLOBALS['crdr_t_clock'] );
	}
}
crdr_t_pin( '03:00:00' );
// A window that does NOT contain the pinned time (03:00 Tehran).
$closed = array( 'send_from' => 9, 'send_to' => 21 );

/* ============================================================ 0. schema */
echo "== schema and migration\n";
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . CRDR_DB::contacts(), 0 );
t( 'new columns exist', ! array_diff( array( 'run_id', 'localid', 'claim_key' ), $cols ), implode( ',', $cols ) );
$idx = $wpdb->get_results( 'SHOW INDEX FROM ' . CRDR_DB::contacts() . " WHERE Key_name = 'claim_key'", ARRAY_A );
t( 'claim_key is a UNIQUE index', $idx && 0 === (int) $idx[0]['Non_unique'] );
// PHASE 3A: the schema version is 5 now (4 -> 5 adds uid + email to the order table, see below); 3 -> 4 is still data only.
// PHASE 4.2: the current schema is 6 (crdr_contacts.orphan_ts, the retention sweep); the assertion is about "current", the number moved.
t( 'schema version is the current one (6)', '6' === CRDR_DB::DB_VERSION && CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) );
$ocols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . CRDR_DB::orders(), 0 );
t( 'the order table has the identity evidence columns uid and email', ! array_diff( array( 'uid', 'email' ), $ocols ), implode( ',', $ocols ) );

// Upgrade from the previous schema: old table shape + one legacy row survive and gain the new columns.
$wpdb->query( 'ALTER TABLE ' . CRDR_DB::contacts() . ' DROP INDEX claim_key, DROP COLUMN claim_key, DROP COLUMN localid, DROP COLUMN run_id' );
update_option( 'crdr_db_version', '2' );
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
$wpdb->query( 'INSERT INTO ' . CRDR_DB::contacts() . " (ckey,kind,playbook,grp,status,created_ts) VALUES ('p:09129999999','sms','legacy','treated','ok'," . time() . ')' );
CRDR_DB::maybe_upgrade();
// PHASE 4.1: an update from a schema older than 5 sets the "first rebuild required" flag, which pauses SENDING until a rebuild has finished
// (tests/release-safety.php tests that window). This suite goes on to send, so the simulated update is "completed" here, after
// asserting that the update did open the window.
t( 'an update from schema 2 opens the first-rebuild window', (int) get_option( CRDR_DB::REBUILD_OPTION ) > 0 );
delete_option( CRDR_DB::REBUILD_OPTION );
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
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name}، کد شما {coupon} {optout}' ) );
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 6 );
t( 'invalid stored template: run refused, nothing sent', 'template' === $r['blocked'] && 0 === $r['sent'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count(), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'invalid stored template: the Persian reason names the token', false !== mb_strpos( $r['error'], '{coupon}' ) );
$pv = CRDR_Playbooks::preview( 'dormant' );
t( 'preview lists the template errors', ! empty( $pv['template_errors'] ) );
t( 'turning an invalid campaign on is refused', is_array( CRDR_Playbooks::set_mode( 'dormant', 'auto' ) ) );
t( 'turning it off is always possible', true === CRDR_Playbooks::set_mode( 'dormant', 'off' ) );

// ... and a valid template with an offer gets a real coupon in the text
crdr_t_cfg( array( 'mode' => 'manual', 'offer' => array( 'type' => 'percent', 'percent' => 10, 'days' => 5 ), 'template' => 'سلام {name}، {discount}٪ تخفیف با کد {coupon} تا {expire} {optout}' ) );
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
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} از {store} {optout}' ) );
crdr_t_reset( 6 );
$blogname = get_option( 'blogname' );
update_option( 'blogname', '' );
$r = CRDR_Playbooks::run( 'dormant', 6 );
update_option( 'blogname', $blogname );
t( 'unfillable placeholder at send time: nothing sent', 0 === $r['sent'] && 0 === count( crdr_t_requests() ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'unfillable placeholder: counted as failed (6 of 6: fewer than 10 in a row, so the run goes on)', 6 === $r['failed'] );
t( 'unfillable placeholder: rows say nobuild (nothing sent, not a contact) and name the token', 6 === crdr_t_count( "status = 'nobuild' AND error LIKE '%{store}%'" ) && 0 === crdr_t_count( "status = 'failed'" ) );
t( 'unfillable placeholder: the claim was released (claim_key cleared)', 0 === crdr_t_count( 'claim_key IS NOT NULL' ) );
crdr_t_cfg( array( 'mode' => 'manual', 'cross_sell' => true, 'template' => 'پیشنهاد {product} {optout}' ) );
$pbx = CRDR_Playbooks::get( 'dormant' );
$cu  = CRDR_Actions::customer_by_key( 'p:09120000001' );
$cmp = CRDR_Playbooks::compose( $pbx, $cu, false, null );
t( 'compose: missing {product} value -> not ok', false === $cmp[2]['ok'] && array( '{product}' ) === $cmp[2]['missing'] );

// saving
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}' ) );
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
$r = CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'کد {coupon} {optout}', 'offer_type' => 'percent', 'offer_percent' => 10, 'offer_days' => 5 ) );
t( 'save(): {coupon} is accepted once the offer is a discount code', true === $r );
t( 'save(): unknown campaign id', false === CRDR_Playbooks::save( 'nope', array() ) );

// settings (manual SMS texts)
$s = CRDR_Settings::sanitize( array( 'sms_template' => 'سلام {name} کد {coupon} {optout}', 'sms_template_coupon' => 'سلام {name}، کد {coupon} تا {expire} {optout}' ) );
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
crdr_t_cfg( array( 'mode' => 'off' ), array( 'sms_template' => 'سلام {name} {optout}' ) );
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
// Deterministic: the clock is pinned (crdr_t_pin) and the site timezone is Asia/Tehran: nothing here depends on the real hour.
$pin = 'crdr_t_pin';
$coupons = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
};
$cfg = function ( $from, $to ) {
	crdr_t_cfg( array( 'mode' => 'off' ), array( 'send_from' => $from, 'send_to' => $to, 'sms_template' => 'سلام {name} {optout}', 'sms_template_coupon' => 'سلام {name} کد {coupon} تا {expire} {optout}' ) );
	crdr_t_reset( 3 );
	return (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . ' ORDER BY id LIMIT 1' );
};
$send = function ( $cid, $type = 'sms', array $more = array() ) {
	return crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array_merge( array( 'id' => $cid, 'type' => $type ), $more ) );
};

// without a pinned clock the real time is used (the filter is only a seam), and real-time results do not depend on the hour here
$pin( null );
crdr_t_cfg( array(), array( 'send_from' => 0, 'send_to' => 24 ) );
t( 'no pinned clock: a 0..24 window is open whatever the real time is', true === CRDR_Playbooks::in_send_window() );
crdr_t_cfg( array(), array( 'send_from' => 5, 'send_to' => 5 ) );
t( 'no pinned clock: a window with from == to is closed whatever the real time is', false === CRDR_Playbooks::in_send_window() );
$pin( '03:00:00' ); // the window is 09..21
$cid   = $cfg( 9, 21 );
t( 'clock seam: 03:00 is outside 09..21, whatever the real time is', false === CRDR_Playbooks::in_send_window() );
$pin( '12:00:00' );
t( 'clock seam: 12:00 is inside 09..21', true === CRDR_Playbooks::in_send_window() );
$pin( '03:00:00' );
$coup0 = $coupons();
$j     = $send( $cid );
t( 'one-off outside hours: refused', false === $j['success'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
t( 'one-off outside hours: Persian reason that names the hours', false !== mb_strpos( $j['data']['message'], 'ساعت مجاز' ) && false !== mb_strpos( $j['data']['message'], CRDR_Jalali::digits( '9' ) ) && false !== mb_strpos( $j['data']['message'], CRDR_Jalali::digits( '21' ) ), $j['data']['message'] ?? '' );
t( 'one-off outside hours: nothing claimed, nothing reached the gateway', 0 === crdr_t_count() && 0 === count( crdr_t_requests() ) );
$j = $send( $cid, 'sms_coupon' );
t( 'one-off outside hours: coupon SMS refused too', false === $j['success'] && 0 === crdr_t_count() && 0 === count( crdr_t_requests() ) );
t( 'one-off outside hours: no discount code is created for a blocked coupon SMS', $coup0 === $coupons() );
$j = $send( $cid, 'sms', array( 'override' => 1, 'override_window' => 1 ) );
t( 'one-off outside hours: an administrator cannot override it (no such option)', false === $j['success'] && 0 === count( crdr_t_requests() ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cid, 'coupon' => 0 ) );
t( 'one-off outside hours: the preview already says so (no pointless confirm box)', false === $j['success'] && false !== mb_strpos( $j['data']['message'], 'ساعت مجاز' ) );
foreach ( array( 'called', 'skip', 'optout' ) as $type ) {
	$j = $send( $cid, $type, array( 'note' => 'x' ) );
	t( "one-off outside hours: '$type' is not an SMS and still works", true === $j['success'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}
$cid2 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . ' ORDER BY id LIMIT 1 OFFSET 1' );
$j    = $send( $cid2 );
t( 'one-off outside hours: a refused attempt leaves no trace, so the customer is not "contacted"', false === $j['success'] && 0 === crdr_t_count( "ckey = 'p:09120000002'" ) );

// a blocked click must not use up the cooldown: once the window opens, the same customer can be messaged
$cid = $cfg( 9, 21 );
$send( $cid );
$pin( '12:00:00' );
$j = $send( $cid );
t( 'one-off: after a blocked click at 03:00, the same customer can be messaged at 12:00', true === $j['success'] && 1 === count( crdr_t_requests() ) && 1 === crdr_t_count( "kind = 'sms' AND status = 'ok'" ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );

// boundaries of 09..21: the start is inclusive, the end is exclusive
foreach ( array(
	'08:59:59' => false,
	'09:00:00' => true,
	'20:59:59' => true,
	'21:00:00' => false,
	'23:59:59' => false,
	'00:00:00' => false,
) as $at => $open ) {
	$pin( $at );
	$cid = $cfg( 9, 21 );
	$j   = $send( $cid );
	t( "one-off boundary: 09..21 at {$at} is " . ( $open ? 'open' : 'closed' ), $open === ( true === $j['success'] ) && ( $open ? 1 : 0 ) === count( crdr_t_requests() ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}
$pin( '09:00:00' );
$cid = $cfg( 9, 9 ); // from == to is always closed (unchanged behaviour)
$j   = $send( $cid );
t( 'one-off boundary: from == to is closed even at that hour', false === $j['success'] && 0 === count( crdr_t_requests() ) );
$pin( '03:00:00' );
$cid = $cfg( 0, 24 );
$j   = $send( $cid, 'sms_coupon' );
t( 'one-off boundary: 0..24 is open at 03:00, coupon SMS goes out', true === $j['success'] && 1 === count( crdr_t_requests() ) && 1 === crdr_t_count( "coupon <> '' AND status = 'ok'" ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );

// overnight window: not supported, so it blocks every hour (same as campaigns); the message shows the hours so it is discoverable
foreach ( array( '23:00:00', '03:00:00', '12:00:00' ) as $at ) {
	$pin( $at );
	$cid = $cfg( 22, 6 );
	$j   = $send( $cid );
	t( "one-off overnight window (22..6) at {$at}: blocked, hours shown in the message", false === $j['success'] && 0 === count( crdr_t_requests() ) && false !== mb_strpos( $j['data']['message'], CRDR_Jalali::digits( '22' ) ) && false !== mb_strpos( $j['data']['message'], CRDR_Jalali::digits( '6' ) ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}
$pin( '03:00:00' );

// existing protections are untouched: capability, nonce, opt-out
$cid = $cfg( 0, 24 );
$uid = wp_insert_user( array( 'user_login' => 'crdr_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ), $uid );
t( 'one-off: a user without manage_woocommerce is refused (403)', false === $j['success'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count(), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = $send( $cid, 'sms', array( 'nonce' => 'bad' ) );
t( 'one-off: a bad nonce is refused', true !== ( $j['success'] ?? null ) && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count() );
$send( $cid, 'optout' );
$j = $send( $cid );
t( 'one-off: opt-out is still respected inside the hours', false === $j['success'] && 0 === count( crdr_t_requests() ) );
wp_delete_user( $uid );
crdr_t_reset( 0 );

/* ============================ B2. automatic (cron) campaign blocks are observable */
echo "== cron: blocked runs are recorded and visible\n";
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
$hs   = function () {
	$a = get_option( CRDR_Health::OPTION, array() );
	return isset( $a['dormant'] ) ? $a['dormant'] : array();
};
$cronlog = function ( $prefix = 'blocked:' ) {
	return array_values( array_filter( (array) get_option( CRDR_Send::CRON_LOG_OPTION, array() ), function ( $e ) use ( $prefix ) {
		return 'cron' === $e['trigger'] && 0 === strpos( (string) $e['result'], $prefix );
	} ) );
};
$notice = function () {
	wp_set_current_user( 1 );
	return CRDR_Health::notice_html();
};
$reset_health = function () {
	delete_option( CRDR_Health::OPTION );
	delete_option( CRDR_Send::LOG_OPTION );
	delete_option( CRDR_Send::CRON_LOG_OPTION );
};

// --- invalid template (e.g. saved by an older version, or edited in the database)
$reset_health();
crdr_t_cfg( array( 'mode' => 'auto', 'template' => 'سلام {nmae}' ) );
crdr_t_reset( 6 );
CRDR_Playbooks::hourly();
$b = $hs()['blocked'] ?? null;
t( 'cron + invalid template: nothing sent', 0 === count( crdr_t_requests() ) && 0 === crdr_t_count() );
t( 'cron + invalid template: the block is recorded with its reason', is_array( $b ) && 'template' === $b['code'] && 1 === $b['count'] && $b['since'] > time() - 60, wp_json_encode( $hs() ) );
t( 'cron + invalid template: one audit-log entry, marked as cron and blocked', 1 === count( $cronlog() ) && 'blocked:template' === $cronlog()[0]['result'] && 'dormant' === $cronlog()[0]['playbook'] );
CRDR_Playbooks::hourly();
CRDR_Playbooks::hourly();
CRDR_Playbooks::hourly();
t( 'cron + invalid template: repeated ticks only raise the counter', 4 === $hs()['blocked']['count'] && $hs()['blocked']['since'] === $b['since'] );
t( 'cron + invalid template: ...and do NOT add log entries (no noise)', 1 === count( $cronlog() ), (string) count( $cronlog() ) );
$html = $notice();
t( 'cron + invalid template: the admin sees a persistent warning that names the campaign and the fix', false !== strpos( $html, 'notice-warning' ) && false !== mb_strpos( $html, 'خواب' ) && false !== mb_strpos( $html, CRDR_Health::message( 'template' ) ) && false !== strpos( $html, 'customer-radar-campaigns' ), $html );
wp_set_current_user( 0 );
t( 'the warning is only for people who may manage the plugin', '' === CRDR_Health::notice_html() );
// where it is shown
wp_set_current_user( 1 );
$show = function ( $screen ) {
	set_current_screen( $screen );
	ob_start();
	CRDR_Health::admin_notices();
	return ob_get_clean();
};
t( 'warning shows on the WordPress dashboard', '' !== $show( 'dashboard' ) );
t( 'warning shows on the plugin\'s own pages', '' !== $show( 'toplevel_page_customer-radar' ) && '' !== $show( 'admin_page_customer-radar-campaigns' ) );
t( 'warning does not take over unrelated admin screens', '' === $show( 'edit-post' ) );
set_current_screen( 'front' );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign card shows the block and since when', false !== mb_strpos( $html, 'اجرای خودکار متوقف' ) && false !== mb_strpos( $html, CRDR_Health::message( 'template' ) ) );
// only automatic campaigns are reported
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {nmae}' ) );
t( 'a campaign no longer in auto mode is not reported (stale state ignored)', '' === $notice() );
crdr_t_cfg( array( 'mode' => 'auto', 'template' => 'سلام {nmae}' ) );
t( 'back in auto mode it is reported again', '' !== $notice() );
// privacy
$dump = wp_json_encode( get_option( CRDR_Health::OPTION ) ) . wp_json_encode( get_option( CRDR_Send::LOG_OPTION ) ) . wp_json_encode( get_option( CRDR_Send::CRON_LOG_OPTION ) );
t( 'nothing personal or secret in the recorded state: no key, phone or message text', false === strpos( $dump, 'TESTKEY' ) && false === strpos( $dump, '0912' ) && false === strpos( $dump, 'nmae' ) && false === mb_strpos( $dump, 'سلام' ) );

// --- recovery: fixed template, next tick executes, the block is cleared and the history says so
crdr_t_cfg( array( 'mode' => 'auto', 'template' => 'سلام {name} {optout}' ) );
CRDR_Playbooks::hourly();
$st = $hs();
t( 'recovery: the run executed and sent', 6 === count( crdr_t_requests() ) && 6 === crdr_t_count( "status = 'ok'" ) );
t( 'recovery: block cleared, last executed run recorded with counts', ! isset( $st['blocked'] ) && 6 === $st['last_run']['sent'] && 0 === $st['last_run']['failed'], wp_json_encode( $st ) );
t( 'recovery: one "recovered" entry in the log', 1 === count( $cronlog( 'recovered' ) ) );
t( 'recovery: no warning any more', '' === $notice() );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign card tells a blocked run apart from an executed one', false === mb_strpos( $html, 'اجرای خودکار متوقف' ) && false !== mb_strpos( $html, 'آخرین اجرای خودکار' ) );

// --- no SMS provider
$reset_health();
crdr_t_cfg( array( 'mode' => 'auto' ), array( 'sms_provider' => 'none' ) );
crdr_t_reset( 4 );
CRDR_Playbooks::hourly();
CRDR_Playbooks::hourly();
t( 'cron + no provider: recorded once as no_provider, counted twice', 'no_provider' === ( $hs()['blocked']['code'] ?? '' ) && 2 === $hs()['blocked']['count'] && 1 === count( $cronlog() ) && 0 === crdr_t_count() );
t( 'cron + no provider: the warning points to Settings', false !== strpos( $notice(), 'customer-radar-settings' ) );
crdr_t_cfg( array( 'mode' => 'auto', 'template' => 'سلام {nmae}' ), array( 'sms_provider' => 'none' ) );
CRDR_Playbooks::hourly();
t( 'cron: a different reason replaces the old one and is logged as a new event', 'no_provider' === $hs()['blocked']['code'] && 1 === count( $cronlog() ) );
crdr_t_cfg( array( 'mode' => 'auto', 'template' => 'سلام {nmae}' ) ); // provider fixed, template still bad
CRDR_Playbooks::hourly();
t( 'cron: reason changes no_provider -> template: new entry, counter restarted', 'template' === $hs()['blocked']['code'] && 1 === $hs()['blocked']['count'] && 2 === count( $cronlog() ) );

// --- busy campaign lock (held by ANOTHER connection, like a manual run in another request)
$reset_health();
crdr_t_cfg( array( 'mode' => 'auto' ) );
crdr_t_reset( 4 );
$ln = new ReflectionMethod( 'CRDR_Send', 'lock_name' );
$ln->setAccessible( true );
$other = new mysqli( $wpdb->dbhost, DB_USER, DB_PASSWORD, DB_NAME );
$nm    = $other->real_escape_string( $ln->invoke( null, 'dormant' ) );
$got   = $other->query( "SELECT GET_LOCK('{$nm}', 0)" )->fetch_row()[0];
t( 'busy: the second connection holds the lock', '1' === (string) $got );
CRDR_Playbooks::hourly();
t( 'busy: recorded, nothing sent', 'busy' === ( $hs()['blocked']['code'] ?? '' ) && 1 === $hs()['blocked']['count'] && 0 === crdr_t_count() );
t( 'busy: one tick is normal (a manual run may be in progress): no warning yet', '' === $notice() );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'busy: the campaign card already mentions it', false !== mb_strpos( $html, CRDR_Health::message( 'busy' ) ) );
CRDR_Playbooks::hourly();
CRDR_Playbooks::hourly();
t( 'busy: three ticks in a row raise the warning', 3 === $hs()['blocked']['count'] && false !== mb_strpos( $notice(), CRDR_Health::message( 'busy' ) ) );
t( 'busy: still one log entry', 1 === count( $cronlog() ) );
$other->query( "SELECT RELEASE_LOCK('{$nm}')" );
$other->close();
CRDR_Playbooks::hourly();
t( 'busy: once the lock is free the run executes and the block clears', 4 === count( crdr_t_requests() ) && ! isset( $hs()['blocked'] ) && '' === $notice() );

// --- sending hours: expected closure is NOT a problem; an impossible window IS
$reset_health();
crdr_t_cfg( array( 'mode' => 'auto' ), $closed );
crdr_t_reset( 3 );
CRDR_Playbooks::hourly();
CRDR_Playbooks::hourly();
t( 'outside the (valid) hours: nothing sent, nothing recorded, nothing logged, no warning', 0 === count( crdr_t_requests() ) && array() === $hs() && array() === (array) get_option( CRDR_Send::LOG_OPTION, array() ) && array() === (array) get_option( CRDR_Send::CRON_LOG_OPTION, array() ) && '' === $notice() );
crdr_t_cfg( array( 'mode' => 'auto' ), array( 'send_from' => 22, 'send_to' => 6 ) );
CRDR_Playbooks::hourly();
CRDR_Playbooks::hourly();
t( 'overnight/impossible window (22..6): recorded as window_invalid', 'window_invalid' === ( $hs()['blocked']['code'] ?? '' ) && 2 === $hs()['blocked']['count'] && 0 === count( crdr_t_requests() ) );
t( 'overnight/impossible window: logged once, warning points to Settings', 1 === count( $cronlog() ) && false !== strpos( $notice(), 'customer-radar-settings' ) );
crdr_t_cfg( array( 'mode' => 'auto' ), $closed ); // fixed, but it is night right now
CRDR_Playbooks::hourly();
t( 'window fixed while the shop is closed: the stale window_invalid warning goes away (rules unchanged: still no send)', ! isset( $hs()['blocked'] ) && 0 === count( crdr_t_requests() ) && '' === $notice() );
crdr_t_cfg( array( 'mode' => 'manual' ), array( 'send_from' => 22, 'send_to' => 6 ) );
CRDR_Playbooks::hourly();
t( 'an impossible window is not reported for campaigns that are not automatic', array() === $hs() );

// --- manual runs never touch the cron health state (a person already sees the answer)
$reset_health();
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {nmae}' ) );
CRDR_Playbooks::run( 'dormant', 5, array( 'trigger' => 'manual' ) );
t( 'manual blocked run: logged as before, but the cron health state is untouched', array() === $hs() && 1 === count( (array) get_option( CRDR_Send::LOG_OPTION ) ) );

// --- housekeeping: state of a deleted campaign is dropped on the next tick
update_option( CRDR_Health::OPTION, array( 'ghost' => array( 'blocked' => array( 'code' => 'template', 'since' => 1, 'last' => 1, 'count' => 1 ) ) ) );
crdr_t_cfg( array( 'mode' => 'auto' ) );
CRDR_Playbooks::hourly();
t( 'state of a campaign that no longer exists is pruned', ! isset( get_option( CRDR_Health::OPTION )['ghost'] ) );
$reset_health();
crdr_t_reset( 0 );

/* ============================== A3. the run log: routine cron activity cannot evict audit entries */
echo "== run log retention\n";
$caps = CRDR_Send_Rules::log_caps();
$run  = function () {
	return (array) get_option( CRDR_Send::LOG_OPTION, array() );
};
$cron = function () {
	return (array) get_option( CRDR_Send::CRON_LOG_OPTION, array() );
};
$fresh = function () {
	delete_option( CRDR_Send::LOG_OPTION );
	delete_option( CRDR_Send::CRON_LOG_OPTION );
	delete_option( CRDR_Send::LOST_OPTION );
};

// a real administrator override, then a long stretch of real cron ticks
$fresh();
crdr_t_cfg( array( 'mode' => 'auto' ), $closed );
crdr_t_reset( 3 );
$r = CRDR_Playbooks::run( 'dormant', 6, array( 'trigger' => 'manual', 'override_window' => true ) );
$ov = array_values( array_filter( $run(), function ( $e ) {
	return ! empty( $e['override'] );
} ) );
t( 'override run: recorded in the people log, with who and that the hours were bypassed', 1 === count( $ov ) && 1 === (int) $ov[0]['user'] && 'ran' === $ov[0]['result'], wp_json_encode( $run() ) );
crdr_t_cfg( array( 'mode' => 'auto' ) ); // open window
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::customers() ); // nothing to send: every tick is a routine "ran" (the log is kept)
$fresh_run = $run();
foreach ( range( 1, 40 ) as $i ) {
	CRDR_Playbooks::hourly();
}
t( 'real cron ticks write to the cron log, not to the people log', count( $cron() ) > 0 && $run() === $fresh_run );
t( 'real cron ticks are bounded by the routine cap', count( $cron() ) <= $caps['routine'] && count( $cron() ) > 0, (string) count( $cron() ) );
foreach ( range( 1, 1000 ) as $i ) { // far more than the whole log holds
	CRDR_Send::log_run( array( 'run' => 'x' . $i, 'ts' => time(), 'playbook' => 'dormant', 'trigger' => 'cron', 'user' => 0, 'mode' => 'auto', 'in_window' => true, 'override' => false, 'result' => 'ran', 'counts' => array( 'sent' => 0 ) ) );
}
t( 'after 1000 more routine ticks the override entry is still there, unchanged', $run() === $fresh_run && ! empty( array_filter( $run(), function ( $e ) {
	return ! empty( $e['override'] );
} ) ) );
t( 'the cron log never grows past its routine cap', count( $cron() ) === $caps['routine'] );
t( 'nothing important was lost, so no loss is reported', 0 === (int) get_option( CRDR_Send::LOST_OPTION, 0 ) );

// cron state changes survive routine noise
$fresh();
CRDR_Health::record_block( 'dormant', 'template' );
$ev0 = $cron();
foreach ( range( 1, 500 ) as $i ) {
	CRDR_Send::log_run( array( 'run' => 'y' . $i, 'ts' => time(), 'playbook' => 'dormant', 'trigger' => 'cron', 'user' => 0, 'mode' => 'auto', 'in_window' => true, 'override' => false, 'result' => 'ran' ) );
}
t( 'a cron "blocked" event is not evicted by routine ticks', 1 === count( array_filter( $cron(), function ( $e ) {
	return 'blocked:template' === $e['result'];
} ) ) );

// ordinary manual clicks cannot evict overrides either; the audit class is bounded and reports a loss
$fresh();
$mkm = function ( $i, $override ) {
	return array( 'run' => 'm' . $i, 'ts' => time(), 'playbook' => 'dormant', 'trigger' => 'manual', 'user' => 1, 'mode' => 'manual', 'in_window' => ! $override, 'override' => $override, 'result' => $override ? 'ran' : 'blocked:mode_off' );
};
CRDR_Send::log_run( $mkm( 0, true ) );
foreach ( range( 1, $caps['action'] * 3 ) as $i ) {
	CRDR_Send::log_run( $mkm( $i, false ) );
}
t( 'hundreds of refused manual clicks do not evict the override', 1 === count( array_filter( $run(), function ( $e ) {
	return ! empty( $e['override'] );
} ) ) && count( $run() ) === 1 + $caps['action'] );
foreach ( range( 1, $caps['override'] + 10 ) as $i ) {
	CRDR_Send::log_run( $mkm( 1000 + $i, true ) );
}
$ovs = array_values( array_filter( $run(), function ( $e ) {
	return ! empty( $e['override'] );
} ) );
t( 'the audit class is bounded and keeps the newest entries', count( $ovs ) === $caps['override'] && 'm' . ( 1000 + $caps['override'] + 10 ) === end( $ovs )['run'] );
t( 'evicting audit entries is never silent: the loss is counted', 11 === (int) get_option( CRDR_Send::LOST_OPTION, 0 ), (string) get_option( CRDR_Send::LOST_OPTION, 0 ) );

// size is bounded
$bytes = strlen( maybe_serialize( $run() ) ) + strlen( maybe_serialize( $cron() ) );
t( 'the whole log stays small (under 200 KB at its caps)', $bytes < 200000, (string) $bytes );

// the reader
$fresh();
CRDR_Send::log_run( $mkm( 1, true ) );
CRDR_Health::record_block( 'dormant', 'busy' );
CRDR_Send::log_run( $mkm( 2, false ) );
$all = CRDR_Send::log_entries();
t( 'log_entries(): both logs read back as one list, oldest first', 3 === count( $all ) && $all === CRDR_Send_Rules::log_merge( $run(), $cron() ) );

// nothing sensitive can be stored
$fresh();
CRDR_Send::log_run( array_merge( $mkm( 1, true ), array( 'phone' => '09125550101', 'message' => 'متن خصوصی', 'api_key' => 'TESTKEY-SECRET', 'counts' => array( 'sent' => 2, 'phone' => '0912' ) ) ) );
$raw = wp_json_encode( $run() );
t( 'log_run() drops phone, message text and keys even if a caller passes them', false === strpos( $raw, '0912555' ) && false === mb_strpos( $raw, 'خصوصی' ) && false === strpos( $raw, 'SECRET' ) && false !== strpos( $raw, '"sent":2' ), $raw );

// a corrupted option must not break sending or lose the new entry
update_option( CRDR_Send::LOG_OPTION, 'garbage', false );
CRDR_Send::log_run( $mkm( 9, true ) );
t( 'a corrupted log option is replaced, the new entry is kept', 1 === count( $run() ) && 'm9' === $run()[0]['run'] );
$fresh();
crdr_t_reset( 0 );

/* ====== B3. customers whose message cannot be built are set aside, they do not starve the rest */
echo "== unbuildable messages\n";
// a PER-CUSTOMER failure: the discount code cannot be saved for these customers (test-only filter that throws, like a database fault; production code has no seam)
$GLOBALS['crdr_t_bad'] = array();
add_filter( 'wp_insert_post_empty_content', function ( $empty, $postarr ) {
	if ( isset( $postarr['post_type'] ) && 'shop_coupon' === $postarr['post_type'] ) {
		foreach ( $GLOBALS['crdr_t_bad'] as $k ) {
			if ( false !== strpos( (string) ( isset( $postarr['post_excerpt'] ) ? $postarr['post_excerpt'] : '' ), $k ) ) {
				throw new RuntimeException( 'simulated database failure for ' . $k ); // what a real DB fault looks like to the caller
			}
		}
	}
	return $empty;
}, 10, 2 );
$offer_cfg = array( 'mode' => 'manual', 'offer' => array( 'type' => 'percent', 'percent' => 10, 'days' => 5 ), 'template' => 'سلام {name}، کد {coupon} {optout}' );
$bad3      = array( 'p:09120000001', 'p:09120000002', 'p:09120000003' ); // the customers at the very top of the audience
$scenario  = function () use ( $offer_cfg, $bad3 ) {
	crdr_t_cfg( $offer_cfg );
	crdr_t_reset( 10 );
	$GLOBALS['crdr_t_bad'] = $bad3;
};
$clean_html = function ( $h ) {
	return ! preg_match( '/(Warning|Notice|Deprecated|Fatal error)\s*:/', $h ) && false === strpos( $h, 'Stack trace' );
};
$nb = function ( $ck = null ) {
	return crdr_t_rows( "status = 'nobuild'" . ( $ck ? " AND ckey = '" . esc_sql( $ck ) . "'" : '' ) );
};

$scenario();
$coup0 = $coupons();
$r1    = CRDR_Playbooks::run( 'dormant', 4 );
t( 'run 1: the three unbuildable customers at the top do not stop the run: customer 4 is still served', 1 === $r1['sent'] && 3 === $r1['failed'], wp_json_encode( $r1, JSON_UNESCAPED_UNICODE ) );
t( 'run 1: each unbuildable customer has ONE row, status nobuild, with a readable reason', 3 === count( $nb() ) && 3 === count( array_filter( array_column( $nb(), 'error' ), function ( $e ) {
	return false !== mb_strpos( $e, 'کد تخفیف' );
} ) ) );
t( 'run 1: the reason holds no phone number, key or exception text', 0 === count( array_filter( array_column( $nb(), 'error' ), function ( $e ) {
	return false !== strpos( $e, '0912' ) || false !== strpos( $e, 'p:' );
} ) ) );
t( 'run 1: no false contact: no sms ok / pending row and no claim for them', 0 === crdr_t_count( "status IN ('ok','pending','unknown') AND ckey IN ('p:09120000001','p:09120000002','p:09120000003')" ) && 0 === crdr_t_count( 'claim_key IS NOT NULL' ) );
t( 'run 1: no false cooldown: they are not "recently contacted"', '' === CRDR_DB::blocked_reason( 'p:09120000001' ) && false === CRDR_Actions::in_cooldown( 'p:09120000002' ) );
t( 'run 1: only the one message that was built was sent, and only one coupon exists for it', 1 === count( crdr_t_requests() ) && $coup0 + 1 === $coupons() );
$stamp = array_column( $nb(), 'created_ts', 'ckey' );

$r2 = CRDR_Playbooks::run( 'dormant', 4 );
t( 'run 2: the set-aside customers are skipped, the next four eligible customers progress', 4 === $r2['sent'] && 0 === $r2['failed'], wp_json_encode( $r2, JSON_UNESCAPED_UNICODE ) );
$r3 = CRDR_Playbooks::run( 'dormant', 4 );
t( 'run 3: the rest of the audience is served', 2 === $r3['sent'] && 0 === $r3['failed'], wp_json_encode( $r3, JSON_UNESCAPED_UNICODE ) );
t( 'the set-aside rows were not touched or duplicated by the later runs', $stamp === array_column( $nb(), 'created_ts', 'ckey' ) && 3 === count( $nb() ) );
t( 'duplicate-send safety holds: 7 customers, 7 messages, nobody twice', 7 === count( crdr_t_requests() ) && 7 === crdr_t_count( "status = 'ok'" ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT ckey FROM ' . CRDR_DB::contacts() . " WHERE status = 'ok' GROUP BY ckey HAVING COUNT(*) > 1) d" ) );
$r4 = CRDR_Playbooks::run( 'dormant', 4 );
t( 'another run changes nothing (no resend, no new failures)', 0 === $r4['sent'] && 0 === $r4['failed'] && 7 === count( crdr_t_requests() ) );

// visible to the owner
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign card says how many customers are set aside, why, and how to recover', false !== mb_strpos( $html, 'کنار گذاشته' ) && false !== mb_strpos( $html, 'کد تخفیف' ) && false !== mb_strpos( $html, 'ذخیره' ) );
$cid1 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$html = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid1 ) );
t( 'the customer page shows the failure, in Persian', $clean_html( $html ) && false !== mb_strpos( $html, 'پیام ساخته یا پذیرفته نشد' ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid1, 'type' => 'sms' ) );
t( 'a set-aside customer can still be messaged by hand (nothing was "contacted")', true === $j['success'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );

// recovery 1: the cause is fixed; they stay set aside until something is saved ...
$scenario();
CRDR_Playbooks::run( 'dormant', 20 );
$GLOBALS['crdr_t_bad'] = array();
$n0 = count( crdr_t_requests() );
$r  = CRDR_Playbooks::run( 'dormant', 20 );
t( 'recovery: fixing the cause alone does not hammer them again straight away', 0 === $r['sent'] && $n0 === count( crdr_t_requests() ) );
// ... saving the campaign lifts it at once
CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'سلام {name}، کد {coupon} {optout}', 'offer_type' => 'percent', 'offer_percent' => 10, 'offer_days' => 5, 'daily_cap' => 100 ) );
$r = CRDR_Playbooks::run( 'dormant', 20 );
t( 'recovery: saving the campaign lifts the set-aside; the three are served exactly once', 3 === $r['sent'] && 0 === count( $nb() ) + crdr_t_count( "status = 'nobuild'" ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
// ... as does switching the mode, saving Settings, or simply waiting out the six hours
foreach ( array(
	'mode switch'   => function () {
		CRDR_Playbooks::set_mode( 'dormant', 'off' );
		CRDR_Playbooks::set_mode( 'dormant', 'manual' );
	},
	'settings save' => function () {
		CRDR_Settings::save( CRDR_Settings::all() );
	},
	'time'          => function () use ( $wpdb ) {
		$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . " SET created_ts = created_ts - 25000 WHERE status = 'nobuild'" );
	},
) as $how => $lift ) {
	$scenario();
	CRDR_Playbooks::run( 'dormant', 20 );
	$GLOBALS['crdr_t_bad'] = array();
	$lift();
	$r = CRDR_Playbooks::run( 'dormant', 20 );
	t( "recovery by {$how}: the set-aside customers are served again", 3 === $r['sent'] && 0 === $r['failed'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
}
// still broken after the wait: tried again, but still ONE row each
$scenario();
CRDR_Playbooks::run( 'dormant', 20 );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . " SET created_ts = created_ts - 25000 WHERE status = 'nobuild'" );
$r = CRDR_Playbooks::run( 'dormant', 20 );
t( 'after the wait a still-broken customer is tried once more and fails again...', 3 === $r['failed'] && 0 === $r['sent'] );
t( '... and the table does not grow: still exactly one nobuild row per customer', 3 === count( $nb() ) && 3 === crdr_t_count( "status = 'nobuild' AND created_ts > " . ( time() - 60 ) ) );

// a GLOBAL cause (blank store name): at most ten customers are set aside per run, then it stops; no coupon is wasted
crdr_t_cfg( array_merge( $offer_cfg, array( 'template' => 'سلام {name} از {store} کد {coupon} {optout}' ) ) );
crdr_t_reset( 15 );
$GLOBALS['crdr_t_bad'] = array();
$coup0    = $coupons();
$blogname = get_option( 'blogname' );
update_option( 'blogname', '' );
$r = CRDR_Playbooks::run( 'dormant', 50 );
t( 'global cause: stops after ten in a row, nothing sent', 10 === $r['failed'] && 0 === $r['sent'] && 0 === count( crdr_t_requests() ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'global cause: the reason names the missing value', 10 === crdr_t_count( "status = 'nobuild' AND error LIKE '%{store}%'" ) && false !== mb_strpos( $r['error'], '{store}' ) );
t( 'global cause: no discount code was created for a message that could not be built', $coup0 === $coupons() );
update_option( 'blogname', $blogname );
CRDR_Settings::save( CRDR_Settings::all() );
$r = CRDR_Playbooks::run( 'dormant', 50 );
t( 'global cause fixed + settings saved: everyone is served once', 15 === $r['sent'] && 15 === count( crdr_t_requests() ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );

// a message that renders to nothing
crdr_t_cfg( array( 'mode' => 'manual', 'template' => '{optout}' ), array( 'optout_text' => '' ) );
crdr_t_reset( 4 );
$r = CRDR_Playbooks::run( 'dormant', 10 );
// PHASE 4.1: an empty opt-out phrase is no longer "a message that renders to nothing" at send time: every promotional text must carry {optout},
// and an empty phrase is refused up front (before anybody is claimed, so there is nothing to set aside). The safety property this test
// pinned (nothing is sent) is unchanged; how it is reached changed. The same scenario in tests/release-safety.php, section F.
t( 'empty text after filling (empty opt-out phrase): nothing sent, nobody claimed, a Persian reason', 0 === $r['sent'] && 0 === count( crdr_t_requests() ) && 'template' === $r['blocked'] && 0 === crdr_t_count( "kind = 'sms'" ) && false !== mb_strpos( $r['error'], 'عبارت لغو عضویت' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );

// gateway failures are a different thing and keep their rule: failed (retryable), stop after three
crdr_t_cfg( array( 'mode' => 'manual' ), array(), array( 'mode' => 'connect_fail' ) );
crdr_t_reset( 6 );
$r = CRDR_Playbooks::run( 'dormant', 10 );
t( 'gateway definite failure: unchanged (3 failed rows, stops, not set aside)', 3 === $r['failed'] && 3 === crdr_t_count( "status = 'failed'" ) && 0 === crdr_t_count( "status = 'nobuild'" ) );
remove_all_filters( 'wp_insert_post_empty_content' );
$GLOBALS['crdr_t_bad'] = array();
crdr_t_reset( 0 );

/* ====== B4. what the gateway says decides WHO is set aside: recipient / message / nobody ====== */
echo "== gateway failures by cause\n";
$gw = function ( array $by_phone = array(), $mode = 'ok', $n = 12 ) {
	crdr_t_cfg( array(), array(), array( 'mode' => $mode, 'by_phone' => $by_phone ) );
	crdr_t_reset( $n );
};
$phone = function ( $i ) {
	return sprintf( '09120000%03d', $i );
};
$mockset = function ( array $by_phone = array(), $mode = 'ok' ) {
	update_option( 'crdr_t_mock', wp_json_encode( array( 'mode' => $mode, 'by_phone' => $by_phone ) ) );
};
$per_phone = function () {
	$by = array_count_values( array_column( crdr_t_requests(), 'receptor' ) );
	return $by;
};
$st = function ( $status ) {
	return crdr_t_count( "status = '{$status}'" );
};
$save_campaign = function () {
	CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'سلام {name} {optout}', 'offer_type' => 'none', 'daily_cap' => 100 ) );
};
$no_pii = function ( $text ) {
	return false === strpos( $text, '0912' ) && false === strpos( $text, 'TESTKEY' ) && false === strpos( $text, 'ECHO' ) && false === mb_strpos( $text, 'سلام' );
};

// ---- 1. a permanently invalid recipient (411) ----------------------------------------------------------------------
$bad5 = array();
foreach ( range( 1, 5 ) as $i ) {
	$bad5[ $phone( $i ) ] = 'invalid_receptor';
}
$gw( $bad5, 'ok', 12 );
$r1 = CRDR_Playbooks::run( 'dormant', 8 );
t( 'recipient rejected (411): five rejected customers at the very top do NOT stop the run (the old rule stopped after 3)', 5 === $r1['failed'] && 3 === $r1['sent'], wp_json_encode( $r1, JSON_UNESCAPED_UNICODE ) );
t( 'recipient rejected: one row each, status "rejected", with the provider code in a Persian reason', 5 === $st( 'rejected' ) && 5 === count( array_filter( array_column( crdr_t_rows( "status = 'rejected'" ), 'error' ), function ( $e ) {
	return false !== strpos( $e, '411' ) && 1 === preg_match( '/\p{Arabic}/u', $e );
} ) ) );
t( 'recipient rejected: the reason holds no phone number, key or message text', 5 === count( array_filter( array_column( crdr_t_rows( "status = 'rejected'" ), 'error' ), $no_pii ) ) );
t( 'recipient rejected: no false contact: no ok/pending/unknown row, no claim left, not in cooldown, daily cap untouched', 0 === crdr_t_count( "status IN ('ok','pending','unknown') AND ckey IN ('p:09120000001','p:09120000005')" ) && 0 === crdr_t_count( 'claim_key IS NOT NULL' ) && '' === CRDR_DB::blocked_reason( 'p:09120000001' ) && false === CRDR_Actions::in_cooldown( 'p:09120000003' ) && 3 === CRDR_Playbooks::sent_today( 'dormant' ) );
$r2 = CRDR_Playbooks::run( 'dormant', 8 );
t( 'audience progress: the next run skips the rejected five and serves the rest', 4 === $r2['sent'] && 0 === $r2['failed'], wp_json_encode( $r2, JSON_UNESCAPED_UNICODE ) );
$r3 = CRDR_Playbooks::run( 'dormant', 8 );
t( 'nothing left to do: no resend, no new failures', 0 === $r3['sent'] && 0 === $r3['failed'] );
$by = $per_phone();
t( 'duplicate-send safety: every rejected number was tried exactly once, every served customer exactly once', 12 === count( $by ) && 12 === array_sum( $by ) && 7 === crdr_t_count( "status = 'ok'" ) );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign card: says how many numbers were rejected, why, and offers an explicit "try again" form (nonce, POST)', false !== mb_strpos( $html, 'رد شده' ) && false !== strpos( $html, 'crdr_retry_rejected' ) && false !== strpos( $html, '_wpnonce' ) && false === strpos( $html, '0912' ) && false === strpos( $html, 'TESTKEY' ) );
$cid1 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$html = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid1 ) );
t( 'customer page: shows the rejection in Persian', false !== mb_strpos( $html, 'رد شد' ) && false !== strpos( $html, '411' ) );

// recovery A: the number is corrected in the order -> it is a different customer key, so it is simply served
$wpdb->insert( CRDR_DB::customers(), array( 'ckey' => 'p:09129990001', 'name' => 'اصلاح‌شده', 'phone' => '09129990001', 'email' => '', 'orders_count' => 1, 'total_spent' => 1, 'first_ts' => time() - 90 * 86400, 'last_ts' => time() - 90 * 86400, 'segment' => 'hibernating', 'priority' => 99 ) );
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'recovery: a corrected number is a new customer (key = phone) and is served at once; the old ones stay aside', 1 === $r['sent'] && 5 === $st( 'rejected' ) && 1 === ( $per_phone()['09129990001'] ?? 0 ) );
// the provider now accepts them, but nothing retries by itself ...
$mockset();
$n0 = count( crdr_t_requests() );
$r  = CRDR_Playbooks::run( 'dormant', 20 );
t( 'recovery: without an explicit action the rejected numbers are NOT hammered again', 0 === $r['sent'] && $n0 === count( crdr_t_requests() ) );
// ... saving the campaign / settings is for message problems, not for a bad recipient
$save_campaign();
CRDR_Settings::save( CRDR_Settings::all() );
$r = CRDR_Playbooks::run( 'dormant', 20 );
t( 'recovery: saving the campaign or the settings does not lift a recipient rejection (it is not about the text or the gateway)', 0 === $r['sent'] && 5 === $st( 'rejected' ) );
// ... the explicit action does, and it is guarded
$uid = wp_insert_user( array( 'user_login' => 'crdr_sub_rej_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$e0  = CRDR_Send::reject_epoch( 'dormant' );
$w   = crdr_t_parallel( array( array( 'retry_rejected', (string) $uid, 'good', 'dormant' ), array( 'retry_rejected', '1', 'bad', 'dormant' ) ), 2 );
wp_cache_delete( CRDR_Send::REJECT_EPOCH_OPTION, 'options' );
t( 'recovery: the "try again" action refuses a user without the capability and a bad nonce (nothing changes)', $e0 === CRDR_Send::reject_epoch( 'dormant' ) && 0 === ( $w[0]['epoch'] ?? 0 ) && 0 === ( $w[1]['epoch'] ?? 0 ), wp_json_encode( $w, JSON_UNESCAPED_UNICODE ) );
$w = crdr_t_parallel( array( array( 'retry_rejected', '1', 'good', 'dormant' ) ), 2 );
wp_cache_delete( CRDR_Send::REJECT_EPOCH_OPTION, 'options' ); // the worker changed it in another process; this one still has the old value cached
wp_cache_delete( 'notoptions', 'options' );
t( 'recovery: an administrator with a valid nonce lifts it and is sent back to the campaigns page', CRDR_Send::reject_epoch( 'dormant' ) > $e0 && false !== strpos( (string) ( $w[0]['redirect'] ?? '' ), 'customer-radar-campaigns' ), wp_json_encode( $w, JSON_UNESCAPED_UNICODE ) );
$r = CRDR_Playbooks::run( 'dormant', 20 );
t( 'recovery: after "try again" the five are served', 5 === $r['sent'] && 0 === $r['failed'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'recovery: the rows that recorded the rejection are gone once the customer has been served', 0 === $st( 'rejected' ) );
t( 'duplicate-send safety after recovery: 13 customers, 13 ok rows, nobody twice; the five were asked twice (rejected, then sent) and the rest once', 13 === crdr_t_count( "status = 'ok'" ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT ckey FROM ' . CRDR_DB::contacts() . " WHERE status = 'ok' GROUP BY ckey HAVING COUNT(*) > 1) d" ) && 2 === $per_phone()['09120000001'] && 1 === $per_phone()['09120000006'] );
wp_delete_user( $uid );

// recovery B: a long expiry as the last resort
$gw( array( $phone( 1 ) => 'invalid_receptor' ), 'ok', 3 );
CRDR_Playbooks::run( 'dormant', 5 );
$mockset();
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . " SET created_ts = created_ts - 29 * 86400 WHERE status = 'rejected'" );
$r = CRDR_Playbooks::run( 'dormant', 5 );
t( 'rejected: still aside after 29 days', 0 === $r['sent'] && 1 === $st( 'rejected' ) );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . " SET created_ts = created_ts - 2 * 86400 WHERE status = 'rejected'" );
$r = CRDR_Playbooks::run( 'dormant', 5 );
t( 'rejected: tried again after 30 days (provider-side problems can be fixed too)', 1 === $r['sent'] && 0 === $st( 'rejected' ) );

// still rejected on the second attempt: one row, never a growing pile
$gw( array( $phone( 1 ) => 'invalid_receptor' ), 'ok', 3 );
CRDR_Playbooks::run( 'dormant', 5 );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . " SET created_ts = created_ts - 31 * 86400 WHERE status = 'rejected'" );
CRDR_Playbooks::run( 'dormant', 5 );
t( 'rejected again after the expiry: still exactly one row for that customer', 1 === $st( 'rejected' ) );

// ---- 2. the provider's own text is never stored or shown -------------------------------------------------------------
$gw( array( $phone( 1 ) => 'echo' ), 'ok', 3 );
$r = CRDR_Playbooks::run( 'dormant', 5 );
$e = array_column( crdr_t_rows( "status = 'rejected'" ), 'error' );
t( 'a provider answer that repeats the phone number and the API key is replaced by our own text', 1 === count( $e ) && $no_pii( $e[0] ) && false !== strpos( $e[0], '411' ) && $no_pii( $r['error'] ), wp_json_encode( $e, JSON_UNESCAPED_UNICODE ) );
$gw( array( $phone( 1 ) => 'leaky' ), 'ok', 3 );
$r = CRDR_Playbooks::run( 'dormant', 5 );
$e = array_column( crdr_t_rows( "status = 'unknown'" ), 'error' );
t( 'a transport error that contains the request URL (API key) and the phone number is redacted before it is stored', 1 === count( $e ) && $no_pii( $e[0] ) && false !== mb_strpos( $e[0], 'ممکن است' ) && $no_pii( $r['error'] ), wp_json_encode( $e, JSON_UNESCAPED_UNICODE ) );

// ---- 3. the MESSAGE is rejected (413 / 422): same set-aside as an unbuildable message ------------------------------
$gw( array( $phone( 1 ) => 'bad_text', $phone( 2 ) => 'bad_text', $phone( 3 ) => 'too_long' ), 'ok', 8 );
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'message rejected (422, 413): set aside as "nobuild" and the other five are served in the same run', 3 === $r['failed'] && 5 === $r['sent'] && 3 === $st( 'nobuild' ) && 0 === $st( 'failed' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'message rejected: nothing counts as contacted', '' === CRDR_DB::blocked_reason( 'p:09120000001' ) && 5 === crdr_t_count( "status = 'ok'" ) );
$mockset();
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'message rejected: not retried straight away', 0 === $r['sent'] );
$save_campaign();
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'message rejected: saving the campaign (a fixed text) lifts it', 3 === $r['sent'] && 0 === $st( 'nobuild' ) );

// ---- 4. nobody is at fault: credit, key ----------------------------------------------------------------------------------
foreach ( array( 'credit' => '418', 'bad_key' => '403' ) as $mode => $code ) {
	$gw( array(), $mode, 6 );
	$r = CRDR_Playbooks::run( 'dormant', 6 );
	t( "gateway-wide ({$code}): the run stops at the FIRST failure, one request only", 1 === $r['failed'] && 1 === count( crdr_t_requests() ) && false !== strpos( $r['error'], $code ) );
	$mockset();
	$r = CRDR_Playbooks::run( 'dormant', 6 );
	t( "gateway-wide ({$code}): the customer was not penalised: once fixed the SAME run order serves everybody, customer 1 first", 6 === $r['sent'] && 2 === $per_phone()['09120000001'] );
}

// ---- 5. transient network failure: breaker only for failures IN A ROW, nobody set aside -----------------------------------------
$gw( array( $phone( 1 ) => 'connect_fail', $phone( 3 ) => 'connect_fail', $phone( 5 ) => 'connect_fail' ), 'ok', 8 );
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'transient failures separated by successes do not trip the breaker (3 failed, 5 sent in one run)', 3 === $r['failed'] && 5 === $r['sent'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'transient failures: plain "failed" rows, nobody set aside, nobody contacted', 3 === $st( 'failed' ) && 0 === $st( 'nobuild' ) + $st( 'retry' ) + $st( 'rejected' ) && '' === CRDR_DB::blocked_reason( 'p:09120000003' ) );
$mockset();
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'transient failures: retried at the next run (the retry path), each exactly once', 3 === $r['sent'] );
$gw( array(), 'connect_fail', 8 );
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'broadly broken gateway: three in a row stop the run (circuit breaker), nobody set aside', 3 === $r['failed'] && 0 === $r['sent'] && 3 === count( array_filter( array_column( crdr_t_rows(), 'status' ), function ( $x ) {
	return 'failed' === $x;
} ) ) && 0 === $st( 'retry' ) );
$mockset();
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'broadly broken gateway: back to normal at the next run', 8 === $r['sent'] );

// ---- 6. unclear cause (402): RELEASE FREEZE - unknown, never retried (a fresh localid would defeat the provider's duplicate check) -----
$gw( array( $phone( 1 ) => 'op_failed' ), 'ok', 6 );
$r1 = CRDR_Playbooks::run( 'dormant', 8 );
$u1 = crdr_t_rows( "status = 'unknown'" );
t( 'unclear failure (402): UNKNOWN, not failed / retry; the run stops at it (one request, nobody after it)', 1 === $r1['unknown'] && 0 === $r1['failed'] && 0 === $r1['sent'] && 1 === count( crdr_t_requests() ) && 1 === count( $u1 ) && 0 === $st( 'retry' ) && 0 === $st( 'failed' ), wp_json_encode( $r1, JSON_UNESCAPED_UNICODE ) );
t( 'unclear failure: the row keeps the localid that was sent and our own wording with the code (no provider text)', 1 === count( $u1 ) && '' !== $u1[0]['localid'] && false !== strpos( (string) $u1[0]['error'], '402' ) && $no_pii( (string) $u1[0]['error'] ), wp_json_encode( $u1, JSON_UNESCAPED_UNICODE ) );
t( 'unclear failure: the customer counts as contacted (cooldown) and uses the daily cap, so nobody can send to them again', 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000001' ) && 1 === CRDR_Playbooks::sent_today( 'dormant' ) );
$r2 = CRDR_Playbooks::run( 'dormant', 8 );
$by = array_count_values( array_column( crdr_t_requests(), 'receptor' ) );
t( 'unclear failure: the NEXT run carries on with OTHER customers and never asks for the same one again', 5 === $r2['sent'] && 1 === $by['09120000001'] && 6 === count( $by ), wp_json_encode( array( $r2, $by ), JSON_UNESCAPED_UNICODE ) );
$mockset();
$save_campaign();
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'unclear failure: saving the campaign / settings (the recovery for "set aside" customers) does NOT release it', 0 === $r['sent'] && 1 === $st( 'unknown' ) && 1 === array_count_values( array_column( crdr_t_requests(), 'receptor' ) )['09120000001'] );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . " SET created_ts = created_ts - 25000 WHERE status = 'unknown'" );
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'unclear failure: waiting six hours does not release it either (no automatic retry, so no second SMS with a fresh localid)', 0 === $r['sent'] && 1 === $st( 'unknown' ) && 1 === array_count_values( array_column( crdr_t_requests(), 'receptor' ) )['09120000001'] );
$gw( array( $phone( 2 ) => 'op_failed' ), 'ok', 5 );
$r = CRDR_Playbooks::run( 'dormant', 8 );
t( 'one unclear failure ends the run for that moment (customers 3..5 are served by the next run)', 1 === $r['unknown'] && 0 === $r['failed'] && 1 === $r['sent'] );

// PHASE 4.2: 451 is now a DOCUMENTED code (the provider's current table: too many calls from this IP) and is classified as 'rate' (nothing sent, stop, try
// again at the next run; tests/release-safety.php section H). The ambiguity this loop pins is about codes the provider does NOT document, so it uses 499.
// ---- 7. AMBIGUOUS outcomes: never retried, counted as contacted -------------------------------------------------------------------
foreach ( array( 'timeout' => 'timeout', 'http500' => 'HTTP 500', 'server_busy' => '409 "try later"', 'undocumented' => '499 (not in the provider table)' ) as $mode => $label ) {
	$gw( array( $phone( 1 ) => $mode ), 'ok', 5 );
	$r = CRDR_Playbooks::run( 'dormant', 8 );
	t( "ambiguous ({$label}): the run stops, the row is unknown, one request", 1 === $r['unknown'] && 0 === $r['failed'] && 1 === $st( 'unknown' ) && 1 === count( crdr_t_requests() ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
	t( "ambiguous ({$label}): counts as contacted (cooldown, daily cap)", 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000001' ) && 1 === CRDR_Playbooks::sent_today( 'dormant' ) );
	$mockset();
	CRDR_Playbooks::run( 'dormant', 8 );
	CRDR_Playbooks::run( 'dormant', 8 );
	CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'manual', 'template' => 'سلام {name} {optout}', 'offer_type' => 'none', 'daily_cap' => 100 ) );
	CRDR_Settings::save( CRDR_Settings::all() );
	CRDR_Playbooks::run( 'dormant', 8 );
	t( "ambiguous ({$label}): never retried, not by later runs, not by saving, and the others were served once", 1 === $per_phone()['09120000001'] && 5 === array_sum( $per_phone() ) && 1 === $st( 'unknown' ) );
}

// ---- 8. the one-off SMS button uses the same classification ------------------------------------------------------------------------
$gw( array( $phone( 1 ) => 'invalid_receptor' ), 'ok', 3 );
$cid = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off SMS to a rejected number: a clear Persian refusal without the number, not a contact, nothing left claimed', false === $j['success'] && false !== strpos( $j['data']['message'], '411' ) && $no_pii( $j['data']['message'] ) && 1 === $st( 'rejected' ) && 0 === crdr_t_count( 'claim_key IS NOT NULL' ) && '' === CRDR_DB::blocked_reason( 'p:09120000001' ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$mockset();
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off SMS: a person can try again by hand after the number is fixed', true === $j['success'] && 1 === crdr_t_count( "status = 'ok'" ) );

// ---- 9. the run log and health state carry only counts -------------------------------------------------------------------------------
$dump = wp_json_encode( get_option( CRDR_Send::LOG_OPTION ) ) . wp_json_encode( get_option( CRDR_Send::CRON_LOG_OPTION ) ) . wp_json_encode( get_option( CRDR_Health::OPTION ) );
t( 'logs and health state: no phone number and no key', false === strpos( $dump, '0912' ) && false === strpos( $dump, 'TESTKEY' ) );
crdr_t_reset( 0 );

/* ====== B5. outcomes are shown for what they are: attempted / accepted / unknown / failed / rejected ====== */
echo "== outcome wording and metrics\n";
$mk_row = function ( $ckey, $status, array $x = array() ) {
	CRDR_DB::add_contact( $ckey, 'sms', array_merge( array( 'playbook' => 'dormant', 'status' => $status, 'message' => 'متن آزمایشی', 'error' => '' ), $x ) );
};
$age = function ( $days ) use ( $wpdb ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = created_ts - %d', $days * 86400 ) );
};
$order = function ( $ckey, $net, $days_after_contact ) use ( $wpdb ) {
	$cts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT created_ts FROM ' . CRDR_DB::contacts() . ' WHERE ckey = %s ORDER BY id LIMIT 1', $ckey ) );
	$wpdb->insert( CRDR_DB::orders(), array( 'order_id' => wp_rand( 100000, 999999 ), 'ckey' => $ckey, 'ts' => $cts + $days_after_contact * 86400, 'net' => $net, 'items' => 1 ) );
};
crdr_t_cfg( array( 'mode' => 'manual', 'window_days' => 14, 'holdout_pct' => 20 ) );
crdr_t_reset( 8 );
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::orders() ); // the orders of an earlier run must not leak into the revenue figures
$mk_row( 'p:09120000001', 'ok' );
$mk_row( 'p:09120000002', 'unknown', array( 'error' => 'cURL error 28 timeout' ) );
$mk_row( 'p:09120000003', 'failed', array( 'message' => '', 'error' => 'اعتبار حساب سرویس پیامک کافی نیست (کاوه‌نگار، کد 418).' ) );
$mk_row( 'p:09120000004', 'rejected', array( 'message' => '', 'error' => 'شماره‌ی گیرنده از نظر سرویس پیامک معتبر نیست (کاوه‌نگار، کد 411).' ) );
$mk_row( 'p:09120000005', 'holdout', array( 'grp' => 'holdout', 'message' => '' ) );
$mk_row( 'p:09120000006', 'nobuild', array( 'message' => '', 'error' => 'x' ) );
$mk_row( 'p:09120000007', 'pending' );
$mk_row( 'p:09120000008', 'ok' );
// right after the attempts (fresh rows): who rests, and what is counted against today's cap
t( 'cooldown: accepted, unknown and pending outcomes keep the customer resting (nothing is sent twice)', 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000001' ) && 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000002' ) && 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000007' ) );
t( 'cooldown: failed, rejected and unbuildable customers do not rest (nothing went out)', '' === CRDR_DB::blocked_reason( 'p:09120000003' ) && '' === CRDR_DB::blocked_reason( 'p:09120000004' ) && '' === CRDR_DB::blocked_reason( 'p:09120000006' ) );
t( 'daily cap: accepted (2) + unknown (1) + pending (1) use it up; failed / rejected / nobuild / control do not', 4 === CRDR_Playbooks::sent_today( 'dormant' ), (string) CRDR_Playbooks::sent_today( 'dormant' ) );
$age( 30 ); // now old enough for the 14-day measurement window
// 1, 2 and 5 bought again; 8 did not. The customer with the UNKNOWN outcome must not be counted as "messaged".
$order( 'p:09120000001', 100000, 3 );
$order( 'p:09120000002', 900000, 3 );
$order( 'p:09120000005', 50000, 3 );
$res = CRDR_Playbooks::results( 'dormant' );
// PHASE 4.2 (CAMP-1 approved): the primary comparison is INTENTION TO TREAT - every customer chosen for the message counts, whatever happened to the SMS
// (unknown, failed, rejected, unbuildable and still pending included); the old pin (accepted messages only: n 2, conv 1, revenue 100,000) is now the SECONDARY group.
t( 'metrics (4.2, ITT): the primary treated group is the 7 customers chosen for the message (accepted 2, unknown, failed, rejected, unbuildable, pending), against the 1 control', 7 === $res['treated']['n'] && 2 === $res['treated']['conv'] && 1 === $res['holdout']['n'] && 1 === $res['holdout']['conv'] && 'itt' === $res['basis'], wp_json_encode( $res ) );
t( 'metrics (4.2, ITT): the unknown customer\'s big order (900000) IS in the primary treated revenue (by assignment: 100,000 + 900,000)', 1000000.0 === (float) $res['treated']['rev'], wp_json_encode( $res['treated'] ) );
t( 'metrics (4.2, secondary): the accepted-only group is the pre-4.2 comparison: 2 customers, 1 ordered, revenue 100,000; the unknown customer is NOT in it', 2 === $res['accepted']['n'] && 1 === $res['accepted']['conv'] && 100000.0 === (float) $res['accepted']['rev'], wp_json_encode( $res['accepted'] ) );
t( 'metrics: the number of unknown outcomes is reported, not hidden', 1 === $res['unknown'] );
t( 'metrics: "messages sent in total" is the accepted ones only (unknown / pending / failed are not counted)', 2 === $res['sent_total'] );

$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns', 'results' => 'dormant' ) );
$clean_o = function ( $h ) {
	return ! preg_match( '/(Warning|Notice|Deprecated|Fatal error)\s*:/', $h ) && false === strpos( $h, 'Stack trace' );
};
$o = function ( $s ) {
	return CRDR_Send_Rules::outcome( $s );
};
t( 'results page renders', $clean_o( $html ) && false !== mb_strpos( $html, 'آخرین ارسال‌ها' ) );
foreach ( array( 'ok', 'unknown', 'failed', 'rejected', 'holdout', 'nobuild', 'pending' ) as $stt ) {
	t( "recent sends: the label for '{$stt}' is shown", false !== mb_strpos( $html, $o( $stt )['label'] ) );
}
t( 'recent sends: nothing says plain "sent" any more (the old label claimed more than is known)', false === mb_strpos( $html, 'ارسال شد' ) && false === mb_strpos( $html, 'پیام گرفتند' ) );
t( 'recent sends: a legend tells the owner accepted is not delivered and what unknown means', false !== mb_strpos( $html, 'رسید تحویل ندارد' ) && false !== mb_strpos( $html, 'نتیجه نامشخص' ) );
t( 'results page: reports the unknown outcome that was left out of the comparison', false !== mb_strpos( $html, 'نتیجه‌ی نامشخص' ) );
$row_of = function ( $html, $label ) {
	preg_match_all( '/<tr>.*?<\/tr>/s', $html, $m );
	foreach ( $m[0] as $tr ) {
		if ( false !== mb_strpos( $tr, $label ) ) {
			return $tr;
		}
	}
	return '';
};
$uk_row = $row_of( $html, 'نتیجه نامشخص' );
t( 'recent sends: the unknown row is not described as accepted', '' !== $uk_row && false === mb_strpos( $uk_row, 'پذیرفته' ) );

$cid2 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000002'" );
$page = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid2 ) );
t( 'customer history: an unknown SMS says "unknown / may have gone out", never "accepted"', false !== mb_strpos( $page, 'نتیجه نامشخص' ) && false === mb_strpos( $page, 'پذیرفته' ) );
$cid1 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$page = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid1 ) );
t( 'customer history: an accepted SMS now says who accepted it (before, it had no status at all)', false !== mb_strpos( $page, 'پذیرفته‌شده توسط سرویس پیامک' ) );
$cid4 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000004'" );
$page = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid4 ) );
t( 'customer history: a rejected number says so', false !== mb_strpos( $page, 'شماره رد شد' ) && false !== strpos( $page, '411' ) );

$card = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign card: the cap line says what is counted (accepted or unknown), not "sent today"', false === mb_strpos( $card, 'امروز ارسال شده' ) && false !== mb_strpos( $card, 'نتیجه‌ی نامشخص' ) );
t( 'campaign card: the result line does not call the treated group "message receivers"', false === mb_strpos( $card, 'پیام‌گرفته‌ها' ) );

// what the person sees right after pressing a one-off SMS button
crdr_t_cfg( array(), array(), array( 'mode' => 'ok', 'by_phone' => array( '09120000002' => 'timeout' ) ) );
crdr_t_reset( 3 );
$cid = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off SMS accepted: the answer says the SMS service took it, and that arrival is not confirmed', true === $j['success'] && false !== mb_strpos( $j['data']['message'], 'سرویس پیامک' ) && false !== mb_strpos( $j['data']['message'], 'تأیید' ) && false === mb_strpos( $j['data']['message'], 'ارسال شد' ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$cid = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000002'" );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'one-off SMS with a timeout: not a success, says it may have gone out, and does not call the customer "sent"', false === $j['success'] && false !== mb_strpos( $j['data']['message'], 'ممکن است' ) && false === mb_strpos( $j['data']['message'], 'ارسال‌شده» حساب' ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$js = file_get_contents( CRDR_DIR . 'assets/admin.js' );
t( 'the run log shown in the browser says "accepted", not "sent"', false !== mb_strpos( $js, 'پذیرفته' ) && false === mb_strpos( $js, "line('ارسال: '" ) );
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::orders() );
crdr_t_reset( 0 );

/* ============================ B6. data minimisation: no text for an SMS that was never sent ============================ */
echo "== data minimisation\n";
if ( ! function_exists( 'PWSMS' ) ) { // a stand-in for the Persian WooCommerce SMS plugin, only used when a test selects it
	class CRDR_T_Pwsms {
		public function send_sms( $args ) {
			$b = isset( $GLOBALS['crdr_t_pwsms'] ) ? $GLOBALS['crdr_t_pwsms'] : 'ok';
			if ( 'throw' === $b ) {
				throw new RuntimeException( 'internal error for 09120000001 with key TESTKEY' );
			}
			return 'ok' === $b ? true : $b;
		}
	}
	function PWSMS() {
		return new CRDR_T_Pwsms();
	}
}
$msgs = function () {
	$o = array();
	foreach ( crdr_t_rows( "kind = 'sms'" ) as $r ) {
		$o[ $r['status'] ][] = $r;
	}
	return $o;
};
crdr_t_cfg( array( 'mode' => 'manual', 'offer' => array( 'type' => 'percent', 'percent' => 10, 'days' => 5 ), 'template' => 'سلام {name}، کد {coupon} {optout}' ), array(), array(
	'mode' => 'ok', 'by_phone' => array( '09120000001' => 'invalid_receptor', '09120000002' => 'bad_text', '09120000004' => 'timeout', '09120000005' => 'connect_fail' ),
) );
crdr_t_reset( 9 );
CRDR_Playbooks::run( 'dormant', 8 ); // c1 rejected, c2 refused text, c3 accepted, c4 timeout -> stops (402 is unknown now and is covered in section 6; 'retry' is no longer written)
CRDR_Playbooks::run( 'dormant', 8 ); // c5 network failure, the rest accepted
$by = $msgs();
foreach ( array( 'rejected', 'nobuild', 'failed' ) as $stt ) {
	$rows = isset( $by[ $stt ] ) ? $by[ $stt ] : array();
	t( "minimised: '{$stt}' (nothing was sent) keeps NO message text", 1 === count( $rows ) && '' === (string) $rows[0]['message'] && '' !== $rows[0]['error'], wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
}
t( 'minimised: the discount code of an unsent message is kept so that the unused code can be found', '' !== $by['rejected'][0]['coupon'] && '' !== $by['failed'][0]['coupon'] );
t( 'kept: an accepted SMS keeps its text (the record of what the customer was sent)', count( $by['ok'] ) >= 4 && 0 === count( array_filter( $by['ok'], function ( $r ) {
	return '' === (string) $r['message'] || false === mb_strpos( $r['message'], 'سلام' );
} ) ) );
t( 'kept: an unknown outcome keeps its text (it may have gone out; this is how you find out what)', 1 === count( $by['unknown'] ) && false !== mb_strpos( $by['unknown'][0]['message'], 'سلام' ) && '' !== $by['unknown'][0]['localid'] );
$u = $by['unknown'][0];
t( 'late answer: an unknown row corrected to ok keeps its text', true === CRDR_Send::finalize( $u['id'], 'ok' ) && $u['message'] === crdr_t_rows( "id = {$u['id']}" )[0]['message'] );

// an interrupted send: the row is still 'pending' with its text; when it is recovered as 'unknown' the text stays
$pk = CRDR_Send::claim( 'p:09120000099', 'dormant', 'stale' );
( new ReflectionMethod( 'CRDR_Send', 'annotate' ) )->setAccessible( true );
$an = new ReflectionMethod( 'CRDR_Send', 'annotate' );
$an->setAccessible( true );
$an->invoke( null, $pk['id'], 'سلام نیمه‌کاره', '', '1790000000000000001' );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 3600 ) . ' WHERE id = ' . (int) $pk['id'] );
CRDR_Send::recover_stale();
$pr = crdr_t_rows( 'id = ' . (int) $pk['id'] )[0];
t( 'an interrupted send recovered as unknown keeps the text that may have gone out', 'unknown' === $pr['status'] && 'سلام نیمه‌کاره' === $pr['message'] );
$wpdb->query( 'DELETE FROM ' . CRDR_DB::contacts() . ' WHERE id = ' . (int) $pk['id'] );

// admin features still work with the minimised rows
t( 'cooldown, cap and metrics are untouched by the clearing', 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000006' ) && '' === CRDR_DB::blocked_reason( 'p:09120000001' ) );
$card = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign page: the set-aside lines still work (they read the reason, not the text)', false !== mb_strpos( $card, 'کنار گذاشته' ) );
$age( 30 ); // old enough for the results page to show the recent sends (it waits for the measurement window)
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns', 'results' => 'dormant' ) );
t( 'recent sends: the reason of an unsent row is shown, the text of an accepted row is shown', false !== mb_strpos( $html, 'شماره‌ی گیرنده از نظر سرویس پیامک معتبر نیست' ) && false !== mb_strpos( $html, 'سلام' ) && false === strpos( $html, 'Warning:' ) );
$cid1 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$pg   = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid1 ) );
t( 'customer history: shows the reason for an unsent SMS and no empty text box', false !== mb_strpos( $pg, 'شماره رد شد' ) && false === strpos( $pg, '<div class="crdr-muted"></div>' ) );
$cid6 = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000006'" );
$pg   = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid6 ) );
t( 'customer history: still shows the text of an accepted SMS', false !== mb_strpos( $pg, 'سلام' ) );

// nothing sensitive anywhere that is stored or logged
$errs = implode( "\n", $wpdb->get_col( 'SELECT error FROM ' . CRDR_DB::contacts() . " WHERE error <> ''" ) );
$dump = $errs . wp_json_encode( get_option( CRDR_Send::LOG_OPTION ) ) . wp_json_encode( get_option( CRDR_Send::CRON_LOG_OPTION ) ) . wp_json_encode( get_option( CRDR_Health::OPTION ) );
t( 'stored errors and logs: no phone number, no key, no URL, no raw provider or cURL text', '' !== $errs && 0 === preg_match( '/09\d{9}|TESTKEY|https?:|kavenegar\.com|Operation timed out|Could not resolve/i', $dump ), $errs );
t( 'stored errors: a network failure is reduced to its cURL number', 1 === preg_match( '/cURL 6/', $errs ) );

// the other SMS plugin: its raw text is not copied either
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
update_option( 'crdr_settings', array_merge( CRDR_Settings::all(), array( 'sms_provider' => 'pwsms' ) ) );
foreach ( array( 'Invalid credentials for user admin 09120000001 key TESTKEY', 'cURL error 28: timed out 09120000001', 'throw' ) as $n => $behaviour ) {
	$GLOBALS['crdr_t_pwsms'] = $behaviour;
	$r = CRDR_Send::claim( 'p:0912000000' . ( $n + 1 ), 'dormant', 'pw' );
	CRDR_Send::send_claimed( $r['id'], '0912000000' . ( $n + 1 ), 'سلام خصوصی' );
}
$rows = crdr_t_rows( "kind = 'sms'" );
// PHASE 4.2 (approved): a plain text from that plugin used to be 'failed/uncertain' -> 'retry' (tried again after six hours). It is UNKNOWN now: that plugin
// catches its gateway's exceptions and returns their text, so a text may come after the gateway accepted the message, and a retry could send it twice.
// The administrator now sees a MASKED excerpt of the text (no phone number, no key, no link, no long token): the words stay, the secrets do not.
t( 'other SMS plugin: an error text is UNKNOWN (not retried) with a masked excerpt; a timeout / exception is "unknown" with no raw text', 'unknown' === $rows[0]['status'] && 'unknown' === $rows[1]['status'] && 'unknown' === $rows[2]['status'] && 0 === preg_match( '/09\d{9}|TESTKEY|timed out|internal error/i', implode( "\n", array_column( $rows, 'error' ) ) ) && false !== strpos( $rows[0]['error'], 'Invalid credentials' ), wp_json_encode( array_column( $rows, 'error' ), JSON_UNESCAPED_UNICODE ) );
t( 'other SMS plugin: every unknown row keeps its text (it may have been sent), none is a contact-free row', '' !== (string) $rows[0]['message'] && '' !== (string) $rows[1]['message'] && '' !== (string) $rows[2]['message'] );
$GLOBALS['crdr_t_pwsms'] = 'ok';
crdr_t_reset( 0 );

// upgrade 3 -> 4: rows written by the previous versions are cleaned once, and only those
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
$secret = 'KEY-' . wp_rand( 100000, 999999 );
update_option( 'crdr_settings', array_merge( CRDR_Settings::all(), array( 'kavenegar_key' => $secret ) ) );
$ins = function ( $ck, $status, $message, $error ) use ( $wpdb ) {
	$wpdb->insert( CRDR_DB::contacts(), array( 'ckey' => $ck, 'kind' => 'sms', 'playbook' => 'dormant', 'grp' => 'treated', 'message' => $message, 'status' => $status, 'error' => $error, 'created_ts' => time() - 86400 * 3 ) );
	return (int) $wpdb->insert_id;
};
$old = array(
	'failed'   => $ins( 'p:09121110001', 'failed', 'سلام ۱', 'کاوه‌نگار (418): اعتبار کافی نیست' ),
	'rejected' => $ins( 'p:09121110002', 'rejected', 'سلام ۲', 'خطا برای 09121110002' ),
	'nobuild'  => $ins( 'p:09121110003', 'nobuild', 'سلام ۳', 'x ' . $secret . ' y' ),
	'retry'    => $ins( 'p:09121110004', 'retry', 'سلام ۴', '' ),
	'ok'       => $ins( 'p:09121110005', 'ok', 'سلام ۵ کد ABC', '' ),
	'unknown'  => $ins( 'p:09121110006', 'unknown', 'سلام ۶', 'پاسخ مشخص نشد (cURL error 28 09121110006)' ),
	'pending'  => $ins( 'p:09121110007', 'pending', 'سلام ۷', '' ),
);
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . " SET claim_key = ckey WHERE status = 'pending'" );
$before = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() );
update_option( 'crdr_db_version', '3' );
CRDR_DB::maybe_upgrade();
delete_option( CRDR_DB::REBUILD_OPTION ); // (see the PHASE 4.1 note above)
$row = function ( $id ) {
	return crdr_t_rows( 'id = ' . (int) $id )[0];
};
t( 'upgrade 3->4: the version moves on (to the current version; since Phase 3A that is 5, and the same call also performs 4->5)', CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) );
t( 'upgrade 3->4: text of SMS that were never sent is cleared (failed, rejected, nobuild, retry)', '' === (string) $row( $old['failed'] )['message'] && '' === (string) $row( $old['rejected'] )['message'] && '' === (string) $row( $old['nobuild'] )['message'] && '' === (string) $row( $old['retry'] )['message'] );
t( 'upgrade 3->4: ok, unknown and pending rows keep their text, status and claim', 'سلام ۵ کد ABC' === $row( $old['ok'] )['message'] && 'سلام ۶' === $row( $old['unknown'] )['message'] && 'سلام ۷' === $row( $old['pending'] )['message'] && 'pending' === $row( $old['pending'] )['status'] && 'p:09121110007' === $row( $old['pending'] )['claim_key'] );
t( 'upgrade 3->4: no row was removed and no status changed', (int) $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() ) && 'failed' === $row( $old['failed'] )['status'] && 'rejected' === $row( $old['rejected'] )['status'] );
t( 'upgrade 3->4: an old "provider text" error becomes our own wording', CRDR_Send_Rules::failure_reason( 418 ) === $row( $old['failed'] )['error'] );
t( 'upgrade 3->4: phone numbers and the API key are removed from old error texts (also for ok/unknown rows)', false === strpos( $row( $old['rejected'] )['error'], '0912111' ) && false === strpos( $row( $old['nobuild'] )['error'], $secret ) && false === strpos( $row( $old['unknown'] )['error'], '0912111' ) && false !== strpos( $row( $old['unknown'] )['error'], 'cURL error 28' ) );
$snap = wp_json_encode( crdr_t_rows() );
update_option( 'crdr_db_version', '3' );
CRDR_DB::maybe_upgrade();
delete_option( CRDR_DB::REBUILD_OPTION );
t( 'upgrade 3->4: running it again changes nothing', $snap === wp_json_encode( crdr_t_rows() ) );
t( 'upgrade 3->4: a customer is still resting after the cleaning (cooldown unchanged)', 'cooldown' === CRDR_DB::blocked_reason( 'p:09121110005' ) && '' === CRDR_DB::blocked_reason( 'p:09121110001' ) );
// 4 -> 5 (Phase 3A): the order table gains uid and email; existing rows survive and keep their key
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::orders() );
$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . CRDR_DB::orders() . ' (order_id,ckey,ts,net,items) VALUES (%d,%s,%d,%f,%d)', 4242, 'p:09120000042', 1790000000, 1234.0, 2 ) );
$wpdb->query( 'ALTER TABLE ' . CRDR_DB::orders() . ' DROP COLUMN uid, DROP COLUMN email' );
update_option( 'crdr_db_version', '4' );
CRDR_DB::maybe_upgrade();
delete_option( CRDR_DB::REBUILD_OPTION );
$ocols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . CRDR_DB::orders(), 0 );
$orow  = $wpdb->get_row( 'SELECT * FROM ' . CRDR_DB::orders() . ' WHERE order_id = 4242', ARRAY_A );
t( 'upgrade 4->5: uid and email are added to the order table, the version moves on (to the current 6 since Phase 4.2, which also adds contacts.orphan_ts), the existing row keeps its key, values and gets the defaults', ! array_diff( array( 'uid', 'email' ), $ocols ) && CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) && 'p:09120000042' === $orow['ckey'] && '0' === (string) $orow['uid'] && '' === $orow['email'] && 2 === (int) $orow['items'], wp_json_encode( array( $ocols, $orow ) ) );
CRDR_DB::maybe_upgrade();
t( 'upgrade 4->5: running it again changes nothing', 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() ) && CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) );
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::orders() );
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
crdr_t_reset( 0 );

/* ====== C1. a sending window that can never open is refused when it is saved ====== */
echo "== settings: sending window\n";
$errs = function ( $from, $to ) {
	return CRDR_Settings::window_errors( CRDR_Settings::sanitize( array( 'send_from' => $from, 'send_to' => $to ) ) );
};
t( 'window check: 9..21, 0..24, 22..24, 0..1 and 23..24 are valid', array() === $errs( 9, 21 ) && array() === $errs( 0, 24 ) && array() === $errs( 22, 24 ) && array() === $errs( 0, 1 ) && array() === $errs( 23, 24 ) );
t( 'window check: from == to (9..9) and a window that wraps midnight (22..6) are refused', 1 === count( $errs( 9, 9 ) ) && 1 === count( $errs( 22, 6 ) ) );
t( 'window check: the message is Persian, shows the two hours and says what to do', false !== mb_strpos( $errs( 22, 6 )[0], 'ساعت شروع' ) && false !== mb_strpos( $errs( 22, 6 )[0], CRDR_Jalali::digits( '22' ) ) && false !== mb_strpos( $errs( 22, 6 )[0], 'شبانه' ) && false !== mb_strpos( $errs( 22, 6 )[0], CRDR_Jalali::digits( '24' ) ) );
t( 'window check: values outside the form\'s range are clamped first (30..0 becomes 23..1, which is refused)', 1 === count( $errs( 30, 0 ) ) );
t( 'window check: a form that does not send the hours keeps the defaults, which are valid', array() === CRDR_Settings::window_errors( CRDR_Settings::sanitize( array() ) ) );
crdr_t_cfg( array(), array( 'send_from' => 10, 'send_to' => 18 ) );
$read = function () {
	wp_cache_delete( 'crdr_settings', 'options' ); // the worker process changed it; this process may hold an old copy
	wp_cache_delete( 'alloptions', 'options' );
	$o = get_option( 'crdr_settings' );
	return array( (int) $o['send_from'], (int) $o['send_to'] );
};
$w = crdr_t_parallel( array( array( 'save_settings', '1', '22', '6' ) ), 2 );
t( 'saving 22..6 through the real handler: nothing is stored and the person is sent back to the form', array( 10, 18 ) === $read() && false !== strpos( (string) ( $w[0]['redirect'] ?? '' ), 'invalid=1' ), wp_json_encode( $w ) );
// PHASE 4.2: with a PERSISTENT object cache WordPress keeps transients only in the cache, so deleting the entry here would delete the worker's real draft.
// Only forget this process's own copy (flush_runtime, WP 6.1+); with the default cache the delete below is the same thing.
if ( function_exists( 'wp_cache_flush_runtime' ) ) {
	wp_cache_flush_runtime();
} elseif ( ! wp_using_ext_object_cache() ) {
	wp_cache_delete( 'crdr_settings_draft_1', 'transient' );
}
$page = crdr_t_page( array( 'CRDR_Page_Settings', 'render' ), array( 'page' => 'customer-radar-settings', 'invalid' => 1 ) );
t( 'the form comes back with the reason and what was typed', false !== mb_strpos( $page, 'ذخیره نشد' ) && false !== mb_strpos( $page, 'ساعت شروع' ) && false !== strpos( $page, 'value="22"' ) && false !== strpos( $page, 'value="6"' ) );
$w = crdr_t_parallel( array( array( 'save_settings', '1', '9', '9' ) ), 2 );
t( 'saving 9..9 is refused too', array( 10, 18 ) === $read() && false !== strpos( (string) ( $w[0]['redirect'] ?? '' ), 'invalid=1' ) );
$w = crdr_t_parallel( array( array( 'save_settings', '1', '8', '22' ) ), 2 );
t( 'saving a valid window works as before', array( 8, 22 ) === $read() && false !== strpos( (string) ( $w[0]['redirect'] ?? '' ), 'saved=1' ), wp_json_encode( $w ) );
// a window saved by an earlier version is not touched; it keeps failing safe and explaining itself (unchanged behaviour)
update_option( 'crdr_settings', array_merge( CRDR_Settings::all(), array( 'send_from' => 22, 'send_to' => 6 ) ) );
t( 'an already stored overnight window still never opens (fail closed, unchanged)', false === CRDR_Playbooks::in_send_window() );
crdr_t_cfg();
crdr_t_reset( 0 );

/* ====== C3. a scheduler that does not run is reported ====== */
echo "== cron overdue\n";
$sched = function ( $offset ) {
	wp_clear_scheduled_hook( CRDR_Playbooks::HOURLY );
	wp_schedule_event( time() + $offset, 'hourly', CRDR_Playbooks::HOURLY );
};
$disabled = function ( $on ) {
	remove_all_filters( 'crdr_wp_cron_disabled' );
	add_filter( 'crdr_wp_cron_disabled', $on ? '__return_true' : '__return_false' );
};
$note = function () {
	wp_set_current_user( 1 );
	return CRDR_Health::notice_html();
};
delete_option( CRDR_Health::OPTION );
crdr_t_cfg( array( 'mode' => 'auto' ) );
crdr_t_reset( 2 );
$sched( 600 );
$disabled( true );
t( 'cron: on time (next run in the future): no warning', '' === $note() );
$sched( -5 * 3600 );
t( 'cron, host cron expected: 5 hours overdue raises the warning, telling the owner what to do', false !== mb_strpos( $note(), 'notice-warning' ) && false !== mb_strpos( $note(), 'پیامکی نمی‌فرستند' ) && false !== strpos( $note(), 'cron' ) );
$disabled( false );
t( 'cron, WP-Cron on: the same 5 hours is normal on a quiet site, no warning', '' === $note() );
$sched( -49 * 3600 );
t( 'cron, WP-Cron on: 49 hours overdue is a real problem', false !== mb_strpos( $note(), 'WP-Cron' ) );
crdr_t_cfg( array( 'mode' => 'manual' ) );
t( 'cron: only automatic campaigns depend on it, so no warning without one', '' === $note() );
crdr_t_cfg( array( 'mode' => 'auto' ) );
wp_set_current_user( 0 );
t( 'cron: the warning is only for people who may manage the plugin', '' === CRDR_Health::notice_html() );
// together with a blocked campaign: one box, both reasons
CRDR_Health::record_block( 'dormant', 'no_provider' );
$both = $note();
t( 'cron: shown together with a campaign problem in the same warning', false !== mb_strpos( $both, 'WP-Cron' ) && false !== mb_strpos( $both, CRDR_Health::message( 'no_provider' ) ) && 1 === substr_count( $both, 'notice-warning' ) );
// and it clears as soon as the scheduler has run
$sched( 3000 );
delete_option( CRDR_Health::OPTION );
t( 'cron: once the next run is in the future again the warning is gone', '' === $note() );
remove_all_filters( 'crdr_wp_cron_disabled' );
wp_clear_scheduled_hook( CRDR_Playbooks::HOURLY );
wp_schedule_event( time() + 300, 'hourly', CRDR_Playbooks::HOURLY );
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
t( 'timeout: the run stops at the uncertain SMS and says so', 1 === $r['unknown'] && 0 === $r['sent'] && '' !== $r['error'] && false !== mb_strpos( $r['error'], 'ممکن است پیامک رفته باشد' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$u = crdr_t_rows( "status = 'unknown'" );
t( 'timeout: the row is "unknown" (not failed), keeps message + localid, claim cleared', 1 === count( $u ) && '' !== $u[0]['localid'] && 'سلام مشتری 1 لغو۱۱' === $u[0]['message'] && null === $u[0]['claim_key'] );
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
t( 'gateway says "no credit" (418): definite failure, not "unknown", and the run stops at the first one (nobody is at fault)', 1 === $r['failed'] && 0 === $r['unknown'] && 1 === count( crdr_t_requests() ) && false !== strpos( $r['error'], '418' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );

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
// the ten-minute boundary (600 s): 599 s old is still in flight, 601 s old is abandoned
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 599 ) . ' WHERE id = ' . (int) $fresh['id'] );
t( 'stale boundary: a claim 599 s old is left alone', 0 === CRDR_Send::recover_stale() && 'pending' === crdr_t_rows( "id = {$fresh['id']}" )[0]['status'] );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 601 ) . ' WHERE id = ' . (int) $fresh['id'] );
t( 'stale boundary: a claim 601 s old is recovered', 1 === CRDR_Send::recover_stale() );
// never reached the gateway (no localid was ever recorded): nothing was sent, so it is NOT a contact and may be tried again
$row = crdr_t_rows( "id = {$fresh['id']}" )[0];
t( 'recovered before sending: status failed (nothing went out), claim key freed, a reason is stored, no text', 'failed' === $row['status'] && null === $row['claim_key'] && '' !== $row['error'] && '' === (string) $row['message'] && '' === $row['localid'] );
t( 'recovered before sending: the customer is NOT resting and can be claimed again', '' === CRDR_DB::blocked_reason( 'p:09120000001' ) && isset( CRDR_Send::claim( 'p:09120000001', 'dormant', 'z' )['id'] ) );
$wpdb->query( 'DELETE FROM ' . CRDR_DB::contacts() . " WHERE ckey = 'p:09120000001' AND status = 'pending'" );

// the slow original process wakes up after its claim was recovered: it must not send
crdr_t_cfg( array(), array(), array( 'mode' => 'ok' ) );
crdr_t_reset( 3 );
$slow = CRDR_Send::claim( 'p:09120000001', 'dormant', 'slow' );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 3600 ) . ' WHERE id = ' . (int) $slow['id'] );
CRDR_Send::recover_stale();
$out = CRDR_Send::send_claimed( $slow['id'], '09120000001', 'سلام' );
t( 'a slow process whose claim was recovered before it recorded its text sends nothing', 'failed' === $out['state'] && 0 === count( crdr_t_requests() ) && 'failed' === crdr_t_rows( "id = {$slow['id']}" )[0]['status'] );

// reached the gateway (localid recorded): it may have gone out, so it becomes unknown and the customer rests
crdr_t_reset( 3 );
$sent = CRDR_Send::claim( 'p:09120000001', 'dormant', 'x2' );
$an   = new ReflectionMethod( 'CRDR_Send', 'annotate' );
$an->setAccessible( true );
$an->invoke( null, $sent['id'], 'سلام', '', '1790000000000000009' );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 3600 ) . ' WHERE id = ' . (int) $sent['id'] );
t( 'recover_stale converts a claim that reached the sending step to "unknown"', 1 === CRDR_Send::recover_stale() );
$row = crdr_t_rows( "id = {$sent['id']}" )[0];
t( 'recovered after sending: unknown, claim key freed, explanation stored, the text is kept', 'unknown' === $row['status'] && null === $row['claim_key'] && '' !== $row['error'] && 'سلام' === $row['message'] );
t( 'recovered customer is NOT eligible for another send', 'cooldown' === CRDR_Send::claim( 'p:09120000001', 'dormant', 'z' )['skip'] );
crdr_t_reset( 4 );
$a = CRDR_Send::claim( 'p:09120000001', 'dormant', 'a' );
$b = CRDR_Send::claim( 'p:09120000002', 'dormant', 'b' );
$an->invoke( null, $b['id'], 'سلام', '', '1790000000000000010' );
$wpdb->query( 'UPDATE ' . CRDR_DB::contacts() . ' SET created_ts = ' . ( time() - 3600 ) );
t( 'recover_stale handles a never-sent and a possibly-sent claim in one call', 2 === CRDR_Send::recover_stale() && 'failed' === crdr_t_rows( "id = {$a['id']}" )[0]['status'] && 'unknown' === crdr_t_rows( "id = {$b['id']}" )[0]['status'] );

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
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}' ) );
crdr_t_reset( 3 );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'campaign list renders and mentions the hours apply to manual runs too', $clean( $html ) && false !== mb_strpos( $html, 'ساعت مجاز ارسال (خودکار و دستی)' ) );
CRDR_Playbooks::save( 'dormant', array( 'name' => 'خواب', 'mode' => 'auto', 'template' => 'کد {coupon}', 'offer_type' => 'none' ) ); // rejected -> draft
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns', 'edit' => 'dormant', 'invalid' => 1 ) );
t( 'edit form after a rejected save: explains what is wrong, in Persian', $clean( $html ) && false !== mb_strpos( $html, 'کمپین ذخیره نشد' ) && false !== mb_strpos( $html, 'notice-error' ) );
t( 'edit form after a rejected save: keeps what the person typed', false !== mb_strpos( $html, '>کد {coupon}</textarea>' ) );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns', 'edit' => 'dormant' ) );
t( 'edit form opened normally: stored template, no stale error', $clean( $html ) && false !== mb_strpos( $html, '>سلام {name} {optout}</textarea>' ) && false === mb_strpos( $html, 'کمپین ذخیره نشد' ) );
set_transient( CRDR_Settings::draft_key(), array( 'values' => CRDR_Settings::sanitize( array( 'sms_template' => 'مرحله {coupon}' ) ), 'errors' => array( 'خطای آزمایشی' ) ), 300 );
$html = crdr_t_page( array( 'CRDR_Page_Settings', 'render' ), array( 'page' => 'customer-radar-settings', 'invalid' => 1 ) );
t( 'settings after a rejected save: error shown and typed text kept', $clean( $html ) && false !== mb_strpos( $html, 'خطای آزمایشی' ) && false !== mb_strpos( $html, 'مرحله {coupon}' ) );
$html = crdr_t_page( array( 'CRDR_Page_Settings', 'render' ), array( 'page' => 'customer-radar-settings' ) );
t( 'settings page opened normally renders', $clean( $html ) && false !== mb_strpos( $html, 'ارسال پیامک‌های تبلیغاتی (کمپین خودکار، کمپین دستی و پیامک تکی فهرست تماس) فقط از ساعت' ) );
CRDR_DB::add_contact( 'p:09120000001', 'sms', array( 'playbook' => 'dormant', 'status' => 'unknown', 'error' => 'timeout', 'message' => 'سلام' ) );
$cid  = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'" );
$html = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid ) );
t( 'customer page shows an uncertain SMS as such', $clean( $html ) && false !== mb_strpos( $html, 'نتیجه نامشخص' ) );

/* ============================================== G. sync guard (real database, real processes) */
echo "== sync guard\n";
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
$set_state = function ( $phase, array $extra = array() ) {
	update_option( CRDR_Sync::STATE_OPTION, array_merge( array( 'phase' => $phase, 'updated' => time(), 'page' => 1, 'total' => 10, 'done' => 0, 'inflight' => 0 ), $extra ), false );
};
$contacts = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() );
};
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}' ) );
crdr_t_reset( 4 );
$cid = (int) $wpdb->get_var( 'SELECT id FROM ' . CRDR_DB::customers() . ' ORDER BY id LIMIT 1' );

// ---- while the data is being rebuilt, nothing that sends or lists customers works (every server-side entry point)
$set_state( 'orders' );
foreach ( array( 'sms', 'sms_coupon', 'optout', 'called', 'skip' ) as $type ) {
	$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => $type ) );
	t( "rebuild running: one-off '{$type}' is refused with the reason", false === $j['success'] && 'sync_running' === $j['data']['code'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}
t( 'rebuild running: nothing reached the gateway and no contact (not even an opt-out) was written', 0 === count( crdr_t_requests() ) && 0 === $contacts() );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cid, 'coupon' => 0 ) );
t( 'rebuild running: the one-off preview is refused', false === $j['success'] && 'sync_running' === $j['data']['code'] );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_pb_preview' ), array( 'id' => 'dormant' ) );
t( 'rebuild running: the campaign preview answers "blocked", with no audience and no samples', true === $j['success'] && 'sync_running' === $j['data']['blocked'] && 0 === $j['data']['audience'] && array() === $j['data']['samples'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_pb_run' ), array( 'id' => 'dormant' ) );
t( 'rebuild running: the campaign run button (ajax) is refused', true === $j['success'] && 'sync_running' === $j['data']['blocked'] && 0 === $j['data']['sent'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$r = CRDR_Playbooks::run( 'dormant', 10, array( 'trigger' => 'manual' ) );
t( 'rebuild running: Playbooks::run() (manual)', 'sync_running' === $r['blocked'] && 0 === $r['sent'] );
$r = CRDR_Playbooks::run( 'dormant', 10, array( 'trigger' => 'cron' ) );
t( 'rebuild running: Playbooks::run() (cron)', 'sync_running' === $r['blocked'] && 0 === $r['sent'] );
crdr_t_cfg( array( 'mode' => 'auto' ) );
CRDR_Playbooks::hourly();
t( 'rebuild running: the hourly cron tick sends nothing', 0 === count( crdr_t_requests() ) && 0 === $contacts() );
$claim = CRDR_Send::claim( 'p:09120000001', 'dormant', 'g' );
t( 'rebuild running: CRDR_Send::claim() refuses and writes nothing', array( 'skip' => 'syncing' ) === $claim && 0 === $contacts() );
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}' ) );
$html_calls  = crdr_t_page( array( 'CRDR_Page_Customers', 'render_calls' ), array( 'page' => 'customer-radar-calls' ) );
$html_list   = crdr_t_page( array( 'CRDR_Page_Customers', 'render_list' ), array( 'page' => 'customer-radar-customers' ) );
$html_detail = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $cid ) );
foreach ( array( 'call list' => $html_calls, 'customer list' => $html_list, 'customer page' => $html_detail ) as $what => $html ) {
	t( "rebuild running: the {$what} shows the reason instead of customers", false !== mb_strpos( $html, 'در حال به‌روزرسانی' ) && false === strpos( $html, '<table' ) && false === mb_strpos( $html, 'مشتری 1' ) && false === strpos( $html, 'data-act="sms"' ) );
}
$html = crdr_t_page( array( 'CRDR_Page_Dashboard', 'render' ), array( 'page' => 'customer-radar' ) );
t( 'rebuild running: the dashboard warns that its numbers are incomplete', false !== mb_strpos( $html, 'عددهای این صفحه تا پایان به‌روزرسانی کامل نیستند' ) );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns' ) );
t( 'rebuild running: the campaigns page warns too', false !== mb_strpos( $html, 'عددهای این صفحه تا پایان به‌روزرسانی کامل نیستند' ) );
$w = crdr_t_parallel( array( array( 'export' ) ), 2 );
t( 'rebuild running: the CSV export is refused (wp_die) and streams nothing', ! empty( $w[0]['refused'] ) && false !== mb_strpos( $w[0]['message'], 'در حال به‌روزرسانی' ), wp_json_encode( $w, JSON_UNESCAPED_UNICODE ) );

// ---- a failed or stalled rebuild keeps blocking, and says so
foreach ( array( 'failed' => array( 'failed', array() ), 'stalled' => array( 'orders', array( 'updated' => time() - CRDR_Sync::STALL_AFTER - 10 ) ) ) as $label => $def ) {
	$set_state( $def[0], $def[1] );
	$r = CRDR_Playbooks::run( 'dormant', 10, array( 'trigger' => 'manual' ) );
	$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
	t( "a {$label} rebuild still blocks campaigns and one-off SMS, with its own reason", $r['blocked'] === ( 'failed' === $label ? 'sync_failed' : 'sync_stalled' ) && false === $j['success'] && 0 === count( crdr_t_requests() ), wp_json_encode( array( $r['blocked'], $j['data']['code'] ?? null ) ) );
	wp_set_current_user( 1 );
	t( "a {$label} rebuild is shown to the administrator as a persistent warning", false !== mb_strpos( CRDR_Health::notice_html(), 'تحلیل سفارش‌ها' ) );
}

// ---- after a successful sync everything works again
$set_state( 'done' );
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}' ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'after a successful sync: a one-off SMS is sent', true === $j['success'] && 1 === count( crdr_t_requests() ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$r = CRDR_Playbooks::run( 'dormant', 10, array( 'trigger' => 'manual' ) );
t( 'after a successful sync: the campaign runs (3 more customers)', '' === $r['blocked'] && 3 === $r['sent'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$html = crdr_t_page( array( 'CRDR_Page_Customers', 'render_list' ), array( 'page' => 'customer-radar-customers' ) );
t( 'after a successful sync: the customer list shows customers again', false !== strpos( $html, '<table' ) && false !== mb_strpos( $html, 'مشتری 1' ) );
$w = crdr_t_parallel( array( array( 'export' ) ), 2 );
t( 'after a successful sync: the CSV export streams (the file starts with its Persian header row)', empty( $w[0]['refused'] ) && false !== mb_strpos( (string) ( $w[0]['raw'] ?? '' ), 'نام' ), substr( wp_json_encode( $w, JSON_UNESCAPED_UNICODE ), 0, 200 ) );

// ---- a REAL rebuild over real WooCommerce orders and a real database (205 orders = 2 pages)
$made = array();
for ( $i = 1; $i <= 205; $i++ ) {
	$o = wc_create_order();
	$o->set_billing_phone( sprintf( '0913%07d', $i ) );
	$o->set_billing_email( "sync{$i}@example.invalid" );
	crdr_t_money( $o, 1000 );
	$o->set_status( 'completed' );
	$o->save();
	$made[] = $o->get_id();
}
$trace = array();
$s     = CRDR_Sync::start();
$trace[] = array( $s['phase'], CRDR_Sync::guard()['blocked'], 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() ) );
t( 'real rebuild: start() empties the tables and the guard is already on', array( 'orders', true, true ) === $trace[0] && 205 === (int) $s['total'], wp_json_encode( $s ) );
for ( $i = 0; $i < 5; $i++ ) {
	$s       = CRDR_Sync::step();
	$trace[] = array( $s['phase'], CRDR_Sync::guard()['blocked'] );
	if ( 1 === $i ) { // between the pages and the scoring a campaign is still refused
		$r = CRDR_Playbooks::run( 'dormant', 5, array( 'trigger' => 'manual' ) );
		t( 'real rebuild: after the last page, while scoring is pending, a campaign run is still refused', 'finalize' === $s['phase'] && 'sync_running' === $r['blocked'] );
	}
}
t( 'real rebuild (2 pages): phases orders, finalize, stats, done; guard on after every request but the last', array_column( $trace, 0 ) === array( 'orders', 'orders', 'finalize', 'stats', 'done', 'done' ) && array( true, true, true, true, false, false ) === array_column( $trace, 1 ), wp_json_encode( $trace ) );
$sum_orders = (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() );
t( 'real rebuild: 205 customers and 205 orders, none counted twice', 205 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() ) && 205 === $sum_orders && 205 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() ) );
t( 'real rebuild: the lock row is gone and the state is "done" with no error', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) ) && ! isset( CRDR_Sync::state( true )['error'] ) );

// ---- lock contention between real processes: only one may hold it
$w   = crdr_t_parallel( array_fill( 0, 6, array( 'sync_lock', '1500' ) ), 3 );
$got = count( array_filter( array_column( $w, 'got' ) ) );
t( 'six processes ask for the sync lock at the same instant: exactly one gets it (the database decides, not PHP)', 1 === $got, wp_json_encode( $w ) );
t( 'and the lock is released afterwards', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) ) );

// ---- concurrent steps never import a page twice
$s = CRDR_Sync::start();
$w = crdr_t_parallel( array_fill( 0, 4, array( 'sync_step' ) ), 3 );
$advanced = count( array_filter( $w, function ( $x ) {
	return isset( $x['busy'] ) && ! $x['busy'];
} ) );
$st  = CRDR_Sync::state( true );
$cnt = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() );
$sum = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(orders_count),0) FROM ' . CRDR_DB::customers() );
t( 'four concurrent steps: every step that ran advanced one page, and no order is counted twice (customers = orders = pages read)', $advanced >= 1 && (int) $st['page'] === 1 + $advanced && $cnt === $sum && $sum === (int) $st['done'] && ! isset( $st['error'] ), wp_json_encode( array( 'results' => $w, 'state' => array( $st['phase'], $st['page'], $st['done'] ), 'orders' => $cnt, 'customers_sum' => $sum ) ) );

// ---- a step that dies in the middle of a page
$s = CRDR_Sync::start();
$w = crdr_t_parallel( array( array( 'sync_die' ) ), 3 );
$st = CRDR_Sync::state( true );
t( 'a step that dies mid-page leaves the rebuild marked in-flight and the guard on', ! empty( $w[0]['died_after_orders'] ) && 'orders' === $st['phase'] && ! empty( $st['inflight'] ) && true === CRDR_Sync::guard()['blocked'], wp_json_encode( array( $w, $st ) ) );
t( 'the dead step\'s lock is released by its shutdown handler (a retry does not wait ten minutes)', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) ) );
$partial = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() );
$s       = CRDR_Sync::step();
t( 'the next step does NOT resume the half-applied page: the rebuild fails, nothing more is imported', 'failed' === $s['phase'] && 'interrupted_page' === $s['error'] && $partial === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() ) && $partial > 0 && $partial < 200 );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'type' => 'sms' ) );
t( 'after the interrupted rebuild, sending stays blocked ("failed") until a new rebuild completes', 'sync_failed' === ( $j['data']['code'] ?? '' ) );
$s = CRDR_Sync::start();
for ( $i = 0; $i < 5; $i++ ) {
	$s = CRDR_Sync::step();
}
t( 'retry: a fresh start() rebuilds everything correctly (205 orders, 205 customers, nothing counted twice) and re-opens sending', 'done' === $s['phase'] && false === CRDR_Sync::guard()['blocked'] && 205 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() ) && 205 === (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() ) );
foreach ( $made as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
crdr_t_reset( 0 );


/* ============================ H. stale customer ids: a page left open across a rebuild cannot act on somebody else ============================ */
echo "== stale customer ids\n";
delete_option( CRDR_Sync::STATE_OPTION );
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}' ) );
crdr_t_reset( 3 );
$all_contacts = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() );
};
$coupons = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
};
$id_of = function ( $ckey ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $ckey ) );
};
$A = 'p:09120000001';
$B = 'p:09120000003';
$idA = $id_of( $A );
$page = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $idA ) );
preg_match( '/data-id="(\d+)" data-k="([0-9a-f]+)"/', $page, $m );
$page_id = isset( $m[1] ) ? (int) $m[1] : 0;
$page_k  = isset( $m[2] ) ? $m[2] : '';
t( 'the customer page carries the row id AND a stable identity token', $page_id === $idA && 24 === strlen( $page_k ) && $page_k === CRDR_Actions::identity( $A ), wp_json_encode( $m ) );
t( 'the token is not the customer key (no phone number or e-mail in the page attribute) and differs per customer', false === strpos( $page, 'data-k="p:' ) && false === strpos( $page_k, '0912' ) && CRDR_Actions::identity( $A ) !== CRDR_Actions::identity( $B ) );

// ---- control: the page as it was drawn still works while nothing was rebuilt
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $page_id, 'k' => $page_k, 'coupon' => 0 ) );
t( 'unchanged data: the preview works with the page\'s id and token', true === $j['success'] && $A === 'p:' . $j['data']['phone'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $page_id, 'k' => $page_k, 'type' => 'called' ) );
t( 'unchanged data: "called" is written for that customer', true === $j['success'] && 1 === crdr_t_count( $wpdb->prepare( 'ckey = %s', $A ) ) );
crdr_t_reset( 3 );

// ---- no token at all, or a made-up one
foreach ( array( 'missing' => null, 'made-up' => str_repeat( 'a', 24 ), 'empty' => '' ) as $what => $k ) {
	$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $idA, 'k' => $k, 'type' => 'optout' ) );
	t( "a {$what} identity token is refused as stale, nothing is written", false === $j['success'] && 'stale_customer' === ( $j['data']['code'] ?? '' ) && 0 === $all_contacts(), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}

// ---- a rebuild hands the ids out again: the id the page holds now belongs to somebody else
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::customers() );
$now = time();
foreach ( array( 3, 2, 1 ) as $i ) { // reverse order: the id that was customer 1's is customer 3's now
	$phone = sprintf( '09120000%03d', $i );
	$wpdb->insert( CRDR_DB::customers(), array( 'ckey' => 'p:' . $phone, 'name' => 'مشتری ' . $i, 'phone' => $phone, 'email' => '', 'orders_count' => 1, 'total_spent' => 100000, 'first_ts' => $now - 90 * 86400, 'last_ts' => $now - 90 * 86400, 'segment' => 'hibernating', 'priority' => 1 ) );
}
t( 'setup: after the "rebuild" the old row id points to a DIFFERENT customer', $id_of( $A ) !== $idA && $id_of( $B ) === $idA );
$coupons_before = $coupons();
foreach ( array( 'sms', 'sms_coupon', 'called', 'skip', 'optout' ) as $type ) {
	$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $page_id, 'k' => $page_k, 'type' => $type, 'note' => 'x' ) );
	t( "stale page, one-off '{$type}': refused as stale (a clear message, code stale_customer)", false === $j['success'] && 'stale_customer' === ( $j['data']['code'] ?? '' ) && false !== mb_strpos( $j['data']['message'] ?? '', 'دوباره بارگذاری' ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}
t( 'stale page: NOTHING happened to the customer who now holds the id (no SMS, no contact row, no opt-out, no call note, no coupon)', 0 === count( crdr_t_requests() ) && 0 === $all_contacts() && $coupons() === $coupons_before );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $page_id, 'k' => $page_k, 'coupon' => 0 ) );
t( 'stale page: the preview is refused too, and it does not show the other customer\'s phone number or name', false === $j['success'] && 'stale_customer' === ( $j['data']['code'] ?? '' ) && false === strpos( wp_json_encode( $j, JSON_UNESCAPED_UNICODE ), '09120000003' ) && false === mb_strpos( wp_json_encode( $j, JSON_UNESCAPED_UNICODE ), 'مشتری 3' ) );

// ---- the same customer, fresh page: id and token agree again and the action works on the right person
$idA2 = $id_of( $A );
$page2 = crdr_t_page( array( 'CRDR_Page_Customers', 'render_detail' ), array( 'page' => 'customer-radar-customer', 'id' => $idA2 ) );
preg_match( '/data-id="(\d+)" data-k="([0-9a-f]+)"/', $page2, $m2 );
t( 'a fresh page after the rebuild carries the new id with the SAME stable token', (int) $m2[1] === $idA2 && $idA2 !== $page_id && $m2[2] === $page_k );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $idA2, 'k' => $m2[2], 'type' => 'called' ) );
t( 'the old token with the customer\'s NEW id works (the identity, not the row id, is what the page vouches for)', true === $j['success'] && 1 === crdr_t_count( $wpdb->prepare( 'ckey = %s', $A ) ) && 1 === $all_contacts() );
$C  = 'p:09120000002'; // (customer A was just logged as "called", which starts his cooldown: use another customer for the SMS)
$j  = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $id_of( $C ), 'k' => CRDR_Actions::identity( $C ), 'type' => 'sms' ) );
t( 'and a real one-off SMS goes to the customer the token names (one gateway call, to his own number)', true === $j['success'] && 1 === count( crdr_t_requests() ) && false !== strpos( wp_json_encode( crdr_t_requests() ), '09120000002' ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );

// ---- other ways an id can go stale
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $B ) );
$before = $all_contacts();
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $idA, 'k' => CRDR_Actions::identity( $B ), 'type' => 'optout' ) );
t( 'a customer who no longer exists after the rebuild: refused as stale, nothing written', false === $j['success'] && 'stale_customer' === ( $j['data']['code'] ?? '' ) && $before === $all_contacts() );
$wpdb->update( CRDR_DB::customers(), array( 'ckey' => 'p:09129999999', 'phone' => '09129999999' ), array( 'id' => $idA2 ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $idA2, 'k' => $m2[2], 'type' => 'optout' ) );
t( 'the same row id but the customer\'s key changed (new phone after a rebuild): refused as stale', false === $j['success'] && 'stale_customer' === ( $j['data']['code'] ?? '' ) && 0 === crdr_t_count( "kind = 'optout'" ) );
crdr_t_reset( 0 );


/* ============================ I. the sync lock: ownership, renewal, takeover (real database) ============================ */
echo "== sync lock ownership\n";
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
$lock_val  = function () use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );
};
$state_row = function () use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::STATE_OPTION ), ARRAY_A );
};
$priv = function ( $method, array $args = array() ) {
	$m = new ReflectionMethod( 'CRDR_Sync', $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
};
$held = function ( $set = false, $value = null ) {
	$p = new ReflectionProperty( 'CRDR_Sync', 'held' );
	$p->setAccessible( true );
	if ( $set ) {
		$p->setValue( null, $value );
	}
	return $p->getValue();
};

// ---- the fenced state write on the real database (INSERT ... SELECT ... ON DUPLICATE KEY UPDATE)
$val = $priv( 'lock' );
t( 'real database: the lock row is "time:token"', 1 === preg_match( '/^\d+:[0-9a-f]{16}$/', (string) $lock_val() ) && $lock_val() === $val );
t( 'real database: no state row yet', null === $state_row() );
$s1 = $priv( 'save_state', array( array( 'phase' => 'orders', 'page' => 1 ) ) );
$r1 = $state_row();
t( 'real database: the owner\'s write CREATES the state row (not autoloaded) and get_option reads it back', $r1 && 'no' === $r1['autoload'] && 'orders' === CRDR_Sync::state( true )['phase'] && $s1['rev'] === CRDR_Sync::state( true )['rev'] );
$s2 = $priv( 'save_state', array( array( 'phase' => 'orders', 'page' => 1 ) ) ); // identical content: only 'updated' / 'rev' differ
t( 'real database: a second write with the same content is still accepted (the rev field makes every write a change)', $s2['rev'] !== $s1['rev'] && CRDR_Sync::state( true )['rev'] === $s2['rev'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", time() . ':the-taker', CRDR_Sync::LOCK_OPTION ) ); // somebody takes the lock over
$before = $state_row();
$lost   = false;
try {
	$priv( 'save_state', array( array( 'phase' => 'done', 'page' => 9 ) ) );
} catch ( CRDR_Sync_Lost $e ) {
	$lost = true;
}
t( 'real database: after the takeover the old owner\'s write is refused by the database (CRDR_Sync_Lost) and the stored state is byte-for-byte unchanged', $lost && $before === $state_row() && 'orders' === CRDR_Sync::state( true )['phase'] );
CRDR_Sync::unlock( $val );
t( 'real database: and its unlock() (shutdown handler) leaves the taker\'s lock in place', false !== strpos( (string) $lock_val(), ':the-taker' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );
$held( true, null );
delete_option( CRDR_Sync::STATE_OPTION );

// ---- renewal: compare-and-swap on the exact value we wrote
$val = $priv( 'lock' );
$tok = $held()['token'];
$old = ( time() - CRDR_Sync::LOCK_RENEW - 30 ) . ':' . $tok;
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $old, CRDR_Sync::LOCK_OPTION ) );
$held( true, array( 'token' => $tok, 'val' => $old, 'at' => time() - CRDR_Sync::LOCK_RENEW - 30 ) );
$priv( 'checkpoint' );
t( 'real database: an old lease is renewed in place (same token, fresh time)', time() - (int) $lock_val() <= 3 && substr( $lock_val(), -16 ) === $tok );
$old = ( time() - CRDR_Sync::LOCK_RENEW - 30 ) . ':' . $tok;
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", time() . ':the-taker', CRDR_Sync::LOCK_OPTION ) );
$held( true, array( 'token' => $tok, 'val' => $old, 'at' => time() - CRDR_Sync::LOCK_RENEW - 30 ) );
$lost = false;
try {
	$priv( 'checkpoint' );
} catch ( CRDR_Sync_Lost $e ) {
	$lost = true;
}
t( 'real database: a renewal after a takeover fails (CRDR_Sync_Lost) and does not overwrite the taker\'s lock', $lost && false !== strpos( (string) $lock_val(), ':the-taker' ) && null === $held() );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );

// ---- six real processes race to take over the same DEAD lock: exactly one wins
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", CRDR_Sync::LOCK_OPTION, ( time() - CRDR_Sync::LOCK_TTL - 120 ) . ':dead-step' ) );
$w   = crdr_t_parallel( array_fill( 0, 6, array( 'sync_lock', '1500' ) ), 3 );
$got = count( array_filter( array_column( $w, 'got' ) ) );
t( 'six processes take over the same dead lock at the same instant: exactly one wins (compare-and-swap in the database)', 1 === $got, wp_json_encode( $w ) );
t( 'and the winner released it afterwards', null === $lock_val() );

// ---- a LIVE lock is never taken over, however many ask
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", CRDR_Sync::LOCK_OPTION, ( time() - 20 ) . ':live-step' ) );
$w = crdr_t_parallel( array_fill( 0, 4, array( 'sync_lock', '200' ) ), 3 );
t( 'four processes ask for a lock that is 20 seconds old: nobody gets it, and it is untouched', 0 === count( array_filter( array_column( $w, 'got' ) ) ) && false !== strpos( (string) $lock_val(), ':live-step' ), wp_json_encode( $w ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );

// ---- a real step that loses its lock in the middle of a page (WooCommerce orders, real tables)
$made = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$o = wc_create_order();
	$o->set_billing_phone( sprintf( '0913%07d', $i ) );
	$o->set_status( 'completed' );
	crdr_t_money( $o, 1000 );
	$o->save();
	$made[] = $o->get_id();
}
CRDR_Sync::start();
$state0 = CRDR_Sync::state( true );
$n      = 0;
$hook   = function ( $v ) use ( &$n, $wpdb, $held ) {
	if ( 20 === ++$n ) { // order 20 of the page "takes" over a minute, and a second process takes the lock meanwhile
		$h = $held();
		$held( true, array( 'token' => $h['token'], 'val' => $h['val'], 'at' => time() - CRDR_Sync::LOCK_RENEW - 1 ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", time() . ':the-taker', CRDR_Sync::LOCK_OPTION ) );
	}
	return $v;
};
add_filter( 'woocommerce_order_get_billing_phone', $hook );
$s = CRDR_Sync::step();
remove_filter( 'woocommerce_order_get_billing_phone', $hook );
$stored = CRDR_Sync::state( true );
$rows   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() );
t( 'real step that lost its lock mid-page: reports busy, imports no further order (the order it was in finishes, none after), writes no state', ! empty( $s['busy'] ) && $rows >= 19 && $rows <= 20 && 'orders' === $stored['phase'] && ! empty( $stored['inflight'] ) && $stored['rev'] !== null, wp_json_encode( array( $s, $rows, $stored ) ) );
t( 'real step that lost its lock: the taker\'s lock is still there and the guard is still on', false !== strpos( (string) $lock_val(), ':the-taker' ) && true === CRDR_Sync::guard()['blocked'] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );
$s = CRDR_Sync::step();
t( 'the next real owner finds the half-applied page and fails the rebuild instead of resuming it', 'failed' === $s['phase'] && 'interrupted_page' === $s['error'] );
foreach ( $made as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
crdr_t_reset( 0 );


/* ============================ J. orders that change while the rebuild runs (real WooCommerce orders, real tables) ============================ */
echo "== reconciliation of orders that change during a rebuild\n";
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
crdr_t_cfg();
$jmade = array();
for ( $i = 1; $i <= 205; $i++ ) {
	$o = wc_create_order();
	$o->set_billing_phone( sprintf( '0914%07d', $i ) );
	$o->set_status( 50 === $i ? 'pending' : 'completed' ); // order 50 is not counted yet
	crdr_t_money( $o, 1000 );
	$o->save();
	$jmade[ $i ] = $o->get_id();
}
$jids = function () use ( $wpdb ) {
	return array_map( 'intval', $wpdb->get_col( 'SELECT order_id FROM ' . CRDR_DB::orders() ) );
};
$jstate = function ( $id, $status ) {
	$o = wc_get_order( $id );
	$o->set_status( $status );
	$o->save();
};
CRDR_Sync::start();
$phases = array();
$s      = CRDR_Sync::step(); // page 1: the first 200 eligible orders (order 50 is not among them)
$phases[] = $s['phase'];
$jstate( $jmade[3], 'cancelled' );   // an order that was already imported leaves the counted statuses ...
$jstate( $jmade[50], 'completed' );  // ... and one that was skipped becomes eligible: the list shifts under the offset
for ( $k = 0; $k < 8; $k++ ) {
	$s        = CRDR_Sync::step();
	$phases[] = $s['phase'] . ( CRDR_Sync::guard()['blocked'] ? '+' : '-' );
	if ( 'done' === $s['phase'] ) {
		break;
	}
}
$eligible = array();
foreach ( $jmade as $i => $id ) {
	if ( 3 !== $i ) {
		$eligible[] = $id;
	}
}
$have    = $jids();
$missing = array_values( array_diff( $eligible, $have ) );
$st      = CRDR_Sync::state( true );
t( 'real rebuild with two orders changing mid-import: EVERY order that is eligible at the end is in the order table (none silently skipped)', array() === $missing && 'done' === $st['phase'], wp_json_encode( array( 'missing' => $missing, 'phases' => $phases, 'state' => $st ) ) );
t( 'real rebuild: the check found and added what the pages had skipped, and nothing is unresolved', ! empty( $st['added'] ) && empty( $st['unresolved'] ) && empty( $st['unverified'] ), wp_json_encode( $st ) );
$sum = (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() );
t( 'real rebuild: no order is counted twice (customers\' order counts add up to the rows of the order table)', $sum === count( $have ) && count( $have ) === count( array_unique( $have ) ), wp_json_encode( array( $sum, count( $have ) ) ) );
t( 'real rebuild: the guard was on after every request but the last (the extra check did not open a gap)', 'done-' === end( $phases ) && 0 === count( array_filter( array_slice( $phases, 1, -1 ), function ( $p ) {
	return '+' !== substr( $p, -1 );
} ) ), wp_json_encode( $phases ) );
t( 'known limit, stated: the order that was cancelled AFTER it was imported is still counted until the next rebuild', in_array( $jmade[3], $have, true ) );
// the next rebuild, with nothing changing, is exact: the stale order is gone, nothing is missing
CRDR_Sync::start();
for ( $k = 0; $k < 8; $k++ ) {
	$s = CRDR_Sync::step();
	if ( 'done' === $s['phase'] ) {
		break;
	}
}
$have2 = $jids();
t( 'the next rebuild corrects it: exactly the 204 eligible orders, the cancelled one is gone', 'done' === $s['phase'] && 204 === count( $have2 ) && ! in_array( $jmade[3], $have2, true ) && 204 === (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() ) );
foreach ( $jmade as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
crdr_t_reset( 0 );


/* ============================ K. the background chain through the real Action Scheduler ============================ */
echo "== action scheduler chain\n";
if ( ! function_exists( 'as_enqueue_async_action' ) || ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
	echo "   (skipped: Action Scheduler is not available here; the chain is then untested)\n";
} else {
	delete_option( CRDR_Sync::STATE_OPTION );
	delete_option( CRDR_Sync::LOCK_OPTION );
	crdr_t_cfg();
	as_unschedule_all_actions( CRDR_Sync::STEP_HOOK );
	$kmade = array();
	for ( $i = 1; $i <= 205; $i++ ) {
		$o = wc_create_order();
		$o->set_billing_phone( sprintf( '0915%07d', $i ) );
		$o->set_status( 'completed' );
		crdr_t_money( $o, 1000 );
		$o->save();
		$kmade[] = $o->get_id();
	}
	$pending = function () {
		return count( as_get_scheduled_actions( array( 'hook' => CRDR_Sync::STEP_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	};
	$run_queue = function () {
		ActionScheduler_QueueRunner::instance()->run( 'crdr-test' );
	};
	CRDR_Sync::start_background(); // what the nightly job does
	t( 'nightly start: the rebuild started and ONE step is queued in Action Scheduler', 'orders' === CRDR_Sync::state( true )['phase'] && 1 === $pending() );
	t( 'while the chain is queued, sending is blocked', true === CRDR_Sync::guard()['blocked'] );
	// The runner takes the queue oldest first, and every order created above queued WooCommerce actions of its own: ours waits
	// behind them. So run the runner until the chain has ended (one call handles one action here).
	$rounds = 0;
	while ( $pending() > 0 && $rounds < 1500 ) {
		$run_queue();
		$rounds++;
	}
	$st = CRDR_Sync::state( true );
	t( 'the real queue runner drives the whole rebuild to "done" (2 pages, scoring, analytics), one step per action', 'done' === $st['phase'] && empty( $st['error'] ) && 205 === (int) $st['done'], wp_json_encode( array( $st, $rounds ) ) );
	t( 'the chain ends by itself: no step is left queued, and sending is allowed again', 0 === $pending() && false === CRDR_Sync::guard()['blocked'] );
	t( 'the data is right: 205 orders, 205 customers, none counted twice', 205 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() ) && 205 === (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() ) );
	t( 'the lock is free after the chain', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) ) );

	// a failing step ends the chain (it does not retry on its own) and the guard stays on
	as_unschedule_all_actions( CRDR_Sync::STEP_HOOK );
	CRDR_Sync::start_background();
	$boom = function ( $v ) {
		if ( doing_action( CRDR_Sync::STEP_HOOK ) ) { // only inside our own queued step, not in WooCommerce's other actions
			throw new RuntimeException( 'simulated failure while reading an order' );
		}
		return $v;
	};
	add_filter( 'woocommerce_order_get_billing_phone', $boom );
	$rounds = 0;
	while ( $pending() > 0 && $rounds < 1500 ) {
		$run_queue();
		$rounds++;
	}
	remove_filter( 'woocommerce_order_get_billing_phone', $boom );
	$st = CRDR_Sync::state( true );
	t( 'a step that throws inside the real queue runner: the rebuild is "failed", NO further step is queued, the guard stays on', 'failed' === $st['phase'] && 0 === $pending() && true === CRDR_Sync::guard()['blocked'], wp_json_encode( $st ) );

	// a second action while one is running: the lock makes it wait, and the chain reschedules instead of ending
	as_unschedule_all_actions( CRDR_Sync::STEP_HOOK );
	CRDR_Sync::start();
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", CRDR_Sync::LOCK_OPTION, ( time() - 5 ) . ':another-step' ) );
	CRDR_Sync::background_step();
	t( 'an action that finds another step running does nothing and schedules itself again (the chain does not die, and does not run twice)', 'orders' === CRDR_Sync::state( true )['phase'] && 1 === (int) CRDR_Sync::state( true )['page'] && false !== wp_next_scheduled( CRDR_Sync::STEP_HOOK ) );
	wp_clear_scheduled_hook( CRDR_Sync::STEP_HOOK );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );

	as_unschedule_all_actions( CRDR_Sync::STEP_HOOK );
	foreach ( $kmade as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	delete_option( CRDR_Sync::STATE_OPTION );
	delete_option( CRDR_Sync::LOCK_OPTION );
	crdr_t_reset( 0 );
}


/* ============================ L. customer identity (real WooCommerce orders, real tables, real contact history) ============================ */
echo "== customer identity\n";
delete_option( CRDR_Sync::LINKS_OPTION );
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}', 'audience' => array( 'segments' => array( 'new', 'champion', 'loyal', 'potential', 'cant_lose', 'at_risk', 'need_attention', 'hibernating', 'lost' ) ) ) );
crdr_t_reset( 0 );
$lmade = array();
$lorder = function ( $phone, $email, $uid, $days_ago, $total, $first = 'علی' ) use ( &$lmade ) {
	$o = wc_create_order();
	$o->set_billing_phone( $phone );
	$o->set_billing_email( $email );
	$o->set_billing_first_name( $first );
	$o->set_customer_id( $uid );
	$o->set_date_created( time() - $days_ago * 86400 );
	$o->set_status( 'completed' );
	crdr_t_money( $o, $total );
	$o->save();
	$lmade[] = $o->get_id();
	return $o->get_id();
};
// G2: three orders, email only in between, a new phone at the end
$g1 = $lorder( '0912-345-6789', 'g2@example.test', 0, 200, 300000 );
$g2 = $lorder( '', 'G2@Example.test', 0, 100, 200000 );
$g3 = $lorder( '09351234567', 'g2@example.test', 0, 10, 250000 );
// two erased orders and one with malformed contact data
$e1 = $lorder( '00000000000', 'deleted@site.invalid', 0, 150, 120000, '' );
$e2 = $lorder( '00000000000', 'deleted@site.invalid', 0, 140, 130000, '' );
$m1 = $lorder( 'abc', '', 0, 30, 100000, '' ); // (WooCommerce refuses to store a malformed email through its API; that case is in tests/identity.php)
// a registered user who changed phone and email
$r1 = $lorder( '09121110001', 'x1@example.test', 77, 120, 100000 );
$r2 = $lorder( '09122220002', 'x2@example.test', 77, 20, 100000 );
// a shared mailbox: one email, three different phones, no account
$s1 = $lorder( '09125550001', 'office@example.test', 0, 90, 100000 );
$s2 = $lorder( '09125550002', 'office@example.test', 0, 80, 100000 );
$s3 = $lorder( '09125550003', 'office@example.test', 0, 70, 100000 );
// an ordinary customer
$n1 = $lorder( '09120000005', 'u5@example.test', 0, 60, 450000 );
// contact history written under the OLD keys (before this rebuild existed): an opt-out under the old phone, a recent SMS under the email-only key
CRDR_DB::add_contact( 'p:09123456789', 'optout', array( 'playbook' => 'manual', 'message' => 'x' ) );
CRDR_DB::add_contact( 'e:g2@example.test', 'sms', array( 'playbook' => 'dormant', 'status' => 'ok' ) );
CRDR_DB::add_contact( 'p:09121110001', 'called', array( 'playbook' => 'manual' ) );
$lrebuild = function () {
	CRDR_Sync::start();
	for ( $k = 0; $k < 12; $k++ ) {
		$s = CRDR_Sync::step();
		if ( 'done' === $s['phase'] || 'failed' === $s['phase'] ) {
			return $s;
		}
	}
	return $s;
};
$lrow = function ( $ckey ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $ckey ), ARRAY_A );
};
$lkeys = function () use ( $wpdb ) {
	$k = $wpdb->get_col( 'SELECT ckey FROM ' . CRDR_DB::customers() );
	sort( $k );
	return $k;
};
$s = $lrebuild();
$st = CRDR_Sync::state( true );
t( 'identity: the rebuild finishes ("done"), nothing unresolved', 'done' === $s['phase'] && empty( $st['error'] ) && 0 === (int) $st['inflight'], wp_json_encode( $st ) );
t( 'identity: 12 orders in, 9 customers out (G2 joined, the registered user joined, the shared mailbox NOT joined, 3 unidentifiable orders apart, 1 ordinary)', 9 === count( $lkeys() ) && 12 === (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() ) && 12 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() ), wp_json_encode( $lkeys() ) );
t( 'identity: the report is in the state (joined 3 customers into 2 people)', 3 === (int) $st['identity']['joined'] && 2 === (int) $st['identity']['people'] && 1 === (int) $st['identity']['emails_shared'] && 3 === (int) $st['identity']['unidentified'], wp_json_encode( $st['identity'] ) );
$g = $lrow( 'p:09351234567' );
t( 'identity: G2 is ONE customer keyed by the latest phone, with all three orders, the summed spend and the number to send to', $g && 3 === (int) $g['orders_count'] && 750000.0 === (float) $g['total_spent'] && '09351234567' === $g['phone'] && 'g2@example.test' === $g['email'], wp_json_encode( $g ) );
t( 'identity: the old G2 keys are gone from the customer table and from the orders table', null === $lrow( 'p:09123456789' ) && null === $lrow( 'e:g2@example.test' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() . ' WHERE ckey IN (%s,%s)', 'p:09123456789', 'e:g2@example.test' ) ) && 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() . ' WHERE ckey = %s', 'p:09351234567' ) ) );
$u = $lrow( 'p:09122220002' );
t( 'identity: the registered user with two phones and two emails is one customer (latest phone, latest email, user id kept)', $u && 2 === (int) $u['orders_count'] && 77 === (int) $u['user_id'] && 'x2@example.test' === $u['email'] && null === $lrow( 'p:09121110001' ) );
t( 'identity: the shared mailbox stays three customers, each with its own phone', 1 === (int) $lrow( 'p:09125550001' )['orders_count'] && 1 === (int) $lrow( 'p:09125550002' )['orders_count'] && 1 === (int) $lrow( 'p:09125550003' )['orders_count'] );
$un = $wpdb->get_results( "SELECT * FROM " . CRDR_DB::customers() . " WHERE ckey LIKE 'o:%' ORDER BY ckey", ARRAY_A );
t( 'identity: the two erased orders and the malformed one are three separate customers with no phone and no email', 3 === count( $un ) && array( '', '', '' ) === array_column( $un, 'phone' ) && array( '', '', '' ) === array_column( $un, 'email' ) && array( 1, 1, 1 ) === array_map( 'intval', array_column( $un, 'orders_count' ) ), wp_json_encode( $un ) );
t( 'identity: the unidentifiable customers still count in the order totals (revenue is the sum of every order)', 1.0 * ( 300000 + 200000 + 250000 + 120000 + 130000 + 100000 + 200000 + 300000 + 450000 ) === (float) $wpdb->get_var( 'SELECT SUM(total_spent) FROM ' . CRDR_DB::customers() ) );
// ---- contact history follows the person
// CHANGED in Phase 3A.1 (was: 'the opt-out ... now belong to the joined customer', expecting NO row left under the old keys): opt-out rows are now COPIED to
// every key of the person and stay under their old keys too, so that the person stays opted out whatever happens to the joined key later.
t( 'identity: the SMS recorded under the old email-only key moved to the joined customer, and the opt-out recorded under the old phone is now on the joined customer too (and still on the old key)', 2 === crdr_t_count( $wpdb->prepare( 'ckey = %s', 'p:09351234567' ) ) && 1 === crdr_t_count( $wpdb->prepare( 'ckey = %s AND kind = %s', 'p:09351234567', 'optout' ) ) && 0 === crdr_t_count( $wpdb->prepare( "ckey IN (%s,%s) AND kind <> 'optout'", 'p:09123456789', 'e:g2@example.test' ) ) && 1 === crdr_t_count( $wpdb->prepare( 'ckey = %s AND kind = %s', 'p:09123456789', 'optout' ) ) );
t( 'identity: the joined customer is still opted out (blocked_reason), so no message can reach the new number', 'optout' === CRDR_DB::blocked_reason( 'p:09351234567' ) );
t( 'identity: a call note under the old phone of the registered user follows him to his new key (cooldown holds)', 1 === crdr_t_count( $wpdb->prepare( 'ckey = %s AND kind = %s', 'p:09122220002', 'called' ) ) && 'cooldown' === CRDR_DB::blocked_reason( 'p:09122220002' ) );
$claim = CRDR_Send::claim( 'p:09351234567', 'dormant', 'L1' );
t( 'identity: claiming the joined customer for a campaign is refused (opted out)', array( 'skip' => 'optout' ) === $claim );
// ---- campaigns: nobody opted out, in cooldown or without a phone is messaged
$before_req = count( crdr_t_requests() );
$res        = CRDR_Playbooks::run( 'dormant', 50, array( 'trigger' => 'manual' ) );
$sent_to    = array();
foreach ( crdr_t_requests() as $rq ) {
	$sent_to[] = wp_json_encode( $rq );
}
$all_sent = implode( ' ', $sent_to );
t( 'identity: a campaign over every segment messages only the customers that may be messaged: the shared-mailbox phones and the ordinary customer (4), not the opted-out G2, not the registered user in cooldown, nobody without a phone', 4 === count( crdr_t_requests() ) - $before_req && false === strpos( $all_sent, '09351234567' ) && false === strpos( $all_sent, '09123456789' ) && false === strpos( $all_sent, '09122220002' ) && false !== strpos( $all_sent, '09125550001' ) && false !== strpos( $all_sent, '09120000005' ), wp_json_encode( array( $res, $sent_to ) ) );
t( 'identity: no message at all was attempted for the three unidentifiable customers (no phone: they are not in a campaign audience)', 0 === crdr_t_count( "ckey LIKE 'o:%'" ) );
// ---- the stale-id protection with the new keys
$gid = (int) $lrow( 'p:09351234567' )['id'];
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'k' => CRDR_Actions::identity( 'p:09123456789' ), 'type' => 'called' ) );
$cnt = crdr_t_count( $wpdb->prepare( 'ckey = %s', 'p:09351234567' ) );
t( 'identity: a page drawn BEFORE the join (token of the old phone key) cannot act on the joined customer: refused as stale, nothing written', false === $j['success'] && 'stale_customer' === ( $j['data']['code'] ?? '' ) && 2 === $cnt, wp_json_encode( $j ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'called' ) );
t( 'identity: a page drawn AFTER the join (current token) works on the joined customer', true === $j['success'] && 3 === crdr_t_count( $wpdb->prepare( 'ckey = %s', 'p:09351234567' ) ) );
// ---- a second rebuild changes nothing
$keys1 = $lkeys();
$cont1 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() );
$sums1 = $wpdb->get_results( 'SELECT ckey, orders_count, total_spent FROM ' . CRDR_DB::customers() . ' ORDER BY ckey', ARRAY_A );
$s     = $lrebuild();
t( 'identity: rebuilding again gives the same customers, the same totals and the same contact history (repeatable, nothing re-keyed twice)', 'done' === $s['phase'] && $keys1 === $lkeys() && $sums1 === $wpdb->get_results( 'SELECT ckey, orders_count, total_spent FROM ' . CRDR_DB::customers() . ' ORDER BY ckey', ARRAY_A ) && $cont1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() ) );
// ---- the person changes phone again: the key follows, the history follows
$lorder( '09350009999', 'g2@example.test', 0, 1, 100000 );
CRDR_DB::add_contact( 'p:09351234567', 'skip', array( 'playbook' => 'manual' ) );
$s = $lrebuild();
t( 'identity: the joins are remembered in an option (member => canonical key)', array( 'e:g2@example.test' => 'p:09351234567', 'p:09121110001' => 'p:09122220002', 'p:09123456789' => 'p:09351234567' ) === get_option( CRDR_Sync::LINKS_OPTION ), wp_json_encode( get_option( CRDR_Sync::LINKS_OPTION ) ) );
t( 'identity: a later order with a THIRD phone for the same email (it is a "shared mailbox" for new joins now) does NOT take G2 apart: the joined customer keeps its key, its 3 orders and its opt-out; the new phone is a separate customer', 'done' === $s['phase'] && null !== $lrow( 'p:09351234567' ) && null !== $lrow( 'p:09350009999' ) && null === $lrow( 'p:09123456789' ) && 3 === (int) $lrow( 'p:09351234567' )['orders_count'] && 'optout' === CRDR_DB::blocked_reason( 'p:09351234567' ) && 0 === crdr_t_count( $wpdb->prepare( 'ckey = %s', 'p:09350009999' ) ), wp_json_encode( $lkeys() ) );
t( 'identity: ... and the old phone of the opted-out person did NOT come back as a customer without history', null === $lrow( 'p:09123456789' ) && null === $lrow( 'e:g2@example.test' ) );
// ---- failure in the middle of the identity step
$orig_in_flight = array( 'phase' => 'finalize', 'page' => 2, 'total' => 12, 'done' => 12, 'inflight' => 'resolve', 'updated' => time() );
update_option( CRDR_Sync::STATE_OPTION, $orig_in_flight, false );
$s = CRDR_Sync::step();
t( 'identity: a step found dead in the middle of the identity step (in-flight marker) fails the rebuild instead of continuing; sending stays blocked', 'failed' === $s['phase'] && 'interrupted_page' === $s['error'] && true === CRDR_Sync::guard()['blocked'] );
$seen_state = null;
$boom       = function ( $q ) use ( &$seen_state, $wpdb ) {
	if ( false !== strpos( $q, 'UPDATE ' . CRDR_DB::contacts() . ' SET ckey' ) ) {
		// what the stored rebuild state says at the very moment contact history is being re-keyed
		$seen_state = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::STATE_OPTION ) ) );
		throw new RuntimeException( 'simulated database error while re-keying contacts' );
	}
	return $q;
};
// make the identity step have something to do again, then break it half way
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
CRDR_DB::add_contact( 'p:09351234567', 'optout', array( 'playbook' => 'manual' ) );
$lorder( '', 'office2@example.test', 0, 5, 100000 );
$lorder( '09126660001', 'office2@example.test', 0, 4, 100000 );
add_filter( 'query', $boom );
$s = $lrebuild();
remove_filter( 'query', $boom );
$errs_ok = 'failed' === $s['phase'];
t( 'identity: while contact history is being re-keyed the stored state says "in flight: resolve" and is unsafe (so a process that dies right there fails the rebuild)', is_array( $seen_state ) && 'resolve' === $seen_state['inflight'] && 'finalize' === $seen_state['phase'] && true === CRDR_Sync::guard()['blocked'], wp_json_encode( $seen_state ) );
t( 'identity: an error in the middle of the identity step fails the rebuild (never "done" with half-joined data), sending stays blocked', $errs_ok && true === CRDR_Sync::guard()['blocked'], wp_json_encode( CRDR_Sync::state( true ) ) );
$s = $lrebuild();
t( 'identity: a new rebuild after that failure completes and gives the correct, joined result', 'done' === $s['phase'] && false === CRDR_Sync::guard()['blocked'] && null !== $lrow( 'p:09126660001' ) && null === $lrow( 'e:office2@example.test' ) );
foreach ( $lmade as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
delete_option( CRDR_Sync::STATE_OPTION );
delete_option( CRDR_Sync::LOCK_OPTION );
delete_option( CRDR_Sync::LINKS_OPTION );
crdr_t_reset( 0 );


/* ============================ M. identity integrity: opt-outs, failures at every boundary, remembered joins, repair ============================ */
echo "== identity integrity\n";
$mmade = array();
$mo = function ( $phone, $email, $uid, $days, $status = 'completed' ) use ( &$mmade ) {
	$o = wc_create_order();
	$o->set_billing_phone( $phone );
	$o->set_billing_email( $email );
	$o->set_billing_first_name( 'علی' );
	$o->set_customer_id( $uid );
	$o->set_date_created( time() - $days * 86400 );
	$o->set_status( $status );
	crdr_t_money( $o, 1000 );
	$o->save();
	$mmade[ $o->get_id() ] = true;
	return $o->get_id();
};
$mset = function ( $id, $status ) {
	$o = wc_get_order( $id );
	$o->set_status( $status );
	$o->save();
};
$mreset = function () use ( $wpdb ) {
	$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
	foreach ( array( CRDR_Sync::LINKS_OPTION, CRDR_Sync::APART_OPTION, CRDR_Sync::STATE_OPTION, CRDR_Sync::LOCK_OPTION ) as $opt ) {
		delete_option( $opt );
	}
	crdr_t_cfg( array( 'mode' => 'manual', 'template' => 'سلام {name} {optout}', 'audience' => array( 'segments' => array( 'new', 'champion', 'loyal', 'potential', 'cant_lose', 'at_risk', 'need_attention', 'hibernating', 'lost' ) ) ) );
};
$mrebuild = function () {
	CRDR_Sync::start();
	for ( $k = 0; $k < 12; $k++ ) {
		$s = CRDR_Sync::step();
		if ( in_array( $s['phase'], array( 'done', 'failed' ), true ) ) {
			return $s['phase'];
		}
	}
	return '?';
};
$mkeys = function () use ( $wpdb ) {
	$k = $wpdb->get_col( 'SELECT ckey FROM ' . CRDR_DB::customers() );
	sort( $k );
	return $k;
};
$mblocked = function ( $k ) {
	return CRDR_DB::blocked_reason( $k );
};
$optout = function ( $k ) {
	CRDR_DB::add_contact( $k, 'optout', array( 'playbook' => 'manual' ) );
};
$count_kind = function ( $ckey, $kind ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() . ' WHERE ckey = %s AND kind = %s', $ckey, $kind ) );
};
$P1 = 'p:09121110001';
$P2 = 'p:09122220002';

// ---- 1. the opt-out is never lost when the key that held it stops being a customer
$mreset();
$a = $mo( '09121110001', 'x@example.test', 0, 100 );
$b = $mo( '09122220002', 'x@example.test', 0, 10 );
t( 'integrity: the two phones of one email are joined (key = the latest phone)', 'done' === $mrebuild() && array( $P2 ) === $mkeys() );
$optout( $P2 ); // the joined customer is opted out (recorded for the canonical key only, as an older version or a direct write would)
$mset( $b, 'cancelled' ); // the order that gave the joined customer its key leaves the counted statuses
t( 'integrity: the joined customer opted out; its newest order is cancelled; the old phone is a customer again and is STILL opted out (was: not blocked)', 'done' === $mrebuild() && array( $P1 ) === $mkeys() && 'optout' === $mblocked( $P1 ), wp_json_encode( array( $mkeys(), $mblocked( $P1 ) ) ) );
$mreset();
$mset( $b, 'completed' );
$optout( $P1 ); // opted out BEFORE the join, under the old phone
t( 'integrity: opted out under the old phone before the join: joined customer is opted out, AND the old key keeps its own row (rows are copied, never only moved)', 'done' === $mrebuild() && 'optout' === $mblocked( $P2 ) && 1 === $count_kind( $P1, 'optout' ) && 1 === $count_kind( $P2, 'optout' ), wp_json_encode( array( $count_kind( $P1, 'optout' ), $count_kind( $P2, 'optout' ) ) ) );
$mset( $b, 'cancelled' );
t( 'integrity: ... then the joined customer\'s newest order is cancelled: the old phone is still opted out', 'done' === $mrebuild() && array( $P1 ) === $mkeys() && 'optout' === $mblocked( $P1 ) );
$mset( $b, 'completed' );

// ---- 2. an opt-out clicked on the joined customer is written for every key of the person (the real handler)
$mreset();
t( 'integrity: (setup) joined again', 'done' === $mrebuild() && array( $P2 ) === $mkeys() );
$gid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $P2 ) );
$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'optout' ) );
t( 'integrity: "do not message" on the joined customer writes an opt-out for the joined key AND for the key it was joined from', true === $j['success'] && 1 === $count_kind( $P2, 'optout' ) && 1 === $count_kind( $P1, 'optout' ), wp_json_encode( array( $j, $count_kind( $P1, 'optout' ) ) ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'optout' ) );
$mset( $b, 'cancelled' );
t( 'integrity: ... so it also holds after the newest order is cancelled (no rebuild needed to propagate it)', 'done' === $mrebuild() && 'optout' === $mblocked( $P1 ) );
$mset( $b, 'completed' );

// ---- 3. what deleting the whole list of remembered joins really does (tested, not assumed)
$mreset();
$c = $mo( '09123330003', 'x@example.test', 0, 2, 'cancelled' ); // a third phone for the same email, not counted yet
t( 'integrity: (setup) two phones joined', 'done' === $mrebuild() && array( $P2 ) === $mkeys() );
CRDR_DB::add_contact( $P2, 'sms', array( 'playbook' => 'dormant', 'status' => 'ok' ) ); // a recent SMS (cooldown) under the joined key
$gid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $P2 ) );
crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'optout' ) );
$mset( $c, 'completed' ); // the third phone arrives: the email is a shared mailbox for NEW joins now
t( 'integrity: the third phone does not take the person apart (sticky joins)', 'done' === $mrebuild() && array( $P2, 'p:09123330003' ) === $mkeys() );
delete_option( CRDR_Sync::LINKS_OPTION );
$ph = $mrebuild();
t( 'integrity: deleting the whole links option: the person falls apart into three customers (the rules no longer join a shared mailbox)', 'done' === $ph && array( $P1, $P2, 'p:09123330003' ) === $mkeys(), wp_json_encode( $mkeys() ) );
t( 'integrity: ... but BOTH old keys are still opted out (the opt-out was written for every key); only the brand-new third phone, which was never connected, is not', 'optout' === $mblocked( $P1 ) && 'optout' === $mblocked( $P2 ) && '' === $mblocked( 'p:09123330003' ) );
t( 'integrity: ... and the cooldown from the recent SMS stays with the joined key only (documented: the history is not split back)', 'cooldown' !== $mblocked( $P1 ) && 1 === $count_kind( $P2, 'sms' ) && 0 === $count_kind( $P1, 'sms' ) );
$mset( $c, 'cancelled' );

// ---- 4. damaged list of remembered joins
$mreset();
update_option( CRDR_Sync::LINKS_OPTION, array( $P1 => array( 'x' ), 'junk' => 5, 'p:09120000009' => 'p:09120000008', 7 => $P2 ), false );
$ph = $mrebuild();
$st = CRDR_Sync::state( true );
t( 'integrity: a damaged links option (array value, junk, numbers) does not fail the rebuild (was: TypeError, every rebuild failed)', 'done' === $ph && array( $P2 ) === $mkeys(), wp_json_encode( $st ) );
t( 'integrity: ... the damage is counted in the report and the option is rewritten clean', (int) $st['identity']['links_dropped'] >= 3 && array( $P1 => $P2 ) === get_option( CRDR_Sync::LINKS_OPTION ), wp_json_encode( array( $st['identity'], get_option( CRDR_Sync::LINKS_OPTION ) ) ) );
update_option( CRDR_Sync::LINKS_OPTION, 'not an array at all', false );
t( 'integrity: an option that is a plain string is treated as "no joins" and repaired', 'done' === $mrebuild() && array( $P1 => $P2 ) === get_option( CRDR_Sync::LINKS_OPTION ) );

// ---- 5. supported repair of a wrong join: unjoin()
$mreset();
$optout( $P1 ); // person X (old phone) opted out earlier
$mrebuild();
t( 'integrity: (setup) two people sharing an email are joined; the joined customer is opted out (conservative)', array( $P2 ) === $mkeys() && 'optout' === $mblocked( $P2 ) );
t( 'repair: invalid keys are refused', array( 'ok' => false, 'reason' => 'no_valid_key' ) === CRDR_Sync::unjoin( array( 'junk', '' ) ) );
update_option( CRDR_Sync::STATE_OPTION, array( 'phase' => 'orders', 'updated' => time() ), false );
t( 'repair: refused while a rebuild is running', 'rebuild_running' === CRDR_Sync::unjoin( array( $P1 ) )['reason'] );
delete_option( CRDR_Sync::STATE_OPTION );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", CRDR_Sync::LOCK_OPTION, ( time() - 5 ) . ':another-step' ) );
t( 'repair: refused while another step holds the sync lock', 'busy' === CRDR_Sync::unjoin( array( $P1 ) )['reason'] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );
$r = CRDR_Sync::unjoin( array( $P1 ) );
t( 'repair: unjoin() succeeds; the link is removed and the key is kept apart', true === $r['ok'] && array() === get_option( CRDR_Sync::LINKS_OPTION ) && array( $P1 ) === get_option( CRDR_Sync::APART_OPTION ), wp_json_encode( array( $r, get_option( CRDR_Sync::LINKS_OPTION ), get_option( CRDR_Sync::APART_OPTION ) ) ) );
t( 'repair: nothing changes in the customer table until the next rebuild (and no lock is left behind)', array( $P2 ) === $mkeys() && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) ) );
t( 'repair: the next rebuild gives two customers, each with the opt-out (the second person inherits it: conservative, see docs)', 'done' === $mrebuild() && array( $P1, $P2 ) === $mkeys() && 'optout' === $mblocked( $P1 ) && 'optout' === $mblocked( $P2 ) );
t( 'repair: the rules do NOT join them again on later rebuilds', 'done' === $mrebuild() && array( $P1, $P2 ) === $mkeys() && array() === get_option( CRDR_Sync::LINKS_OPTION ) );
t( 'repair: the copied opt-out is recognisable (so an owner who is sure can remove it): its message is the copy note', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() . " WHERE kind = 'optout' AND ckey = %s AND message = %s", $P2, CRDR_DB::OPTOUT_COPY_NOTE ) ) );
$r = CRDR_Sync::rejoin( array( $P1 ) );
t( 'repair: rejoin() lifts the separation; the next rebuild joins them again by the rule', true === $r['ok'] && array() === get_option( CRDR_Sync::APART_OPTION ) && 'done' === $mrebuild() && array( $P2 ) === $mkeys() );

// ---- 6. the remembered joins are written only while we own the lock
$mreset();
$seen = array();
$hook = function ( $q ) use ( $wpdb, &$seen ) {
	if ( preg_match( '/^(INSERT|UPDATE)/', $q ) && false !== strpos( $q, 'crdr_identity_links' ) && empty( $seen['took'] ) ) {
		$seen['took'] = true;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", time() . ':the-taker', CRDR_Sync::LOCK_OPTION ) );
	}
	return $q;
};
add_filter( 'query', $hook );
CRDR_Sync::start();
$s = array( 'phase' => '' );
for ( $k = 0; $k < 4; $k++ ) {
	$s = CRDR_Sync::step();
}
remove_filter( 'query', $hook );
t( 'integrity: a process whose lock was taken over right before it writes the remembered joins writes nothing (was: it wrote them)', ! empty( $seen['took'] ) && false === get_option( CRDR_Sync::LINKS_OPTION ) && false !== strpos( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) ), ':the-taker' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );

// ---- 7. a rebuild that was half way when this version was installed (orders imported without the evidence columns)
$mreset();
update_option( CRDR_Sync::LINKS_OPTION, array( $P1 => $P2 ), false );
$optout( $P2 );
CRDR_Sync::start();
for ( $k = 0; $k < 6; $k++ ) {
	$s = CRDR_Sync::step();
	if ( 'finalize' === $s['phase'] ) {
		break;
	}
}
$wpdb->query( 'UPDATE ' . CRDR_DB::orders() . " SET uid = 0, email = ''" ); // what an older version wrote
for ( $k = 0; $k < 4; $k++ ) {
	$s = CRDR_Sync::step();
}
t( 'integrity: orders imported by the previous version (no evidence) are not an error; the remembered join still holds and the opt-out still reaches both keys', 'done' === $s['phase'] && array( $P2 ) === $mkeys() && 'optout' === $mblocked( $P1 ) && 'optout' === $mblocked( $P2 ) && array( $P1 => $P2 ) === get_option( CRDR_Sync::LINKS_OPTION ) );

// ---- 8. a page drawn before a repair cannot act on the wrong customer afterwards
$mreset();
$mrebuild();
$gid_old = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $P2 ) );
$tok_old = CRDR_Actions::identity( $P2 );
CRDR_Sync::unjoin( array( $P2 ) ); // the owner separates the joined customer (the key everyone was joined to)
$mrebuild();
$id_p1 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $P1 ) );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $id_p1, 'k' => $tok_old, 'type' => 'called' ) );
t( 'integrity: after a repair, the old page\'s token of the joined key cannot be used on the id of the OTHER customer: refused as stale, nothing written', false === $j['success'] && 'stale_customer' === ( $j['data']['code'] ?? '' ) && $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() ) );
foreach ( array( $P1, $P2 ) as $k ) {
	$cid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $k ) );
	$j   = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cid, 'k' => $tok_old, 'type' => 'called' ) );
	t( "integrity: the old token works only for the customer whose key it names ($k): " . ( $k === $P2 ? 'allowed' : 'refused' ), $k === $P2 ? true === $j['success'] : false === $j['success'] );
}

// ---- 9. a failure or a death at EVERY write of the finalize step, in-process and as a real dying process
$mreset();
foreach ( $mmade as $oid => $_ ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
$mmade = array();
$p1 = $mo( '09121110001', 'x@example.test', 0, 100 );
$p2 = $mo( '09122220002', 'x@example.test', 0, 10 );
$r1 = $mo( '09123330001', 'u1@example.test', 77, 90 );
$r2 = $mo( '09123330002', 'u2@example.test', 77, 20 );
$un = $mo( '09125550001', 'solo@example.test', 0, 40 );
$seed_contacts = function () use ( $optout, $wpdb ) {
	$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
	$optout( 'p:09121110001' ); // the person with two phones opted out under the OLD phone
	CRDR_DB::add_contact( 'p:09123330001', 'called', array( 'playbook' => 'manual' ) ); // the registered user: a call note under his old phone
	CRDR_DB::add_contact( 'p:09125550001', 'optout', array( 'playbook' => 'manual' ) ); // an unrelated customer, opted out
};
$snap = function () use ( $wpdb ) {
	return array(
		'customers' => $wpdb->get_results( 'SELECT ckey, orders_count, total_spent, phone, email, user_id FROM ' . CRDR_DB::customers() . ' ORDER BY ckey', ARRAY_A ),
		'orders'    => $wpdb->get_results( 'SELECT order_id, ckey FROM ' . CRDR_DB::orders() . ' ORDER BY order_id', ARRAY_A ),
		'contacts'  => $wpdb->get_results( 'SELECT ckey, kind, status FROM ' . CRDR_DB::contacts() . ' ORDER BY ckey, kind, status, id', ARRAY_A ),
		'links'     => get_option( CRDR_Sync::LINKS_OPTION ),
	);
};
$to_finalize = function () {
	CRDR_Sync::start();
	for ( $k = 0; $k < 8; $k++ ) {
		$s = CRDR_Sync::state( true );
		if ( 'finalize' === $s['phase'] ) {
			return true;
		}
		CRDR_Sync::step();
	}
	return false;
};
// ---- a database error is never skipped: not by the rebuild, and never "saved" for an opt-out that was not written
$mreset();
$dberr = function ( $match ) {
	return function ( $q ) use ( $match ) {
		static $done = false;
		if ( ! $done && preg_match( $match, $q ) ) {
			$done = true;
			return 'SELECT * FROM crdr_simulated_database_error';
		}
		return $q;
	};
};
CRDR_Sync::start();
$f = $dberr( '/^INSERT INTO ' . preg_quote( CRDR_DB::customers(), '/' ) . '/' );
add_filter( 'query', $f );
$wpdb->suppress_errors( true );
$s = CRDR_Sync::step(); // the first page: one customer row cannot be written
$wpdb->suppress_errors( false );
remove_filter( 'query', $f );
t( 'errors: a database error while importing an order fails the rebuild (was: the order was silently skipped and the rebuild went on to "done")', 'failed' === $s['phase'] && true === CRDR_Sync::guard()['blocked'], wp_json_encode( $s ) );
$mreset();
$mrebuild();
$gid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', 'p:09125550001' ) );
$f = $dberr( '/^INSERT INTO `?' . preg_quote( CRDR_DB::contacts(), '/' ) . '/' ); // ($wpdb->insert() quotes the table name)
add_filter( 'query', $f );
$wpdb->suppress_errors( true );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'optout' ) );
$wpdb->suppress_errors( false );
remove_filter( 'query', $f );
t( 'errors: "do not message" whose first row could not be written answers with an ERROR (was: "saved"); the key still ends up opted out (the person-wide copy loop writes it: the conservative direction)', false === $j['success'] && $count_kind( 'p:09125550001', 'optout' ) >= 1, wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'optout' ) );
t( 'errors: asking again answers "saved" (a repeated opt-out only adds a row)', true === $j['success'] && $count_kind( 'p:09125550001', 'optout' ) >= 1 );
// the same, when EVERY write fails: nothing is recorded and the answer is an error
$f = function ( $q ) {
	return preg_match( '/^INSERT INTO `?' . preg_quote( CRDR_DB::contacts(), '/' ) . '/', $q ) ? 'SELECT * FROM crdr_simulated_database_error' : $q;
};
$wpdb->query( 'TRUNCATE TABLE ' . CRDR_DB::contacts() );
add_filter( 'query', $f );
$wpdb->suppress_errors( true );
$j = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'optout' ) );
$j2 = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $gid, 'type' => 'called' ) );
$wpdb->suppress_errors( false );
remove_filter( 'query', $f );
t( 'errors: when the database refuses every write, "do not message" and "called" both answer with an ERROR and record nothing', false === $j['success'] && false === $j2['success'] && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::contacts() ) );

$opted_keys = array( 'p:09121110001', 'p:09125550001' ); // the keys that are opted out BEFORE the rebuild: they must stay blocked at every moment
$joined_key = 'p:09122220002'; // the new phone of the opted-out person: blocked as soon as it carries the merged customer (or anything of it)
$seed_contacts();
$mreset_keep = function () use ( $mreset, $seed_contacts ) {
	$mreset();
	$seed_contacts();
};
$mreset_keep();
t( 'boundaries: (setup) the scenario reaches the finalize phase', $to_finalize() );
$w = crdr_t_parallel( array( array( 'identity_die', '0' ) ), 2 ); // counts the writes of the finalize step without dying
$N = isset( $w[0]['writes'] ) ? (int) $w[0]['writes'] : 0;
t( 'boundaries: the finalize step of the scenario makes a known number of writes (' . $N . '); each one is tried below', $N >= 8 && 'stats' === ( $w[0]['phase'] ?? '' ), wp_json_encode( $w ) );
$mreset_keep();
$mrebuild();
$ref = $snap();
t( 'boundaries: the clean run has the expected result (3 people joined into 2, 5 orders in 3 customers, the opt-out reaches both phones)', 3 === count( $ref['customers'] ) && 'optout' === $mblocked( 'p:09121110001' ) && 'optout' === $mblocked( 'p:09122220002' ), wp_json_encode( $ref['customers'] ) );
$bad = array();
$release_lock_after_ttl = function () use ( $wpdb ) { // a failed unlock leaves the lock row: it frees itself after LOCK_TTL; simulate that time passing
	$cur = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CRDR_Sync::LOCK_OPTION ) );
	if ( $cur ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", ( time() - CRDR_Sync::LOCK_TTL - 5 ) . ':' . substr( $cur, strpos( $cur, ':' ) + 1 ), CRDR_Sync::LOCK_OPTION ) );
	}
};
for ( $k = 1; $k <= $N; $k++ ) {
	foreach ( array( 'dberror', 'death' ) as $how ) {
		$mreset_keep();
		$to_finalize();
		if ( 'dberror' === $how ) {
			$n    = 0;
			$seen = false;
			$f    = function ( $q ) use ( $k, &$n, &$seen ) { // the k-th write of the step is replaced by an invalid statement: wpdb->query() returns false, as on a deadlock or a full disk
				if ( false !== strpos( $q, 'SELECT ckey, uid, email FROM' ) ) {
					$seen = true;
				}
				if ( $seen && preg_match( '/^\s*(INSERT|UPDATE|DELETE)/i', $q ) && ++$n === $k ) {
					return 'SELECT * FROM crdr_simulated_database_error';
				}
				return $q;
			};
			add_filter( 'query', $f );
			$wpdb->suppress_errors( true );
			$s = CRDR_Sync::step();
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $f );
		} else {
			crdr_t_parallel( array( array( 'identity_die', (string) $k ) ), 2 );
		}
		$problems = array();
		$st       = CRDR_Sync::state( true );
		if ( ! CRDR_Sync::guard()['blocked'] ) {
			$problems[] = 'sending was not blocked (phase ' . $st['phase'] . ')';
		}
		if ( ! in_array( $st['phase'], array( 'failed', 'finalize', 'stats' ), true ) ) {
			$problems[] = 'unexpected phase ' . $st['phase'];
		}
		foreach ( $opted_keys as $key ) { // the invariant: nobody opted out can be messaged, at any point, under any of their keys
			if ( 'optout' !== $mblocked( $key ) ) {
				$problems[] = "$key not opted out";
			}
		}
		$merged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT orders_count FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $joined_key ) );
		if ( $merged >= 2 && 'optout' !== $mblocked( $joined_key ) ) { // a customer that carries the opted-out person's orders is opted out
			$problems[] = 'the merged customer is not opted out';
		}
		$release_lock_after_ttl();
		// the next step: either it fails the rebuild (found half applied), or finishes the idempotent work
		$s2 = CRDR_Sync::step();
		// (at the very last write, the release of the lock, all the work is already done: the next step then legitimately reaches "done")
		if ( ! in_array( $s2['phase'], array( 'failed', 'stats', 'done' ), true ) || ( in_array( $s2['phase'], array( 'stats', 'done' ), true ) && 'failed' === $st['phase'] ) ) {
			$problems[] = 'next step gave ' . $s2['phase'];
		}
		foreach ( $opted_keys as $key ) {
			if ( 'optout' !== $mblocked( $key ) ) {
				$problems[] = "$key not opted out after the next step";
			}
		}
		$merged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT orders_count FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $joined_key ) );
		if ( $merged >= 2 && 'optout' !== $mblocked( $joined_key ) ) {
			$problems[] = 'the merged customer is not opted out after the next step';
		}
		// recovery: a new rebuild ends "done" and equals the clean run
		$release_lock_after_ttl();
		if ( 'done' !== $mrebuild() ) {
			$problems[] = 'recovery rebuild did not finish';
		} elseif ( $snap() !== $ref ) {
			$problems[] = 'recovery result differs from the clean run';
		}
		if ( $problems ) {
			$bad[ "$how@$k" ] = $problems;
		}
	}
}
t( 'boundaries: a database error (the write returns false) and a death (separate process, exit) at EACH of the ' . $N . ' writes of the finalize step: sending stays blocked, every opted-out key stays opted out at every moment, the next step fails the rebuild or finishes it, and a new rebuild always ends exactly like the clean run', array() === $bad, wp_json_encode( $bad ) );

// ---- clean up
foreach ( $mmade as $oid => $_ ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
foreach ( array( CRDR_Sync::LINKS_OPTION, CRDR_Sync::APART_OPTION, CRDR_Sync::STATE_OPTION, CRDR_Sync::LOCK_OPTION ) as $opt ) {
	delete_option( $opt );
}
crdr_t_reset( 0 );


/* ============================ N. the database step gives EXACTLY the answer of the resolver on all the data (random stores) ============================ */
echo "== identity step equals the full resolver on random data\n";
foreach ( array( CRDR_Sync::LINKS_OPTION, CRDR_Sync::APART_OPTION, CRDR_Sync::STATE_OPTION, CRDR_Sync::LOCK_OPTION ) as $opt ) {
	delete_option( $opt );
}
$lk = new ReflectionMethod( 'CRDR_Sync', 'lock' );
$lk->setAccessible( true );
$rs = new ReflectionMethod( 'CRDR_Sync', 'resolve_identities' );
$rs->setAccessible( true );
$all_ok     = true;
$stable     = true;
$mismatches = array();
$joined_sum = 0;
$refused_sum = 0;
$shared_sum = 0;
foreach ( array( 11, 22, 33, 44, 55, 66 ) as $seed ) {
	mt_srand( $seed );
	foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::contacts() ) as $tb ) {
		$wpdb->query( 'TRUNCATE TABLE ' . $tb );
	}
	delete_option( CRDR_Sync::LINKS_OPTION );
	$N = 1200; $oid = 0; $cv = array(); $ov = array();
	$pool = 150 + $seed * 3; // a small pool of emails: lots of sharing
	$accounts = 60 + $seed; // a small pool of accounts
	for ( $i = 1; $i <= $N; $i++ ) {
		$kind = mt_rand( 1, 100 );
		$email0 = mt_rand( 1, 100 ) <= 35 ? 'shared' . mt_rand( 1, $pool ) . '@example.test' : 'own' . $i . '@example.test';
		if ( $kind <= 70 ) {
			$key = 'p:09' . str_pad( (string) $i, 9, '0', STR_PAD_LEFT );
		} elseif ( $kind <= 85 ) {
			$key = 'e:' . $email0;
		} elseif ( $kind <= 90 ) {
			$key = 'u:' . ( 100000 + $i );
		} else {
			$key = 'o:' . ( 500000 + $i );
		}
		if ( isset( $seen_keys[ $seed ][ $key ] ) ) {
			continue;
		}
		$seen_keys[ $seed ][ $key ] = true;
		$uid = mt_rand( 1, 100 ) <= 40 ? mt_rand( 1, $accounts ) : 0;
		if ( 0 === strpos( $key, 'u:' ) ) {
			$uid = 100000 + $i;
		}
		$ts = 1700000000 + mt_rand( 0, 90000000 );
		$k  = mt_rand( 1, 3 );
		$cv[] = $wpdb->prepare( '(%s,%d,%s,%s,%s,%d,%f,%d,%d)', $key, $uid, 'c' . $i, 0 === strpos( $key, 'p:' ) ? substr( $key, 2 ) : '', 0 === strpos( $key, 'e:' ) ? substr( $key, 2 ) : $email0, $k, 1000 * $k, $ts - 100000, $ts );
		for ( $r = 0; $r < $k; $r++ ) {
			$u = $uid && mt_rand( 1, 100 ) <= 12 ? mt_rand( 1, $accounts ) : $uid; // a few orders carry a second account
			$e = 0 === strpos( $key, 'e:' ) ? substr( $key, 2 ) : ( 'o:' === substr( $key, 0, 2 ) ? '' : ( $r && mt_rand( 1, 100 ) <= 30 ? 'shared' . mt_rand( 1, $pool ) . '@example.test' : $email0 ) );
			if ( 'o:' === substr( $key, 0, 2 ) ) { $u = 0; }
			$ov[] = $wpdb->prepare( '(%d,%s,%d,%f,%d,%d,%s)', ++$oid, $key, $ts - $r * 86400, 1000, 1, $u, $e );
		}
	}
	$wpdb->query( 'INSERT INTO ' . CRDR_DB::customers() . ' (ckey,user_id,name,phone,email,orders_count,total_spent,first_ts,last_ts) VALUES ' . implode( ',', $cv ) );
	foreach ( array_chunk( $ov, 1000 ) as $chunk ) {
		$wpdb->query( 'INSERT INTO ' . CRDR_DB::orders() . ' (order_id,ckey,ts,net,items,uid,email) VALUES ' . implode( ',', $chunk ) );
	}
	// the reference: the pure resolver on ALL customers and ALL evidence
	$clusters = array();
	foreach ( $wpdb->get_results( 'SELECT ckey, last_ts FROM ' . CRDR_DB::customers(), ARRAY_A ) as $r ) {
		$clusters[ $r['ckey'] ] = (int) $r['last_ts'];
	}
	$evidence = $wpdb->get_results( 'SELECT ckey, uid, email FROM ' . CRDR_DB::orders() . ' GROUP BY ckey, uid, email', ARRAY_N );
	$ref      = CRDR_Identity::resolve( $clusters, $evidence );
	$orders_before = $wpdb->get_results( 'SELECT order_id, ckey FROM ' . CRDR_DB::orders() . ' ORDER BY order_id', OBJECT_K );
	$tot_orders    = (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() );
	$lock = $lk->invoke( null );
	$s    = $rs->invoke( null, array( 'phase' => 'finalize', 'page' => 1, 'total' => 0, 'done' => 0, 'inflight' => 0 ) );
	CRDR_Sync::unlock( $lock );
	$want_keys = array_values( array_diff( array_keys( $clusters ), array_keys( $ref['map'] ) ) );
	sort( $want_keys );
	$got_keys = $wpdb->get_col( 'SELECT ckey FROM ' . CRDR_DB::customers() );
	sort( $got_keys );
	$orders_ok = true;
	foreach ( $wpdb->get_results( 'SELECT order_id, ckey FROM ' . CRDR_DB::orders(), ARRAY_A ) as $row ) {
		$old  = $orders_before[ $row['order_id'] ]->ckey;
		$want = isset( $ref['map'][ $old ] ) ? $ref['map'][ $old ] : $old;
		if ( $row['ckey'] !== $want ) {
			$orders_ok = false;
			break;
		}
	}
	$sum_ok = $tot_orders === (int) $wpdb->get_var( 'SELECT SUM(orders_count) FROM ' . CRDR_DB::customers() );
	$links_ok = $ref['links'] === get_option( CRDR_Sync::LINKS_OPTION, array() );
	$rep_ok = (int) $s['identity']['joined'] === (int) $ref['report']['joined'] && (int) $s['identity']['people'] === (int) $ref['report']['people'] && (int) $s['identity']['refused'] === (int) $ref['report']['refused'];
	if ( $want_keys !== $got_keys || ! $orders_ok || ! $sum_ok || ! $links_ok || ! $rep_ok ) {
		$all_ok = false;
		$mismatches[ $seed ] = array( 'keys' => $want_keys === $got_keys, 'orders' => $orders_ok, 'sum' => $sum_ok, 'links' => $links_ok, 'report' => $rep_ok );
	}
	$joined_sum  += (int) $ref['report']['joined'];
	$refused_sum += (int) $ref['report']['refused'];
	$shared_sum  += (int) $ref['report']['emails_shared'] + (int) $ref['report']['emails_contested'];
	// a NEW rebuild of the same data (everything imported again, provisional keys again) with the remembered joins gives the same people
	$first_keys  = $got_keys;
	$first_links = get_option( CRDR_Sync::LINKS_OPTION, array() );
	foreach ( array( CRDR_DB::customers(), CRDR_DB::orders() ) as $tb ) {
		$wpdb->query( 'TRUNCATE TABLE ' . $tb );
	}
	$wpdb->query( 'INSERT INTO ' . CRDR_DB::customers() . ' (ckey,user_id,name,phone,email,orders_count,total_spent,first_ts,last_ts) VALUES ' . implode( ',', $cv ) );
	foreach ( array_chunk( $ov, 1000 ) as $chunk ) {
		$wpdb->query( 'INSERT INTO ' . CRDR_DB::orders() . ' (order_id,ckey,ts,net,items,uid,email) VALUES ' . implode( ',', $chunk ) );
	}
	$lock = $lk->invoke( null );
	$rs->invoke( null, array( 'phase' => 'finalize', 'page' => 1, 'total' => 0, 'done' => 0, 'inflight' => 0 ) );
	CRDR_Sync::unlock( $lock );
	$again_keys = $wpdb->get_col( 'SELECT ckey FROM ' . CRDR_DB::customers() );
	sort( $again_keys );
	if ( $again_keys !== $first_keys || $first_links !== get_option( CRDR_Sync::LINKS_OPTION, array() ) ) {
		$stable = false;
		$mismatches[ "$seed-rebuild" ] = array( 'keys' => $again_keys === $first_keys );
	}
}
t( 'random stores: the database step (loads only the joinable customers, writes in batches) ends with EXACTLY the customers, order keys, order totals, remembered joins and counts that the resolver gives on all the data', $all_ok, wp_json_encode( $mismatches ) );
t( 'random stores: the test is not trivial (it joined hundreds of customers, hit shared / contested emails, and refused joins of two accounts)', $joined_sum > 200 && $shared_sum > 10 && $refused_sum >= 1, wp_json_encode( array( $joined_sum, $shared_sum, $refused_sum ) ) );
t( 'random stores: a new rebuild of the same data (re-imported, with the remembered joins) gives exactly the same people and the same remembered joins (repeatable rebuilds)', $stable, wp_json_encode( $mismatches ) );
foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::contacts() ) as $tb ) {
	$wpdb->query( 'TRUNCATE TABLE ' . $tb );
}
foreach ( array( CRDR_Sync::LINKS_OPTION, CRDR_Sync::STATE_OPTION, CRDR_Sync::LOCK_OPTION ) as $opt ) {
	delete_option( $opt );
}

/* ----------------------------------------------------------------- done */
printf( "\nphase 1 safety: %d passed, %d failed\n", $GLOBALS['P'], $GLOBALS['F'] );
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::halt( $GLOBALS['F'] ? 1 : 0 );
}
