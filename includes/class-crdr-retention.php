<?php
defined( 'ABSPATH' ) || exit;

/**
 * Retention sweep for contact records whose customer is gone (Phase 4.2).
 *
 * Why: WooCommerce can anonymise old orders on its own schedule ("Accounts & Privacy"), and that does not call the WordPress privacy
 * hooks. After the next rebuild the customer of those orders disappears from this plugin, but the rows of `crdr_contacts` that were
 * written for that customer (the text of the SMS with the person's name in it, the discount code, the staff id) stayed forever, and
 * nothing in the admin could reach them.
 *
 * What it does, and ONLY at the end of a rebuild that finished successfully (phase "done", written under the sync lock; a failed,
 * interrupted or stalled rebuild never gets here):
 *   1. mark   : a non-opt-out contact row whose customer key has no row in `crdr_customers` gets orphan_ts = now the first time that is seen;
 *               a row whose customer is back (new order with the same phone / e-mail) gets orphan_ts = 0 again.
 *   2. sweep  : rows orphaned for at least SWEEP_DAYS (90) lose what is not needed any more: message, error text, discount code, staff id,
 *               run id, gateway id. Nothing else is touched.
 *
 * What stays on a swept row: customer key, kind, campaign, group, status and time. The customer key of a customer with a phone is
 * `p:<phone number>`, so the phone number STAYS in that row. It is kept because (a) a cooldown / "recently contacted" check and the campaign
 * results are computed from these columns, and (b) the person may order again with the same number, and the cooldown must then still hold.
 * Those rows are removed only by the privacy eraser, the full-erasure uninstall, or by hand (docs/data-retention.md).
 *
 * Opt-out rows are never marked, swept or changed: their key and time are exactly what keeps a person from being messaged again.
 *
 * It does not run when the customer table is empty (nothing to compare with: an empty store, or statuses that count no order): an empty
 * table must not turn every contact row into an "orphan".
 */
class CRDR_Retention {

	const OPTION     = 'crdr_retention_state';
	const SWEEP_DAYS = 90;

	/** Rows changed per statement, and the most statements per rebuild (a very large backlog is finished by the next rebuilds). */
	const CHUNK      = 5000;
	const MAX_ROUNDS = 40;

	/**
	 * Mark and sweep. Call only after a rebuild has finished successfully.
	 *
	 * @param int|null $now unix time (tests)
	 * @return array ran (bool), marked, cleared (rows changed), swept (rows minimised), error ('' or a code)
	 */
	public static function run( $now = null ) {
		global $wpdb;
		$now = null === $now ? time() : (int) $now;
		$out = array( 'ran' => false, 'ts' => $now, 'marked' => 0, 'cleared' => 0, 'swept' => 0, 'error' => '' );
		$k   = CRDR_DB::contacts();
		$c   = CRDR_DB::customers();
		try {
			$customers = $wpdb->get_var( "SELECT COUNT(*) FROM {$c}" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( null === $customers || $wpdb->last_error ) {
				throw new RuntimeException( 'database_error' );
			}
			if ( 0 === (int) $customers ) {
				$out['error'] = 'no_customers'; // not an error of the database: there is nothing to compare with
				return self::store( $out );
			}
			$out['ran'] = true;
			// 1. a customer is back -> not orphaned any more
			$r = $wpdb->query( "UPDATE {$k} SET orphan_ts = 0 WHERE orphan_ts > 0 AND EXISTS (SELECT 1 FROM {$c} cu WHERE cu.ckey = {$k}.ckey)" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( false === $r ) {
				throw new RuntimeException( 'database_error' );
			}
			$out['cleared'] = (int) $r;
			// 2. first time a non-opt-out row is seen without a customer
			$r = $wpdb->query( $wpdb->prepare( "UPDATE {$k} SET orphan_ts = %d WHERE kind <> 'optout' AND orphan_ts = 0 AND NOT EXISTS (SELECT 1 FROM {$c} cu WHERE cu.ckey = {$k}.ckey)", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( false === $r ) {
				throw new RuntimeException( 'database_error' );
			}
			$out['marked'] = (int) $r;
			// 3. old enough: minimise (a pending claim is a send in flight and is never touched)
			$cut = $now - self::SWEEP_DAYS * DAY_IN_SECONDS;
			for ( $i = 0; $i < self::MAX_ROUNDS; $i++ ) {
				$r = $wpdb->query( $wpdb->prepare(
					"UPDATE {$k} SET message = '', error = '', coupon = '', user_id = 0, run_id = '', localid = ''
					 WHERE kind <> 'optout' AND status <> 'pending' AND orphan_ts > 0 AND orphan_ts <= %d
					   AND ( message <> '' OR error <> '' OR coupon <> '' OR user_id <> 0 OR run_id <> '' OR localid <> '' ) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
					$cut,
					self::CHUNK
				) );
				if ( false === $r ) {
					throw new RuntimeException( 'database_error' );
				}
				$out['swept'] += (int) $r;
				if ( (int) $r < self::CHUNK ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$out['error'] = 'database_error'; // a code only: no SQL, no data
		}
		return self::store( $out );
	}

	/** What the last run did (for the admin / support). */
	public static function last() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function store( array $out ) {
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/** Rows without a customer that are not (yet) minimised and not opt-outs: what an eraser cannot attribute to a person. */
	public static function unattributed_count() {
		global $wpdb;
		$k = CRDR_DB::contacts();
		$c = CRDR_DB::customers();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$k} WHERE kind <> 'optout' AND ( message <> '' OR coupon <> '' ) AND NOT EXISTS (SELECT 1 FROM {$c} cu WHERE cu.ckey = {$k}.ckey)" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
