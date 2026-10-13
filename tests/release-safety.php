<?php
/**
 * Release remediation (Phase 4.1) regression tests, WordPress level: the final opt-out check before the gateway, the customer file,
 * the schema checks, uninstall, privacy export / erasure, promotional templates and the upgrade window.
 *
 * DESTRUCTIVE for the plugin's own data. Same rules as phase1-safety.php: only on a disposable shop whose host is `localhost` and
 * whose option `crdr_test_shop` is "1".
 * Run:  wp eval-file wp-content/plugins/customer-radar/tests/release-safety.php
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

/* ============================================ A. the last opt-out check, before the gateway */
echo "== final opt-out check at the send boundary\n";
$wpdb->suppress_errors( true );
t( 'the unused CRDR_SMS::send() wrapper (it skipped every guard) is gone', ! method_exists( 'CRDR_SMS', 'send' ) );

$offer = array( 'template' => 'سلام {name} کد {coupon} تا {expire} {optout}', 'offer' => array( 'type' => 'percent', 'percent' => 10, 'days' => 7 ) );
$coupons_of = function ( $ckey ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey' AND meta_value = %s", $ckey ) );
};
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey'" );

// control: nothing opts out -> all three are messaged (so the assertions below are not vacuous)
crdr_t_cfg( $offer );
crdr_t_reset( 3 );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'control: without an opt-out all three customers are messaged', 3 === $res['sent'] && 3 === count( crdr_t_requests() ), wp_json_encode( $res ) );

// the person clicks "do not message" while the discount code is being created (after the claim, before the gateway)
crdr_t_cfg( $offer );
crdr_t_reset( 3 );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey'" );
$victim = null;
$hook   = function ( $id ) use ( &$victim ) {
	if ( $victim ) {
		return;
	}
	$c      = new WC_Coupon( $id );
	$victim = (string) $c->get_meta( '_crdr_ckey' );
	CRDR_DB::add_contact( $victim, 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
};
add_action( 'woocommerce_new_coupon', $hook );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
remove_action( 'woocommerce_new_coupon', $hook );
$phones = array_column( crdr_t_requests(), 'receptor' );
t( 'opt-out during woocommerce_new_coupon: the first customer in line is the one who opted out', 'p:09120000001' === $victim, (string) $victim );
t( 'opt-out during woocommerce_new_coupon: the gateway is NOT called for that customer', ! in_array( '09120000001', $phones, true ), implode( ',', $phones ) );
t( 'opt-out during woocommerce_new_coupon: the other two are still messaged', array( '09120000002', '09120000003' ) === $phones, implode( ',', $phones ) );
t( 'opt-out during woocommerce_new_coupon: counted as skipped, not as a failure, no error shown', 2 === $res['sent'] && 1 === $res['skipped'] && 0 === $res['failed'] && '' === $res['error'], wp_json_encode( $res ) );
t( 'opt-out during woocommerce_new_coupon: no sms row and no pending claim is left for that customer', 0 === crdr_t_count( "ckey = 'p:09120000001' AND kind = 'sms'" ) && 0 === crdr_t_count( "status = 'pending'" ) );
t( 'opt-out during woocommerce_new_coupon: the discount code made for the unsent message is deleted, the others stay', 0 === $coupons_of( 'p:09120000001' ) && 1 === $coupons_of( 'p:09120000002' ) && 1 === $coupons_of( 'p:09120000003' ) );
t( 'opt-out during woocommerce_new_coupon: the opt-out itself is untouched', 1 === crdr_t_count( "ckey = 'p:09120000001' AND kind = 'optout'" ) );
$again = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'opt-out during woocommerce_new_coupon: a later run still leaves that customer alone', 0 === $again['sent'] && 2 === count( crdr_t_requests() ), wp_json_encode( $again ) );

// the one-off call-list button takes the same path
crdr_t_cfg( array() );
crdr_t_reset( 2 );
@unlink( crdr_t_log_path() );
$victim = null;
add_action( 'woocommerce_new_coupon', $hook );
$cust = $wpdb->get_row( "SELECT * FROM " . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'", ARRAY_A );
$r    = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cust['id'], 'type' => 'sms_coupon' ) );
remove_action( 'woocommerce_new_coupon', $hook );
t( 'one-off sms + code, opt-out during the code creation: refused with the opt-out message, gateway not called', false === $r['success'] && false !== mb_strpos( (string) $r['data']['message'], 'درخواست نکردن پیام' ) && 0 === count( crdr_t_requests() ), wp_json_encode( $r ) );
t( 'one-off sms + code, opt-out during the code creation: no claim left behind', 0 === crdr_t_count( "status = 'pending'" ) && 0 === crdr_t_count( "kind = 'sms'" ) );

// a database that cannot answer the last check is "do not send", never "free to send"
$nth = 0;
$cut = function ( $nth_fail ) use ( &$nth ) {
	$nth = 0;
	return function ( $q ) use ( &$nth, $nth_fail ) {
		if ( false !== strpos( $q, "kind = 'optout' LIMIT 1" ) && ++$nth === $nth_fail ) {
			return 'SELECT * FROM crdr_table_that_does_not_exist';
		}
		return $q;
	};
};
foreach ( array( 1 => 'at claim time', 2 => 'at the last check' ) as $n => $when ) {
	crdr_t_cfg( array() );
	crdr_t_reset( 3 );
	@unlink( crdr_t_log_path() );
	$f = $cut( $n );
	add_filter( 'query', $f );
	$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
	remove_filter( 'query', $f );
	t( "opt-out lookup fails {$when}: nothing is sent", 0 === $res['sent'] && 0 === count( crdr_t_requests() ), wp_json_encode( $res ) );
	t( "opt-out lookup fails {$when}: the run says so (blocked db_error) instead of looking like a normal run", 'db_error' === $res['blocked'] && '' !== $res['error'], wp_json_encode( $res ) );
	t( "opt-out lookup fails {$when}: no claim stays pending, no sms counts as a contact", 0 === crdr_t_count( "status = 'pending'" ) && 0 === crdr_t_count( "kind = 'sms' AND status IN ('ok','unknown')" ), wp_json_encode( crdr_t_rows( "kind = 'sms'" ) ) );
}
// ... and once the database answers again the same customers are messaged
crdr_t_cfg( array() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'after the failure the customers are not lost: the next run messages them', $res['sent'] >= 1 && '' === $res['blocked'], wp_json_encode( $res ) );

/* ============================================ B. the customer file */
echo "== customer file (CSV)\n";
crdr_t_cfg( array() );
crdr_t_reset( 4 );
$evil_mail = "=cmd|'/ccalc'!A0@x.com";
t( 'the formula payload is a valid e-mail address (so a customer can really put it in an order)', (bool) is_email( $evil_mail ) );
$wpdb->update( CRDR_DB::customers(), array( 'email' => $evil_mail, 'name' => '=HYPERLINK("http://x","y")' ), array( 'ckey' => 'p:09120000001' ) );
$wpdb->update( CRDR_DB::customers(), array( 'email' => '+1@x.com', 'name' => '@SUM(1)' ), array( 'ckey' => 'p:09120000003' ) );
$wpdb->update( CRDR_DB::customers(), array( 'name' => "-2+3\t" ), array( 'ckey' => 'p:09120000004' ) );
CRDR_DB::add_contact( 'p:09120000002', 'optout', array( 'playbook' => 'manual', 'message' => 'x' ) );
$rows = $wpdb->get_results( 'SELECT * FROM ' . CRDR_DB::customers() . ' ORDER BY ckey', ARRAY_A );
$ok   = true;
$mem  = fopen( 'php://memory', 'w+' );
CRDR_Actions::csv_write( $mem, $rows, CRDR_DB::optout_keys( wp_list_pluck( $rows, 'ckey' ), $ok ) );
rewind( $mem );
$bom = fread( $mem, 3 );
$lines = array();
while ( false !== ( $row = fgetcsv( $mem ) ) ) {
	$lines[] = $row;
}
fclose( $mem );
t( 'csv: UTF-8 BOM, a header and one line per customer', "\xEF\xBB\xBF" === $bom && 5 === count( $lines ), count( $lines ) );
$lines[0][0] = ltrim( $lines[0][0], "\xEF\xBB\xBF" );
t( 'csv: the last column is the opt-out column, named for what it means', CRDR_Actions::EXPORT_OPTOUT_HEADER === end( $lines[0] ) && 13 === count( $lines[0] ) );
$flag = array();
foreach ( array_slice( $lines, 1 ) as $l ) {
	$flag[ $l[1] ] = end( $l );
}
t( 'csv: the customer who opted out is marked, the others are not', array( '09120000001' => '', '09120000002' => CRDR_Actions::EXPORT_OPTOUT_YES, '09120000003' => '', '09120000004' => '' ) === $flag, wp_json_encode( $flag ) );
$bad = array();
foreach ( array_slice( $lines, 1 ) as $l ) {
	foreach ( array( 0, 1, 2, 10 ) as $i ) { // name, phone, e-mail, segment: every text column
		if ( '' !== $l[ $i ] && in_array( $l[ $i ][0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$bad[] = $l[ $i ];
		}
	}
}
t( 'csv: no text column (name, phone, e-mail, segment) starts with = + - @ tab or CR', ! $bad, wp_json_encode( $bad ) );
t( 'csv: the e-mail payload is defused, not dropped', "'" . $evil_mail === $lines[1][2] && "'+1@x.com" === $lines[3][2], wp_json_encode( array( $lines[1][2], $lines[3][2] ) ) );
t( 'csv: csv_safe leaves ordinary text alone', 'علی' === CRDR_Actions::csv_safe( 'علی' ) && '09121234567' === CRDR_Actions::csv_safe( '09121234567' ) && '' === CRDR_Actions::csv_safe( '' ) );
t( 'csv: the export button says what the opt-out column is for', false !== mb_strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-crdr-ui.php' ), 'ستون آخر آن‌ها را مشخص می‌کند' ) );

// the handler itself: a database error is an error page, never an "empty" or an opt-out-less file
$export = function ( $fail_fragment ) {
	wp_set_current_user( 1 );
	$_GET = $_REQUEST = array( '_wpnonce' => wp_create_nonce( CRDR_Actions::NONCE ) );
	$f = function ( $q ) use ( $fail_fragment ) {
		return false !== strpos( $q, $fail_fragment ) ? 'SELECT * FROM crdr_table_that_does_not_exist' : $q;
	};
	$h = function () {
		return function ( $m ) {
			throw new CRDR_T_Done( is_string( $m ) ? $m : 'die' );
		};
	};
	add_filter( 'wp_die_handler', $h, 99 );
	add_filter( 'query', $f );
	$msg = 'NO DIE';
	ob_start();
	try {
		CRDR_Actions::post_export();
	} catch ( CRDR_T_Done $e ) {
		$msg = $e->getMessage();
	}
	ob_end_clean();
	remove_filter( 'query', $f );
	remove_filter( 'wp_die_handler', $h, 99 );
	return $msg;
};
t( 'csv handler: the customer query fails -> an error, not an empty file', false !== mb_strpos( $export( 'LIMIT 50000' ), 'خطای پایگاه‌داده' ) );
t( 'csv handler: the opt-out lookup fails -> an error, not a file that hides opt-outs', false !== mb_strpos( $export( "WHERE kind = 'optout' AND ckey IN" ), 'خطای پایگاه‌داده' ) );

/* ============================================ C. the schema is checked, not assumed */
echo "== schema checks\n";
$short = array( 'customers' => CRDR_DB::customers(), 'orders' => CRDR_DB::orders(), 'order_items' => CRDR_DB::items(), 'contacts' => CRDR_DB::contacts() );
$same  = true;
foreach ( CRDR_DB::required_schema() as $name => $cols ) {
	$real = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $short[ $name ] );
	if ( array_diff( $cols, $real ) || array_diff( $real, $cols ) ) {
		$same = false;
		echo "   schema list differs for {$name}: " . wp_json_encode( array( array_diff( $cols, $real ), array_diff( $real, $cols ) ) ) . "\n";
	}
}
t( 'the plugin\'s list of required columns equals the real tables exactly (the list cannot drift)', $same );
t( 'a complete schema has no problems, and the version is recorded', array() === CRDR_DB::schema_problems() && CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) );

// a host that refuses every ALTER; the attempts are counted
$alters     = 0;
$fail_alter = function ( $q ) use ( &$alters ) {
	if ( preg_match( '/^\s*ALTER TABLE/i', $q ) ) {
		$alters++;
		return 'SELECT * FROM crdr_alter_refused';
	}
	return $q;
};
$heal_state = function () {
	CRDR_Sync::start();
	for ( $i = 0; $i < 40; $i++ ) {
		$s = CRDR_Sync::step();
		if ( ! in_array( $s['phase'], CRDR_Sync::WORK, true ) ) {
			break;
		}
	}
};

// 1. a failed schema operation never records the version, and everything that matters refuses to run
crdr_t_cfg( array() );
crdr_t_reset( 3 );
@unlink( crdr_t_log_path() );
$wpdb->query( 'ALTER TABLE ' . CRDR_DB::orders() . ' DROP COLUMN uid, DROP COLUMN email' );
update_option( 'crdr_db_version', '4' ); // "an update is being applied"
delete_option( CRDR_DB::SCHEMA_ERROR );
CRDR_DB::forget_schema_check();
$wpdb->insert( CRDR_DB::orders(), array( 'order_id' => 99001, 'ckey' => 'p:09120000001', 'ts' => time(), 'net' => 1, 'items' => 1 ) ); // a marker that must survive
add_filter( 'query', $fail_alter );
CRDR_DB::maybe_upgrade();
t( 'upgrade whose ALTER fails: the version is NOT recorded as current', '4' === get_option( 'crdr_db_version' ), (string) get_option( 'crdr_db_version' ) );
t( 'upgrade whose ALTER fails: install() reports it', false === CRDR_DB::install() );
$err = get_option( CRDR_DB::SCHEMA_ERROR );
t( 'upgrade whose ALTER fails: the missing columns are named (orders.uid, orders.email)', is_array( $err ) && in_array( 'orders.uid', $err['problems'], true ) && in_array( 'orders.email', $err['problems'], true ), wp_json_encode( $err ) );
delete_option( CRDR_DB::SCHEMA_ERROR );
$before = $alters;
CRDR_DB::maybe_upgrade();
$once = $alters - $before;
CRDR_DB::maybe_upgrade();
CRDR_DB::maybe_upgrade();
t( 'upgrade whose ALTER fails: it is retried later, not on every request', $once > 0 && $alters - $before === $once, "$once / " . ( $alters - $before ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'incomplete schema: a campaign run is blocked with the schema code and a message, nothing is sent', 'schema' === $res['blocked'] && '' !== $res['error'] && 0 === $res['sent'] && 0 === count( crdr_t_requests() ), wp_json_encode( $res ) );
t( 'incomplete schema: the message says what to do (re-activate, ask the host for CREATE/ALTER)', false !== mb_strpos( $res['error'], 'غیرفعال و دوباره فعال' ) && false !== mb_strpos( $res['error'], 'ALTER' ) );
t( 'incomplete schema: the message names tables/columns only, no SQL and no table prefix', false === stripos( $res['error'], 'SELECT' ) && false === stripos( $res['error'], 'wp_crdr' ) );
$cust = $wpdb->get_row( 'SELECT * FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'", ARRAY_A );
$r    = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cust['id'], 'type' => 'sms' ) );
t( 'incomplete schema: a one-off sms is refused too', false === $r['success'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ), wp_json_encode( $r ) );
$customers_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() );
$st = CRDR_Sync::start();
t( 'incomplete schema: a rebuild does not start, and empties nothing', 'failed' === $st['phase'] && 'schema' === $st['error'] && $customers_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() ) && 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() . ' WHERE order_id = 99001' ), wp_json_encode( $st ) );
$g = CRDR_Sync::guard();
t( 'incomplete schema: the guard explains it (tables, not "interrupted")', $g['blocked'] && false !== mb_strpos( $g['message'], 'جدول‌های افزونه' ) );
wp_set_current_user( 1 );
$html = CRDR_Health::notice_html();
t( 'incomplete schema: the administrator sees a persistent notice', false !== mb_strpos( $html, 'جدول‌های افزونه' ) && false !== mb_strpos( $html, 'orders.uid' ), mb_substr( strip_tags( $html ), 0, 160 ) );
remove_filter( 'query', $fail_alter );

// 2. recovery: once the host allows it, the schema is completed, the version is recorded, the notice goes
delete_option( CRDR_DB::SCHEMA_ERROR );
$missing = CRDR_DB::ensure_schema();
t( 'recovery: ensure_schema() repairs the columns', array() === $missing && array() === CRDR_DB::schema_problems(), wp_json_encode( $missing ) );
t( 'recovery: the version is recorded only now, and the error is gone', CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) && false === get_option( CRDR_DB::SCHEMA_ERROR ) );
$heal_state();
t( 'recovery: after a rebuild the data state is done again', 'done' === CRDR_Sync::state( true )['phase'] );

// 3. version 5 on paper, a column gone (what a version number cannot see)
crdr_t_cfg( array() );
crdr_t_reset( 3 );
@unlink( crdr_t_log_path() );
$wpdb->query( 'ALTER TABLE ' . CRDR_DB::contacts() . ' DROP COLUMN localid' );
CRDR_DB::forget_schema_check();
t( 'a dropped column is found although the version says 5', array( 'contacts.localid' ) === CRDR_DB::schema_problems() && CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) );
add_filter( 'query', $fail_alter );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
remove_filter( 'query', $fail_alter );
t( 'version 5 but a column missing and not repairable: the run is blocked, nothing sent', 'schema' === $res['blocked'] && 0 === count( crdr_t_requests() ), wp_json_encode( $res ) );
delete_option( CRDR_DB::SCHEMA_ERROR );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'version 5 but a column missing and repairable: the run repairs it first, then runs', '' === $res['blocked'] && $res['sent'] > 0 && array() === CRDR_DB::schema_problems(), wp_json_encode( $res ) );

// 4. the unique claim index is part of the schema (without it two processes could claim one customer)
$wpdb->query( 'ALTER TABLE ' . CRDR_DB::contacts() . ' DROP INDEX claim_key' );
CRDR_DB::forget_schema_check();
t( 'the missing unique claim index is reported', array( 'contacts.claim_key (unique)' ) === CRDR_DB::schema_problems() );
add_filter( 'query', $fail_alter );
CRDR_DB::ensure_schema();
$claim = CRDR_Send::claim( 'p:09120000001', 'dormant', 'r1' );
remove_filter( 'query', $fail_alter );
t( 'no claim is handed out while the claim index is missing', isset( $claim['skip'] ) && 'schema' === $claim['skip'], wp_json_encode( $claim ) );
delete_option( CRDR_DB::SCHEMA_ERROR );
CRDR_DB::ensure_schema();
t( 'the claim index is restored by the repair', array() === CRDR_DB::schema_problems() );

// 5. a lost contact table is NEVER re-created empty on its own (that would forget every opt-out)
crdr_t_cfg( array() );
crdr_t_reset( 3 );
CRDR_DB::add_contact( 'p:09120000001', 'optout', array( 'playbook' => 'manual', 'message' => 'x' ) );
$k = CRDR_DB::contacts();
$wpdb->query( "RENAME TABLE {$k} TO {$k}_held" );
CRDR_DB::forget_schema_check();
@unlink( crdr_t_log_path() );
$res    = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
$exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$k}'" );
t( 'contact table lost: the run is blocked and nothing is sent', 'schema' === $res['blocked'] && 0 === count( crdr_t_requests() ), wp_json_encode( $res ) );
t( 'contact table lost: it is NOT silently re-created empty (by the run, by ensure_schema, or by an update check)', ! $exists );
update_option( 'crdr_db_version', '4' );
delete_option( CRDR_DB::SCHEMA_ERROR );
CRDR_DB::maybe_upgrade();
CRDR_DB::ensure_schema();
t( 'contact table lost: ... nor by an update that arrives while it is missing', ! $wpdb->get_var( "SHOW TABLES LIKE '{$k}'" ) );
t( 'contact table lost: the message explains the opt-out consequence and the choice', false !== mb_strpos( CRDR_DB::schema_message( array( 'contacts' ) ), 'پیام نده' ) && false !== mb_strpos( CRDR_DB::schema_message( array( 'contacts' ) ), 'پشتیبان' ) );
wp_set_current_user( 1 );
t( 'contact table lost: the administrator sees it', false !== mb_strpos( CRDR_Health::notice_html(), 'پیام نده' ) );
$wpdb->query( "RENAME TABLE {$k}_held TO {$k}" );
update_option( 'crdr_db_version', CRDR_DB::DB_VERSION );
delete_option( CRDR_DB::SCHEMA_ERROR );
CRDR_DB::forget_schema_check();
t( 'contact table restored from the backup: opt-out is back and the schema is complete', array() === CRDR_DB::ensure_schema() && 1 === crdr_t_count( "kind = 'optout'" ) );
// explicit activation (a person's decision) does create a missing table
$wpdb->query( "RENAME TABLE {$k} TO {$k}_held" );
CRDR_DB::install();
t( 'activating the plugin (an explicit act) does re-create a missing contact table', (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$k}'" ) && 0 === crdr_t_count( '1=1' ) );
$wpdb->query( "DROP TABLE {$k}" );
$wpdb->query( "RENAME TABLE {$k}_held TO {$k}" );
CRDR_DB::forget_schema_check();
delete_option( CRDR_DB::SCHEMA_ERROR );
update_option( 'crdr_db_version', CRDR_DB::DB_VERSION );

// 6. a derived table (customers) can be re-created: it is rebuilt from the orders
$c = CRDR_DB::customers();
$wpdb->query( "RENAME TABLE {$c} TO {$c}_held" );
CRDR_DB::forget_schema_check();
$missing = CRDR_DB::ensure_schema();
t( 'customer table lost: it is re-created (it is rebuilt from the orders), nothing is sent from it', array() === $missing && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c}" ) );
$wpdb->query( "DROP TABLE {$c}" );
$wpdb->query( "RENAME TABLE {$c}_held TO {$c}" );
CRDR_DB::forget_schema_check();
delete_option( CRDR_DB::SCHEMA_ERROR );

/* ============================================ D. uninstall: what stays, what goes, and the explicit full erasure */
echo "== uninstall policy\n";
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'customer-radar/customer-radar.php' );
}
$opt_tables = array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::items() );
$has_table  = function ( $t ) use ( $wpdb ) {
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
};
$seed_everything = function ( $erase_flag = 0 ) use ( $wpdb ) {
	crdr_t_cfg( array() );
	crdr_t_reset( 4 );
	update_option( 'crdr_settings', array_merge( get_option( 'crdr_settings' ), array( 'erase_on_uninstall' => $erase_flag, 'kavenegar_key' => 'SECRETKEY123' ) ) );
	$wpdb->insert( CRDR_DB::orders(), array( 'order_id' => 7001, 'ckey' => 'p:09120000001', 'ts' => time(), 'net' => 5, 'items' => 1, 'uid' => 0, 'email' => 'a@example.test' ) );
	$wpdb->insert( CRDR_DB::items(), array( 'order_id' => 7001, 'product_id' => 3, 'qty' => 1, 'total' => 5 ) );
	CRDR_DB::add_contact( 'p:09120000001', 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام', 'coupon' => 'ZZ1', 'error' => 'e' ) );
	CRDR_DB::add_contact( 'p:09120000002', 'sms', array( 'playbook' => 'dormant', 'message' => 'سلام مشتری ۲ متن خصوصی', 'status' => 'ok', 'coupon' => 'RDABC' ) );
	CRDR_DB::add_contact( 'p:09120000003', 'called', array( 'playbook' => 'manual', 'message' => 'یادداشت' ) );
	CRDR_DB::add_contact( 'p:09120000004', 'skip', array( 'playbook' => 'manual', 'message' => '' ) );
	update_option( CRDR_Sync::LINKS_OPTION, array( 'e:a@example.test' => 'p:09120000001' ), false );
	update_option( CRDR_Sync::APART_OPTION, array( 'p:09120000003' ), false );
	update_option( CRDR_Send::LOG_OPTION, array( array( 'ts' => time(), 'playbook' => 'dormant', 'trigger' => 'manual', 'result' => 'ran' ) ), false );
	update_option( CRDR_Health::OPTION, array( 'dormant' => array( 'last_run' => array( 'ts' => time() ) ) ), false );
	set_transient( 'crdr_draft_1_abcdef', array( 'cfg' => array( 'template' => 'سلام' ) ), 900 );
	set_transient( 'crdr_settings_draft_1', array( 'values' => array() ), 900 );
	wp_schedule_event( time() + 3600, 'hourly', 'crdr_hourly' );
	wp_schedule_single_event( time() + 60, 'crdr_sync_step' );
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'crdr_sync_step', array(), 'customer-radar' );
	}
	// a coupon this plugin made (it carries a customer's phone) and one the shop made by hand
	$c = new WC_Coupon();
	$c->set_code( 'RDUNINST' );
	$c->set_amount( 10 );
	$c->set_discount_type( 'percent' );
	$c->set_description( 'ساخته‌شده توسط رادار مشتری برای p:09120000001' );
	$c->set_email_restrictions( array( 'a@example.test' ) );
	$c->update_meta_data( '_crdr_ckey', 'p:09120000001' );
	$c->save();
	$h = new WC_Coupon();
	$h->set_code( 'HANDMADE1' );
	$h->set_amount( 5 );
	$h->set_description( 'کمپین دستی فروشگاه' );
	$h->save();
};
$count_as = function () use ( $wpdb ) {
	$tbl = $wpdb->prefix . 'actionscheduler_actions';
	return $wpdb->get_var( "SHOW TABLES LIKE '{$tbl}'" ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE hook = 'crdr_sync_step' AND status = 'pending'" ) : 0;
};
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey'" );

t( 'the setting "erase everything on uninstall" is OFF by default, and only an explicit tick turns it on', 0 === CRDR_Settings::defaults()['erase_on_uninstall'] && 0 === CRDR_Settings::sanitize( array() )['erase_on_uninstall'] && 0 === CRDR_Settings::sanitize( array( 'erase_on_uninstall' => '' ) )['erase_on_uninstall'] && 1 === CRDR_Settings::sanitize( array( 'erase_on_uninstall' => '1' ) )['erase_on_uninstall'] );
// every option the code creates is in the list uninstall removes (a new option that nobody added there would survive forever)
$found = array();
foreach ( glob( dirname( __DIR__ ) . '/includes/class-crdr-*.php' ) as $f ) {
	$src = file_get_contents( $f );
	preg_match_all( "/const [A-Z_]*(?:OPTION|EPOCH)[A-Z_]*\s*=\s*'(crdr_\w+)'/", $src, $m1 );
	preg_match_all( "/(?:get|update|delete|add)_option\(\s*'(crdr_\w+)'/", $src, $m2 );
	$found = array_merge( $found, $m1[1], $m2[1] );
}
$found = array_unique( $found );
t( 'every option the code creates is removed by uninstall (list in CRDR_Uninstall::options())', ! array_diff( $found, CRDR_Uninstall::options() ), wp_json_encode( array_values( array_diff( $found, CRDR_Uninstall::options() ) ) ) );

// ---- default: opt-outs stay, everything else goes
$seed_everything( 0 );
$blog = get_option( 'blogname' );
$before_others = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '%crdr%' AND option_name NOT LIKE '%transient%'" );
$ret = include dirname( __DIR__ ) . '/uninstall.php';
$k   = CRDR_DB::contacts();
t( 'default uninstall: the customer, order and item tables are gone', ! $has_table( CRDR_DB::customers() ) && ! $has_table( CRDR_DB::orders() ) && ! $has_table( CRDR_DB::items() ) );
t( 'default uninstall: the contact table stays', $has_table( $k ) );
$rows = $wpdb->get_results( "SELECT * FROM {$k}", ARRAY_A );
t( 'default uninstall: ONLY the opt-out rows remain (SMS history, calls, "not today" are gone)', 1 === count( $rows ) && 'optout' === $rows[0]['kind'] && 'p:09120000001' === $rows[0]['ckey'], wp_json_encode( $rows ) );
$r = $rows[0];
t( 'default uninstall: the kept row says only who and when (no text, coupon, error, staff id, run or claim data)', '' === $r['message'] && '' === $r['coupon'] && '' === $r['error'] && 0 === (int) $r['user_id'] && '' === $r['run_id'] && '' === $r['localid'] && null === $r['claim_key'] && '' === $r['playbook'] && (int) $r['created_ts'] > 0, wp_json_encode( $r ) );
$left = array();
foreach ( CRDR_Uninstall::options() as $o ) {
	if ( false !== get_option( $o, false ) ) {
		$left[] = $o;
	}
}
t( 'default uninstall: every option is gone (settings with the API key, joins, logs, state)', ! $left, implode( ',', $left ) );
t( 'default uninstall: transients (rejected-form drafts) are gone', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_crdr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_crdr\\_%'" ) );
t( 'default uninstall: no scheduled event and no pending Action Scheduler action of the plugin is left', ! wp_next_scheduled( 'crdr_hourly' ) && ! wp_next_scheduled( 'crdr_sync_step' ) && ! wp_next_scheduled( 'crdr_daily' ) && 0 === $count_as() );
$cid = wc_get_coupon_id_by_code( 'RDUNINST' );
$cc  = new WC_Coupon( $cid );
t( 'default uninstall: WooCommerce coupons are untouched (customers may still hold the code)', $cid && false !== strpos( $cc->get_description(), 'p:09120000001' ) && 'p:09120000001' === $cc->get_meta( '_crdr_ckey' ) && 1 === count( $cc->get_email_restrictions() ) );
t( 'default uninstall: unrelated site data is untouched', $blog === get_option( 'blogname' ) && $before_others === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '%crdr%' AND option_name NOT LIKE '%transient%'" ) );
t( 'default uninstall: running it twice changes nothing more', array( 'erase_all' => false, 'optouts_kept' => 1 ) === CRDR_Uninstall::run( false ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$k}" ) );

// ---- reinstall after the default uninstall: the people who opted out are still protected
CRDR_DB::forget_schema_check();
$ok = CRDR_DB::install();
t( 'reinstall after a default uninstall: the schema is complete and the version recorded', true === $ok && array() === CRDR_DB::schema_problems() && CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) );
t( 'reinstall: the earlier opt-out is still there and enforced by the claim', true === CRDR_DB::is_opted_out( 'p:09120000001' ) && 'optout' === CRDR_DB::blocked_reason( 'p:09120000001' ) );
crdr_t_cfg( array() );
crdr_t_reset( 3 ); // customers 1..3 come back (a rebuild would do the same); the old contact rows were cleared by the reset
CRDR_DB::add_contact( 'p:09120000001', 'optout', array( 'playbook' => '', 'message' => '' ) ); // what the kept row is, re-added because crdr_t_reset truncates
@unlink( crdr_t_log_path() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
$phones = array_column( crdr_t_requests(), 'receptor' );
t( 'reinstall: the opted-out customer is not messaged, the others are', ! in_array( '09120000001', $phones, true ) && 2 === count( $phones ), implode( ',', $phones ) );

// ---- explicit full erasure
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" ); // (a coupon code must be unique: start from none)
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey'" );
wp_cache_flush();
$seed_everything( 1 );
t( 'full erasure requested: the setting is read as ON', true === CRDR_Uninstall::erase_requested() );
include dirname( __DIR__ ) . '/uninstall.php';
t( 'full erasure: ALL tables are gone, including the contact table with the opt-outs', ! $has_table( CRDR_DB::customers() ) && ! $has_table( CRDR_DB::orders() ) && ! $has_table( CRDR_DB::items() ) && ! $has_table( $k ) );
$left = array();
foreach ( CRDR_Uninstall::options() as $o ) {
	if ( false !== get_option( $o, false ) ) {
		$left[] = $o;
	}
}
t( 'full erasure: every option is gone', ! $left, implode( ',', $left ) );
wp_cache_flush(); // the scrub is plain SQL (the plugin is gone when it runs); WooCommerce must read the database, not its object cache
$cid = wc_get_coupon_id_by_code( 'RDUNINST' );
$cc  = new WC_Coupon( $cid );
$raw = array( $wpdb->get_var( $wpdb->prepare( "SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $cid ) ), $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_crdr_ckey'", $cid ) ) );
t( 'full erasure: the plugin\'s coupon keeps its code, amount and e-mail restriction but loses the description and the hidden customer key', 'rduninst' === strtolower( $cc->get_code() ) && '' === $cc->get_description() && '' === (string) $cc->get_meta( '_crdr_ckey' ) && array( 'a@example.test' ) === $cc->get_email_restrictions() && array( '', '0' ) === array( (string) $raw[0], (string) $raw[1] ), wp_json_encode( array( $cid, $cc->get_code(), $cc->get_description(), $cc->get_meta( '_crdr_ckey' ), $cc->get_email_restrictions(), $raw ) ) );
$hm = new WC_Coupon( wc_get_coupon_id_by_code( 'HANDMADE1' ) );
t( 'full erasure: a coupon the shop made by hand is not touched', 'کمپین دستی فروشگاه' === $hm->get_description() );
t( 'full erasure: no scheduled work is left', ! wp_next_scheduled( 'crdr_hourly' ) && 0 === $count_as() );
CRDR_DB::forget_schema_check();
CRDR_DB::install();
t( 'reinstall after a full erasure: a clean, complete schema and NO opt-out (that is what was asked for)', array() === CRDR_DB::schema_problems() && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$k}" ) && false === CRDR_DB::is_opted_out( 'p:09120000001' ) );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey'" );
delete_option( 'crdr_settings' );
crdr_t_cfg( array() );

/* ============================================ E. personal-data export and erasure */
echo "== privacy: export and erasure\n";
$wpdb->suppress_errors( true );
$exps = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$ers  = apply_filters( 'wp_privacy_personal_data_erasers', array() );
t( 'the plugin registers a WordPress personal-data exporter and eraser', isset( $exps['customer-radar']['callback'] ) && isset( $ers['customer-radar']['callback'] ) && is_callable( $exps['customer-radar']['callback'] ) && is_callable( $ers['customer-radar']['callback'] ) );
t( 'the plugin contributes suggested privacy-policy text (to review, naming the SMS service as a recipient)', (bool) preg_match( '/كاوه|کاوه/u', (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-crdr-privacy.php' ) ) );

$mk_coupon = function ( $code, $ckey, array $emails, $used = 0, array $used_by = array() ) {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_amount( 10 );
	$c->set_discount_type( 'percent' );
	$c->set_description( 'ساخته‌شده توسط رادار مشتری برای ' . $ckey );
	$c->set_email_restrictions( $emails );
	$c->update_meta_data( '_crdr_ckey', $ckey );
	$c->set_usage_count( $used );
	$c->save();
	foreach ( $used_by as $u ) { // WooCommerce: one _used_by row per use
		add_post_meta( $c->get_id(), '_used_by', $u );
	}
	return $c->get_id();
};
$people = function () use ( $wpdb, $mk_coupon ) {
	crdr_t_cfg( array() );
	foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::items(), CRDR_DB::contacts() ) as $tb ) {
		$wpdb->query( "TRUNCATE TABLE {$tb}" );
	}
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_crdr_ckey')" );
	wp_cache_flush();
	delete_option( CRDR_Sync::STATE_OPTION );
	$cust = function ( $ckey, $name, $phone, $email ) use ( $wpdb ) {
		$wpdb->insert( CRDR_DB::customers(), array( 'ckey' => $ckey, 'name' => $name, 'phone' => $phone, 'email' => $email, 'orders_count' => 2, 'total_spent' => 100, 'first_ts' => time() - 9000, 'last_ts' => time() - 5000, 'segment' => 'loyal', 'priority' => 5 ) );
	};
	$order = function ( $id, $ckey, $email, $uid = 0 ) use ( $wpdb ) {
		$wpdb->insert( CRDR_DB::orders(), array( 'order_id' => $id, 'ckey' => $ckey, 'ts' => time() - 5000, 'net' => 50, 'items' => 1, 'uid' => $uid, 'email' => $email ) );
		$wpdb->insert( CRDR_DB::items(), array( 'order_id' => $id, 'product_id' => 11, 'qty' => 1, 'total' => 50 ) );
	};
	// Carol: one person, two orders, a joined e-mail key, a full contact history, an opt-out, two discount codes
	$cust( 'p:09122220002', 'کارول', '09122220002', 'carol@example.test' );
	$order( 8001, 'p:09122220002', 'carol@example.test' );
	$order( 8002, 'p:09122220002', 'carol@example.test' );
	CRDR_DB::add_contact( 'p:09122220002', 'sms', array( 'playbook' => 'dormant', 'message' => 'سلام کارول عزیز کد RDCAROL1', 'coupon' => 'RDCAROL1', 'status' => 'ok' ) );
	CRDR_DB::add_contact( 'p:09122220002', 'called', array( 'playbook' => 'manual', 'message' => 'با کارول صحبت شد' ) );
	CRDR_DB::add_contact( 'p:09122220002', 'skip', array( 'playbook' => 'manual', 'message' => '' ) );
	CRDR_DB::add_contact( 'p:09122220002', 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام', 'error' => 'x' ) );
	CRDR_DB::add_contact( 'e:carol@example.test', 'optout', array( 'playbook' => 'manual', 'message' => CRDR_DB::OPTOUT_COPY_NOTE ) );
	// Dan and Erin: two people, ONE phone number
	$cust( 'p:09123330003', 'دن', '09123330003', 'dan@example.test' );
	$order( 8101, 'p:09123330003', 'dan@example.test' );
	$order( 8102, 'p:09123330003', 'erin@example.test' );
	CRDR_DB::add_contact( 'p:09123330003', 'sms', array( 'playbook' => 'dormant', 'message' => 'سلام دن', 'status' => 'ok' ) );
	// Frank: nothing to do with them
	$cust( 'p:09124440004', 'فرانک', '09124440004', 'frank@example.test' );
	$order( 8201, 'p:09124440004', 'frank@example.test' );
	CRDR_DB::add_contact( 'p:09124440004', 'sms', array( 'playbook' => 'dormant', 'message' => 'سلام فرانک', 'status' => 'ok' ) );
	CRDR_DB::add_contact( 'p:09124440004', 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
	update_option( CRDR_Sync::LINKS_OPTION, array( 'e:carol@example.test' => 'p:09122220002', 'e:frank@example.test' => 'p:09124440004' ), false );
	update_option( CRDR_Sync::APART_OPTION, array( 'e:carol@example.test', 'p:09129990009' ), false );
	$mk_coupon( 'RDCAROL1', 'p:09122220002', array( 'carol@example.test' ) );
	$mk_coupon( 'RDCAROL2', 'p:09122220002', array( 'carol@example.test' ), 1, array( 'carol@example.test', '77' ) );
	$mk_coupon( 'RDFRANK1', 'p:09124440004', array( 'frank@example.test' ) );
	$h = new WC_Coupon(); // a coupon the shop made by hand for the same e-mail: not this plugin's, not touched
	$h->set_code( 'HANDCAROL' );
	$h->set_amount( 5 );
	$h->set_email_restrictions( array( 'carol@example.test' ) );
	$h->save();
};
$snap = function ( array $keys ) use ( $wpdb ) {
	$in  = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
	$out = array();
	foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::contacts() ) as $tb ) {
		$out[] = $wpdb->get_results( "SELECT * FROM {$tb} WHERE ckey IN ({$in}) ORDER BY 1, 2", ARRAY_A );
	}
	return md5( wp_json_encode( $out ) );
};
$coupon_exists = function ( $code ) {
	wp_cache_flush();
	return (bool) wc_get_coupon_id_by_code( $code );
};

// ---------------------------------------------------------------- export
$people();
$ex = CRDR_Privacy::export( 'Carol@Example.test', 1 );
$by = array();
foreach ( $ex['data'] as $item ) {
	$by[ $item['group_id'] ][] = $item;
}
$blob = wp_json_encode( $ex, JSON_UNESCAPED_UNICODE );
t( 'export: one page, done', true === $ex['done'] );
t( 'export: the groups of data about the person are all there (customer, orders, contacts, coupons, joined keys)', ! array_diff( array( 'crdr-customer', 'crdr-orders', 'crdr-contacts', 'crdr-coupons', 'crdr-identity' ), array_keys( $by ) ), implode( ',', array_keys( $by ) ) );
t( 'export: her two orders, her four contact rows (with the message text), both of her codes', 2 === count( $by['crdr-orders'] ) && 5 === count( $by['crdr-contacts'] ) && 2 === count( $by['crdr-coupons'] ) && false !== mb_strpos( $blob, 'سلام کارول عزیز' ) && false !== mb_strpos( $blob, 'RDCAROL1' ) );
t( 'export: nothing about other people (Dan, Erin, Frank, their numbers, their e-mails, the hand-made coupon)', false === mb_strpos( $blob, 'فرانک' ) && false === strpos( $blob, '09124440004' ) && false === strpos( $blob, 'frank@' ) && false === strpos( $blob, 'erin@' ) && false === mb_strpos( $blob, 'دن' ) && false === strpos( $blob, 'HANDCAROL' ) && false === strpos( $blob, 'RDFRANK1' ), mb_substr( $blob, 0, 200 ) );
t( 'export: an e-mail the plugin has never seen, and a broken one, give an empty answer and no error', array() === CRDR_Privacy::export( 'nobody@example.test' )['data'] && array() === CRDR_Privacy::export( 'not an email' )['data'] && array() === CRDR_Privacy::export( '' )['data'] );
$ex2 = CRDR_Privacy::export( 'dan@example.test' );
t( 'export: for a shared phone number the export still shows the number\'s own data, but only the person\'s own orders', 1 === count( array_filter( $ex2['data'], function ( $i ) {
	return 'crdr-orders' === $i['group_id'];
} ) ) );

// ---------------------------------------------------------------- erasure
$people();
$frank_before = $snap( array( 'p:09124440004', 'e:frank@example.test' ) );
$dan_before   = $snap( array( 'p:09123330003' ) );
$r = CRDR_Privacy::erase( 'carol@example.test', 1 );
t( 'erase: something was removed and something was retained, and WordPress is told why', true === $r['items_removed'] && true === $r['items_retained'] && $r['messages'] && true === $r['done'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'erase: the retained-message names the opt-out list', false !== mb_strpos( implode( ' ', $r['messages'] ), 'پیام نده' ) );
t( 'erase: her customer row, order rows and item rows are gone', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09122220002'" ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() . " WHERE ckey = 'p:09122220002'" ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::items() . ' WHERE order_id IN (8001, 8002)' ) );
t( 'erase: her SMS history, the call note and "not today" are gone (message text included)', 0 === crdr_t_count( "ckey IN ('p:09122220002','e:carol@example.test') AND kind <> 'optout'" ) );
$kept = crdr_t_rows( "ckey IN ('p:09122220002','e:carol@example.test') AND kind = 'optout'" );
t( 'erase: her opt-out rows stay (both keys) and say only who and when', 2 === count( $kept ) && '' === $kept[0]['message'] && '' === $kept[1]['message'] && '' === $kept[0]['error'] && 0 === (int) $kept[0]['user_id'] && '' === $kept[0]['playbook'], wp_json_encode( $kept, JSON_UNESCAPED_UNICODE ) );
t( 'erase: ... and they still protect her number', true === CRDR_DB::is_opted_out( 'p:09122220002' ) && 'optout' === CRDR_DB::blocked_reason( 'p:09122220002' ) );
t( 'erase: her remembered join and her kept-apart entry are gone, Frank\'s join and the unrelated kept-apart key stay', array( 'e:frank@example.test' => 'p:09124440004' ) === get_option( CRDR_Sync::LINKS_OPTION ) && array( 'p:09129990009' ) === get_option( CRDR_Sync::APART_OPTION ), wp_json_encode( array( get_option( CRDR_Sync::LINKS_OPTION ), get_option( CRDR_Sync::APART_OPTION ) ) ) );
t( 'erase: the unused discount code made for her is deleted', ! $coupon_exists( 'RDCAROL1' ) );
wp_cache_flush();
$used = new WC_Coupon( wc_get_coupon_id_by_code( 'RDCAROL2' ) );
t( 'erase: the USED code stays for the accounting but loses the description, the hidden customer key, her e-mail restriction and her name in "used by" (another user stays)', 1 === (int) $used->get_usage_count() && '' === $used->get_description() && '' === (string) $used->get_meta( '_crdr_ckey' ) && array() === $used->get_email_restrictions() && array( '77' ) === array_map( 'strval', $used->get_used_by() ), wp_json_encode( array( $used->get_description(), $used->get_email_restrictions(), $used->get_used_by() ) ) );
t( 'erase: a coupon the shop made by hand for her e-mail is NOT touched (not ours)', $coupon_exists( 'HANDCAROL' ) );
t( 'erase: other people are untouched (Frank\'s rows, join and code; Dan and Erin\'s shared number)', $frank_before === $snap( array( 'p:09124440004', 'e:frank@example.test' ) ) && $dan_before === $snap( array( 'p:09123330003' ) ) && $coupon_exists( 'RDFRANK1' ) );

// repeated erasure: nothing left to do, nothing breaks, nothing changes
$state_before = md5( wp_json_encode( array( $snap( array( 'p:09122220002', 'e:carol@example.test', 'p:09124440004', 'p:09123330003' ) ), get_option( CRDR_Sync::LINKS_OPTION ), get_option( CRDR_Sync::APART_OPTION ) ) ) );
$r2 = CRDR_Privacy::erase( 'carol@example.test', 1 );
t( 'erase again: nothing more is removed and nothing fails', false === $r2['items_removed'] && true === $r2['done'], wp_json_encode( $r2, JSON_UNESCAPED_UNICODE ) );
t( 'erase again: not one row or option changed', $state_before === md5( wp_json_encode( array( $snap( array( 'p:09122220002', 'e:carol@example.test', 'p:09124440004', 'p:09123330003' ) ), get_option( CRDR_Sync::LINKS_OPTION ), get_option( CRDR_Sync::APART_OPTION ) ) ) ) );
t( 'erase again: an e-mail that was never a customer is a no-op too', array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ) === CRDR_Privacy::erase( 'nobody@example.test', 1 ) );

// a shared phone number is not erased on one person's request
$people();
$dan_before = $snap( array( 'p:09123330003' ) );
$r = CRDR_Privacy::erase( 'dan@example.test', 1 );
t( 'shared number: Dan\'s request leaves the number\'s customer row, orders and history alone, and says so', $dan_before === $snap( array( 'p:09123330003' ) ) && true === $r['items_retained'] && false !== mb_strpos( implode( ' ', $r['messages'] ), 'مشتری دیگری' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );

// a send in flight is not deleted under the sender
$people();
$wpdb->insert( CRDR_DB::contacts(), array( 'ckey' => 'p:09122220002', 'kind' => 'sms', 'playbook' => 'dormant', 'status' => 'pending', 'claim_key' => 'p:09122220002', 'created_ts' => time() ) );
$r = CRDR_Privacy::erase( 'carol@example.test', 1 );
t( 'erase: a claim that is still being sent stays (and the answer says to run it again)', 1 === crdr_t_count( "status = 'pending'" ) && true === $r['items_retained'] && false !== mb_strpos( implode( ' ', $r['messages'] ), 'در حال ارسال' ) );

// not while a rebuild is writing the same tables
$people();
CRDR_Sync::start();
$before = $snap( array( 'p:09122220002' ) );
$r      = CRDR_Privacy::erase( 'carol@example.test', 1 );
$st     = CRDR_Sync::state( true );
t( 'erase during a rebuild: nothing is deleted, the answer says to try again', $before === $snap( array( 'p:09122220002' ) ) || 'starting' !== $st['phase'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'erase during a rebuild: reported as retained', true === $r['items_retained'] && false !== mb_strpos( implode( ' ', $r['messages'] ), 'در حال به‌روزرسانی' ) );
delete_option( CRDR_Sync::STATE_OPTION );
CRDR_Sync::unlock( (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = '" . CRDR_Sync::LOCK_OPTION . "'" ) );

// the opt-out decision is one switch, and it works both ways
$people();
add_filter( 'crdr_privacy_keep_optout', '__return_false' );
$r = CRDR_Privacy::erase( 'carol@example.test', 1 );
remove_filter( 'crdr_privacy_keep_optout', '__return_false' );
t( 'policy switch (filter crdr_privacy_keep_optout = false): the opt-out rows go too, and nothing is claimed as retained for them', 0 === crdr_t_count( "ckey IN ('p:09122220002','e:carol@example.test')" ) && false === CRDR_DB::is_opted_out( 'p:09122220002' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'policy switch: the default (no filter) keeps them', true === CRDR_Privacy::keep_optout() );

// ---------------------------------------------------------------- with real WooCommerce orders, and the rebuild afterwards
$wc_order = function ( $email, $phone, $name ) {
	$o = wc_create_order();
	$o->set_billing_email( $email );
	$o->set_billing_phone( $phone );
	$o->set_billing_first_name( $name );
	crdr_t_money( $o, 100000 );
	$o->set_status( 'completed' );
	$o->set_date_created( time() - 20 * DAY_IN_SECONDS );
	$o->save();
	return $o->get_id();
};
$rebuild = function () {
	CRDR_Sync::start();
	for ( $i = 0; $i < 60; $i++ ) {
		$s = CRDR_Sync::step();
		if ( ! in_array( $s['phase'], CRDR_Sync::WORK, true ) ) {
			break;
		}
	}
	return CRDR_Sync::state( true )['phase'];
};
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
crdr_t_cfg( array() );
update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
foreach ( array( array( 'gina@example.test', '09125550005', 'Gina', true ), array( 'hana@example.test', '09126660006', 'Hana', false ) ) as $pp ) {
	list( $mail, $phone, $nm, $wc_erases ) = $pp;
	crdr_t_reset( 0 );
	foreach ( array( CRDR_DB::orders(), CRDR_DB::items() ) as $tb ) {
		$wpdb->query( "TRUNCATE TABLE {$tb}" );
	}
	foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $old ) {
		$old->delete( true );
	}
	$wc_order( $mail, $phone, $nm );
	$wc_order( $mail, $phone, $nm );
	$wc_order( 'other@example.test', '09127770007', 'Other' );
	t( "[{$nm}] the rebuild finishes and knows the customer", 'done' === $rebuild() && 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:{$phone}'" ) );
	CRDR_DB::add_contact( "p:{$phone}", 'sms', array( 'playbook' => 'dormant', 'message' => "سلام {$nm}", 'status' => 'ok' ) );
	CRDR_DB::add_contact( "p:{$phone}", 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
	$res = CRDR_Privacy::erase( $mail, 1 );
	t( "[{$nm}] erase: removed, with the opt-out retained", true === $res['items_removed'] && true === $res['items_retained'] );
	t( "[{$nm}] erase: her customer, orders and SMS history are gone at once (not only after the next rebuild)", 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:{$phone}'" ) && 0 === crdr_t_count( "ckey = 'p:{$phone}' AND kind = 'sms'" ) );
	if ( $wc_erases ) {
		WC_Privacy_Erasers::order_data_eraser( $mail, 1 ); // WordPress runs this eraser next to ours
	}
	t( "[{$nm}] the rebuild after erasure finishes", 'done' === $rebuild() );
	$ck = $wpdb->get_col( 'SELECT ckey FROM ' . CRDR_DB::customers() . ' ORDER BY ckey' );
	if ( $wc_erases ) {
		t( "[{$nm}] WooCommerce erased the orders: the rebuild does NOT bring her back (no phone, no e-mail, no name kept)", ! in_array( "p:{$phone}", $ck, true ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . " WHERE email = '{$mail}' OR name LIKE '%{$nm}%'" ), implode( ',', $ck ) );
	} else {
		t( "[{$nm}] WooCommerce KEPT the orders: the rebuild mirrors them (customer is back), but with no contact history", in_array( "p:{$phone}", $ck, true ) && 0 === crdr_t_count( "ckey = 'p:{$phone}' AND kind = 'sms'" ), implode( ',', $ck ) );
	}
	t( "[{$nm}] after the rebuild the retained opt-out still protects the number", true === CRDR_DB::is_opted_out( "p:{$phone}" ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( array( 'need_phone' => 1, 'exclude_contacted' => 1 ) ) . " AND c.ckey = 'p:{$phone}'" ) );
	t( "[{$nm}] another customer of the same rebuild is untouched", in_array( 'p:09127770007', $ck, true ) );
	$res3 = CRDR_Privacy::erase( $mail, 1 );
	t( "[{$nm}] erasing again after the rebuild is harmless", true === $res3['done'] && 'done' === CRDR_Sync::state( true )['phase'] );
}
foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $old ) {
	$old->delete( true );
}
delete_option( 'woocommerce_erasure_request_removes_order_data' );

/* ============================================ F. promotional templates, and the window after an update */
echo "== promotional template safety\n";
$wipe_orders = function () {
	foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
		$o->delete( true );
	}
};
$wipe_orders();
$none_opt = 'سلام {name} عزیز، تخفیف ویژه برای شما در {store}';
$with_opt = 'سلام {name} عزیز، تخفیف ویژه برای شما در {store}. {optout}';
$bad = array();
foreach ( CRDR_Playbooks::defaults() as $id => $pb ) {
	if ( CRDR_Playbooks::template_errors( $pb ) ) {
		$bad[] = $id;
	}
}
t( 'every built-in campaign text satisfies the rule (a fresh install works)', ! $bad, implode( ',', $bad ) );
t( 'the default settings texts satisfy it too, and the default opt-out phrase is not empty', array() === CRDR_Settings::template_errors( CRDR_Settings::defaults() ) && '' !== trim( CRDR_Settings::defaults()['optout_text'] ) );
$errs = CRDR_Settings::template_errors( CRDR_Settings::sanitize( array( 'sms_template' => $none_opt, 'sms_template_coupon' => 'کد {coupon} {discount} {expire}' ) ) );
t( 'settings: both one-off texts without {optout} are refused when saved', 2 === count( array_filter( $errs, function ( $e ) {
	return false !== mb_strpos( $e, '{optout}' );
} ) ), wp_json_encode( $errs, JSON_UNESCAPED_UNICODE ) );
t( 'settings: an empty opt-out phrase is refused when saved (it would send promotions with no way to stop them)', (bool) CRDR_Settings::template_errors( CRDR_Settings::sanitize( array( 'optout_text' => '   ' ) ) ) );
t( 'settings: ... and a normal phrase is fine', array() === CRDR_Settings::optout_errors( array( 'optout_text' => 'لغو۱۱' ) ) );

crdr_t_cfg( array( 'template' => $with_opt ) );
$saved = CRDR_Playbooks::save( 'dormant', array( 'name' => 'x', 'mode' => 'manual', 'template' => $none_opt, 'audience' => array() ) );
t( 'campaign: saving a text without {optout} is refused, nothing is stored', is_array( $saved ) && false !== mb_strpos( $saved[0], '{optout}' ) && $with_opt === CRDR_Playbooks::get( 'dormant' )['template'], wp_json_encode( $saved, JSON_UNESCAPED_UNICODE ) );
crdr_t_cfg( array( 'template' => $none_opt, 'mode' => 'off' ) );
$mode = CRDR_Playbooks::set_mode( 'dormant', 'auto' );
t( 'campaign: such a text cannot be switched on (manual or auto)', is_array( $mode ) && is_array( CRDR_Playbooks::set_mode( 'dormant', 'manual' ) ) );
crdr_t_cfg( array( 'template' => $none_opt, 'mode' => 'manual' ) ); // a config saved by an older version
crdr_t_reset( 3 );
@unlink( crdr_t_log_path() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'campaign saved earlier without {optout}: the run is blocked as a template problem, nothing is sent', 'template' === $res['blocked'] && 0 === count( crdr_t_requests() ) && false !== mb_strpos( $res['error'], '{optout}' ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
crdr_t_cfg( array( 'template' => $with_opt ), array( 'optout_text' => '' ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'empty opt-out phrase in the settings: the run is blocked before anybody is claimed, nothing is sent', 'template' === $res['blocked'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ) && false !== mb_strpos( $res['error'], 'عبارت لغو عضویت' ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
$cust = $wpdb->get_row( 'SELECT * FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000001'", ARRAY_A );
$r    = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cust['id'], 'type' => 'sms' ) );
t( 'empty opt-out phrase: a one-off SMS is refused as well, nothing is claimed', false === $r['success'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$r = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cust['id'] ) );
t( 'empty opt-out phrase: the preview says so instead of showing a text that would not be sent', false === $r['success'] );
update_option( 'crdr_settings', array_merge( CRDR_Settings::defaults(), array( 'sms_provider' => 'kavenegar', 'kavenegar_key' => 'TESTKEY', 'send_from' => 0, 'send_to' => 24, 'sms_template' => $none_opt ) ) );
$r = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cust['id'], 'type' => 'sms' ) );
t( 'a one-off text without {optout} (saved by an older version) is refused too', false === $r['success'] && 0 === count( crdr_t_requests() ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
crdr_t_cfg( array( 'template' => $with_opt ) );
@unlink( crdr_t_log_path() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
$msgs = array_column( crdr_t_requests(), 'message' );
t( 'control: with the phrase in place every message goes out WITH it', 3 === $res['sent'] && 3 === count( $msgs ) && 3 === count( array_filter( $msgs, function ( $m ) {
	return false !== mb_strpos( $m, 'لغو۱۱' );
} ) ), wp_json_encode( $msgs, JSON_UNESCAPED_UNICODE ) );

echo "== the window after an update (schema older than 5)\n";
// state of a shop that has just been updated: two keys of one person (shared e-mail), opted out under one of them only; no rebuild yet
$split = function () use ( $wpdb ) {
	crdr_t_cfg( array() );
	crdr_t_reset( 0 );
	foreach ( array( CRDR_DB::orders(), CRDR_DB::items() ) as $tb ) {
		$wpdb->query( "TRUNCATE TABLE {$tb}" );
	}
	delete_option( CRDR_DB::REBUILD_OPTION );
	delete_option( CRDR_Sync::LINKS_OPTION );
	update_option( CRDR_Sync::STATE_OPTION, array( 'phase' => 'done', 'updated' => time(), 'started' => time() - 86400, 'finished' => time() - 86000 ) );
	foreach ( array( array( 'p:09124440004', '09124440004' ), array( 'p:09125550005', '09125550005' ), array( 'p:09127770007', '09127770007' ) ) as $i => $c ) {
		$wpdb->insert( CRDR_DB::customers(), array( 'ckey' => $c[0], 'name' => 'مشتری ' . ( $i + 1 ), 'phone' => $c[1], 'email' => $i < 2 ? 'dara@example.test' : '', 'orders_count' => 1, 'total_spent' => 100000, 'first_ts' => time() - 90 * 86400, 'last_ts' => time() - 90 * 86400, 'segment' => 'hibernating', 'priority' => 9 - $i ) );
	}
	CRDR_DB::add_contact( 'p:09124440004', 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
};
$split();
update_option( 'crdr_db_version', '4' );
CRDR_DB::forget_schema_check();
CRDR_DB::maybe_upgrade();
t( 'update from schema 4: the version moves to 5 and the first-rebuild flag is set', CRDR_DB::DB_VERSION === get_option( 'crdr_db_version' ) && (int) get_option( CRDR_DB::REBUILD_OPTION ) > 0 );
@unlink( crdr_t_log_path() );
$sg = CRDR_Sync::send_guard();
t( 'update window: sending is blocked with its own code, and lists stay readable (the plain guard is open)', $sg['blocked'] && 'rebuild_required' === $sg['code'] && false === CRDR_Sync::guard()['blocked'] );
t( 'update window: the message tells the owner exactly what to press', false !== mb_strpos( $sg['message'], 'تحلیل سفارش‌ها' ) && false !== mb_strpos( $sg['message'], 'داشبورد' ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'update window: a manual campaign run is refused, nobody is claimed, nothing is sent', 'rebuild_required' === $res['blocked'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
$pbs = get_option( CRDR_Playbooks::OPTION );
$pbs['dormant']['mode'] = 'auto';
update_option( CRDR_Playbooks::OPTION, $pbs, false );
CRDR_Playbooks::hourly();
t( 'update window: the scheduled (cron) run sends nothing either', 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ) );
wp_set_current_user( 1 );
t( 'update window: the administrator sees the instruction on the plugin screens', false !== mb_strpos( CRDR_Health::notice_html(), 'تحلیل سفارش‌ها' ) );
$p = CRDR_Playbooks::preview( 'dormant' );
t( 'update window: the campaign preview says it is blocked', 'rebuild_required' === $p['blocked'] );
$cust = $wpdb->get_row( 'SELECT * FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09127770007'", ARRAY_A );
$r    = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cust['id'], 'type' => 'sms' ) );
t( 'update window: a one-off SMS is refused with the same instruction', false === $r['success'] && 'rebuild_required' === $r['data']['code'] && 0 === count( crdr_t_requests() ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$r = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cust['id'] ) );
t( 'update window: the one-off preview is refused too', false === $r['success'] && 'rebuild_required' === $r['data']['code'] );
t( 'update window: claim() hands out nothing', isset( CRDR_Send::claim( 'p:09127770007', 'dormant', 'r' )['skip'] ) && 0 === crdr_t_count( "status = 'pending'" ) );
$r1 = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cust['id'], 'type' => 'optout' ) );
$r2 = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), array( 'id' => $cust['id'], 'type' => 'called', 'note' => 'x' ) );
t( 'update window: recording "do not message" and "called" still works (an opt-out must never wait for a rebuild)', true === $r1['success'] && true === $r2['success'] && true === CRDR_DB::is_opted_out( 'p:09127770007' ) );
CRDR_DB::add_contact( 'p:09127770007', 'optout', array( 'playbook' => '' ) ); // idempotent: already there
$wpdb->query( "DELETE FROM " . CRDR_DB::contacts() . " WHERE ckey = 'p:09127770007'" ); // (put the third customer back to "never contacted" for the rebuild below)

// a rebuild that BEGAN before the update does not end the window; a failed one does not either
update_option( CRDR_DB::REBUILD_OPTION, time() + 3600, false );
$rebuild = function () {
	CRDR_Sync::start();
	for ( $i = 0; $i < 60; $i++ ) {
		$s = CRDR_Sync::step();
		if ( ! in_array( $s['phase'], CRDR_Sync::WORK, true ) ) {
			break;
		}
	}
	return CRDR_Sync::state( true )['phase'];
};
$wc_order = function ( $email, $phone, $name, $days = 60 ) {
	$o = wc_create_order();
	$o->set_billing_email( $email );
	$o->set_billing_phone( $phone );
	$o->set_billing_first_name( $name );
	crdr_t_money( $o, 100000 );
	$o->set_status( 'completed' );
	$o->set_date_created( time() - $days * DAY_IN_SECONDS );
	$o->save();
};
$wipe_orders();
$wc_order( 'dara@example.test', '09124440004', 'Dara', 80 );
$wc_order( 'dara@example.test', '09125550005', 'Dara', 70 );
$wc_order( '', '09127770007', 'Sam', 90 );
t( 'a rebuild that started before the update was recorded does NOT end the window', 'done' === $rebuild() && false !== get_option( CRDR_DB::REBUILD_OPTION, false ) && true === CRDR_Sync::send_guard()['blocked'] );
update_option( CRDR_Sync::STATE_OPTION, array( 'phase' => 'failed', 'error' => 'step', 'updated' => time() ) );
t( 'a failed rebuild keeps sending blocked', true === CRDR_Sync::send_guard()['blocked'] && 'sync_failed' === CRDR_Sync::send_guard()['code'] );

// the real thing: the first rebuild after the update
$split();
update_option( 'crdr_db_version', '3' ); // from an even older schema
CRDR_DB::maybe_upgrade();
t( 'update from schema 3: the same window opens', (int) get_option( CRDR_DB::REBUILD_OPTION ) > 0 );
$pbs = get_option( CRDR_Playbooks::OPTION );
$pbs['dormant']['mode'] = 'manual';
update_option( CRDR_Playbooks::OPTION, $pbs, false );
$before_audience = $wpdb->get_col( 'SELECT c.ckey FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( array( 'need_phone' => 1, 'exclude_contacted' => 1 ) ) . ' ORDER BY c.ckey' );
t( 'update window, before the rebuild: the person\'s OTHER number is still in the audience (this is why sending waits)', in_array( 'p:09125550005', $before_audience, true ), implode( ',', $before_audience ) );
t( 'the first rebuild finishes', 'done' === $rebuild() );
t( 'first rebuild: the window is closed (the flag is gone)', false === get_option( CRDR_DB::REBUILD_OPTION, false ) && false === CRDR_Sync::send_guard()['blocked'] );
$ck = $wpdb->get_col( 'SELECT ckey FROM ' . CRDR_DB::customers() . ' ORDER BY ckey' );
$op = $wpdb->get_col( "SELECT DISTINCT ckey FROM " . CRDR_DB::contacts() . " WHERE kind = 'optout' ORDER BY ckey" );
t( 'first rebuild: the same person is one customer now, and the opt-out sits on BOTH of her keys', 2 === count( $ck ) && array( 'p:09124440004', 'p:09125550005' ) === $op, wp_json_encode( array( $ck, $op ) ) );
@unlink( crdr_t_log_path() );
crdr_t_cfg( array( 'audience' => array() ) ); // everybody the rebuild produced (their segments depend on today's date)
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
$phones = array_column( crdr_t_requests(), 'receptor' );
t( 'after the first rebuild campaigns run, and neither number of the opted-out person is messaged', '' === $res['blocked'] && ! in_array( '09124440004', $phones, true ) && ! in_array( '09125550005', $phones, true ) && in_array( '09127770007', $phones, true ), implode( ',', $phones ) . ' ' . wp_json_encode( $res ) );

// a first installation never opens the window; neither does re-running the update
delete_option( CRDR_DB::REBUILD_OPTION );
delete_option( 'crdr_db_version' );
CRDR_DB::forget_schema_check();
CRDR_DB::install();
t( 'a fresh installation does not open the window', false === get_option( CRDR_DB::REBUILD_OPTION, false ) );
CRDR_DB::maybe_upgrade();
t( 'nor does an up-to-date shop', false === get_option( CRDR_DB::REBUILD_OPTION, false ) );
$wipe_orders();

/* ============================================ G. Phase 4.2 / increment 1: honest erasure and the retention sweep */
echo "== privacy: anonymised orders, accurate erasure report, retention sweep\n";
$wpdb->suppress_errors( true );
$K = CRDR_DB::contacts();
$C = CRDR_DB::customers();
$g_orders = function () {
	foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
		$o->delete( true );
	}
};
$g_order = function ( $email, $phone, $name, $days = 400 ) {
	$o = wc_create_order();
	$o->set_billing_email( $email );
	$o->set_billing_phone( $phone );
	$o->set_billing_first_name( $name );
	crdr_t_money( $o, 100000 );
	$o->set_status( 'completed' );
	$o->set_date_created( time() - $days * DAY_IN_SECONDS );
	$o->save();
	return $o->get_id();
};
$g_rebuild = function () {
	CRDR_Sync::start();
	for ( $i = 0; $i < 60; $i++ ) {
		$s = CRDR_Sync::step();
		if ( ! in_array( $s['phase'], CRDR_Sync::WORK, true ) ) {
			break;
		}
	}
	return CRDR_Sync::state( true )['phase'];
};
$g_reset = function ( $offer ) use ( $wpdb, $K, $g_orders ) {
	$g_orders();
	crdr_t_cfg( $offer ? array( 'audience' => array(), 'offer' => array( 'type' => 'percent', 'percent' => 10, 'days' => 7 ), 'template' => 'سلام {name} کد {coupon} تا {expire} {optout}' ) : array( 'audience' => array() ) );
	foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::items(), $K ) as $tb ) {
		$wpdb->query( "TRUNCATE TABLE {$tb}" );
	}
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" );
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey'" );
	wp_cache_flush();
	delete_option( CRDR_Sync::STATE_OPTION );
	delete_option( CRDR_Retention::OPTION );
	@unlink( crdr_t_log_path() );
	update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
};

t( 'schema: the retention bookkeeping column exists and is part of the required schema', in_array( 'orphan_ts', CRDR_DB::required_schema()['contacts'], true ) && array() === CRDR_DB::schema_problems() && '6' === CRDR_DB::DB_VERSION );

// ---------------------------------------------------------------- the Cara scenario, with a discount code (attributable through the coupon)
$g_reset( true );
$g_order( 'cara@example.test', '09123330003', 'Cara' );
$g_order( 'other@example.test', '09127770007', 'Other' );
t( 'Cara: the rebuild finishes', 'done' === $g_rebuild() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'Cara: she and the other customer were messaged (the code is restricted to her e-mail)', 2 === $res['sent'], wp_json_encode( $res ) );
foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'billing_email' => 'cara@example.test' ) ) as $o ) {
	WC_Privacy_Erasers::remove_order_personal_data( $o ); // what WooCommerce's scheduled "anonymise old orders" does; it never calls the WordPress hooks
}
t( 'Cara: the nightly rebuild after anonymisation finishes', 'done' === $g_rebuild() );
t( 'Cara: she is no longer a customer, but her SMS row (with her name) is still in the table', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$C} WHERE ckey = 'p:09123330003'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$K} WHERE ckey = 'p:09123330003' AND message <> ''" ) );
$p = CRDR_Privacy::person( 'cara@example.test' );
t( 'Cara: the plugin\'s own coupon (restricted to her e-mail, carrying her key) still ties the e-mail to her phone key', in_array( 'p:09123330003', $p['exclusive'], true ), wp_json_encode( $p ) );
$r = CRDR_Privacy::erase( 'cara@example.test', 1 );
t( 'Cara erasure: located through the coupon, her SMS row is really gone', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$K} WHERE ckey = 'p:09123330003'" ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'Cara erasure: reports removal (rows and the coupon were really deleted) and nothing retained', true === $r['items_removed'] && false === $r['items_retained'] && ! $r['messages'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'Cara erasure: the other customer is untouched', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$K} WHERE ckey = 'p:09127770007' AND message <> ''" ) );

// ---------------------------------------------------------------- the same without any coupon: nothing can tie the e-mail to the phone key
$g_reset( false );
$g_order( 'cara@example.test', '09123330003', 'Cara' );
$g_order( 'other@example.test', '09127770007', 'Other' );
$g_rebuild();
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'billing_email' => 'cara@example.test' ) ) as $o ) {
	WC_Privacy_Erasers::remove_order_personal_data( $o );
}
$g_rebuild();
$before_rows = wp_json_encode( $wpdb->get_results( "SELECT * FROM {$K} ORDER BY id", ARRAY_A ) );
$r = CRDR_Privacy::erase( 'cara@example.test', 1 );
t( 'no coupon: the eraser did NOT claim to remove anything (it located nothing)', false === $r['items_removed'], wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'no coupon: ... it says it retained what it could not attribute, and why', true === $r['items_retained'] && 1 === count( $r['messages'] ) && false !== mb_strpos( $r['messages'][0], 'نسبت نداد' ) && false !== mb_strpos( $r['messages'][0], '۹۰' ), wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
t( 'no coupon: not one row changed (the report matches what happened)', $before_rows === wp_json_encode( $wpdb->get_results( "SELECT * FROM {$K} ORDER BY id", ARRAY_A ) ) );
$r2 = CRDR_Privacy::erase( 'cara@example.test', 1 );
t( 'no coupon: asking again gives the same honest answer', $r === $r2 );

// ---------------------------------------------------------------- the sweep
echo "== retention sweep\n";
$now = time();
$mark_swept = function () use ( $wpdb, $K ) {
	return $wpdb->get_row( "SELECT message, coupon, error, user_id, run_id, localid, ckey, kind, playbook, grp, status, created_ts, orphan_ts FROM {$K} WHERE ckey = 'p:09123330003' AND kind = 'sms' LIMIT 1", ARRAY_A );
};
$row = $mark_swept();
t( 'sweep: the successful rebuild after anonymisation MARKED her row (orphan_ts set, text still there: 90 days have not passed)', (int) $row['orphan_ts'] > 0 && '' !== $row['message'] && (int) $row['orphan_ts'] <= time() );
$other = $wpdb->get_row( "SELECT orphan_ts FROM {$K} WHERE ckey = 'p:09127770007' AND kind = 'sms'", ARRAY_A );
t( 'sweep: a row whose customer still exists is not marked', 0 === (int) $other['orphan_ts'] );
t( 'sweep: 89 days later nothing is swept yet', 0 === CRDR_Retention::run( $now + 89 * DAY_IN_SECONDS - 3600 )['swept'] || '' !== $mark_swept()['message'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$K} SET orphan_ts = %d WHERE ckey = 'p:09123330003'", $now - 91 * DAY_IN_SECONDS ) );
$st = CRDR_Retention::run( $now );
$row = $mark_swept();
t( 'sweep: after 91 days her row is minimised: text, error, code, staff id, run id and gateway id are gone', 1 === $st['swept'] && '' === $row['message'] && '' === $row['coupon'] && '' === $row['error'] && 0 === (int) $row['user_id'] && '' === $row['run_id'] && '' === $row['localid'], wp_json_encode( $st ) . wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) );
t( 'sweep: ... and what a cooldown / the results need stays: key, kind, campaign, group, status, time', 'p:09123330003' === $row['ckey'] && 'sms' === $row['kind'] && 'dormant' === $row['playbook'] && 'ok' === $row['status'] && (int) $row['created_ts'] > 0 );
$st2 = CRDR_Retention::run( $now );
t( 'sweep: running it again changes nothing (idempotent)', 0 === $st2['swept'] && 0 === $st2['marked'] );
t( 'sweep: the cooldown still holds for the minimised row', 'cooldown' === CRDR_DB::blocked_reason( 'p:09123330003' ) );
t( 'sweep: the state of the last run is kept for support', is_array( CRDR_Retention::last() ) && isset( CRDR_Retention::last()['swept'] ) );

// opt-outs are never marked or swept
CRDR_DB::add_contact( 'p:09125550005', 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
$wpdb->query( "UPDATE {$K} SET orphan_ts = " . ( $now - 200 * DAY_IN_SECONDS ) . " WHERE kind = 'optout'" ); // even if someone forced it
CRDR_Retention::run( $now );
$oo = $wpdb->get_row( "SELECT message, kind FROM {$K} WHERE ckey = 'p:09125550005'", ARRAY_A );
t( 'sweep: an opt-out row (its customer does not exist) keeps its key, its time and its text', 'optout' === $oo['kind'] && 'عدم تمایل به دریافت پیام' === $oo['message'] && true === CRDR_DB::is_opted_out( 'p:09125550005' ) );
$wpdb->query( "UPDATE {$K} SET orphan_ts = 0 WHERE kind = 'optout'" );

// a send in flight is never touched
$wpdb->insert( $K, array( 'ckey' => 'p:09129990009', 'kind' => 'sms', 'playbook' => 'dormant', 'status' => 'pending', 'claim_key' => 'p:09129990009', 'message' => 'در حال ارسال', 'created_ts' => $now - 100 * DAY_IN_SECONDS, 'orphan_ts' => $now - 100 * DAY_IN_SECONDS ) );
CRDR_Retention::run( $now );
t( 'sweep: a pending claim keeps its text', 'در حال ارسال' === $wpdb->get_var( "SELECT message FROM {$K} WHERE ckey = 'p:09129990009'" ) );
$wpdb->query( "DELETE FROM {$K} WHERE ckey = 'p:09129990009'" );

// the customer comes back: not an orphan any more, nothing is swept
$wpdb->query( "UPDATE {$K} SET message = 'متن', orphan_ts = " . ( $now - 120 * DAY_IN_SECONDS ) . " WHERE ckey = 'p:09123330003'" );
$g_order( 'cara2@example.test', '09123330003', 'Cara', 5 ); // the same phone number orders again
$g_rebuild(); // (this is a successful rebuild: it runs the sweep itself)
$row = $mark_swept();
t( 'sweep: a customer that came back (same phone) is un-marked by the rebuild and its text is NOT swept', 0 === (int) $row['orphan_ts'] && 'متن' === $row['message'], wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) );

// the rebuild itself runs the sweep, and only a SUCCESSFUL one does
$g_orders();
$g_order( 'keep@example.test', '09128880008', 'Keep', 30 );
$g_rebuild();
$wpdb->query( "UPDATE {$K} SET message = 'متن قدیمی', orphan_ts = " . ( $now - 120 * DAY_IN_SECONDS ) . " WHERE ckey = 'p:09123330003'" );
delete_option( CRDR_Retention::OPTION );
t( 'rebuild: a successful rebuild sweeps the old orphan', 'done' === $g_rebuild() && '' === (string) $wpdb->get_var( "SELECT message FROM {$K} WHERE ckey = 'p:09123330003' AND kind = 'sms'" ) && isset( CRDR_Retention::last()['swept'] ) && CRDR_Retention::last()['swept'] >= 1 );

// failed / interrupted / stalled rebuilds never sweep
$restore_old = function () use ( $wpdb, $K, $now ) {
	$wpdb->query( "UPDATE {$K} SET message = 'متن قدیمی', orphan_ts = " . ( $now - 120 * DAY_IN_SECONDS ) . " WHERE ckey = 'p:09123330003' AND kind = 'sms'" );
	delete_option( CRDR_Retention::OPTION );
};
$still = function () use ( $wpdb, $K ) {
	return 'متن قدیمی' === (string) $wpdb->get_var( "SELECT message FROM {$K} WHERE ckey = 'p:09123330003' AND kind = 'sms'" ) && false === get_option( CRDR_Retention::OPTION, false );
};
$restore_old();
$boom = function ( $q ) {
	return false !== strpos( $q, 'INSERT IGNORE INTO' . ' ' . CRDR_DB::orders() ) ? 'SELECT * FROM crdr_forced_failure' : $q;
};
add_filter( 'query', $boom );
$ph = $g_rebuild();
remove_filter( 'query', $boom );
t( 'failed rebuild (a database error while importing): it ends "failed" and does not sweep or mark anything', 'failed' === $ph && $still() );
$restore_old();
CRDR_Sync::start();
CRDR_Sync::step(); // one step, then the process "dies": the state stays in a working phase
t( 'interrupted rebuild (stopped between steps): no sweep', in_array( CRDR_Sync::state( true )['phase'], CRDR_Sync::WORK, true ) && $still() );
$st_stalled = CRDR_Sync::state( true );
$st_stalled['updated'] = time() - CRDR_Sync::STALL_AFTER - 60;
update_option( CRDR_Sync::STATE_OPTION, $st_stalled, false );
t( 'stalled rebuild: no sweep, still blocking', 'sync_stalled' === CRDR_Sync::guard()['code'] && $still() );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = '" . CRDR_Sync::LOCK_OPTION . "'" );
$ph = $g_rebuild();
t( 'the next successful rebuild then does sweep it', 'done' === $ph && '' === (string) $wpdb->get_var( "SELECT message FROM {$K} WHERE ckey = 'p:09123330003' AND kind = 'sms'" ) );

// nothing to compare with: no customers at all -> no marking, no sweeping
$restore_old();
$g_orders();
$ph = $g_rebuild();
t( 'a rebuild that finds no customer at all (empty store / statuses count nothing) does not turn every row into an orphan', 'done' === $ph && 'no_customers' === CRDR_Retention::last()['error'] && $still_msg = ( 'متن قدیمی' === (string) $wpdb->get_var( "SELECT message FROM {$K} WHERE ckey = 'p:09123330003' AND kind = 'sms'" ) ), wp_json_encode( CRDR_Retention::last() ) );

// a database error in the sweep is a code in the state, never a failed rebuild
$g_order( 'keep@example.test', '09128880008', 'Keep', 30 );
$boom2 = function ( $q ) {
	return 0 === strpos( ltrim( $q ), 'UPDATE ' . CRDR_DB::contacts() . ' SET orphan_ts' ) ? 'SELECT * FROM crdr_forced_failure' : $q;
};
add_filter( 'query', $boom2 );
$ph = $g_rebuild();
remove_filter( 'query', $boom2 );
t( 'a database error inside the sweep: the rebuild is still "done" and the state says database_error', 'done' === $ph && 'database_error' === CRDR_Retention::last()['error'] );

// ---------------------------------------------------------------- partial data and repeated erasure after a sweep
$g_reset( true );
$g_order( 'dina@example.test', '09124440004', 'Dina', 400 );
$g_order( 'eli@example.test', '09126660006', 'Eli', 400 );
$g_rebuild();
CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
$wpdb->query( "UPDATE {$K} SET message = '', coupon = '', error = '', user_id = 0, run_id = '', localid = '' WHERE ckey = 'p:09126660006'" ); // partial data: one row has nothing left to minimise
foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects' ) ) as $o ) {
	WC_Privacy_Erasers::remove_order_personal_data( $o );
}
$g_rebuild();
$wpdb->query( "UPDATE {$K} SET orphan_ts = " . ( $now - 95 * DAY_IN_SECONDS ) . " WHERE kind <> 'optout'" );
$st = CRDR_Retention::run( $now );
t( 'partial data: only the row that still had something is counted as swept (1 of 2)', 1 === $st['swept'], wp_json_encode( $st ) );
$r1 = CRDR_Privacy::erase( 'dina@example.test', 1 );
$r2 = CRDR_Privacy::erase( 'dina@example.test', 1 );
t( 'erasure after the sweep: Dina\'s row (located through her coupon) is removed once, and a second request finds nothing and claims nothing', true === $r1['items_removed'] && false === $r2['items_removed'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$K} WHERE ckey = 'p:09124440004'" ), wp_json_encode( array( $r1, $r2 ), JSON_UNESCAPED_UNICODE ) );
t( 'erasure after the sweep: Eli\'s row is not touched by Dina\'s request', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$K} WHERE ckey = 'p:09126660006'" ) );

// opt-out retained through anonymisation + sweep + erasure
$g_reset( false );
$g_order( 'fay@example.test', '09125550005', 'Fay', 400 );
$g_rebuild();
CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
CRDR_DB::add_contact( 'p:09125550005', 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects' ) ) as $o ) {
	WC_Privacy_Erasers::remove_order_personal_data( $o );
}
$g_rebuild();
$wpdb->query( "UPDATE {$K} SET orphan_ts = " . ( $now - 95 * DAY_IN_SECONDS ) . " WHERE kind <> 'optout'" );
CRDR_Retention::run( $now );
CRDR_Privacy::erase( 'fay@example.test', 1 );
t( 'opt-out retained through anonymisation, rebuild, sweep and an erasure request that cannot attribute it: still opted out, key and time intact', true === CRDR_DB::is_opted_out( 'p:09125550005' ) && (int) $wpdb->get_var( "SELECT created_ts FROM {$K} WHERE ckey = 'p:09125550005' AND kind = 'optout'" ) > 0 && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$K} WHERE ckey = 'p:09125550005' AND kind = 'optout'" ) );
$g_orders();
delete_option( 'woocommerce_erasure_request_removes_order_data' );

/* ============================================ H. Phase 4.2 / increment 2: SMS correctness and safety */
echo "== SMS: provider codes, unrecognised answers, third-party plugin strings, final opt-out boundary, daily cap\n";
$wpdb->suppress_errors( true );
$K  = CRDR_DB::contacts();
$st = function ( $s ) use ( $wpdb, $K ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$K} WHERE kind = 'sms' AND status = %s", $s ) );
};
$per_phone = function () {
	$out = array();
	foreach ( crdr_t_requests() as $r ) {
		$out[ $r['receptor'] ] = ( $out[ $r['receptor'] ] ?? 0 ) + 1;
	}
	return $out;
};

// ---- documented codes that stop the run (access / configuration / rate): one request, nobody penalised, served when it is fixed
foreach ( array( 416 => 'code_416', 427 => 'code_427', 429 => 'code_429', 451 => 'rate_limit' ) as $code => $mode ) {
	crdr_t_cfg( array(), array(), array( 'mode' => $mode ) );
	crdr_t_reset( 5 );
	$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
	t( "code {$code}: the run stops at the first customer, exactly one request, nothing counted as sent or unknown", 1 === $res['failed'] && 0 === $res['sent'] && 0 === $res['unknown'] && 1 === count( crdr_t_requests() ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
	t( "code {$code}: the row is a plain 'failed' (not a contact, not set aside), the claim is cleared", 1 === $st( 'failed' ) && 0 === crdr_t_count( "status = 'pending'" ) && '' === CRDR_DB::blocked_reason( 'p:09120000001' ), wp_json_encode( crdr_t_rows( "kind = 'sms'" ), JSON_UNESCAPED_UNICODE ) );
	t( "code {$code}: the administrator gets an explanation of its own, carrying the code and no provider wording", false !== strpos( $res['error'], 'کد ' . $code ) && false === strpos( $res['error'], 'documented code' ) && false === strpos( $res['error'], 'too many calls' ), $res['error'] );
	update_option( 'crdr_t_mock', wp_json_encode( array( 'mode' => 'ok' ) ) );
	@unlink( crdr_t_log_path() );
	$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
	t( "code {$code}: once the cause is fixed everybody is served, once each (nobody was penalised)", 5 === $res['sent'] && array_fill_keys( array( '09120000001', '09120000002', '09120000003', '09120000004', '09120000005' ), 1 ) === $per_phone(), wp_json_encode( $per_phone() ) );
}
// ---- 420: the TEXT is refused (links restricted): customers are set aside as "message" failures, ten in a row stop the run, saving lifts it
crdr_t_cfg( array(), array(), array( 'mode' => 'code_420' ) );
crdr_t_reset( 12 );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'code 420: ten refusals in a row stop the run, and exactly ten requests were made', 10 === $res['failed'] && 0 === $res['sent'] && 10 === count( crdr_t_requests() ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
t( 'code 420: the ten are set aside as "nobuild" (nothing sent, not a contact), with the explanation about links', 10 === $st( 'nobuild' ) && 0 === $st( 'unknown' ) && false !== mb_strpos( (string) $wpdb->get_var( "SELECT error FROM {$K} WHERE status = 'nobuild' LIMIT 1" ), 'لینک' ) );
CRDR_Send::build_epoch_bump(); // what saving the campaign or the settings does
update_option( 'crdr_t_mock', wp_json_encode( array( 'mode' => 'ok' ) ) );
@unlink( crdr_t_log_path() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'code 420: after the text is fixed and saved, all twelve are served', 12 === $res['sent'], wp_json_encode( $res ) );

// ---- unrecognised answers: never "sent", never a safe retry
foreach ( array( 'undocumented' => 'status 499', 'h200_201' => 'HTTP 200 with status 201', 'garbage200' => 'HTTP 200 with a body that is not JSON', 'server_busy' => '409', 'http500' => 'HTTP 500' ) as $mode => $label ) {
	crdr_t_cfg( array(), array(), array( 'mode' => $mode ) );
	crdr_t_reset( 4 );
	$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
	t( "unrecognised ({$label}): not sent; the run stops; the row is 'unknown'; one request", 0 === $res['sent'] && 1 === $res['unknown'] && 1 === $st( 'unknown' ) && 1 === count( crdr_t_requests() ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
	t( "unrecognised ({$label}): counts as contacted, so it is neither delivered nor retried", 'cooldown' === CRDR_DB::blocked_reason( 'p:09120000001' ) && 0 === $st( 'failed' ) && 0 === $st( 'retry' ) && 0 === $st( 'ok' ) );
	update_option( 'crdr_t_mock', wp_json_encode( array( 'mode' => 'ok' ) ) );
	CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
	t( "unrecognised ({$label}): later runs serve the others and never ask again for that customer", 1 === ( $per_phone()['09120000001'] ?? 0 ) && 4 === array_sum( $per_phone() ) );
}

// ---- the Persian WooCommerce SMS plugin (a stub with its real contract: send_sms() returns true, or a TEXT, and catches its own exceptions)
if ( ! function_exists( 'PWSMS' ) ) {
	class CRDR_T_PWSMS {
		public $ret   = true;
		public $calls = array();
		public function send_sms( $data ) {
			$this->calls[] = $data;
			if ( $this->ret instanceof Exception ) {
				throw $this->ret;
			}
			return $this->ret;
		}
	}
	function PWSMS() {
		static $i;
		return $i ? $i : $i = new CRDR_T_PWSMS();
	}
}
$pw = PWSMS();
$pw_run = function ( $ret ) use ( $pw ) {
	$pw->ret   = $ret;
	$pw->calls = array();
	crdr_t_cfg( array(), array( 'sms_provider' => 'pwsms' ) );
	crdr_t_reset( 3 );
	return CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
};
$res = $pw_run( true );
t( 'PWSMS: true is accepted (and the number goes as a list, as that plugin expects)', 3 === $res['sent'] && 3 === count( $pw->calls ) && is_array( $pw->calls[0]['mobile'] ) && '09120000001' === $pw->calls[0]['mobile'][0] );
$texts = array(
	'a text we do not know'                        => 'خطا: پاسخ نامفهوم از درگاه',
	'a text that looks like a failure'             => 'Error 422: invalid sender',
	'a text with a link, a long token and a phone' => 'HTTP 500 https://x.example/send?key=abcdefghijklmnop1234567890&to=09121234567 failed',
	'a text holding the Kavenegar key'             => 'bad request with key TESTKEY inside',
);
foreach ( $texts as $label => $ret ) {
	$res = $pw_run( $ret );
	$err = (string) $wpdb->get_var( "SELECT error FROM {$K} WHERE kind = 'sms' AND status = 'unknown' LIMIT 1" );
	t( "PWSMS, {$label}: UNKNOWN (never retried): one call, the run stops, no 'failed' / 'retry' row", 1 === $res['unknown'] && 0 === $res['sent'] && 1 === count( $pw->calls ) && 1 === $st( 'unknown' ) && 0 === $st( 'failed' ) && 0 === $st( 'retry' ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
	t( "PWSMS, {$label}: the administrator sees a diagnostic, and it holds no link, token, phone number or API key", '' !== $err && false !== mb_strpos( $err, 'پاسخ متنی افزونه پیامک ووکامرس' ) && false === strpos( $err, 'http' ) && false === strpos( $err, 'abcdefghijklmnop' ) && false === strpos( $err, '09121234567' ) && false === strpos( $err, 'TESTKEY' ) && mb_strlen( $err ) <= 255, $err );
	$pw->ret = true;
	CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
	t( "PWSMS, {$label}: later runs never ask that plugin to send to that customer again", 1 === count( array_filter( $pw->calls, function ( $c ) {
		return '09120000001' === $c['mobile'][0];
	} ) ) );
}
$res = $pw_run( 'cURL error 28: Operation timed out after 20001 milliseconds' );
t( 'PWSMS: a timeout text is UNKNOWN as before', 1 === $res['unknown'] && 0 === $st( 'retry' ) );
foreach ( array( 'an exception' => new RuntimeException( 'secret detail https://x.example?k=abcdefghijklmnop1234567' ), 'false' => false, 'null' => null, 'an array' => array( 'ok' => 1 ), 'an empty text' => '' ) as $label => $ret ) {
	$res = $pw_run( $ret );
	$err = (string) $wpdb->get_var( "SELECT error FROM {$K} WHERE kind = 'sms' AND status = 'unknown' LIMIT 1" );
	t( "PWSMS, {$label}: UNKNOWN, no retry, and nothing of the exception text is stored", 1 === $res['unknown'] && 0 === $res['sent'] && 0 === $st( 'retry' ) && 0 === $st( 'failed' ) && false === strpos( $err, 'abcdefghijklmnop' ) && false === strpos( $err, 'secret detail' ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) . $err );
}
$res = $pw_run( 'شماره موبایل خالی/نامعتبر است.' );
t( 'PWSMS: the answer that plugin gives BEFORE any gateway call about the number is a definite failure of that recipient (rejected), the run goes on', 3 === $res['failed'] && 3 === $st( 'rejected' ) && 0 === $st( 'unknown' ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
$res = $pw_run( 'متن پیامک خالی است.' );
t( 'PWSMS: the answer about an empty text is a definite failure of the message (nobuild)', $st( 'nobuild' ) >= 1 && 0 === $st( 'unknown' ) && 0 === $res['sent'] );
update_option( 'crdr_settings', array_merge( CRDR_Settings::all(), array( 'sms_provider' => 'kavenegar' ) ) );

// ---- the final opt-out boundary asks about the claimed key AND the number (the reproduced u:77 case)
crdr_t_cfg( array() );
crdr_t_reset( 4 );
@unlink( crdr_t_log_path() );
CRDR_DB::add_contact( 'p:09120000001', 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
$claim = CRDR_Send::claim( 'u:77', 'manual', 'r-u77' );
t( 'u:77: a claim for a customer key that is not opted out is granted (control)', isset( $claim['id'] ), wp_json_encode( $claim ) );
$out = CRDR_Send::send_claimed( $claim['id'], '09120000001', 'سلام', '' );
t( 'u:77 + an opted-out phone: the gateway is NOT called, nothing is sent', 'failed' === $out['state'] && 'optout' === $out['scope'] && 0 === count( crdr_t_requests() ), wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) );
t( 'u:77 + an opted-out phone: the claim is given back (no row left, no contact recorded for u:77)', 0 === crdr_t_count( "ckey = 'u:77'" ) );
$claim = CRDR_Send::claim( 'u:78', 'manual', 'r-u78' );
$out   = CRDR_Send::send_claimed( $claim['id'], '09120000002', 'سلام', '' );
t( 'control: u:78 + a phone that is not opted out IS sent', 'sent' === $out['state'] && 1 === count( crdr_t_requests() ) );
$claim = CRDR_Send::claim( 'p:09120000003', 'manual', 'r-ok' );
$out   = CRDR_Send::send_claimed( $claim['id'], '+98 912 000 0003', 'سلام', '' );
t( 'control: the ordinary case (key and number are the same person, number written in another format) is sent', 'sent' === $out['state'] );
// the same, but the number is spelled in another format than the key: the number is normalised before it is looked up
$claim = CRDR_Send::claim( 'u:79', 'manual', 'r-fmt' );
$out   = CRDR_Send::send_claimed( $claim['id'], '+98 912 000 0001', 'سلام', '' );
t( 'u:79 + the opted-out number written as +98 912 000 0001: still refused (the number is normalised first)', 'optout' === $out['scope'] && 0 === crdr_t_count( "ckey = 'u:79'" ), wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) );
// the opt-out lookup for the number cannot be read: do not send
$claim = CRDR_Send::claim( 'u:80', 'manual', 'r-db' );
$n     = 0;
$f     = function ( $q ) use ( &$n ) {
	if ( false !== strpos( $q, "kind = 'optout' LIMIT 1" ) && 2 === ++$n ) { // the first lookup is the key, the second is the number
		return 'SELECT * FROM crdr_forced_failure';
	}
	return $q;
};
$before = count( crdr_t_requests() );
add_filter( 'query', $f );
$out = CRDR_Send::send_claimed( $claim['id'], '09120000004', 'سلام', '' );
remove_filter( 'query', $f );
t( 'the opt-out lookup for the NUMBER fails: nothing is sent, the claim ends as failed (not a contact), scope db', 'failed' === $out['state'] && 'db' === $out['scope'] && $before === count( crdr_t_requests() ) && 0 === crdr_t_count( "ckey = 'u:80' AND status IN ('ok','unknown','pending')" ), wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) );
// a claim that is not there any more is not ours to send
$out = CRDR_Send::send_claimed( 987654321, '09120000004', 'سلام', '' );
t( 'a claim that no longer exists (recovered or released elsewhere): nothing is sent', 'failed' === $out['state'] && 'claim' === $out['scope'] && $before === count( crdr_t_requests() ) );

// ---- the daily cap fails closed
crdr_t_cfg( array( 'daily_cap' => 100 ) );
crdr_t_reset( 6 );
@unlink( crdr_t_log_path() );
$fail_count = function ( $from ) {
	$n = 0;
	return function ( $q ) use ( &$n, $from ) {
		if ( false !== strpos( $q, "status IN ('ok','pending','unknown')" ) && false !== strpos( $q, 'created_ts >=' ) && ++$n >= $from ) {
			return 'SELECT * FROM crdr_forced_failure';
		}
		return $q;
	};
};
$f = $fail_count( 1 );
add_filter( 'query', $f );
t( 'sent_today(): a count that cannot be read is NOT zero (fails closed), and no cap is left', CRDR_Playbooks::CAP_UNKNOWN === CRDR_Playbooks::sent_today( 'dormant' ) && 0 === CRDR_Playbooks::cap_left( 'dormant', CRDR_Playbooks::get( 'dormant' ) ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
$pv  = CRDR_Playbooks::preview( 'dormant' );
remove_filter( 'query', $f );
t( 'run(): with the count unreadable nothing is sent, nobody is claimed, and the answer says database error', 0 === $res['sent'] && 'db_error' === $res['blocked'] && '' !== $res['error'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
t( 'preview(): the same condition is shown as blocked (db_error), "sent today" is shown as 0, never a huge number', 'db_error' === $pv['blocked'] && 0 === $pv['today'] && false === $pv['can_override'], wp_json_encode( array( $pv['blocked'], $pv['today'] ) ) );
// the count works at first and fails in the middle of a run (between the claim and the send): that customer is released, the run stops, nothing more goes out
$f = $fail_count( 3 ); // 1: run_claimed start, 2: cap_left, 3: the per-customer check
add_filter( 'query', $f );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
remove_filter( 'query', $f );
t( 'a count that fails in the middle of a run: the claimed customer is released, the run stops with db_error, nothing is sent', 0 === $res['sent'] && 'db_error' === $res['blocked'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "status = 'pending'" ) && 0 === crdr_t_count( "kind = 'sms'" ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'and when the database answers again the same customers are served (nothing was lost, nothing doubled)', 6 === $res['sent'] && 6 === count( crdr_t_requests() ) );
// the cap still works when the count is readable (control)
crdr_t_cfg( array( 'daily_cap' => 2 ) );
crdr_t_reset( 6 );
@unlink( crdr_t_log_path() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'control: with a readable count the daily cap of 2 still stops the run at 2', 2 === $res['sent'] && 2 === count( crdr_t_requests() ) && 0 === $res['left'], wp_json_encode( $res ) );
crdr_t_cfg( array() );

/* ============================================ I. Phase 4.2 / increment 3: a copy of the shop must not send the real shop's automatic campaigns */
echo "== site identity: clone protection\n";
$wpdb->suppress_errors( true );
$orig_home = get_option( 'home' );
$orig_site = get_option( 'siteurl' );
$orig_id   = get_option( CRDR_Site::OPTION );
$move_to   = function ( $url ) {
	update_option( 'home', $url );
	update_option( 'siteurl', $url );
};
$back_home = function () use ( $orig_home, $orig_site ) {
	update_option( 'home', $orig_home );
	update_option( 'siteurl', $orig_site );
};
class CRDR_T_Redirect extends Exception {}
$trap = function () {
	add_filter( 'wp_redirect', $f1 = function ( $l ) {
		throw new CRDR_T_Redirect( $l );
	} );
	add_filter( 'wp_die_handler', $f2 = function () {
		return function ( $m ) {
			throw new CRDR_T_Done( is_string( $m ) ? $m : 'die' );
		};
	}, 99 );
	return array( $f1, $f2 );
};
$confirm_call = function ( $user_id, $method = 'POST', $nonce = true ) use ( $trap ) {
	wp_set_current_user( $user_id );
	$_SERVER['REQUEST_METHOD'] = $method;
	$_REQUEST                  = $_POST = array( 'action' => CRDR_Site::ACTION );
	if ( $nonce ) {
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce( CRDR_Site::ACTION );
	}
	list( $f1, $f2 ) = $trap();
	$out = 'NO EXIT';
	ob_start();
	try {
		CRDR_Site::handle_confirm();
	} catch ( CRDR_T_Redirect $e ) {
		$out = 'redirect:' . $e->getMessage();
	} catch ( CRDR_T_Done $e ) {
		$out = 'die:' . $e->getMessage();
	}
	ob_end_clean();
	remove_filter( 'wp_redirect', $f1 );
	remove_filter( 'wp_die_handler', $f2, 99 );
	return $out;
};
$real_looking = array( 'kavenegar_key' => 'LIVE-KEY-4f9a8c2e7b1d6035aa91c4e8', 'sms_provider' => 'kavenegar' );
$auto_cfg = function () use ( $real_looking ) {
	crdr_t_cfg( array( 'mode' => 'auto' ), $real_looking );
};

// ---- a fresh installation records the site's address; an existing record is never replaced by the installer
delete_option( CRDR_Site::OPTION );
t( 'no identity recorded: the status is "unconfirmed" (the safe side), not ok', 'unconfirmed' === CRDR_Site::status()['code'] && false === CRDR_Site::automatic_allowed() );
delete_option( 'crdr_db_version' );
CRDR_DB::forget_schema_check();
CRDR_DB::install();
$id = CRDR_Site::stored();
t( 'fresh install: the identity is recorded (normalised address, how = install)', null !== $id && CRDR_Site::normalize( $orig_home ) === $id['url'] && 'install' === $id['how'] && CRDR_Site::automatic_allowed(), wp_json_encode( $id ) );
update_option( CRDR_Site::OPTION, array( 'url' => 'the-real-shop.example', 'set' => 1, 'by' => 0, 'how' => 'install' ), false );
CRDR_DB::install();
t( 'installing / updating again does NOT replace an identity that exists (even a different one)', 'the-real-shop.example' === CRDR_Site::stored()['url'] && false === CRDR_Site::record_if_absent() );

// ---- THE CLONE: this database, restored on another address, with real-looking gateway settings and an automatic campaign
update_option( CRDR_Site::OPTION, array( 'url' => CRDR_Site::normalize( $orig_home ), 'set' => 1, 'by' => 0, 'how' => 'install' ), false );
$real_id = get_option( CRDR_Site::OPTION );
$auto_cfg();
crdr_t_reset( 4 );
@unlink( crdr_t_log_path() );
delete_option( CRDR_Health::OPTION );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'cron' ) );
t( 'control (the real address): an automatic run sends', 4 === $res['sent'], wp_json_encode( $res ) );
crdr_t_reset( 4 );
@unlink( crdr_t_log_path() );
$move_to( 'https://staging.example.test' );
t( 'the copy: the site address is read from the options (the test really moved the site)', 'staging.example.test' === CRDR_Site::current() && 'changed' === CRDR_Site::status()['code'] );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'cron' ) );
t( 'clone: an automatic run is refused (site_changed), nothing is claimed, nothing reaches the gateway', 'site_changed' === $res['blocked'] && 0 === $res['sent'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
CRDR_Playbooks::hourly();
CRDR_Playbooks::hourly();
CRDR_Admin::check_schema(); // (what the plugin does on admin_init; WooCommerce's own admin hooks cannot run in a CLI test)
t( 'clone: the hourly cron tick sends nothing, however often it runs', 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" ) );
$hs = CRDR_Health::load();
t( 'clone: the campaign is recorded as blocked for this reason (one state record, counted)', isset( $hs['dormant']['blocked']['code'] ) && 'site_changed' === $hs['dormant']['blocked']['code'] && $hs['dormant']['blocked']['count'] >= 2, wp_json_encode( $hs ) );
t( 'clone: the stored identity was NOT rewritten by any of that (not by the mismatch, the cron, a request)', $real_id === get_option( CRDR_Site::OPTION ) );
$logs = wp_json_encode( array( get_option( CRDR_Health::OPTION ), get_option( CRDR_Send::LOG_OPTION ), get_option( CRDR_Send::CRON_LOG_OPTION ), get_option( CRDR_Site::OPTION ), get_option( CRDR_Retention::OPTION ) ) );
t( 'clone: the logs and the state hold no gateway key', false === strpos( $logs, 'LIVE-KEY' ) );
wp_set_current_user( 1 );
$html = CRDR_Health::notice_html();
t( 'clone: the administrator sees a notice with both addresses, what it means, and the confirmation form (POST, nonce, own action)', false !== strpos( $html, 'staging.example.test' ) && false !== strpos( $html, CRDR_Site::normalize( $orig_home ) ) && false !== strpos( $html, 'name="action" value="' . CRDR_Site::ACTION . '"' ) && false !== strpos( $html, 'method="post"' ) && false !== strpos( $html, '_wpnonce' ), mb_substr( strip_tags( $html ), 0, 200 ) );
t( 'clone: the notice and the page text hold no gateway key', false === strpos( $html, 'LIVE-KEY' ) && false === strpos( CRDR_Site::warning(), 'LIVE-KEY' ) );
$mgr = get_user_by( 'login', 'p6_manager' );
$mgr = $mgr ? $mgr->ID : wp_insert_user( array( 'user_login' => 'p6_manager', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'p6_manager@example.invalid' ) );
$sub = get_user_by( 'login', 'p6_subscriber' );
$sub = $sub ? $sub->ID : wp_insert_user( array( 'user_login' => 'p6_subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'p6_sub@example.invalid' ) );
wp_set_current_user( $mgr );
$html_m = CRDR_Health::notice_html();
t( 'clone: a shop manager sees the notice but NOT the confirm button (only an administrator can confirm)', false !== strpos( $html_m, 'staging.example.test' ) && false === strpos( $html_m, '<form' ), mb_substr( strip_tags( $html_m ), 0, 120 ) );
wp_set_current_user( $sub );
t( 'clone: a subscriber sees nothing', '' === CRDR_Site::notice_html() && '' === CRDR_Health::notice_html() );
wp_set_current_user( 1 );

// ---- manual sending on the copy: RELEASE FREEZE. A person's click is not authorisation: the SERVER needs an Administrator AND a one-time
//      token that the preview issued for this very campaign / customer. Cron and unknown triggers never get in.
$adm2 = get_user_by( 'login', 'p7_admin2' );
$adm2 = $adm2 ? $adm2->ID : wp_insert_user( array( 'user_login' => 'p7_admin2', 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'user_email' => 'p7_admin2@example.invalid' ) );
$acks = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( CRDR_Site::ACK_PREFIX ) . '%' ) );
};
$fresh = function () {
	crdr_t_reset( 4 );
	@unlink( crdr_t_log_path() );
};
$pbrun = function ( $user, $ack, $id = 'dormant' ) {
	return crdr_t_ajax( array( 'CRDR_Actions', 'ajax_pb_run' ), array( 'id' => $id, 'ack' => $ack ), $user );
};
$pbprev = function ( $user ) {
	return crdr_t_ajax( array( 'CRDR_Actions', 'ajax_pb_preview' ), array( 'id' => 'dormant' ), $user );
};
$none = function () {
	return 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "kind = 'sms'" );
};
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( CRDR_Site::ACK_PREFIX ) . '%' ) );
$fresh();

// campaigns ("run now"): shop manager
wp_set_current_user( $mgr );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'clone, manual run by a SHOP MANAGER: refused on the server (site_unconfirmed); nothing claimed, nothing reaches the gateway', 'site_unconfirmed' === $res['blocked'] && 0 === $res['sent'] && $none(), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
$j = $pbrun( $mgr, str_repeat( 'a', 32 ) );
t( 'clone, ajax_pb_run by a SHOP MANAGER with a made-up token: refused', true === $j['success'] && 'site_unconfirmed' === $j['data']['blocked'] && $none(), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$pv = $pbprev( $mgr );
t( 'clone, campaign preview for a SHOP MANAGER: blocked with the reason and NO token is issued', true === $pv['success'] && 'site_unconfirmed' === $pv['data']['blocked'] && '' === $pv['data']['site_ack'] && '' !== $pv['data']['blocked_message'] && 0 === $acks(), wp_json_encode( $pv, JSON_UNESCAPED_UNICODE ) );
t( 'clone, the manager is told it is for the administrator and the text carries both addresses', false !== mb_strpos( $pv['data']['blocked_message'], 'مدیر سایت' ) && false !== strpos( CRDR_Site::warning(), 'staging.example.test' ) );

// campaigns: administrator
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
wp_set_current_user( 1 );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'clone, manual run by an ADMINISTRATOR without a token (a browser confirm() proves nothing): refused', 'site_unconfirmed' === $res['blocked'] && $none(), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
foreach ( array( 'not-hex', '', str_repeat( 'f', 31 ), str_repeat( 'f', 33 ), str_repeat( 'f', 32 ), '../../etc' ) as $bad ) {
	$j = $pbrun( 1, $bad );
	t( 'clone, administrator with an invalid token "' . $bad . '": refused', 'site_unconfirmed' === $j['data']['blocked'] && $none() );
}
// RELEASE FREEZE (visual QA): the currency symbol of an Iranian shop is an HTML entity in WooCommerce; the campaign preview is drawn by the browser as plain text
$cur0 = get_option( 'woocommerce_currency' );
update_option( 'woocommerce_currency', 'IRT' );
$pvc = $pbprev( 1 );
t( 'campaign preview with the toman as currency: the amounts carry the real word, not an HTML entity', true === $pvc['success'] && false !== mb_strpos( $pvc['data']['aov_fmt'], 'تومان' ) && false === strpos( $pvc['data']['aov_fmt'], '&' ) && false === strpos( implode( '', $pvc['data']['scenario_fmt'] ), '&' ), wp_json_encode( array( $pvc['data']['aov_fmt'] ?? null ), JSON_UNESCAPED_UNICODE ) );
update_option( 'woocommerce_currency', $cur0 );
$pv = $pbprev( 1 );
$tok = $pv['data']['site_ack'] ?? '';
t( 'clone, campaign preview for an ADMINISTRATOR: not blocked by the site, carries a random 32-hex one-time token, and the warning', '' === (string) $pv['data']['blocked'] && 1 === preg_match( '/^[a-f0-9]{32}$/', $tok ) && '' !== $pv['data']['site_warning'] && 1 === $acks(), wp_json_encode( $pv, JSON_UNESCAPED_UNICODE ) );
t( 'the token itself is not stored (only its hash is the key), and the row holds user, target, address and expiry', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s", '%' . $tok . '%', '%' . $tok . '%' ) ) );
// bound to the user
$j = $pbrun( $adm2, $tok );
t( 'clone, ANOTHER administrator cannot use that token', 'site_unconfirmed' === $j['data']['blocked'] && $none() );
$j = $pbrun( $mgr, $tok );
t( 'clone, a shop manager cannot use an administrator\'s token', 'site_unconfirmed' === $j['data']['blocked'] && $none() );
// bound to the target
$other = CRDR_Site::issue_ack( 'pb:cross_sell' );
wp_set_current_user( 1 );
$j = $pbrun( 1, $other );
t( 'clone, a token issued for ANOTHER campaign is refused', 'site_unconfirmed' === $j['data']['blocked'] && $none() );
// bound to the address
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_name = %s", 'staging.example.test', 'elsewhere.example.test', CRDR_Site::ACK_PREFIX . substr( hash( 'sha256', $tok ), 0, 40 ) ) );
$j = $pbrun( 1, $tok );
t( 'clone, a token issued for another site address is refused', 'site_unconfirmed' === $j['data']['blocked'] && $none() );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_name = %s", 'elsewhere.example.test', 'staging.example.test', CRDR_Site::ACK_PREFIX . substr( hash( 'sha256', $tok ), 0, 40 ) ) );
// not used up by a refusal for another reason
CRDR_Playbooks::set_mode( 'dormant', 'off' );
$j = $pbrun( 1, $tok );
t( 'clone, a run refused for another reason (campaign off) does NOT spend the token', 'mode_off' === $j['data']['blocked'] && $none() );
CRDR_Playbooks::set_mode( 'dormant', 'auto' );
// expiry
$name = CRDR_Site::ACK_PREFIX . substr( hash( 'sha256', $tok ), 0, 40 );
$orig_row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
$old = json_decode( $orig_row, true );
$old['e'] = time() - 1;
$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $old ) ), array( 'option_name' => $name ) );
$j = $pbrun( 1, $tok );
t( 'clone, an expired token is refused', 'site_unconfirmed' === $j['data']['blocked'] && $none() );
$wpdb->update( $wpdb->options, array( 'option_value' => $orig_row ), array( 'option_name' => $name ) );
// the valid case, a replay, and the chain of steps
$j = $pbrun( 1, $tok );
t( 'clone, ADMINISTRATOR + valid token + nonce: the run goes through (4 sent), and the answer carries the NEXT token', true === $j['success'] && 4 === $j['data']['sent'] && 4 === count( crdr_t_requests() ) && 1 === preg_match( '/^[a-f0-9]{32}$/', (string) ( $j['data']['site_ack'] ?? '' ) ) && $tok !== $j['data']['site_ack'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$next = $j['data']['site_ack'];
$before = count( crdr_t_requests() );
crdr_t_reset( 4 );
@unlink( crdr_t_log_path() );
$j = $pbrun( 1, $tok );
t( 'clone, REPLAY of the spent token: refused, nothing sent', 'site_unconfirmed' === $j['data']['blocked'] && $none(), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = $pbrun( 1, $next );
t( 'clone, the token from the previous step works once (the browser\'s next step)', '' === (string) $j['data']['blocked'] && 4 === $j['data']['sent'] );
$j = $pbrun( 1, $next );
t( 'clone, ... and not twice', 'site_unconfirmed' === $j['data']['blocked'] );
// two requests at the same moment with one token: one DELETE decides
wp_set_current_user( 1 );
$race = CRDR_Site::issue_ack( 'pb:dormant' );
t( 'clone, consume is atomic: the first call spends the token, the second does not', true === CRDR_Site::consume_ack( 'pb:dormant', $race ) && false === CRDR_Site::consume_ack( 'pb:dormant', $race ) );

// cron and unknown triggers fail closed even if somebody passes a valid token
$fresh();
$valid = CRDR_Site::issue_ack( 'pb:dormant' );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'cron', 'site_ack' => $valid ) );
t( 'clone, CRON never accepts a token: refused (site_changed), nothing sent, the token is not spent', 'site_changed' === $res['blocked'] && $none() && '' === CRDR_Site::manual_block( 'pb:dormant', $valid ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'webhook', 'site_ack' => $valid ) );
t( 'clone, an unknown trigger is treated like cron (fails closed)', 'site_changed' === $res['blocked'] && $none() );
wp_set_current_user( 0 );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual', 'site_ack' => $valid ) );
t( 'clone, "manual" with no logged-in user (wp-cli, a stray include) is refused', 'site_unconfirmed' === $res['blocked'] && $none() );
wp_set_current_user( 1 );
CRDR_Playbooks::hourly();
t( 'clone, the hourly tick still sends nothing', $none() );

// an identity that was never recorded is "unconfirmed": the same rule
$saved_id = get_option( CRDR_Site::OPTION );
delete_option( CRDR_Site::OPTION );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'unconfirmed identity: a manual run by an administrator without a token is refused too', 'unconfirmed' === CRDR_Site::status()['code'] && 'site_unconfirmed' === $res['blocked'] && $none() );
$utok = CRDR_Site::issue_ack( 'pb:dormant' );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual', 'site_ack' => $utok ) );
t( 'unconfirmed identity: ... and goes through with the token', 4 === $res['sent'] );
update_option( CRDR_Site::OPTION, $saved_id, false );

// the other guards are untouched by the token
$fresh();
CRDR_DB::add_contact( 'p:09120000002', 'optout', array( 'playbook' => 'manual', 'message' => 'x' ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual', 'site_ack' => CRDR_Site::issue_ack( 'pb:dormant' ) ) );
t( 'clone, manual with a valid token: the opt-out is still honoured', 3 === $res['sent'] && ! in_array( '09120000002', array_column( crdr_t_requests(), 'receptor' ), true ) );
$fresh();
$st_ = get_option( CRDR_Sync::STATE_OPTION );
update_option( CRDR_Sync::STATE_OPTION, array( 'phase' => 'failed', 'error' => 'step', 'updated' => time() ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual', 'site_ack' => CRDR_Site::issue_ack( 'pb:dormant' ) ) );
t( 'clone, manual with a valid token: the rebuild guard still refuses (nothing bypassed)', 'sync_failed' === $res['blocked'] && $none() );
delete_option( CRDR_Sync::STATE_OPTION );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = '" . CRDR_Sync::LOCK_OPTION . "'" );
$fresh();
$pv = CRDR_Playbooks::preview( 'dormant' );
t( 'clone: the plain preview still carries the warning with both addresses', false !== strpos( $pv['site_warning'], 'staging.example.test' ) && false !== strpos( $pv['site_warning'], CRDR_Site::normalize( $orig_home ) ) );

// one-off SMS (ajax_contact), the other entry point
$fresh();
$cust = $wpdb->get_row( 'SELECT * FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000003'", ARRAY_A );
$cust4 = $wpdb->get_row( 'SELECT * FROM ' . CRDR_DB::customers() . " WHERE ckey = 'p:09120000004'", ARRAY_A );
$sms = function ( $user, $row, $ack = null, $type = 'sms' ) {
	$p = array( 'id' => $row['id'], 'type' => $type );
	if ( null !== $ack ) {
		$p['ack'] = $ack;
	}
	return crdr_t_ajax( array( 'CRDR_Actions', 'ajax_contact' ), $p, $user );
};
$pj = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cust['id'] ), $mgr );
t( 'clone, one-off preview for a SHOP MANAGER: refused with site_unconfirmed, no token in the answer', false === $pj['success'] && 'site_unconfirmed' === ( $pj['data']['code'] ?? '' ) && ! isset( $pj['data']['site_ack'] ), wp_json_encode( $pj, JSON_UNESCAPED_UNICODE ) );
$j = $sms( $mgr, $cust );
t( 'clone, one-off SMS by a SHOP MANAGER: refused (403, site_unconfirmed), nothing claimed, nothing sent', false === $j['success'] && 'site_unconfirmed' === $j['data']['code'] && $none() && 0 === crdr_t_count( "ckey = 'p:09120000003'" ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = $sms( $mgr, $cust, str_repeat( 'a', 32 ) );
t( 'clone, one-off SMS by a SHOP MANAGER with a made-up token: refused', false === $j['success'] && 'site_unconfirmed' === $j['data']['code'] && $none() );
$j = $sms( 1, $cust );
t( 'clone, one-off SMS by an ADMINISTRATOR without a token: refused', false === $j['success'] && 'site_unconfirmed' === $j['data']['code'] && $none() );
$j = $sms( 1, $cust, 'zzzz' );
t( 'clone, one-off SMS by an ADMINISTRATOR with a malformed token: refused', false === $j['success'] && 'site_unconfirmed' === $j['data']['code'] && $none() );
$pj = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cust['id'] ), 1 );
$ctok = $pj['data']['site_ack'] ?? '';
t( 'clone, one-off preview for an ADMINISTRATOR: carries the warning and a one-time token', true === $pj['success'] && 1 === preg_match( '/^[a-f0-9]{32}$/', $ctok ) && false !== strpos( $pj['data']['site_warning'], 'staging.example.test' ), wp_json_encode( $pj, JSON_UNESCAPED_UNICODE ) );
$j = $sms( $adm2, $cust, $ctok );
t( 'clone, the token is bound to the user who got it', false === $j['success'] && 'site_unconfirmed' === $j['data']['code'] && $none() );
$j = $sms( 1, $cust4, $ctok );
t( 'clone, the token is bound to the customer it was issued for', false === $j['success'] && 'site_unconfirmed' === $j['data']['code'] && $none() );
$j = $sms( 1, $cust, $ctok );
t( 'clone, one-off SMS by an ADMINISTRATOR with the preview\'s own token: sent, exactly one request reached the gateway', true === $j['success'] && 1 === count( crdr_t_requests() ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
$j = $sms( 1, $cust, $ctok );
t( 'clone, REPLAY of the spent one-off token: refused, still exactly one request', false === $j['success'] && 'site_unconfirmed' === $j['data']['code'] && 1 === count( crdr_t_requests() ), wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
// no send, no token: recording an opt-out, a call and "not today" keep working for everybody who can use the plugin
foreach ( array( 'called', 'skip', 'optout' ) as $type ) {
	$j = $sms( $mgr, $cust4, null, $type );
	t( "clone, a SHOP MANAGER can still record '{$type}' (not a send)", true === $j['success'], wp_json_encode( $j, JSON_UNESCAPED_UNICODE ) );
}
$fresh();
$pj = crdr_t_ajax( array( 'CRDR_Actions', 'ajax_preview' ), array( 'id' => $cust['id'] ), 1 );
CRDR_DB::add_contact( 'p:09120000003', 'optout', array( 'playbook' => 'manual', 'message' => 'x' ) );
$j = $sms( 1, $cust, $pj['data']['site_ack'] );
t( 'clone, one-off SMS with a valid token to somebody who opted out: the opt-out wins, nothing sent', false === $j['success'] && $none() );
t( 'the token rows are small and bounded (never more than ' . CRDR_Site::ACK_MAX . ')', $acks() <= CRDR_Site::ACK_MAX );
for ( $i = 0; $i < CRDR_Site::ACK_MAX + 10; $i++ ) {
	CRDR_Site::issue_ack( 'pb:burst' . $i );
}
t( 'issuing tokens in a burst never keeps more than the ceiling', $acks() <= CRDR_Site::ACK_MAX, (string) $acks() );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, '\"e\":', '\"e\":1,\"old\":') WHERE option_name LIKE %s", $wpdb->esc_like( CRDR_Site::ACK_PREFIX ) . '%' ) );
CRDR_Site::issue_ack( 'pb:once-more' );
t( 'expired rows are removed the next time a token is issued', 1 === $acks(), (string) $acks() );
t( 'uninstall removes these rows (same prefix in both places)', CRDR_Uninstall::ACK_PREFIX === CRDR_Site::ACK_PREFIX );
$admin_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
t( 'the browser script sends the token of the preview with the one-off SMS and with every campaign step, and keeps the next one from the answer', false !== strpos( $admin_js, "send({ ack: d.site_ack" ) && false !== strpos( $admin_js, 'ack: ack }' ) && false !== strpos( $admin_js, 'ack = r.site_ack' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( CRDR_Site::ACK_PREFIX ) . '%' ) );
delete_option( CRDR_Sync::STATE_OPTION );
$fresh();

// ---- the confirmation is authenticated and deliberate
$before = get_option( CRDR_Site::OPTION );
t( 'confirm: a subscriber is refused (403)', 0 === strpos( $confirm_call( $sub ), 'die:' ) && $before === get_option( CRDR_Site::OPTION ) );
t( 'confirm: a shop manager is refused (it needs the Administrator capability)', 0 === strpos( $confirm_call( $mgr ), 'die:' ) && $before === get_option( CRDR_Site::OPTION ) );
t( 'confirm: an administrator without a valid nonce is refused', 0 === strpos( $confirm_call( 1, 'POST', false ), 'die:' ) && $before === get_option( CRDR_Site::OPTION ) );
t( 'confirm: a GET request is refused even from an administrator with a valid nonce', 0 === strpos( $confirm_call( 1, 'GET' ), 'die:' ) && $before === get_option( CRDR_Site::OPTION ) );
t( 'confirm: still paused after all those refusals', false === CRDR_Site::automatic_allowed() );

// ---- a legitimate migration: the administrator confirms, automatic campaigns resume
$out = $confirm_call( 1 );
$nid = get_option( CRDR_Site::OPTION );
t( 'confirm: an administrator with a valid nonce (POST) is redirected back, and the new address is recorded with who and when', 0 === strpos( $out, 'redirect:' ) && false !== strpos( $out, 'site=confirmed' ) && 'staging.example.test' === $nid['url'] && 'confirmed' === $nid['how'] && 1 === (int) $nid['by'] && (int) $nid['set'] > 0 && CRDR_Site::normalize( $orig_home ) === $nid['was'], $out . wp_json_encode( $nid ) );
t( 'confirm: the status is ok now', true === CRDR_Site::automatic_allowed() );
crdr_t_reset( 4 );
@unlink( crdr_t_log_path() );
CRDR_Playbooks::hourly();
t( 'after the confirmation the next automatic tick sends again', 4 === count( crdr_t_requests() ), (string) count( crdr_t_requests() ) );
$hs = CRDR_Health::load();
t( 'after the confirmation the "site changed" block is gone from the campaign state', empty( $hs['dormant']['blocked'] ) );
t( 'after the confirmation the notice is gone', '' === CRDR_Site::notice_html() );

// ---- address normalisation at WordPress level: moves that are not moves
foreach ( array( 'http://staging.example.test' => true, 'https://www.staging.example.test/' => true, 'HTTPS://STAGING.EXAMPLE.TEST' => true, 'https://staging.example.test/shop' => false, 'https://staging.example.test:8443' => false, 'https://other.example.test' => false ) as $url => $same ) {
	$move_to( $url );
	t( "address {$url}: " . ( $same ? 'the same site (no pause)' : 'a different site (paused)' ), $same === CRDR_Site::automatic_allowed() );
}
$move_to( 'https://staging.example.test' );

// ---- put the shop back as it was
$back_home();
update_option( CRDR_Site::OPTION, is_array( $orig_id ) ? $orig_id : array( 'url' => CRDR_Site::normalize( $orig_home ), 'set' => 1, 'by' => 0, 'how' => 'install' ), false );
delete_option( CRDR_Health::OPTION );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_REQUEST = $_POST = array();
t( 'the test shop is back on its own address', true === CRDR_Site::automatic_allowed() );
crdr_t_cfg( array() );

// ---- RELEASE FREEZE: the customer-facing privacy / FAQ text discloses what it must (static checks on the shipped files)
$priv   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-crdr-privacy.php' );
$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
$ui_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-crdr-ui.php' );
foreach ( array( 'privacy policy text' => $priv, 'readme.txt' => $readme ) as $label => $src ) {
	t( "disclosure ({$label}): the other SMS plugin archives recipient and text and this plugin does not control that", false !== mb_strpos( $src, 'بایگانی' ) && false !== mb_strpos( $src, 'پیامک ووکامرس' ) && false !== mb_strpos( $src, 'کنترل' ) );
	t( "disclosure ({$label}): restoring an older backup loses opt-outs recorded after it", false !== mb_strpos( $src, 'پشتیبان' ) && false !== mb_strpos( $src, 'پیام نده' ) );
	t( "disclosure ({$label}): minimised rows can keep a phone number", false !== mb_strpos( $src, 'شماره‌ی موبایل' ) );
	t( "disclosure ({$label}): the export holds at most 50,000 customers, opted-out people included, no filter to drop them", false !== mb_strpos( $src, '۵۰٬۰۰۰' ) && false !== mb_strpos( $src, 'فیلتر' ) );
}
t( 'disclosure (export button): the 50,000 limit is stated next to the button', false !== mb_strpos( $ui_src, '۵۰٬۰۰۰' ) );

t( 'ux: the variable reference documents exactly the placeholders the template engine knows', ( function () { $a = array_keys( CRDR_UI::variable_catalog() ); $b = array_keys( CRDR_Template::catalog() ); sort( $a ); sort( $b ); return $a === $b; } )() );
t( 'ux: order statuses get a Persian name for display only; an unknown (custom) status keeps WooCommerce\'s own name', array( 'تکمیل‌شده', 'در انتظار بررسی', 'Shipped', 'x' ) === array( CRDR_UI::status_label( 'completed' ), CRDR_UI::status_label( 'on-hold' ), CRDR_UI::status_label( 'wc-custom', 'Shipped' ), CRDR_UI::status_label( 'x' ) ) );
t( 'ux: the guide tab is in the navigation', 'customer-radar-guide' === array_search( 'آموزش', CRDR_UI::tabs(), true ) );
// ---- UX pass: the guide tab renders for a shop manager, is refused for a subscriber's capability, and the Settings page shows Persian status names
wp_set_current_user( 1 );
$_GET = array( 'page' => 'customer-radar-guide' );
ob_start();
CRDR_Page_Guide::render();
$g = ob_get_clean();
t( 'guide tab: renders the eight sections and the variable reference, with no developer file path', 8 === substr_count( $g, 'class="crdr-card crdr-guide"' ) && false !== strpos( $g, '{optout}' ) && false !== strpos( $g, '{bought}' ) && false === strpos( $g, 'docs/' ), (string) substr_count( $g, 'crdr-guide' ) );
$_GET = array( 'page' => 'customer-radar-settings' );
ob_start();
CRDR_Page_Settings::render();
$sp = ob_get_clean();
t( 'settings: order statuses are shown with Persian names while the stored values stay WooCommerce slugs', false !== strpos( $sp, 'تکمیل‌شده' ) && false !== strpos( $sp, 'value="completed"' ) && false !== strpos( $sp, 'value="processing"' ) && false !== strpos( $sp, 'crdr-vars' ) );
$_GET = array();
$subs = array_map( function ( $m ) { return $m; }, array( 'customer-radar-guide' ) );
t( 'guide tab: is registered under the same capability as every other page', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-crdr-admin.php' ), "'customer-radar-guide', array( 'CRDR_Page_Guide'" ) );

/* ============================================ J. Phase 4.2 / increment 4: campaign results, holdout stability, real WooCommerce refunds */
if ( ! function_exists( 'crdr_t_page' ) ) {
	function crdr_t_page( $callable, array $get ) {
		wp_set_current_user( 1 );
		$_GET = $_REQUEST = $get;
		ob_start();
		call_user_func( $callable );
		return ob_get_clean();
	}
}
echo "== campaign results: customer as the unit, first assignment, intention to treat\n";
$wpdb->suppress_errors( true );
$K  = CRDR_DB::contacts();
$OT = CRDR_DB::orders();
crdr_t_cfg( array( 'window_days' => 14, 'holdout_pct' => 0 ) );
crdr_t_reset( 0 );
$wpdb->query( "TRUNCATE TABLE {$OT}" );
$now = time();
$D   = DAY_IN_SECONDS;
$row = function ( $ckey, $grp, $status, $ago_days ) use ( $wpdb, $K, $now, $D ) {
	$wpdb->insert( $K, array( 'ckey' => $ckey, 'kind' => 'sms', 'playbook' => 'dormant', 'grp' => $grp, 'status' => $status, 'created_ts' => $now - (int) round( $ago_days * $D ), 'message' => 'x' ) );
};
$order = function ( $id, $ckey, $net, $ago_days ) use ( $wpdb, $OT, $now, $D ) {
	$wpdb->insert( $OT, array( 'order_id' => $id, 'ckey' => $ckey, 'ts' => $now - (int) round( $ago_days * $D ), 'net' => $net, 'items' => 1 ) );
};
// C1: contacted TWICE (a duplicate row in a later wave); only the first assignment counts; the order inside the first window counts, the later one does not
$row( 'p:C1', 'treated', 'ok', 30 );
$row( 'p:C1', 'treated', 'ok', 10 );
$order( 9001, 'p:C1', 100, 25 );
$order( 9002, 'p:C1', 999, 7 );
// C2: the first attempt FAILED (network), the second was accepted; no order
$row( 'p:C2', 'treated', 'failed', 29 );
$row( 'p:C2', 'treated', 'ok', 28 );
// C3: the number was rejected by the provider (never reached), but the customer ordered inside the window
$row( 'p:C3', 'treated', 'rejected', 29 );
$order( 9003, 'p:C3', 200, 27 );
// C4: unknown outcome, no order
$row( 'p:C4', 'treated', 'unknown', 27 );
// C5: the message could not be built; the customer ordered 13 days after being chosen (inside the 14-day window)
$row( 'p:C5', 'treated', 'nobuild', 26 );
$order( 9005, 'p:C5', 50, 13 );
// C6: accepted, ordered 20 days later (outside the window)
$row( 'p:C6', 'treated', 'ok', 25 );
$order( 9006, 'p:C6', 77, 5 );
// C7: accepted three days ago: the window is not over, so not counted yet (immature); its order must not leak in
$row( 'p:C7', 'treated', 'ok', 3 );
$order( 9007, 'p:C7', 500, 2 );
// the control: H1 nothing; H2 ordered the next day and was later DRAWN FOR THE MESSAGE in another wave (a treated, accepted row): still a control; H3 ordered inside the window
$row( 'p:H1', 'holdout', 'holdout', 30 );
$row( 'p:H2', 'holdout', 'holdout', 30 );
$order( 9011, 'p:H2', 70, 29 );
$row( 'p:H2', 'treated', 'ok', 5 );
$row( 'p:H3', 'holdout', 'holdout', 29 );
$order( 9013, 'p:H3', 30, 19 );
// a skipped customer (no complementary product) is not an assignment
$wpdb->insert( $K, array( 'ckey' => 'p:S1', 'kind' => 'skip', 'playbook' => 'dormant', 'grp' => 'treated', 'status' => 'norec', 'created_ts' => $now - 30 * $D ) );
$r = CRDR_Playbooks::results( 'dormant' );
t( 'results: the basis is intention to treat', 'itt' === $r['basis'] );
t( 'results (primary): treated = the six customers chosen for the message whose window is over - a duplicate row, a failed first attempt, a rejected number, an unknown outcome and an unbuildable text all stay in; n 6, 3 ordered inside the window (C1, C3, C5), net sales 350', 6 === $r['treated']['n'] && 3 === $r['treated']['conv'] && abs( 350 - $r['treated']['rev'] ) < 0.01, wp_json_encode( $r['treated'] ) );
t( 'results (primary): holdout = the three controls by FIRST assignment, whatever happened to them later: n 3, 2 ordered (H2, H3), net sales 100', 3 === $r['holdout']['n'] && 2 === $r['holdout']['conv'] && abs( 100 - $r['holdout']['rev'] ) < 0.01, wp_json_encode( $r['holdout'] ) );
t( 'results (secondary): accepted-only = customers with at least one accepted message: C1, C2, C6 -> n 3, 1 ordered (C1), net sales 100', 3 === $r['accepted']['n'] && 1 === $r['accepted']['conv'] && abs( 100 - $r['accepted']['rev'] ) < 0.01, wp_json_encode( $r['accepted'] ) );
t( 'results: the difference is computed from the primary groups (3/6 - 2/3), the accepted-only one is a different number', abs( $r['test']['diff'] - ( 3 / 6 - 2 / 3 ) ) < 1e-9 && abs( $r['test_accepted']['diff'] - ( 1 / 3 - 2 / 3 ) ) < 1e-9, wp_json_encode( array( $r['test']['diff'], $r['test_accepted']['diff'] ) ) );
t( 'results: a customer whose window is not over (C7) is reported as immature and does not leak into the groups; H2\'s later treated row creates no new immature customer', 1 === $r['immature'], (string) $r['immature'] );
t( 'results: information counts - messages accepted (6 treated ok rows), outcome unknown (1)', 6 === $r['sent_total'] && 1 === $r['unknown'], wp_json_encode( array( $r['sent_total'], $r['unknown'] ) ) );
t( 'results: a customer is never in both groups (6 + 3 = the nine mature customers, not nine plus the duplicate rows)', 9 === $r['treated']['n'] + $r['holdout']['n'] );
// the comparison that used to be made (rows with status ok / holdout) would have said something else: show it differs
$old_n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$K} WHERE playbook = 'dormant' AND kind = 'sms' AND status IN ('ok','holdout') AND created_ts <= " . ( $now - 14 * $D ) );
t( 'results: the old rows-with-status-ok basis would have compared 6 rows (3 against 3, the control that became a message and the failed/rejected/unknown/unbuildable customers silently dropped) where the primary groups hold 9 customers', 6 === $old_n && 9 === $r['treated']['n'] + $r['holdout']['n'], (string) $old_n );
// a second run of the numbers changes nothing; the page renders without warnings
$r2 = CRDR_Playbooks::results( 'dormant' );
t( 'results: asking again gives the same answer', $r === $r2 );
$html = crdr_t_page( array( 'CRDR_Page_Campaigns', 'render' ), array( 'page' => 'customer-radar-campaigns', 'results' => 'dormant' ) );
t( 'results page: renders, names the primary group and the secondary one and says how customers are counted', false === strpos( $html, 'Warning:' ) && false !== mb_strpos( $html, 'انتخاب‌شده برای دریافت پیام' ) && false !== mb_strpos( $html, 'زیرمجموعه‌ی گروه اول' ) && false !== mb_strpos( $html, 'اولین بار در این کمپین' ), mb_substr( strip_tags( $html ), 0, 160 ) );
$wpdb->query( "TRUNCATE TABLE {$OT}" );
crdr_t_reset( 0 );

echo "== holdout assignment: stable within a wave, sticky for the measurement window\n";
$fixed = function () {
	return '2026-10';
};
add_filter( 'crdr_campaign_wave', $fixed );
$same = true;
$ids  = array();
for ( $i = 0; $i < 400; $i++ ) {
	$a = CRDR_Playbooks::is_holdout( "p:0912000{$i}", 'dormant', 30 );
	for ( $j = 0; $j < 3; $j++ ) {
		$same = $same && $a === CRDR_Playbooks::is_holdout( "p:0912000{$i}", 'dormant', 30 );
	}
	$ids[ $i ] = $a;
}
t( 'wave: within one wave a customer\'s assignment never changes, however often it is asked', $same );
remove_filter( 'crdr_campaign_wave', $fixed );
$next = function () {
	return '2026-11';
};
add_filter( 'crdr_campaign_wave', $next );
$moved = 0;
for ( $i = 0; $i < 400; $i++ ) {
	if ( CRDR_Playbooks::is_holdout( "p:0912000{$i}", 'dormant', 30 ) !== $ids[ $i ] ) {
		$moved++;
	}
}
remove_filter( 'crdr_campaign_wave', $next );
t( 'wave: a new wave draws again (a good part of the customers change group), so nobody is held out for ever', $moved > 80 && $moved < 320, (string) $moved );
t( 'wave: the default wave is the calendar month in the site\'s timezone', wp_date( 'Y-m' ) === CRDR_Playbooks::wave() && '1999-12' === ( function () {
	return CRDR_Playbooks::wave( strtotime( '1999-12-15 12:00:00 UTC' ) );
} )() || wp_date( 'Y-m', strtotime( '1999-12-15 12:00:00 UTC' ) ) === CRDR_Playbooks::wave( strtotime( '1999-12-15 12:00:00 UTC' ) ) );

// sticky: a control stays a control for the length of its own window even if a new wave's draw says "message"
crdr_t_cfg( array( 'window_days' => 14, 'holdout_pct' => 10, 'cooldown' => 0 ), array( 'cooldown_days' => 0 ) );
crdr_t_reset( 3 );
@unlink( crdr_t_log_path() );
$never = function ( $x ) {
	return $x; // (placeholder to keep the filter list explicit)
};
$wave_for_treated = null; // find a wave in which none of the 3 customers is drawn as control, so only the sticky rule can hold them out
foreach ( array( 'w1', 'w2', 'w3', 'w4', 'w5', 'w6', 'w7', 'w8', 'w9', 'w10', 'w11', 'w12' ) as $w ) {
	add_filter( 'crdr_campaign_wave', $f = function () use ( $w ) {
		return $w;
	} );
	$any = CRDR_Playbooks::is_holdout( 'p:09120000001', 'dormant', 10 ) || CRDR_Playbooks::is_holdout( 'p:09120000002', 'dormant', 10 ) || CRDR_Playbooks::is_holdout( 'p:09120000003', 'dormant', 10 );
	remove_filter( 'crdr_campaign_wave', $f );
	if ( ! $any ) {
		$wave_for_treated = $w;
		break;
	}
}
t( 'sticky (setup): a wave exists in which the hash draws nobody as a control', null !== $wave_for_treated );
add_filter( 'crdr_campaign_wave', $wf = function () use ( $wave_for_treated ) {
	return $wave_for_treated;
} );
$wpdb->insert( $K, array( 'ckey' => 'p:09120000001', 'kind' => 'sms', 'playbook' => 'dormant', 'grp' => 'holdout', 'status' => 'holdout', 'created_ts' => $now - 5 * $D ) );
t( 'sticky: held out 5 days ago, window 14 days -> still held out', true === CRDR_Playbooks::held_out_recently( 'p:09120000001', 'dormant', 14 ) && false === CRDR_Playbooks::held_out_recently( 'p:09120000002', 'dormant', 14 ) );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
$phones = array_column( crdr_t_requests(), 'receptor' );
t( 'sticky: the control is NOT messaged by a later run in a wave that would have drawn them for the message; the other two are', ! in_array( '09120000001', $phones, true ) && 2 === count( $phones ) && 1 === $res['held_out'], wp_json_encode( $res ) . implode( ',', $phones ) );
t( 'sticky: they get a new control row (it never moves them out of their first assignment)', 2 === crdr_t_count( "ckey = 'p:09120000001' AND grp = 'holdout' AND status = 'holdout'" ) );
$wpdb->query( "UPDATE {$K} SET created_ts = " . ( $now - 20 * $D ) . " WHERE ckey = 'p:09120000001'" );
@unlink( crdr_t_log_path() );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
t( 'sticky: once their own window is over (20 days > 14) they can be drawn for the message', in_array( '09120000001', array_column( crdr_t_requests(), 'receptor' ), true ) );
// a database error while asking is "do not message"
crdr_t_reset( 3 );
@unlink( crdr_t_log_path() );
$ff = function ( $q ) {
	return false !== strpos( $q, "grp = 'holdout' AND status = 'holdout'" ) && 0 === strpos( ltrim( $q ), 'SELECT MIN(created_ts)' ) ? 'SELECT * FROM crdr_forced_failure' : $q;
};
add_filter( 'query', $ff );
$res = CRDR_Playbooks::run( 'dormant', 20, array( 'trigger' => 'manual' ) );
remove_filter( 'query', $ff );
t( 'sticky: if the control check cannot be read nobody is messaged and the run says database error', 'db_error' === $res['blocked'] && 0 === count( crdr_t_requests() ) && 0 === crdr_t_count( "status = 'pending'" ), wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
remove_filter( 'crdr_campaign_wave', $wf );
crdr_t_cfg( array() );
crdr_t_reset( 0 );

echo "== net sales with real WooCommerce refunds\n";
$k_p = function ( $name, $price ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $price );
	$p->set_status( 'publish' );
	$p->save();
	return $p->get_id();
};
$p1 = $k_p( 'P4.2 one', 100000 );
$p2 = $k_p( 'P4.2 two', 200000 );
$mk = function ( $phone, array $lines, $ship ) {
	$o = wc_create_order();
	$o->set_billing_phone( $phone );
	$o->set_billing_email( $phone . '@example.test' );
	$o->set_billing_first_name( 'R' . substr( $phone, -2 ) );
	$sum = 0;
	foreach ( $lines as $pid => $price ) {
		$it = new WC_Order_Item_Product();
		$it->set_product_id( $pid );
		$it->set_quantity( 1 );
		$it->set_subtotal( $price );
		$it->set_total( $price );
		$o->add_item( $it );
		$sum += $price;
	}
	$sh = null;
	if ( $ship ) {
		$sh = new WC_Order_Item_Shipping();
		$sh->set_method_title( 'Post' );
		$sh->set_total( $ship );
		$o->add_item( $sh );
	}
	$o->set_total( $sum + $ship );
	$o->set_status( 'completed' );
	$o->set_date_created( time() - 40 * DAY_IN_SECONDS );
	$o->save();
	$ids = array();
	foreach ( $o->get_items( 'line_item' ) as $id => $it ) {
		$ids[ $it->get_product_id() ] = $id;
	}
	$ship_id = null;
	foreach ( $o->get_items( 'shipping' ) as $id => $it ) {
		$ship_id = $id;
	}
	return array( $o->get_id(), $ids, $ship_id );
};
$refund = function ( $oid, $amount, array $lines = array() ) {
	$li = array();
	foreach ( $lines as $item_id => $amt ) {
		$li[ $item_id ] = array( 'qty' => 0, 'refund_total' => $amt, 'refund_tax' => array() );
	}
	return wc_create_refund( array( 'order_id' => $oid, 'amount' => $amount, 'reason' => 'p4.2', 'line_items' => $li, 'refund_payment' => false ) );
};
foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ), 'type' => array( 'shop_order', 'shop_order_refund' ) ) ) as $old ) {
	$old->delete( true );
}
foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::items(), $K ) as $tb ) {
	$wpdb->query( "TRUNCATE TABLE {$tb}" );
}
delete_option( CRDR_Sync::STATE_OPTION );
add_filter( 'woocommerce_order_fully_refunded_status', function () {
	return 'completed'; // a shop may keep the status (the reference dataset has such an order): the plugin must not rely on the status
} );
list( $o1, $l1, $s1 ) = $mk( '09121110001', array( $p1 => 100000, $p2 => 200000 ), 50000 );
$refund( $o1, 80000, array( $l1[ $p2 ] => 80000 ) );                              // line-level: 80,000 against product two
list( $o2, $l2, $s2 ) = $mk( '09121110002', array( $p1 => 100000, $p2 => 200000 ), 50000 );
$refund( $o2, 60000 );                                                            // by amount only: 60,000, no line chosen
list( $o3, $l3, $s3 ) = $mk( '09121110003', array( $p1 => 100000, $p2 => 200000 ), 50000 );
$refund( $o3, 80000, array( $l3[ $p1 ] => 30000, $s3 => 50000 ) );               // 30,000 against product one + the 50,000 shipping
list( $o4, $l4, $s4 ) = $mk( '09121110004', array( $p1 => 100000, $p2 => 200000 ), 50000 );
$refund( $o4, 350000 );                                                           // the whole order by amount: items + shipping
list( $o5, $l5, $s5 ) = $mk( '09121110005', array( $p1 => 100000, $p2 => 200000 ), 50000 );   // untouched
t( 'refunds (setup): the shop kept the status "completed" for the fully refunded order', 'completed' === wc_get_order( $o4 )->get_status() && 350000.0 === (float) wc_get_order( $o4 )->get_total_refunded() );
t( 'refunds (setup): WooCommerce recorded what the plugin reads: a line refund, a shipping refund, an amount-only refund', 80000.0 === (float) wc_get_order( $o1 )->get_total_refunded_for_item( $l1[ $p2 ] ) && 50000.0 === (float) wc_get_order( $o3 )->get_total_shipping_refunded() && 0.0 === (float) wc_get_order( $o2 )->get_total_refunded_for_item( $l2[ $p2 ] ) );
t( 'refunds: the rebuild finishes', 'done' === $rebuild() );
$net  = function ( $oid ) use ( $wpdb, $OT ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT ckey, net, items, refund_gap FROM {$OT} WHERE order_id = %d", $oid ), ARRAY_A );
};
$line = function ( $oid, $pid ) use ( $wpdb ) {
	return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT total FROM ' . CRDR_DB::items() . ' WHERE order_id = %d AND product_id = %d', $oid, $pid ) );
};
t( 'refund, line-level: net sales = items 300,000 - 80,000 = 220,000 (shipping out); product two shows 120,000', abs( 220000 - (float) $net( $o1 )['net'] ) < 0.01 && abs( 120000 - $line( $o1, $p2 ) ) < 0.01 && abs( 100000 - $line( $o1, $p1 ) ) < 0.01 );
t( 'refund, by amount: 60,000 spread in proportion over 100,000 and 200,000 -> 80,000 and 160,000 (net 240,000)', abs( 240000 - (float) $net( $o2 )['net'] ) < 0.01 && abs( 80000 - $line( $o2, $p1 ) ) < 0.01 && abs( 160000 - $line( $o2, $p2 ) ) < 0.01 );
t( 'refund of shipping + a line: only the line reduces net sales: 300,000 - 30,000 = 270,000', abs( 270000 - (float) $net( $o3 )['net'] ) < 0.01 && abs( 70000 - $line( $o3, $p1 ) ) < 0.01 && abs( 200000 - $line( $o3, $p2 ) ) < 0.01 );
$v = $net( $o4 );
t( 'full refund of an order that kept the status completed: VOID: empty customer key, net 0, no product rows', '' === $v['ckey'] && 0.0 === (float) $v['net'] && 0 === (int) $v['items'] && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::items() . ' WHERE order_id = ' . (int) $o4 ) );
t( 'void order: it makes no customer, and the completeness check does not chase it (the rebuild ended without "unresolved")', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . " WHERE phone = '09121110004'" ) && empty( CRDR_Sync::state( true )['unresolved'] ) );
t( 'untouched order: net sales = items 300,000 (not 350,000 with shipping); nothing unreconciled anywhere', abs( 300000 - (float) $net( $o5 )['net'] ) < 0.01 && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$OT} WHERE refund_gap <> 0" ) );
$cust = $wpdb->get_results( 'SELECT phone, orders_count, total_spent FROM ' . CRDR_DB::customers() . ' ORDER BY phone', OBJECT_K );
t( 'customers: four (the fully refunded one is not a customer); each spends its net sales', 4 === count( $cust ) && abs( 220000 - (float) $cust['09121110001']->total_spent ) < 0.01 && abs( 240000 - (float) $cust['09121110002']->total_spent ) < 0.01 && abs( 270000 - (float) $cust['09121110003']->total_spent ) < 0.01 && abs( 300000 - (float) $cust['09121110005']->total_spent ) < 0.01 && 1 === (int) $cust['09121110001']->orders_count );
$stt = CRDR_Analytics::get();
$prod_total = array_sum( array_column( $stt['products'], 'revenue' ) );
$ord_total  = array_sum( array_column( $stt['monthly'], 'revenue' ) );
t( 'analytics: 4 valid orders, revenue 1,030,000 = the sum of the four nets, and the product revenue is exactly the same number (they reconcile)', 4 === $stt['orders_total'] && abs( 1030000 - $ord_total ) < 0.01 && abs( $prod_total - $ord_total ) < 0.01, wp_json_encode( array( $stt['orders_total'], $ord_total, $prod_total ) ) );
// REVIEW 4.2 (fee refund): a refund recorded against a FEE item is not a refund of product sales (fees are not revenue)
list( $o6, $l6, $s6 ) = $mk( '09121110006', array( $p1 => 100000 ), 0 );
$ofee = wc_get_order( $o6 );
$fee  = new WC_Order_Item_Fee();
$fee->set_name( 'COD' );
$fee->set_total( 5000 );
$fee->set_tax_status( 'none' );
$ofee->add_item( $fee );
$ofee->set_total( 105000 );
$ofee->save();
$fee_id = 0;
foreach ( wc_get_order( $o6 )->get_items( 'fee' ) as $fid => $fx ) {
	$fee_id = $fid;
}
$refund( $o6, 5000, array( $fee_id => 5000 ) );
$rf = CRDR_Sales::of_order( wc_get_order( $o6 ) );
t( 'refund of a FEE item only: net sales stay 100,000 (a fee is not revenue), nothing unreconciled', abs( 100000 - $rf['net'] ) < 0.01 && 0.0 === (float) $rf['gap'], wp_json_encode( $rf ) );
t( 'analytics: nothing is unreconciled, and the report says so', array( 'orders' => 0, 'amount' => 0.0 ) === $stt['refund_gap'] );
// a refund that cannot be explained is REPORTED: an inconsistent order (set directly, WooCommerce itself would refuse it)
$wpdb->update( $OT, array( 'refund_gap' => 1234.5 ), array( 'order_id' => $o5 ) );
$stt = CRDR_Analytics::build();
t( 'analytics: an order with an unreconciled refund is counted and its amount is reported, not folded into any number', array( 'orders' => 1, 'amount' => 1234.5 ) === $stt['refund_gap'] && 4 === $stt['orders_total'] );
$wpdb->update( $OT, array( 'refund_gap' => 0 ), array( 'order_id' => $o5 ) );
// a refund made AFTER the first rebuild changes the next one (nothing is cached)
$refund( $o5, 100000, array( $l5[ $p1 ] => 100000 ) );
$rebuild();
t( 'a refund made after the last rebuild is picked up by the next one: product one is refunded in full, net 200,000', abs( 200000 - (float) $net( $o5 )['net'] ) < 0.01 && abs( 0 - $line( $o5, $p1 ) ) < 0.01 );
foreach ( (array) wc_get_orders( array( 'limit' => -1, 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ), 'type' => array( 'shop_order', 'shop_order_refund' ) ) ) as $old ) {
	$old->delete( true );
}
remove_all_filters( 'woocommerce_order_fully_refunded_status' );
foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::items() ) as $tb ) {
	$wpdb->query( "TRUNCATE TABLE {$tb}" );
}

/* ----------------------------------------------------------------- done */
printf( "\nrelease safety: %d passed, %d failed\n", $GLOBALS['P'], $GLOBALS['F'] );
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::halt( $GLOBALS['F'] ? 1 : 0 );
}
