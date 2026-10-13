<?php
/**
 * Sync guard tests: campaign / SMS / customer-list operations are refused while the customer data is being rebuilt.
 *
 *   php tests/sync-guard.php
 *
 * Runs the real CRDR_Sync, CRDR_Send, CRDR_Playbooks, CRDR_Health code on the stand-ins of tests/analytics/harness.php: no
 * WordPress, no database, no network. What that can NOT show (the database really makes the lock atomic, the AJAX / page /
 * export handlers answer correctly end to end) is tested against a real database in tests/phase1-safety.php, section
 * "sync guard". Static tests ([static]) read the plugin source; they exist to catch a NEW unguarded entry point.
 */
require __DIR__ . '/analytics/harness.php';

$GLOBALS['CRDR_R'] = array( 'pass' => 0, 'fail' => 0 );
function check( $name, $got, $want ) {
	if ( $got === $want ) {
		$GLOBALS['CRDR_R']['pass']++;
		return;
	}
	$GLOBALS['CRDR_R']['fail']++;
	echo "FAIL  $name\n      got:  " . json_encode( $got, JSON_UNESCAPED_UNICODE ) . "\n      want: " . json_encode( $want, JSON_UNESCAPED_UNICODE ) . "\n";
}
function section( $t ) {
	echo "== $t\n";
}

/* ------------------------------------------------------------------ helpers */

/** A fresh world: empty options / database / gateway log, site timezone Asia/Tehran, sending allowed 00-24. */
function crdr_t_world( array $settings = array() ) {
	CRDR_T::reset();
	$GLOBALS['CRDR_T']['transients']    = array();
	$GLOBALS['CRDR_T']['scheduled']     = array();
	$GLOBALS['CRDR_T']['gateway_calls'] = array();
	$GLOBALS['CRDR_T']['wc_orders']     = array();
	$GLOBALS['CRDR_T']['wc_queries']    = array();
	unset( $GLOBALS['CRDR_T']['wc_throw_on_page'], $GLOBALS['CRDR_T']['wc_hook'] );
	update_option( 'crdr_settings', array_merge( array( 'sms_provider' => 'kavenegar', 'kavenegar_key' => 'TESTKEY', 'send_from' => 0, 'send_to' => 24 ), $settings ) );
	return $GLOBALS['wpdb'];
}
function crdr_t_set_state( $phase, array $extra = array() ) {
	update_option( CRDR_Sync::STATE_OPTION, array_merge( array( 'phase' => $phase, 'updated' => time(), 'page' => 1, 'total' => 450, 'done' => 0, 'inflight' => 0 ), $extra ) );
}
function crdr_t_state() {
	return get_option( CRDR_Sync::STATE_OPTION );
}
function crdr_t_phase() {
	$s = crdr_t_state();
	return $s['phase'];
}
function crdr_t_gateway() {
	return count( $GLOBALS['CRDR_T']['gateway_calls'] );
}
/** The database as a campaign run needs it: one eligible customer. */
function crdr_t_ready_db( $db ) {
	$db->on( 'GET_LOCK', '1' );
	$db->on( 'RELEASE_LOCK', 1 );
	$db->on( 'SET message = ', 1 ); // CRDR_Send::annotate()
	// Phase 4.2: send_claimed() reads the claimed row's customer key (it will not send a claim it cannot read). The stand-in answers like the
	// database would: the key of the contact row that claim() inserted last under that id.
	$db->on( 'SELECT ckey FROM wp_crdr_contacts WHERE id', function ( $sql ) use ( $db ) {
		return preg_match( '/WHERE id = (\d+)/', $sql, $m ) && isset( $db->inserted[ (int) $m[1] - 1 ] ) ? $db->inserted[ (int) $m[1] - 1 ][1]['ckey'] : null;
	} );
	$db->on( 'SUM(total_spent) spent', array( 'n' => 1, 'spent' => 3000000, 'orders' => 3 ) ); // the audience summary
	$db->on( 'SELECT * FROM wp_crdr_customers c', array( array( 'id' => 1, 'ckey' => 'p:09120000001', 'name' => 'علی', 'phone' => '09120000001', 'email' => '', 'orders_count' => 3, 'total_spent' => 3000000, 'last_ts' => time() - 86400 * 40, 'avg_gap_days' => 30 ) ) );
	update_option( 'crdr_playbooks', array( 'reminder' => array( 'mode' => 'manual', 'holdout_pct' => 0 ) ) );
}
/** Synthetic store with $n completed orders, each from a different customer. */
function crdr_t_store( $n ) {
	$o = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$o[] = array( 'id' => $i, 'status' => 'completed', 'ts' => 1790000000 + $i * 60, 'uid' => 0, 'phone' => sprintf( '0912%07d', $i ), 'email' => '', 'items' => array( array( 1, 1000 ) ), 'shipping' => 0, 'tax' => 0, 'total' => 1000, 'refunded' => 0 );
	}
	$GLOBALS['CRDR_T']['wc_orders'] = $o;
}
function crdr_t_body( $file, $function ) {
	$src = CRDR_T::source( $file );
	if ( ! preg_match( '/\n\t(?:public|private|protected) static function ' . preg_quote( $function, '/' ) . '\(.*?(?=\n\t(?:public|private|protected) static function |\n\t\/\*|\n}\n)/s', $src, $m ) ) {
		return '';
	}
	return $m[0];
}
function crdr_t_guarded( $body ) {
	return 1 === preg_match( '/CRDR_Sync::(?:send_)?guard\(|sync_block_ajax\(|sync_blocked\(/', $body ); // send_guard() IS guard() plus the update check
}

/* ====================================================================================== 1. the guard itself */
section( 'guard(): which states may use the customer data' );
$cases = array(
	'idle'     => false, // never run: nothing to protect
	'done'     => false,
	'starting' => 'sync_running',
	'orders'   => 'sync_running',
	'finalize' => 'sync_running',
	'stats'    => 'sync_running',
	'failed'   => 'sync_failed',
	'weird'    => 'sync_failed', // a phase this version does not know is never assumed to be safe
);
foreach ( $cases as $phase => $want ) {
	crdr_t_world();
	crdr_t_set_state( $phase );
	$g = CRDR_Sync::guard();
	check( "phase '$phase'", $g['blocked'] ? $g['code'] : false, $want );
}
crdr_t_world();
check( 'no state stored at all (a shop that never synced): allowed', CRDR_Sync::guard()['blocked'], false );
update_option( CRDR_Sync::STATE_OPTION, 'garbage' );
check( 'a stored state that is not readable is unsafe, not "idle"', CRDR_Sync::guard()['code'], 'sync_failed' );
crdr_t_world();
crdr_t_set_state( 'orders', array( 'updated' => time() - CRDR_Sync::STALL_AFTER - 60 ) );
check( 'a rebuild with no progress for over 30 minutes is "stalled" and STILL blocks', array( CRDR_Sync::guard()['blocked'], CRDR_Sync::guard()['code'] ), array( true, 'sync_stalled' ) );
crdr_t_set_state( 'orders', array( 'updated' => time() - CRDR_Sync::STALL_AFTER + 60 ) );
check( 'just under 30 minutes is still "running"', CRDR_Sync::guard()['code'], 'sync_running' );
crdr_t_set_state( 'orders' );
$msgs = array();
foreach ( array( 'orders', 'failed' ) as $ph ) {
	crdr_t_set_state( $ph );
	$msgs[] = CRDR_Sync::guard()['message'];
}
check( 'every message is Persian and tells the owner what to do', array_map( function ( $m ) {
	return 1 === preg_match( '/\p{Arabic}/u', $m ) && false !== mb_strpos( $m, 'ارسال پیامک' );
}, $msgs ), array( true, true ) );
check( 'the failed message names the button to press', false !== mb_strpos( $msgs[1], 'تحلیل سفارش‌ها' ), true );

/* ================================================================== 2. SMS choke points: claim() and send_claimed() */
section( 'the two choke points every SMS passes (claim, send_claimed)' );
$db = crdr_t_world();
crdr_t_set_state( 'orders' );
$c = CRDR_Send::claim( 'p:09120000001', 'dormant', 'run' );
check( 'claim() while rebuilding: refused as "syncing"', $c, array( 'skip' => 'syncing' ) );
check( 'claim() while rebuilding: the database was not touched at all', $db->log, array() );
$o = CRDR_Send::send_claimed( 7, '09120000001', 'سلام' );
check( 'send_claimed() while rebuilding: refused, scope "sync", nothing sent', array( $o['state'], $o['scope'], crdr_t_gateway() ), array( 'failed', 'sync', 0 ) );
check( 'send_claimed() while rebuilding: the claim ends as "failed" (not a contact); the message text and localid were never recorded', 1 === count( $db->log ) && false !== strpos( $db->log[0], "SET status = 'failed'" ) && false === strpos( $db->log[0], 'localid' ) && false === strpos( $db->log[0], 'سلام' ), true );

$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$c = CRDR_Send::claim( 'p:09120000001', 'dormant', 'run' );
check( 'claim() after a successful sync: allowed (it claims)', isset( $c['id'] ), true );
$o = CRDR_Send::send_claimed( 1, '09120000001', 'سلام' );
check( 'send_claimed() after a successful sync: reaches the gateway exactly once and succeeds', array( $o['state'], crdr_t_gateway() ), array( 'sent', 1 ) );

$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'orders' );
$c = CRDR_Send::claim( 'p:09120000001', 'dormant', 'run' );
crdr_t_set_state( 'done' ); // claimed while the data was fine ...
$c = CRDR_Send::claim( 'p:09120000002', 'dormant', 'run' );
crdr_t_set_state( 'orders' ); // ... and a rebuild starts before the message goes out
$o = CRDR_Send::send_claimed( (int) $c['id'], '09120000002', 'سلام' );
check( 'a rebuild that starts between claim and send stops the send at the last gate', array( $o['state'], $o['scope'], crdr_t_gateway() ), array( 'failed', 'sync', 0 ) );

/* ============================================================================ 3. campaigns */
section( 'campaigns: manual run, cron run, preview' );
foreach ( array( 'manual', 'cron' ) as $trigger ) {
	$db = crdr_t_world();
	crdr_t_ready_db( $db );
	crdr_t_set_state( 'orders' );
	$r = CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => $trigger ) );
	check( "run() ($trigger) while rebuilding: blocked, nothing sent, nothing selected", array( $r['blocked'], $r['sent'], crdr_t_gateway() ), array( 'sync_running', 0, 0 ) );
	check( "run() ($trigger) while rebuilding: the customer table was never queried", count( array_filter( $db->log, function ( $q ) {
		return false !== strpos( $q, 'crdr_customers' );
	} ) ), 0 );
	check( "run() ($trigger): the Persian reason is returned", false !== mb_strpos( $r['error'], 'در حال به‌روزرسانی' ), true );
}
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'orders' );
CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => 'cron' ) );
check( 'a cron tick during a rebuild is not recorded as a per-campaign problem (one global warning exists instead)', get_option( CRDR_Health::OPTION, array() ), array() );
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'orders' );
CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => 'manual' ) );
$log = get_option( CRDR_Send::LOG_OPTION, array() );
check( 'a refused manual click is written to the run log with the reason', array( count( $log ), $log[0]['result'] ), array( 1, 'blocked:sync_running' ) );

$db = crdr_t_world();
crdr_t_ready_db( $db );
update_option( 'crdr_playbooks', array( 'reminder' => array( 'mode' => 'auto', 'holdout_pct' => 0 ) ) );
crdr_t_set_state( 'stats' );
CRDR_Playbooks::hourly();
check( 'hourly() cron while rebuilding (phase "stats"): nothing sent, customer table not queried', array( crdr_t_gateway(), count( array_filter( $db->log, function ( $q ) {
	return false !== strpos( $q, 'crdr_customers' );
} ) ) ), array( 0, 0 ) );

$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'orders' );
$p = CRDR_Playbooks::preview( 'reminder' );
check( 'preview() while rebuilding: says why, run is not possible, no customers read', array( $p['blocked'], $p['audience'], $p['samples'], count( array_filter( $db->log, function ( $q ) {
	return false !== strpos( $q, 'crdr_customers' );
} ) ) ), array( 'sync_running', 0, array(), 0 ) );
check( 'preview() answers with every key the browser script reads', array_diff( array( 'audience', 'aov', 'cap', 'today', 'holdout', 'samples', 'scenario', 'in_window', 'provider', 'mode', 'template_errors', 'blocked', 'blocked_message', 'can_override' ), array_keys( $p ) ), array() );

$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$r = CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => 'manual' ) );
check( 'run() after a successful sync: sends the campaign message (1 customer, 1 gateway call)', array( $r['blocked'], $r['sent'], crdr_t_gateway() ), array( '', 1, 1 ) );
$db = crdr_t_world();
crdr_t_ready_db( $db );
update_option( 'crdr_playbooks', array( 'reminder' => array( 'mode' => 'auto', 'holdout_pct' => 0 ) ) );
crdr_t_set_state( 'done' );
CRDR_Playbooks::hourly();
check( 'hourly() cron after a successful sync: sends', crdr_t_gateway(), 1 );
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$p = CRDR_Playbooks::preview( 'reminder' );
check( 'preview() after a successful sync is the real preview (one sample, not blocked by the sync)', array( count( $p['samples'] ), 'sync_running' === $p['blocked'] ), array( 1, false ) );

// a run that is already going when the rebuild starts: stops at the next customer
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->on_query = function ( $sql ) { // the rebuild begins the moment the run first reads the customers
	if ( false !== strpos( $sql, 'FROM wp_crdr_customers c' ) ) {
		crdr_t_set_state( 'orders' );
	}
};
$r = CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => 'manual' ) );
check( 'a run in progress when a rebuild starts claims nobody more, sends nothing, says why', array( $r['sent'], crdr_t_gateway(), $r['blocked'] ), array( 0, 0, 'sync_running' ) );

/* ==================================================================== 4. the rebuild lifecycle */
section( 'rebuild lifecycle: the guard stays on from the first request to the last' );
$db = crdr_t_world();
crdr_t_store( 450 ); // 3 pages: 200 + 200 + 50
$phase_at_truncate = array();
$db->on_query      = function ( $sql ) use ( &$phase_at_truncate ) {
	if ( 0 === strpos( $sql, 'TRUNCATE' ) ) {
		$phase_at_truncate[] = CRDR_Sync::guard()['code'] . '/' . crdr_t_phase();
	}
};
$trace = array();
$s     = CRDR_Sync::start();
check( 'start(): the "unsafe" state is on BEFORE any table is emptied (all three truncations happen under it)', $phase_at_truncate, array( 'sync_running/starting', 'sync_running/starting', 'sync_running/starting' ) );
$trace[] = array( $s['phase'], CRDR_Sync::guard()['blocked'] );
check( 'start(): 450 orders found, importing', array( $s['phase'], $s['total'], $s['page'] ), array( 'orders', 450, 1 ) );
$db->on_query = null;
for ( $i = 0; $i < 5; $i++ ) {
	$s       = CRDR_Sync::step();
	$trace[] = array( $s['phase'], CRDR_Sync::guard()['blocked'] );
}
check( 'phases after start() and each of the 5 steps (pages 1, 2, 3, scoring, analytics)', array_column( $trace, 0 ), array( 'orders', 'orders', 'orders', 'finalize', 'stats', 'done' ) );
check( 'the guard is ON after start() and after every step except the last; OFF only when the phase is "done"', array_column( $trace, 1 ), array( true, true, true, true, true, false ) );
check( 'page 3 (50 orders, fewer than a full page) is what ends the import: 450 orders read in total', array( $s['done'], $s['page'] ), array( 450, 4 ) );
check( 'every order was imported exactly once (450 inserts into the order table)', count( array_filter( $db->log, function ( $q ) {
	return 0 === strpos( $q, 'INSERT IGNORE INTO wp_crdr_orders' );
} ) ), 450 );
check( 'the lock is released after every step', $db->lock_row, null );
check( 'the state carries a heartbeat', time() - (int) crdr_t_state()['updated'] <= 5, true );

// intermediate steps: other code asks the guard in between
$db = crdr_t_world();
crdr_t_store( 450 );
CRDR_Sync::start();
CRDR_Sync::step();
check( 'between page 1 and page 2: a campaign run is refused', CRDR_Playbooks::run( 'reminder', 5, array( 'trigger' => 'manual' ) )['blocked'], 'sync_running' );
CRDR_Sync::step();
CRDR_Sync::step(); // last page -> finalize
check( 'right after the LAST page (phase "finalize"): still refused', array( crdr_t_phase(), CRDR_Playbooks::run( 'reminder', 5, array( 'trigger' => 'manual' ) )['blocked'] ), array( 'finalize', 'sync_running' ) );
CRDR_Sync::step(); // finalize -> stats
check( 'in phase "stats": still refused', array( crdr_t_phase(), CRDR_Sync::guard()['code'] ), array( 'stats', 'sync_running' ) );
CRDR_Sync::step();
check( 'only after the last step succeeded: done and allowed', array( crdr_t_phase(), CRDR_Sync::guard()['blocked'] ), array( 'done', false ) );

// an empty store skips the import but not the guard
$db = crdr_t_world();
crdr_t_store( 0 );
$s = CRDR_Sync::start();
check( 'an empty store: start() goes straight to scoring, and is still unsafe until the end', array( $s['phase'], CRDR_Sync::guard()['blocked'] ), array( 'finalize', true ) );
CRDR_Sync::step();
check( 'an empty store: ... and after scoring', array( crdr_t_phase(), CRDR_Sync::guard()['blocked'] ), array( 'stats', true ) );
CRDR_Sync::step();
check( 'an empty store: done', array( crdr_t_phase(), CRDR_Sync::guard()['blocked'] ), array( 'done', false ) );

/* ====================================================================== 4b. no order counted twice */
section( 'orders are read in a fixed order and an order that comes back is not counted twice' );
$db = crdr_t_world();
crdr_t_store( 3 );
CRDR_Sync::start();
$q = end( $GLOBALS['CRDR_T']['wc_queries'] );
CRDR_Sync::step();
$page_query = end( $GLOBALS['CRDR_T']['wc_queries'] );
check( 'the page query orders by ID (unique), not by date (orders created in the same second tie)', array( $page_query['orderby'], $page_query['order'] ), array( 'ID', 'ASC' ) );
$counted = function ( $db ) {
	return count( array_filter( $db->log, function ( $q ) {
		return 0 === strpos( $q, 'INSERT INTO wp_crdr_customers' );
	} ) );
};
check( '3 distinct orders: 3 customer upserts', $counted( $db ), 3 );
// the list shifted while the import ran: order 200 is returned again at the top of page 2
$db = crdr_t_world();
crdr_t_store( 450 );
$all = $GLOBALS['CRDR_T']['wc_orders'];
array_splice( $all, 200, 0, array( $all[199] ) ); // 451 entries, order 200 appears on page 1 AND page 2
$GLOBALS['CRDR_T']['wc_orders'] = $all;
CRDR_Sync::start();
for ( $i = 0; $i < 5; $i++ ) {
	CRDR_Sync::step();
}
check( 'an order returned on two pages is read twice but counted once: 450 customer upserts, 450 order rows, not 451', array( $counted( $db ), count( $db->order_rows ) ), array( 450, 450 ) );
check( 'and the rebuild still completes', array( crdr_t_phase(), CRDR_Sync::guard()['blocked'] ), array( 'done', false ) );

/* ============================================================================== 5. failures and retries */
section( 'failures, interrupted requests and retries');
// WooCommerce throws while reading page 2
$db = crdr_t_world();
crdr_t_store( 450 );
$GLOBALS['CRDR_T']['wc_throw_on_page'] = 2;
CRDR_Sync::start();
CRDR_Sync::step();
$s = CRDR_Sync::step();
check( 'an exception on page 2 turns the sync into "failed", it does not stay "orders" and does not become "done"', array( $s['phase'], $s['failed_phase'], $s['error'] ), array( 'failed', 'orders', 'step:RuntimeException' ) );
check( 'the error is a code only (no exception text, nothing personal)', false === strpos( json_encode( $s ), 'simulated' ), true );
check( 'failed: still unsafe, with its own reason', array( CRDR_Sync::guard()['blocked'], CRDR_Sync::guard()['code'] ), array( true, 'sync_failed' ) );
check( 'failed: a campaign run is refused', CRDR_Playbooks::run( 'reminder', 5, array( 'trigger' => 'manual' ) )['blocked'], 'sync_failed' );
$inserts = count( array_filter( $db->log, function ( $q ) {
	return 0 === strpos( $q, 'INSERT IGNORE INTO wp_crdr_orders' );
} ) );
$queries = count( $GLOBALS['CRDR_T']['wc_queries'] );
for ( $i = 0; $i < 3; $i++ ) {
	CRDR_Sync::step();
}
check( 'calling step() again after a failure does nothing: no page is re-imported, nothing is queried, it stays failed', array( crdr_t_phase(), count( array_filter( $db->log, function ( $q ) {
	return 0 === strpos( $q, 'INSERT IGNORE INTO wp_crdr_orders' );
} ) ) === $inserts, count( $GLOBALS['CRDR_T']['wc_queries'] ) === $queries ), array( 'failed', true, true ) );
$GLOBALS['CRDR_T']['scheduled'] = array();
CRDR_Sync::background_step();
check( 'the background chain ends on a failure (nothing is scheduled to continue it)', $GLOBALS['CRDR_T']['scheduled'], array() );
// retry = a fresh start, which completes
unset( $GLOBALS['CRDR_T']['wc_throw_on_page'] );
$s = CRDR_Sync::start();
check( 'retry: start() rebuilds from scratch (page 1, no leftover error)', array( $s['phase'], $s['page'], isset( $s['error'] ) ), array( 'orders', 1, false ) );
for ( $i = 0; $i < 5; $i++ ) {
	$s = CRDR_Sync::step();
}
check( 'retry: completes, the guard is released, the error is gone', array( $s['phase'], CRDR_Sync::guard()['blocked'], isset( $s['error'] ) ), array( 'done', false, false ) );

// a process that died in the middle of a page (fatal error, timeout): detected, never resumed blindly
$db = crdr_t_world();
crdr_t_store( 450 );
crdr_t_set_state( 'orders', array( 'page' => 2, 'inflight' => 2 ) );
$s      = CRDR_Sync::step();
check( 'an interrupted page (marker still set): the sync fails instead of importing the page again (no order was written, none was even read)', array( $s['phase'], $s['error'], count( array_filter( $db->log, function ( $q ) {
	return 0 === strpos( $q, 'INSERT IGNORE INTO wp_crdr_orders' );
} ) ), count( $GLOBALS['CRDR_T']['wc_queries'] ) ), array( 'failed', 'interrupted_page', 0, 0 ) );
crdr_t_world();
crdr_t_set_state( 'starting' );
$s = CRDR_Sync::step();
check( 'start() that died before it finished (tables may be half emptied): the sync fails', array( $s['phase'], $s['error'] ), array( 'failed', 'interrupted_start' ) );
// the safe-to-resume phases
$db = crdr_t_world();
crdr_t_set_state( 'finalize', array( 'done' => 450 ) );
CRDR_Sync::step();
CRDR_Sync::step();
check( 'an interrupted scoring / analytics phase is idempotent: stepping again resumes it and finishes', array( crdr_t_phase(), CRDR_Sync::guard()['blocked'] ), array( 'done', false ) );
// "done" is only written by the last step
$db = crdr_t_world();
crdr_t_store( 10 );
$seen = array();
CRDR_Sync::start();
for ( $i = 0; $i < 4; $i++ ) {
	$seen[] = CRDR_Sync::step()['phase'];
}
check( 'a 10-order store: "done" appears only after import, scoring and analytics have each run', $seen, array( 'finalize', 'stats', 'done', 'done' ) );
check( 'stepping a finished sync changes nothing', crdr_t_phase(), 'done' );

/* ===================================================================== 6. the lock (PHP-side logic only) */
section( 'step lock: one step at a time (the database half is tested in tests/phase1-safety.php)' );
$db = crdr_t_world();
crdr_t_store( 450 );
crdr_t_set_state( 'orders' );
$db->lock_row = ( time() - 30 ) . ':someone-else';
$q0 = count( $GLOBALS['CRDR_T']['wc_queries'] );
$s  = CRDR_Sync::step();
check( 'while another step holds the lock: step() reports busy and does nothing', array( ! empty( $s['busy'] ), $s['page'], count( $GLOBALS['CRDR_T']['wc_queries'] ) === $q0, $db->lock_row ), array( true, 1, true, ( time() - 30 ) . ':someone-else' ) );
$s = CRDR_Sync::start();
check( 'while another step holds the lock: start() reports busy and does not empty any table', array( ! empty( $s['busy'] ), count( array_filter( $db->log, function ( $q ) {
	return 0 === strpos( $q, 'TRUNCATE' );
} ) ) ), array( true, 0 ) );
check( 'busy does not change the guard (the rebuild is still on)', CRDR_Sync::guard()['blocked'], true );
$db->lock_row = ( time() - CRDR_Sync::LOCK_TTL - 60 ) . ':dead-step';
$s = CRDR_Sync::step();
check( 'a lock held by a step that died long ago is taken over, and the step runs', array( empty( $s['busy'] ), $s['page'], $db->lock_row ), array( true, 2, null ) );
CRDR_Sync::unlock( 'not-my-lock' );
$db->lock_row = '123:mine';
CRDR_Sync::unlock( 'not-my-lock' );
check( 'unlock() never releases a lock it does not own', $db->lock_row, '123:mine' );
$GLOBALS['CRDR_T']['scheduled'] = array();
CRDR_Sync::background_step();
check( 'background chain: when the lock is busy it comes back later instead of ending or hammering', $GLOBALS['CRDR_T']['scheduled'], array( CRDR_Sync::STEP_HOOK ) );
$db = crdr_t_world();
crdr_t_store( 450 );
CRDR_Sync::start();
$GLOBALS['CRDR_T']['scheduled'] = array();
CRDR_Sync::background_step();
check( 'background chain: a running sync schedules its next step', $GLOBALS['CRDR_T']['scheduled'], array( CRDR_Sync::STEP_HOOK ) );



/* ===================================================================== 6c. orders that change while the import runs */
section( 'reconciliation: orders that change status while pages are being read are not silently left out' );
/** Run a whole rebuild; returns the phases seen after each request. */
function crdr_t_rebuild( $max = 40 ) {
	$phases = array( CRDR_Sync::start()['phase'] );
	for ( $i = 0; $i < $max; $i++ ) {
		$s        = CRDR_Sync::step();
		$phases[] = $s['phase'];
		if ( 'done' === $s['phase'] || 'failed' === $s['phase'] ) {
			break;
		}
	}
	return $phases;
}
/** Change an order of the modelled store. */
function crdr_t_order_set( $id, $key, $value ) {
	foreach ( $GLOBALS['CRDR_T']['wc_orders'] as &$o ) {
		if ( (int) $o['id'] === (int) $id ) {
			$o[ $key ] = $value;
		}
	}
}
/** Run $fn once, right before the Nth page of orders is requested (after page N-1 was imported). */
function crdr_t_before_page( $n, callable $fn ) {
	$GLOBALS['CRDR_T']['wc_hook'] = function ( $args ) use ( $n, $fn ) {
		static $fired = false;
		if ( ! $fired && isset( $args['paged'] ) && $n === (int) $args['paged'] && 'objects' === ( isset( $args['return'] ) ? $args['return'] : '' ) ) {
			$fired = true;
			$fn();
		}
	};
}

// 0. nothing changes while it runs: the check reads two lists and changes nothing
$db = crdr_t_world();
crdr_t_store( 450 );
$phases = crdr_t_rebuild();
$st     = crdr_t_state();
check( 'no change during the rebuild: same phases as before (no extra step), nothing added by the check', array( $phases, isset( $st['added'] ), isset( $st['unresolved'] ), count( $db->order_rows ) ), array( array( 'orders', 'orders', 'orders', 'finalize', 'stats', 'done' ), false, false, 450 ) );

// 1. an order that was ALREADY imported is cancelled between page 1 and page 2: the list shifts and one eligible order is skipped
$db = crdr_t_world();
crdr_t_store( 450 );
crdr_t_before_page( 2, function () {
	crdr_t_order_set( 3, 'status', 'cancelled' );
} );
$phases = crdr_t_rebuild();
$st     = crdr_t_state();
$have   = array_keys( $db->order_rows );
$elig   = array_diff( range( 1, 450 ), array( 3 ) );
check( 'an earlier order is cancelled mid-import: EVERY order that is eligible at the end is in the table (order 201 would have been skipped)', array_values( array_diff( $elig, $have ) ), array() );
check( '... the reconcile step found and imported exactly the one that was skipped, and the rebuild still ends "done" with no unresolved orders', array( $st['added'], isset( $st['unresolved'] ), end( $phases ) ), array( 1, false, 'done' ) );
check( '... the guard stayed on through the extra step', array_values( array_unique( array_slice( $phases, 0, -1 ) ) ), array( 'orders', 'finalize', 'stats' ) );
check( '... no order is counted twice (every imported order produced exactly one customer upsert)', count( array_filter( $db->log, function ( $q ) {
	return 0 === strpos( $q, 'INSERT INTO wp_crdr_customers' );
} ) ), count( $db->order_rows ) );
check( '... and the order that was cancelled AFTER it was imported is still counted (a known limit: stale until the next rebuild)', in_array( 3, $have, true ), true );

// 2. an order BECOMES eligible after the pages passed it (pending -> completed)
$db = crdr_t_world();
crdr_t_store( 450 );
crdr_t_order_set( 50, 'status', 'pending' );
crdr_t_before_page( 2, function () {
	crdr_t_order_set( 50, 'status', 'completed' );
} );
crdr_t_rebuild();
check( 'an order that becomes eligible after its page was read is added by the reconcile step', array( in_array( 50, array_keys( $db->order_rows ), true ), count( $db->order_rows ) ), array( true, 450 ) );

// 3. a new order is created while the import runs
$db = crdr_t_world();
crdr_t_store( 450 );
crdr_t_before_page( 3, function () {
	$GLOBALS['CRDR_T']['wc_orders'][] = array( 'id' => 451, 'status' => 'completed', 'ts' => 1790000000 + 451 * 60, 'uid' => 0, 'phone' => '09120000451', 'email' => '', 'items' => array( array( 1, 1000 ) ), 'shipping' => 0, 'tax' => 0, 'total' => 1000, 'refunded' => 0 );
} );
crdr_t_rebuild();
check( 'an order created while the rebuild runs is included (by the last page or by the check), exactly once', array( in_array( 451, array_keys( $db->order_rows ), true ), count( $db->order_rows ) ), array( true, 451 ) );

// 4. many orders were skipped (a burst of cancellations): the check works through them in batches and ends
$db = crdr_t_world();
crdr_t_store( 450 );
crdr_t_before_page( 2, function () {
	for ( $i = 1; $i <= 120; $i++ ) { // 120 imported orders leave the counted statuses: the list shifts by 120
		crdr_t_order_set( $i, 'status', 'cancelled' );
	}
} );
$phases = crdr_t_rebuild();
$missing = array_diff( range( 121, 450 ), array_keys( $db->order_rows ) );
check( 'a burst of 120 cancellations: all 330 orders that are still eligible are in the table; the rebuild ends "done"', array( array_values( $missing ), end( $phases ) ), array( array(), 'done' ) );

// 5. an eligible order that can never be read: reported, not looped on, not hidden
$db = crdr_t_world();
crdr_t_store( 10 );
crdr_t_order_set( 7, 'unloadable', true );
$phases = crdr_t_rebuild();
$st     = crdr_t_state();
check( 'an eligible order that cannot be loaded: the check gives up after a few rounds (no endless loop), the rebuild ends "done"', array( end( $phases ), count( $phases ) <= 8, $st['unresolved'], count( $db->order_rows ) ), array( 'done', true, 1, 9 ) );
$GLOBALS['CRDR_T']['can'] = true;
check( 'and the administrator is told that the numbers are slightly low', false !== mb_strpos( CRDR_Health::notice_html(), 'خوانده نشد' ), true );
crdr_t_world();
check( '(no such notice when nothing is unresolved)', CRDR_Health::notice_html(), '' );

// 6. a store that keeps changing faster than it can be read is bounded
$db = crdr_t_world();
crdr_t_store( 450 );
$GLOBALS['CRDR_T']['wc_hook'] = function ( $args ) { // every list of ids shows one more eligible order than the last one
	static $next = 1000;
	if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
		$GLOBALS['CRDR_T']['wc_orders'][] = array( 'id' => ++$next, 'status' => 'completed', 'ts' => 1790000000 + $next * 60, 'uid' => 0, 'phone' => '0912' . $next . '00', 'email' => '', 'items' => array(), 'shipping' => 0, 'tax' => 0, 'total' => 1000, 'refunded' => 0 );
	}
};
$phases = crdr_t_rebuild( 60 );
$st     = crdr_t_state();
check( 'a store that gains an eligible order every time it is listed: the check stops after RECONCILE_MAX_ROUNDS, says it did NOT verify (never "clean"), and the rebuild ends', array( end( $phases ), $st['rounds'], isset( $st['unverified'] ), isset( $st['unresolved'] ) ), array( 'done', CRDR_Sync::RECONCILE_MAX_ROUNDS, true, false ) );
$GLOBALS['CRDR_T']['can'] = true;
check( '... and the administrator is told that the last check was not completed', false !== mb_strpos( CRDR_Health::notice_html(), 'بررسی نهایی کامل نشد' ), true );

// 7. a step that dies in the middle of importing the missing orders
$db = crdr_t_world();
crdr_t_store( 450 );
crdr_t_set_state( 'finalize', array( 'inflight' => 'reconcile' ) );
$s = CRDR_Sync::step();
check( 'a reconcile step found half done (in-flight marker) fails the rebuild instead of importing again', array( $s['phase'], $s['error'], CRDR_Sync::guard()['code'] ), array( 'failed', 'interrupted_page', 'sync_failed' ) );
$db = crdr_t_world();
crdr_t_store( 450 );
CRDR_Sync::start();
for ( $i = 0; $i < 3; $i++ ) {
	CRDR_Sync::step();
}
$GLOBALS['CRDR_T']['wc_orders'][] = array( 'id' => 451, 'status' => 'completed', 'ts' => 1790000000, 'uid' => 0, 'phone' => '09120000451', 'email' => '', 'items' => array(), 'shipping' => 0, 'tax' => 0, 'total' => 1000, 'refunded' => 0 );
$db->on_query = function ( $sql ) {
	if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_crdr_orders' ) ) {
		throw new RuntimeException( 'simulated database error while importing a missing order' );
	}
};
$s = CRDR_Sync::step();
$db->on_query = null;
check( 'an exception while importing a missing order fails the rebuild (still unsafe, no retry on its own)', array( $s['phase'], CRDR_Sync::guard()['blocked'] ), array( 'failed', true ) );

/* ===================================================================== 6b. ownership, renewal, takeover */
section( 'step lock: ownership, renewal and takeover' );
/** Call a private static method of CRDR_Sync. */
function crdr_t_priv( $method, array $args = array() ) {
	$m = new ReflectionMethod( 'CRDR_Sync', $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}
/** Read / write the private "lock this process holds" record. */
function crdr_t_held( $set = false, $value = null ) {
	$p = new ReflectionProperty( 'CRDR_Sync', 'held' );
	$p->setAccessible( true );
	if ( $set ) {
		$p->setValue( null, $value );
	}
	return $p->getValue();
}
/** Pretend $secs seconds went by since this process last wrote its lock: both the row and the process's own record age. */
function crdr_t_age_lock( $db, $secs ) {
	$h = crdr_t_held();
	$v = ( time() - $secs ) . ':' . $h['token'];
	if ( $db->lock_row === $h['val'] ) {
		$db->lock_row = $v;
	}
	crdr_t_held( true, array( 'token' => $h['token'], 'val' => $v, 'at' => time() - $secs ) );
}
$lock_queries = function ( $db ) {
	return count( array_filter( $db->log, function ( $q ) {
		return false !== strpos( $q, "'crdr_sync_lock'" );
	} ) );
};

$db  = crdr_t_world();
$val = crdr_t_priv( 'lock' );
$h   = crdr_t_held();
check( 'a lock is "time:token" with a random token of its own', array( 1 === preg_match( '/^\d+:[0-9a-f]{16}$/', $val ), $db->lock_row === $val, $h['token'] === substr( $val, strpos( $val, ':' ) + 1 ) ), array( true, true, true ) );
$q0 = $lock_queries( $db );
crdr_t_priv( 'checkpoint' );
check( 'a checkpoint on a young lock costs nothing (no statement, no renewal): nobody can take it before it is LOCK_TTL old', array( $lock_queries( $db ), $db->lock_row ), array( $q0, $val ) );
crdr_t_age_lock( $db, CRDR_Sync::LOCK_RENEW + 5 );
$old = $db->lock_row;
crdr_t_priv( 'checkpoint' );
check( 'a checkpoint on a lock older than LOCK_RENEW renews it: same token, fresh time', array( $db->lock_row !== $old, time() - (int) $db->lock_row <= 2, substr( $db->lock_row, strpos( $db->lock_row, ':' ) ) === ':' . $h['token'] ), array( true, true, true ) );
$renewed = $db->lock_row;
crdr_t_priv( 'checkpoint' );
check( 'and right after the renewal the next checkpoint does nothing again', $db->lock_row, $renewed );
CRDR_Sync::unlock( $val ); // the ORIGINAL value: the lock was renewed since, and unlock still finds it through the token
check( 'unlock() releases the lock even after it was renewed (it follows the token, not the first value)', array( $db->lock_row, crdr_t_held() ), array( null, null ) );

// somebody took it over while this process was stuck: the next checkpoint stops it, and it does not touch their lock
$db  = crdr_t_world();
$val = crdr_t_priv( 'lock' );
crdr_t_age_lock( $db, CRDR_Sync::LOCK_RENEW + 5 );
$taker_val    = time() . ':the-taker';
$db->lock_row = $taker_val;
$lost         = false;
try {
	crdr_t_priv( 'checkpoint' );
} catch ( CRDR_Sync_Lost $e ) {
	$lost = true;
}
check( 'a checkpoint after the lock was taken over throws CRDR_Sync_Lost, forgets the lock, and leaves the taker\'s lock alone', array( $lost, $db->lock_row, crdr_t_held() ), array( true, $taker_val, null ) );
CRDR_Sync::unlock( $val );
check( 'unlock() by the old owner afterwards (its shutdown handler) does not release the taker\'s lock', $db->lock_row, $taker_val );

// a live owner that keeps checkpointing is never taken over; one that stopped is
$db  = crdr_t_world();
$val = crdr_t_priv( 'lock' );
crdr_t_age_lock( $db, CRDR_Sync::LOCK_TTL + 100 ); // a very long step: its lock row is older than the TTL ...
crdr_t_priv( 'checkpoint' );                       // ... but it checkpointed (renewed) before anybody asked
$saved = crdr_t_held();
check( 'a long-running step that checkpoints has a fresh lock, however long it has been running', time() - (int) $db->lock_row <= 2, true );
crdr_t_held( true, null );
check( 'so another process asking for the lock is refused', crdr_t_priv( 'lock' ), false );
crdr_t_held( true, $saved );
crdr_t_age_lock( $db, CRDR_Sync::LOCK_TTL + 100 ); // now it hangs: no checkpoint for longer than the TTL
crdr_t_held( true, null );                         // (this is the other process: its own record is empty)
$taker = crdr_t_priv( 'lock' );
check( 'but a lock nobody renewed for LOCK_TTL seconds is taken over (compare-and-swap on the exact old value)', array( false !== $taker, $db->lock_row === $taker ), array( true, true ) );
CRDR_Sync::unlock( $taker );

// two takers race for the same dead lock: the second compare-and-swap sees a changed row and loses
$db = crdr_t_world();
crdr_t_held( true, null );
$db->lock_row = ( time() - CRDR_Sync::LOCK_TTL - 60 ) . ':dead-step';
$faster       = time() . ':faster-taker';
$db->on_query = function ( $sql ) use ( $db, $faster ) {
	if ( 0 === strpos( $sql, 'UPDATE wp_options SET option_value' ) ) {
		$db->lock_row = $faster; // the other taker's UPDATE lands first
	}
};
check( 'two processes take over the same dead lock at once: only the first wins (the second gets false and runs nothing)', crdr_t_priv( 'lock' ), false );
check( '... and the winner\'s lock is untouched', $db->lock_row, $faster );

// a step that loses its lock in the middle of a page stops before the next order and writes no state
$db = crdr_t_world();
crdr_t_store( 450 );
CRDR_Sync::start();
$orders_seen  = 0;
$taker_val    = time() . ':the-taker';
$db->on_query = function ( $sql ) use ( $db, &$orders_seen, $taker_val ) {
	if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_crdr_orders' ) && 30 === ++$orders_seen ) {
		$h = crdr_t_held();                                   // this step has been "stuck" for a minute inside order 30 ...
		crdr_t_held( true, array( 'token' => $h['token'], 'val' => $h['val'], 'at' => time() - CRDR_Sync::LOCK_RENEW - 1 ) );
		$db->lock_row = $taker_val;                           // ... and somebody took the lock over meanwhile
	}
};
$writes0 = $db->state_writes;
$s       = CRDR_Sync::step();
$db->on_query = null;
$after = crdr_t_state();
check( 'a step that lost its lock in the middle of a page: reports busy (not done, not failed, not retried)', array( ! empty( $s['busy'] ), $s['phase'] ), array( true, 'orders' ) );
check( '... it imported no order after the one it was in (30 order rows, not 200)', count( $db->order_rows ), 30 );
check( '... it wrote NO state after losing the lock (only the in-flight marker written before the page is there)', array( $db->state_writes - $writes0, ! empty( $after['inflight'] ), (int) $after['page'] ), array( 1, true, 1 ) );
check( '... and the taker\'s lock is still in place', $db->lock_row, $taker_val );
check( '... the guard stays on', CRDR_Sync::guard()['blocked'], true );
$db->lock_row = null;
$s = CRDR_Sync::step();
check( 'the next owner finds the half-applied page and fails the rebuild (it never resumes it)', array( $s['phase'], $s['error'] ), array( 'failed', 'interrupted_page' ) );

// the database decides, even if the PHP-side lease logic were wrong: a state write by a process that is no longer the owner is refused
$db = crdr_t_world();
crdr_t_store( 450 );
CRDR_Sync::start();
$n         = 0;
$taker_val = time() . ':the-taker';
$db->on_query = function ( $sql ) use ( $db, &$n, $taker_val ) {
	if ( 0 === strpos( $sql, 'INSERT INTO wp_options' ) && false !== strpos( $sql, "'crdr_sync_state'" ) && 2 === ++$n ) {
		$db->lock_row = $taker_val; // the lock changes hands right before the state write that would clear the in-flight marker
	}
};
$s = CRDR_Sync::step();
$db->on_query = null;
$after = crdr_t_state();
check( 'the state write is refused by the "lock still ours?" condition even though the lease looked fresh: nothing changes', array( ! empty( $s['busy'] ), ! empty( $after['inflight'] ), $after['phase'], $db->lock_row ), array( true, true, 'orders', $taker_val ) );

// a lost start() never empties another table
$db = crdr_t_world();
crdr_t_store( 450 );
$n         = 0;
$taker_val = time() . ':the-taker';
$db->on_query = function ( $sql ) use ( $db, &$n, $taker_val ) {
	if ( 0 === strpos( $sql, 'TRUNCATE' ) && 1 === ++$n ) {
		$h = crdr_t_held();
		crdr_t_held( true, array( 'token' => $h['token'], 'val' => $h['val'], 'at' => time() - CRDR_Sync::LOCK_RENEW - 1 ) );
		$db->lock_row = $taker_val;
	}
};
$s = CRDR_Sync::start();
$db->on_query = null;
check( 'start() that loses its lock after the first TRUNCATE stops: the other two tables are not emptied, busy is reported', array( $n, ! empty( $s['busy'] ), $s['phase'] ), array( 1, true, 'starting' ) );
check( '... and the rebuild stays unsafe', CRDR_Sync::guard()['blocked'], true );

// a long, slow rebuild (every order "takes" a minute) is NOT interrupted: it renews as it goes, and a taker is refused every time
$db = crdr_t_world();
crdr_t_store( 450 );
CRDR_Sync::start();
$refused = array();
$db->on_query = function ( $sql ) use ( $db, &$refused ) {
	if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_crdr_orders' ) ) {
		$saved = crdr_t_held();
		crdr_t_held( true, null );                           // a second process asks for the lock right now ...
		$refused[] = false === crdr_t_priv( 'lock' );        // ... and must be refused: the owner's lease is fresh
		crdr_t_held( true, $saved );
		crdr_t_age_lock( $db, CRDR_Sync::LOCK_RENEW + 1 );   // then another minute passes before the next order
	}
};
$s = CRDR_Sync::step();
$db->on_query = null;
check( 'a page whose 200 orders each take over a minute (over 3 hours, far beyond LOCK_TTL) still completes and is never taken over', array( ! empty( $s['busy'] ), $s['page'], count( $db->order_rows ) ), array( false, 2, 200 ) );
check( '... every one of the 200 takeover attempts was refused', array( count( $refused ), array_unique( $refused ) ), array( 200, array( true ) ) );

// stable identity of a customer (the stale-id fix; the end-to-end behaviour is in tests/phase1-safety.php, section H)
check( 'identity(): 24 hex characters, stable, different per customer, and does not contain the phone number', array( 1 === preg_match( '/^[0-9a-f]{24}$/', CRDR_Actions::identity( 'p:09120000001' ) ), CRDR_Actions::identity( 'p:09120000001' ) === CRDR_Actions::identity( 'p:09120000001' ), CRDR_Actions::identity( 'p:09120000001' ) !== CRDR_Actions::identity( 'p:09120000002' ), false === strpos( CRDR_Actions::identity( 'p:09120000001' ), '0912' ) ), array( true, true, true, true ) );
$act = CRDR_T::source( 'class-crdr-actions.php' );
$bad = array();
foreach ( array( 'ajax_preview', 'ajax_contact' ) as $fn ) {
	$body  = crdr_t_body( 'class-crdr-actions.php', $fn );
	$found = strpos( $body, 'self::customer(' );
	$same  = strpos( $body, 'self::require_same_customer(' );
	$first = array_filter( array( strpos( $body, 'CRDR_DB::add_contact' ), strpos( $body, 'CRDR_Send::claim' ), strpos( $body, 'make_coupon' ), strpos( $body, 'CRDR_Send::send_claimed' ) ), 'is_int' );
	if ( false === $found || false === $same || $same < $found || ( $first && min( $first ) < $same ) ) {
		$bad[] = $fn;
	}
}
check( '[static] ajax_preview and ajax_contact compare the identity right after finding the customer by id, before any contact, claim, coupon or SMS', $bad, array() );
check( '[static] every lookup of a customer by the posted id in the actions file is followed by the identity check', substr_count( $act, 'self::customer( isset' ), substr_count( $act, 'self::require_same_customer( $cust )' ) );
$pages = CRDR_T::source( 'class-crdr-page-customers.php' );
check( '[static] customer rows in the pages carry Actions::row_attrs() (id + identity), never a bare data-id', array( substr_count( $pages, 'CRDR_Actions::row_attrs(' ), 0 === preg_match( '/data-id="\' \. \(int\)/', $pages ) ), array( 2, true ) );
check( '[static] the browser script sends the identity with both per-customer requests (preview and contact)', substr_count( (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' ), 'k: key' ), 2 );

/* ============================================================================= 7. admin visibility */
section( 'what the administrator is told' );
foreach ( array( 'failed' => true, 'orders' => false ) as $ph => $shown ) {
	crdr_t_world();
	crdr_t_set_state( $ph );
	check( "global warning for phase '$ph' (a stalled or failed rebuild is shown everywhere, one that is just running is not)", false !== strpos( CRDR_Health::notice_html(), 'نیمه‌کاره' ), $shown );
}
crdr_t_world();
crdr_t_set_state( 'orders', array( 'updated' => time() - CRDR_Sync::STALL_AFTER - 5 ) );
check( 'global warning for a stalled rebuild', false !== strpos( CRDR_Health::notice_html(), 'نیم‌ساعت' ), true );
crdr_t_world();
crdr_t_set_state( 'done' );
check( 'no warning after a successful sync', CRDR_Health::notice_html(), '' );
foreach ( array( 'sync_running', 'sync_stalled', 'sync_failed', 'outside_window' ) as $code ) {
	check( "health: '$code' is never recorded per campaign", CRDR_Health::is_recorded( $code ), false );
}
check( 'health: ordinary blocks are still recorded', array( CRDR_Health::is_recorded( 'template' ), CRDR_Health::is_recorded( 'no_provider' ) ), array( true, true ) );

/* ============================================================================ 8. static entry-point scan */
section( '[static] every entry point that reads customer data or sends is guarded' );
$required = array(
	array( 'class-crdr-send.php', 'claim' ),
	array( 'class-crdr-send.php', 'send_claimed' ),
	array( 'class-crdr-playbooks.php', 'run' ),
	array( 'class-crdr-playbooks.php', 'preview' ),
	array( 'class-crdr-actions.php', 'ajax_contact' ),
	array( 'class-crdr-actions.php', 'ajax_preview' ),
	array( 'class-crdr-actions.php', 'post_export' ),
	array( 'class-crdr-page-customers.php', 'render_calls' ),
	array( 'class-crdr-page-customers.php', 'render_list' ),
	array( 'class-crdr-page-customers.php', 'render_detail' ),
);
foreach ( $required as $r ) {
	check( "{$r[0]}::{$r[1]}() asks the sync guard", crdr_t_guarded( crdr_t_body( $r[0], $r[1] ) ), true );
}
check( 'ajax_pb_run goes through Playbooks::run (which is guarded)', false !== strpos( crdr_t_body( 'class-crdr-actions.php', 'ajax_pb_run' ), 'CRDR_Playbooks::run' ), true );
check( 'ajax_pb_preview goes through Playbooks::preview (which is guarded)', false !== strpos( crdr_t_body( 'class-crdr-actions.php', 'ajax_pb_preview' ), 'CRDR_Playbooks::preview' ), true );
check( 'the cron entry point hourly() reaches the guarded run()', false !== strpos( crdr_t_body( 'class-crdr-playbooks.php', 'hourly' ), "self::run( \$id, 25, array( 'trigger' => 'cron' ) )" ), true );
check( 'ajax_contact asks the guard BEFORE it looks the customer up by row id', strpos( crdr_t_body( 'class-crdr-actions.php', 'ajax_contact' ), 'sync_block_ajax' ) < strpos( crdr_t_body( 'class-crdr-actions.php', 'ajax_contact' ), 'self::customer(' ), true );
check( 'post_export asks the guard BEFORE it reads the customer table', strpos( crdr_t_body( 'class-crdr-actions.php', 'post_export' ), 'CRDR_Sync::guard' ) < strpos( crdr_t_body( 'class-crdr-actions.php', 'post_export' ), 'CRDR_DB::customers()' ), true );

// the SMS gateway has exactly one caller
$callers = array();
foreach ( glob( dirname( __DIR__ ) . '/includes/class-crdr-*.php' ) as $f ) {
	foreach ( file( $f ) as $line ) {
		$t = ltrim( $line );
		if ( 0 === strpos( $t, '*' ) || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '/*' ) ) {
			continue;
		}
		if ( preg_match( '/CRDR_SMS::(deliver|send)\s*\(/', $line ) ) {
			$callers[] = basename( $f );
		}
	}
}
check( 'CRDR_SMS::deliver() / send() are called from CRDR_Send::send_claimed() and nowhere else (a new caller would bypass the guard)', array_values( array_unique( $callers ) ), array( 'class-crdr-send.php' ) );

// every function that reads the customer table or can send is either guarded or on this reviewed list
$review = array( // function => why it may stay unguarded
	'class-crdr-actions.php::customer'               => 'lookup helper; its callers are guarded',
	'class-crdr-actions.php::customer_by_key'        => 'lookup helper; its callers are guarded',
	'class-crdr-actions.php::ajax_pb_run'            => 'thin wrapper: all it does is call Playbooks::run(), which is guarded (asserted above)',
	'class-crdr-actions.php::ajax_pb_preview'        => 'thin wrapper: all it does is call Playbooks::preview(), which is guarded (asserted above)',
	'class-crdr-analytics.php::build'                => 'part of the sync itself',
	'class-crdr-audience.php::count'                 => 'library query; callers are guarded or display only',
	'class-crdr-audience.php::summary'               => 'library query; callers are guarded or display only',
	'class-crdr-insights.php::generate'              => 'display only: the Growth page shows the sync banner',
	'class-crdr-insights.php::playbook_impact'       => 'display only: the Growth page shows the sync banner',
	'class-crdr-page-campaigns.php::render'          => 'display only: shows the sync banner',
	'class-crdr-page-campaigns.php::results'         => 'display only: shows the sync banner',
	'class-crdr-page-dashboard.php::render'          => 'display only: shows the sync banner',
	'class-crdr-playbooks.php::summary'              => 'display only (campaign cards)',
	'class-crdr-privacy.php::count_rows'             => 'read-only counts for the eraser, which runs inside CRDR_Sync::exclusive() (refuses while a rebuild runs); sends nothing',
	'class-crdr-retention.php::run'                  => 'Phase 4.2: runs only at the end of a rebuild that reached "done", under the sync lock; it compares tables and minimises text, it sends nothing',
	'class-crdr-retention.php::unattributed_count'   => 'Phase 4.2: a read-only count for the eraser\'s message; sends nothing',
	'class-crdr-privacy.php::erase'                  => 'the personal-data eraser: sends nothing, deletes only the rows of one person, and refuses while a rebuild runs (CRDR_Sync::exclusive: one lock, no WORK phase)',
	'class-crdr-sync.php::start_locked'              => 'the sync itself',
	'class-crdr-sync.php::import_order'              => 'the sync itself',
	'class-crdr-sync.php::resolve_identities'       => 'the sync itself',
	'class-crdr-sync.php::import_page'               => 'the sync itself',
	'class-crdr-sync.php::finalize'                  => 'the sync itself',
);
$pattern    = '/CRDR_DB::customers\(\)|CRDR_Audience::(where|summary|count)|CRDR_Playbooks::(run|preview|summary)\(|CRDR_Send::(claim|send_claimed)\(|self::customer\(|self::customer_by_key|CRDR_Actions::customer|CRDR_Recommend::/';
$unreviewed = array();
$stale      = array();
foreach ( glob( dirname( __DIR__ ) . '/includes/class-crdr-*.php' ) as $f ) {
	$src   = file_get_contents( $f );
	$parts = preg_split( '/\n(?=\t(?:public|private|protected) static function )/', $src );
	array_shift( $parts );
	foreach ( $parts as $part ) {
		if ( ! preg_match( '/static function (\w+)/', $part, $m ) || ! preg_match( $pattern, $part ) ) {
			continue;
		}
		$id = basename( $f ) . '::' . $m[1];
		if ( ! crdr_t_guarded( $part ) && ! isset( $review[ $id ] ) ) {
			$unreviewed[] = $id;
		}
		if ( crdr_t_guarded( $part ) && isset( $review[ $id ] ) ) {
			$stale[] = $id;
		}
	}
}
check( 'no function reads the customer table or can send without a guard, except the reviewed list above (a NEW one fails this test: guard it, or review it and add it)', $unreviewed, array() );
check( 'the reviewed list holds no function that is in fact guarded (keep it honest)', $stale, array() );

/* ====================================================================================== schema and last opt-out check (stand-ins) */
section( 'incomplete schema, last opt-out check (stand-ins; the real database is tested in tests/release-safety.php)' );
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->missing_columns = array( 'orders.uid' );
$r = CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => 'manual' ) );
check( 'a missing column blocks the run (code schema), nothing reaches the gateway', array( $r['blocked'], $r['sent'], crdr_t_gateway() ), array( 'schema', 0, 0 ) );
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->claim_index = false;
$r = CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => 'manual' ) );
check( 'a missing unique claim index blocks the run: without it two processes could claim one customer', array( $r['blocked'], crdr_t_gateway() ), array( 'schema', 0 ) );
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->missing_tables = array( 'contacts' );
update_option( 'crdr_db_version', CRDR_DB::DB_VERSION );
$r = CRDR_Playbooks::run( 'reminder', 10, array( 'trigger' => 'manual' ) );
check( 'a lost contact table blocks the run and is not re-created by it', array( $r['blocked'], crdr_t_gateway(), CRDR_DB::history_table_lost( array( 'contacts' ) ) ), array( 'schema', 0, true ) );
$db = crdr_t_world();
check( 'a fresh site (no version recorded) may create its tables', CRDR_DB::history_table_lost( array( 'contacts' ) ), false );

$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->on( 'SELECT ckey FROM wp_crdr_contacts WHERE id', 'p:09120000001' ); // the claim being sent belongs to this customer
$db->on( "kind = 'optout' LIMIT 1", 1 );                                  // ... and the database says: opted out
$out = CRDR_Send::send_claimed( 5, '09120000001', 'سلام', '' );
check( 'send_claimed(): an opt-out found at the last moment ends in "failed / optout" and the gateway is not called', array( $out['state'], $out['scope'], crdr_t_gateway() ), array( 'failed', 'optout', 0 ) );
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->on( 'SELECT ckey FROM wp_crdr_contacts WHERE id', 'p:09120000001' );
$db->on( "kind = 'optout' LIMIT 1", function () use ( $db ) {
	$db->last_error = 'boom'; // the database could not answer
	return null;
} );
$out = CRDR_Send::send_claimed( 5, '09120000001', 'سلام', '' );
check( 'send_claimed(): a database that cannot answer the last check is "do not send"', array( $out['state'], $out['scope'], crdr_t_gateway() ), array( 'failed', 'db', 0 ) );

// Phase 4.2: the reproduced case. The claim belongs to key u:77, the number being sent to is opted out under p:09120000001.
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->on( 'SELECT ckey FROM wp_crdr_contacts WHERE id', 'u:77' );
$db->on( "kind = 'optout' LIMIT 1", function ( $sql ) {
	return false !== strpos( $sql, "'p:09120000001'" ) ? 1 : 0;
} );
$out = CRDR_Send::send_claimed( 5, '09120000001', 'سلام', '' );
check( 'send_claimed(): claimed key u:77 + a number opted out under p:09120000001 -> refused (optout), the gateway is not called', array( $out['state'], $out['scope'], crdr_t_gateway() ), array( 'failed', 'optout', 0 ) );
$out = CRDR_Send::send_claimed( 5, '+98 912 000 0001', 'سلام', '' );
check( 'send_claimed(): the same number written +98 912 000 0001 is normalised before the lookup', array( $out['scope'], crdr_t_gateway() ), array( 'optout', 0 ) );
$out = CRDR_Send::send_claimed( 5, '09120000002', 'سلام', '' );
check( 'send_claimed(): control: a different number that is not opted out is sent', array( $out['state'], crdr_t_gateway() ), array( 'sent', 1 ) );
$db = crdr_t_world();
crdr_t_ready_db( $db );
crdr_t_set_state( 'done' );
$db->on( 'SELECT ckey FROM wp_crdr_contacts WHERE id', null );
$out = CRDR_Send::send_claimed( 5, '09120000002', 'سلام', '' );
check( 'send_claimed(): a claim that cannot be read is not sent (fails closed)', array( $out['state'], $out['scope'], crdr_t_gateway() ), array( 'failed', 'claim', 0 ) );
// sent_today(): a count that failed is not zero
$db = crdr_t_world();
$db->on( "status IN ('ok','pending','unknown')", function () use ( $db ) {
	$db->last_error = 'Lost connection';
	return null;
} );
check( 'sent_today(): a failed count fails closed (CAP_UNKNOWN), cap_left() is 0, today_shown() is 0', array( CRDR_Playbooks::sent_today( 'reminder' ), CRDR_Playbooks::cap_left( 'reminder', array( 'daily_cap' => 40 ) ), CRDR_Playbooks::today_shown( 'reminder' ) ), array( CRDR_Playbooks::CAP_UNKNOWN, 0, 0 ) );
$db = crdr_t_world();
$db->on( "status IN ('ok','pending','unknown')", '3' );
check( 'sent_today(): a count that worked is the count', array( CRDR_Playbooks::sent_today( 'reminder' ), CRDR_Playbooks::cap_left( 'reminder', array( 'daily_cap' => 40 ) ) ), array( 3, 37 ) );

echo "\n";
printf( "PASS %d   FAIL %d\n", $GLOBALS['CRDR_R']['pass'], $GLOBALS['CRDR_R']['fail'] );
exit( $GLOBALS['CRDR_R']['fail'] ? 1 : 0 );
