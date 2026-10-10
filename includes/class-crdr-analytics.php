<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds the analytics bundle (stored in one option) from the aggregate tables.
 * Runs as the last step of every sync; the dashboard only reads the stored result.
 */
class CRDR_Analytics {

	const OPTION = 'crdr_stats';

	/** @return array */
	public static function get() {
		$d = get_option( self::OPTION );
		return is_array( $d ) ? $d : array();
	}

	public static function build() {
		global $wpdb;
		$o = CRDR_DB::orders();
		$c = CRDR_DB::customers();
		$i = CRDR_DB::items();

		$stats = array( 'built' => time() );

		/* --------------------------------------------- one pass over all orders */
		$rows = $wpdb->get_results( "SELECT ckey, ts, net FROM {$o} ORDER BY ckey, ts", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL
		$monthly      = array();
		$gaps         = array();
		$second_days  = array();
		$cust_months  = array();   // ckey => [first_idx, [months]]
		$weekday      = array_fill( 0, 7, 0 );
		$hour         = array_fill( 0, 24, 0 );
		$nets         = array();
		$repeat_rev   = 0.0;
		$total_rev    = 0.0;
		$cust_orders  = array();
		$prev_key     = null;
		$prev_ts      = 0;
		$seq          = 0;
		$last_mi      = -1;
		$first_ts     = 0;
		$cust_rev     = 0.0;

		$flush_customer = function () use ( &$cust_orders, &$repeat_rev, &$cust_rev, &$prev_key ) {
			if ( null !== $prev_key && $cust_orders[ $prev_key ] >= 2 ) {
				$repeat_rev += $cust_rev;
			}
		};

		foreach ( $rows as $r ) {
			list( $ckey, $ts, $net ) = $r;
			$ts  = (int) $ts;
			$net = (float) $net;
			if ( $ckey !== $prev_key ) {
				$flush_customer();
				$prev_key              = $ckey;
				$seq                   = 0;
				$last_mi               = -1;
				$cust_rev              = 0.0;
				$cust_orders[ $ckey ]  = 0;
			}
			$seq++;
			$cust_orders[ $ckey ]++;
			$cust_rev  += $net;
			$total_rev += $net;
			$nets[]     = $net;

			$mi = CRDR_Jalali::month_index( $ts );
			if ( ! isset( $monthly[ $mi ] ) ) {
				$monthly[ $mi ] = array( 'orders' => 0, 'revenue' => 0.0, 'new_rev' => 0.0, 'ret_rev' => 0.0, 'new_cust' => 0, 'active' => 0 );
			}
			$monthly[ $mi ]['orders']++;
			$monthly[ $mi ]['revenue'] += $net;
			if ( 1 === $seq ) {
				$monthly[ $mi ]['new_rev'] += $net;
				$monthly[ $mi ]['new_cust']++;
				$first_ts                   = $ts;
				$cust_months[ $ckey ]       = array( $mi, array() );
			} else {
				$monthly[ $mi ]['ret_rev'] += $net;
				$gap                        = ( $ts - $prev_ts ) / DAY_IN_SECONDS;
				$gaps[]                     = $gap;
				if ( 2 === $seq ) {
					$second_days[] = ( $ts - $first_ts ) / DAY_IN_SECONDS;
				}
			}
			if ( $mi !== $last_mi ) {
				$monthly[ $mi ]['active']++;
				$cust_months[ $ckey ][1][] = $mi;
				$last_mi                   = $mi;
			}
			// Persian week starts on Saturday: wp_date('w') is 0=Sunday .. 6=Saturday.
			$w = (int) wp_date( 'w', $ts );
			$weekday[ ( $w + 1 ) % 7 ]++;
			$hour[ (int) wp_date( 'G', $ts ) ]++;
			$prev_ts = $ts;
		}
		$flush_customer();
		unset( $rows );

		ksort( $monthly );
		$series = array();
		if ( $monthly ) {
			$min = min( array_keys( $monthly ) );
			$max = max( array_keys( $monthly ) );
			for ( $m = max( $min, $max - 23 ); $m <= $max; $m++ ) {
				$x        = isset( $monthly[ $m ] ) ? $monthly[ $m ] : array( 'orders' => 0, 'revenue' => 0, 'new_rev' => 0, 'ret_rev' => 0, 'new_cust' => 0, 'active' => 0 );
				$series[] = array_merge( array( 'idx' => $m, 'label' => CRDR_Jalali::month_label( $m ) ), $x );
			}
		}
		$stats['monthly'] = $series;

		$customers_total = count( $cust_orders );
		$repeaters       = count( array_filter( $cust_orders, function ( $n ) {
			return $n >= 2;
		} ) );
		sort( $second_days, SORT_NUMERIC );
		$stats['orders_total']    = count( $nets );
		$stats['customers_total'] = $customers_total;
		$stats['repeat']          = array(
			'customers'   => $repeaters,
			'customers_pct' => $customers_total ? $repeaters / $customers_total : 0,
			'revenue_pct' => $total_rev > 0 ? $repeat_rev / $total_rev : 0,
			'second_median_days' => $second_days ? CRDR_Stats::percentile( $second_days, 50 ) : null,
		);

		/* ------------------------------------------------------- gap analytics */
		$edges                = array( 7, 14, 30, 45, 60, 90, 120, 180 );
		$stats['gap_hist']    = array( 'edges' => $edges, 'counts' => CRDR_Stats::histogram( $gaps, $edges ), 'n' => count( $gaps ) );
		$stats['survival']    = CRDR_Stats::survival_table( $gaps, 15, 240, 30 );
		sort( $gaps, SORT_NUMERIC );
		$stats['gap_median']  = $gaps ? CRDR_Stats::percentile( $gaps, 50 ) : null;

		/* ------------------------------------------------------ order values */
		sort( $nets, SORT_NUMERIC );
		$stats['aov'] = $nets ? array(
			'mean' => array_sum( $nets ) / count( $nets ),
			'p25'  => CRDR_Stats::percentile( $nets, 25 ),
			'p50'  => CRDR_Stats::percentile( $nets, 50 ),
			'p75'  => CRDR_Stats::percentile( $nets, 75 ),
			'p90'  => CRDR_Stats::percentile( $nets, 90 ),
		) : null;
		$stats['threshold'] = CRDR_Stats::threshold_suggestion( $nets );
		$stats['weekday']   = $weekday;
		$stats['hour']      = $hour;

		/* ---------------------------------------------------------- customers */
		$spent = array_map( 'floatval', $wpdb->get_col( "SELECT total_spent FROM {$c}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$stats['pareto'] = array(
			'top10' => CRDR_Stats::pareto_share( $spent, 0.10 ),
			'top20' => CRDR_Stats::pareto_share( $spent, 0.20 ),
			'top10_count' => max( 1, (int) ceil( count( $spent ) * 0.10 ) ),
			'deciles' => self::deciles( $spent ),
		);

		$grid = array_fill( 1, 5, array_fill( 1, 5, 0 ) );
		foreach ( $wpdb->get_results( "SELECT r, f, COUNT(*) n FROM {$c} WHERE r > 0 GROUP BY r, f", ARRAY_A ) as $g ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$grid[ (int) $g['r'] ][ (int) $g['f'] ] = (int) $g['n'];
		}
		$stats['rfm_grid'] = $grid;

		$now_idx        = CRDR_Jalali::month_index( time() );
		$stats['cohorts'] = CRDR_Stats::cohorts( array_values( $cust_months ), $now_idx, 12, 11 );
		unset( $cust_months );

		/* ------------------------------------------------------------ products */
		$stats['products'] = array_map( function ( $r ) {
			return array( 'id' => (int) $r['product_id'], 'orders' => (int) $r['orders'], 'revenue' => (float) $r['revenue'], 'qty' => (int) $r['qty'] );
		}, (array) $wpdb->get_results(
			"SELECT product_id, COUNT(DISTINCT order_id) orders, SUM(total) revenue, SUM(qty) qty FROM {$i} GROUP BY product_id ORDER BY revenue DESC LIMIT 40", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		) );

		$top_ids = $wpdb->get_col( "SELECT product_id FROM {$i} GROUP BY product_id ORDER BY COUNT(*) DESC LIMIT 300" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$pairs   = array();
		if ( $top_ids ) {
			$allowed = array_flip( array_map( 'intval', $top_ids ) );
			$cursor  = $wpdb->get_results( "SELECT order_id, product_id FROM {$i} ORDER BY order_id", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL
			$baskets = array();
			foreach ( $cursor as $row ) {
				if ( isset( $allowed[ (int) $row[1] ] ) ) {
					$baskets[ $row[0] ][] = (int) $row[1];
				}
			}
			unset( $cursor );
			$n_orders = max( 1, count( $baskets ) );
			$res      = CRDR_Stats::affinity( array_values( $baskets ), max( 3, (int) ceil( $n_orders * 0.003 ) ) );
			$pairs    = array_slice( array_values( array_filter( $res['pairs'], function ( $p ) {
				return $p['lift'] >= 1.2;
			} ) ), 0, 60 );
			$stats['basket_orders'] = $res['orders'];
			$stats['multi_item_pct'] = count( array_filter( $baskets, function ( $b ) {
				return count( array_unique( $b ) ) >= 2;
			} ) ) / $n_orders;
		}
		$stats['pairs'] = $pairs;

		update_option( self::OPTION, $stats, false );
		delete_transient( 'crdr_reco_pairs' );
		return $stats;
	}

	/** Cumulative revenue share held by the top 10%, 20% ... 100% of customers. */
	public static function deciles( array $values ) {
		rsort( $values, SORT_NUMERIC );
		$n     = count( $values );
		$total = array_sum( $values );
		$out   = array();
		for ( $d = 1; $d <= 10; $d++ ) {
			$k     = max( 1, (int) ceil( $n * $d / 10 ) );
			$out[] = $total > 0 && $n > 0 ? array_sum( array_slice( $values, 0, $k ) ) / $total : 0;
		}
		return $out;
	}

	/* ----------------------------------------------------------- helpers */

	private static $names = array();

	public static function product_name( $id ) {
		$id = (int) $id;
		if ( ! isset( self::$names[ $id ] ) ) {
			$t                 = get_the_title( $id );
			self::$names[ $id ] = '' !== $t ? wp_strip_all_tags( $t ) : 'محصول #' . $id;
		}
		return self::$names[ $id ];
	}
}
