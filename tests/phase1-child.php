<?php
/**
 * Worker started by tests/phase1-safety.php (one OS process = one database connection) so that the
 * concurrency tests are real. Usage:  wp eval-file phase1-child.php <job> [args...] <start-time>
 * The last argument is a Unix time (float): every worker waits for it, so they all start together.
 */
if ( 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) || '1' !== get_option( 'crdr_test_shop' ) ) {
	echo wp_json_encode( array( 'error' => 'not the throwaway test shop' ) ) . "\n";
	return;
}
require_once __DIR__ . '/phase1-mock.php';
crdr_t_install_mock();

$args = array_values( $args );
$t0   = (float) array_pop( $args );
$job  = array_shift( $args );
$wait = $t0 - microtime( true );
if ( $wait > 0 ) {
	usleep( (int) ( $wait * 1000000 ) );
}

global $wpdb;
switch ( $job ) {
	case 'run': // a whole campaign run, as cron (trigger=cron) or as a person (trigger=manual)
		wp_set_current_user( 'manual' === $args[0] ? 1 : 0 );
		echo wp_json_encode( CRDR_Playbooks::run( 'dormant', (int) $args[1], array( 'trigger' => $args[0] ) ) ) . "\n";
		break;

	case 'claimsend': // claim + send every customer, no run lock: only the unique claim protects us
		$out = array( 'sent' => 0, 'busy' => 0, 'cooldown' => 0, 'other' => 0 );
		foreach ( $wpdb->get_results( 'SELECT ckey, phone FROM ' . CRDR_DB::customers() . ' ORDER BY id', ARRAY_A ) as $c ) {
			$claim = CRDR_Send::claim( $c['ckey'], 'dormant', 'child' . getmypid() );
			if ( isset( $claim['skip'] ) ) {
				$out[ isset( $out[ $claim['skip'] ] ) ? $claim['skip'] : 'other' ]++;
				continue;
			}
			$r = CRDR_Send::send_claimed( $claim['id'], $c['phone'], 'سلام ' . $c['ckey'] );
			$out['sent'] += 'sent' === $r['state'] ? 1 : 0;
		}
		echo wp_json_encode( $out ) . "\n";
		break;

	case 'retry_rejected': // the admin-post handler "retry rejected numbers": args user id, good|bad nonce, campaign id
		wp_set_current_user( (int) $args[0] );
		$_POST = $_REQUEST = array( 'id' => $args[2], '_wpnonce' => 'good' === $args[1] ? wp_create_nonce( CRDR_Actions::NONCE ) : 'bad' );
		add_filter( 'wp_redirect', function ( $loc ) use ( $args ) {
			echo wp_json_encode( array( 'redirect' => $loc, 'epoch' => CRDR_Send::reject_epoch( $args[2] ) ) ) . "\n";
			exit( 0 );
		} );
		CRDR_Actions::post_retry_rejected();
		echo wp_json_encode( array( 'error' => 'no redirect' ) ) . "\n";
		break;

	case 'save_settings': // the real Settings save handler: args user id, send_from, send_to
		wp_set_current_user( (int) $args[0] );
		$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( CRDR_Actions::NONCE ), 'send_from' => $args[1], 'send_to' => $args[2] );
		add_filter( 'wp_redirect', function ( $loc ) {
			echo wp_json_encode( array( 'redirect' => $loc ) ) . "\n";
			exit( 0 );
		} );
		CRDR_Actions::post_save_settings();
		echo wp_json_encode( array( 'error' => 'no redirect' ) ) . "\n";
		break;

	case 'sync_lock': // try to take the sync lock and hold it for N ms: only one process may succeed
		$m   = new ReflectionMethod( 'CRDR_Sync', 'lock' );
		$m->setAccessible( true );
		$got = $m->invoke( null );
		if ( false !== $got ) {
			usleep( (int) $args[0] * 1000 );
			CRDR_Sync::unlock( $got );
		}
		echo wp_json_encode( array( 'got' => false !== $got ) ) . "\n";
		break;

	case 'sync_step': // one real sync step
		$st = CRDR_Sync::step();
		echo wp_json_encode( array( 'busy' => ! empty( $st['busy'] ), 'phase' => $st['phase'], 'page' => isset( $st['page'] ) ? $st['page'] : 0 ) ) . "\n";
		break;

	case 'identity_die': // one "finalize" step that dies (exit, as after a fatal error) right BEFORE its k-th write after the evidence query; k = 0 only counts
		$k    = (int) $args[0];
		$n    = 0;
		$seen = false;
		add_filter( 'query', function ( $q ) use ( $k, &$n, &$seen ) {
			if ( false !== strpos( $q, 'SELECT ckey, uid, email FROM' ) ) {
				$seen = true;
			}
			if ( $seen && preg_match( '/^\s*(INSERT|UPDATE|DELETE)/i', $q ) && ++$n === $k ) {
				echo wp_json_encode( array( 'died_before_write' => $n ) ) . "\n";
				exit( 0 );
			}
			return $q;
		} );
		$st = CRDR_Sync::step();
		echo wp_json_encode( array( 'finished' => true, 'writes' => $n, 'phase' => $st['phase'] ) ) . "\n";
		break;

	case 'sync_die': // a step that dies in the middle of a page (exit during the 20th order), like a timeout or a fatal error would
		$n = 0;
		add_filter( 'woocommerce_order_get_billing_phone', function ( $v ) use ( &$n ) {
			if ( ++$n >= 20 ) {
				echo wp_json_encode( array( 'died_after_orders' => $n ) ) . "\n";
				exit( 0 ); // shutdown functions still run, exactly as after a fatal error
			}
			return $v;
		} );
		CRDR_Sync::step();
		echo wp_json_encode( array( 'died' => false ) ) . "\n";
		break;

	case 'export': // the CSV export handler: it must refuse (wp_die) while the data is rebuilt, and stream otherwise
		wp_set_current_user( 1 );
		$_GET = $_REQUEST = array( '_wpnonce' => wp_create_nonce( CRDR_Actions::NONCE ) );
		add_filter( 'wp_die_handler', function () {
			return function ( $message ) {
				echo wp_json_encode( array( 'refused' => true, 'message' => wp_strip_all_tags( (string) $message ) ) ) . "\n";
				exit( 0 );
			};
		} );
		ob_start();
		CRDR_Actions::post_export(); // streams and calls exit when it is allowed
		echo wp_json_encode( array( 'refused' => false ) ) . "\n";
		break;

	case 'crash': // claim, talk to the gateway, then die before the outcome is recorded
		$c     = CRDR_Actions::customer_by_key( $args[0] );
		$claim = CRDR_Send::claim( $c['ckey'], 'dormant', 'crash' );
		if ( isset( $claim['skip'] ) ) {
			echo wp_json_encode( array( 'claimed' => false, 'skip' => $claim['skip'] ) ) . "\n";
			break;
		}
		// Exactly what send_claimed() does before it talks to the gateway: record the text and the localid first.
		$localid = CRDR_Send_Rules::localid();
		$an      = new ReflectionMethod( 'CRDR_Send', 'annotate' );
		$an->setAccessible( true );
		$an->invoke( null, $claim['id'], 'سلام', '', $localid );
		CRDR_SMS::deliver( $c['phone'], 'سلام', $localid );
		echo wp_json_encode( array( 'claimed' => true, 'row' => $claim['id'] ) ) . "\n";
		exit( 0 ); // no finalize(): the row stays 'pending'
}
