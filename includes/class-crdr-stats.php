<?php
/**
 * Pure statistics helpers (no WordPress, no database) so every formula can be unit tested.
 */
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

class CRDR_Stats {

	/** Percentile (0..100) of an ascending-sorted numeric array, linear interpolation. */
	public static function percentile( array $sorted, $p ) {
		$n = count( $sorted );
		if ( 0 === $n ) {
			return 0.0;
		}
		if ( 1 === $n ) {
			return (float) $sorted[0];
		}
		$pos = ( $n - 1 ) * max( 0, min( 100, $p ) ) / 100;
		$lo  = (int) floor( $pos );
		$hi  = (int) ceil( $pos );
		return (float) ( $sorted[ $lo ] + ( $sorted[ $hi ] - $sorted[ $lo ] ) * ( $pos - $lo ) );
	}

	/**
	 * Histogram with half-open buckets (lo, hi]; the first bucket also includes lo.
	 *
	 * @param float[] $values
	 * @param float[] $edges  ascending upper edges, e.g. [7,14,30,60,90]; last bucket is "> last edge"
	 * @return int[] counts, length count($edges)+1
	 */
	public static function histogram( array $values, array $edges ) {
		$counts = array_fill( 0, count( $edges ) + 1, 0 );
		foreach ( $values as $v ) {
			$placed = false;
			foreach ( $edges as $i => $e ) {
				if ( $v <= $e ) {
					$counts[ $i ]++;
					$placed = true;
					break;
				}
			}
			if ( ! $placed ) {
				$counts[ count( $edges ) ]++;
			}
		}
		return $counts;
	}

	/**
	 * Natural re-order probability: among customers whose gap between two orders is still open
	 * after $d days, the share that re-ordered within the next $window days.
	 * Uses the empirical distribution of gaps between consecutive orders.
	 *
	 * @param float[] $gaps   days between consecutive orders (all customers)
	 * @return array [ probability|null, sample_size ]
	 */
	public static function reorder_prob( array $gaps, $d, $window ) {
		$open = 0;
		$hit  = 0;
		foreach ( $gaps as $g ) {
			if ( $g > $d ) {
				$open++;
				if ( $g <= $d + $window ) {
					$hit++;
				}
			}
		}
		return array( $open > 0 ? $hit / $open : null, $open );
	}

	/** Table of reorder_prob for d = 0, step, 2*step ... */
	public static function survival_table( array $gaps, $step = 15, $max = 240, $window = 30 ) {
		$out = array();
		for ( $d = 0; $d <= $max; $d += $step ) {
			list( $p, $n ) = self::reorder_prob( $gaps, $d, $window );
			$out[]         = array( 'd' => $d, 'p' => $p, 'n' => $n );
		}
		return $out;
	}

	/** Share of the total held by the top $fraction of values (e.g. 0.1 => top 10 percent). */
	public static function pareto_share( array $values, $fraction ) {
		$n = count( $values );
		if ( 0 === $n ) {
			return 0.0;
		}
		rsort( $values, SORT_NUMERIC );
		$total = array_sum( $values );
		if ( $total <= 0 ) {
			return 0.0;
		}
		$k = max( 1, (int) ceil( $n * $fraction ) );
		return array_sum( array_slice( $values, 0, $k ) ) / $total;
	}

	/**
	 * Market-basket analysis.
	 *
	 * @param array $baskets  list of arrays of product ids (one array per order)
	 * @param int   $min_support minimum number of orders that contain both products
	 * @param int   $max_basket  baskets larger than this (unique products) are skipped for pairs
	 * @return array each: a, b, support, conf_ab, conf_ba, lift  (sorted by lift*support desc)
	 */
	public static function affinity( array $baskets, $min_support = 3, $max_basket = 12 ) {
		$n      = 0;
		$single = array();
		$pair   = array();
		foreach ( $baskets as $items ) {
			$items = array_values( array_unique( array_map( 'intval', $items ) ) );
			if ( ! $items ) {
				continue;
			}
			$n++;
			foreach ( $items as $p ) {
				$single[ $p ] = ( $single[ $p ] ?? 0 ) + 1;
			}
			$m = count( $items );
			if ( $m < 2 || $m > $max_basket ) {
				continue;
			}
			sort( $items );
			for ( $i = 0; $i < $m; $i++ ) {
				for ( $j = $i + 1; $j < $m; $j++ ) {
					$k          = $items[ $i ] . '-' . $items[ $j ];
					$pair[ $k ] = ( $pair[ $k ] ?? 0 ) + 1;
				}
			}
		}
		$out = array();
		foreach ( $pair as $k => $s ) {
			if ( $s < $min_support ) {
				continue;
			}
			list( $a, $b ) = array_map( 'intval', explode( '-', $k ) );
			$out[]         = array(
				'a'       => $a,
				'b'       => $b,
				'support' => $s,
				'conf_ab' => $s / $single[ $a ],
				'conf_ba' => $s / $single[ $b ],
				'lift'    => ( $s / $n ) / ( ( $single[ $a ] / $n ) * ( $single[ $b ] / $n ) ),
			);
		}
		usort( $out, function ( $x, $y ) {
			return ( $y['lift'] * log( 1 + $y['support'] ) ) <=> ( $x['lift'] * log( 1 + $x['support'] ) );
		} );
		return array( 'orders' => $n, 'singles' => $single, 'pairs' => $out );
	}

	/**
	 * Two-proportion z-test (treated vs control).
	 *
	 * @return array diff (x1/n1 - x2/n2), ci_low, ci_high (95 percent), z, p (two sided), enough (bool)
	 */
	public static function two_prop( $x1, $n1, $x2, $n2 ) {
		if ( $n1 <= 0 || $n2 <= 0 ) {
			return array( 'diff' => 0.0, 'ci_low' => 0.0, 'ci_high' => 0.0, 'z' => 0.0, 'p' => 1.0, 'enough' => false );
		}
		$p1   = $x1 / $n1;
		$p2   = $x2 / $n2;
		$diff = $p1 - $p2;
		$pool = ( $x1 + $x2 ) / ( $n1 + $n2 );
		$se0  = sqrt( $pool * ( 1 - $pool ) * ( 1 / $n1 + 1 / $n2 ) );
		$z    = $se0 > 0 ? $diff / $se0 : 0.0;
		$se   = sqrt( $p1 * ( 1 - $p1 ) / $n1 + $p2 * ( 1 - $p2 ) / $n2 );
		return array(
			'diff'    => $diff,
			'ci_low'  => $diff - 1.96 * $se,
			'ci_high' => $diff + 1.96 * $se,
			'z'       => $z,
			'p'       => 2 * ( 1 - self::norm_cdf( abs( $z ) ) ),
			'enough'  => $n1 >= 30 && $n2 >= 30,
		);
	}

	/** Standard normal CDF (Abramowitz-Stegun 7.1.26 via erf). */
	public static function norm_cdf( $x ) {
		$t    = 1 / ( 1 + 0.3275911 * abs( $x ) / M_SQRT2 );
		$y    = 1 - ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$x * $x / 2 );
		return $x >= 0 ? 0.5 * ( 1 + $y ) : 0.5 * ( 1 - $y );
	}

	/** Round to a "nice" marketing number (e.g. 1,234,567 -> 1,200,000). */
	public static function nice_round( $v ) {
		if ( $v <= 0 ) {
			return 0;
		}
		$mag  = pow( 10, max( 0, floor( log10( $v ) ) - 1 ) );
		$base = $v / $mag;
		$step = $base < 20 ? 1 : ( $base < 50 ? 2.5 : 5 );
		return (int) ( round( $base / $step ) * $step * $mag );
	}

	/**
	 * Free-shipping (or "gift above X") threshold suggestion: pick a threshold between the
	 * 50th and 85th percentile of basket values that has the most orders landing within 25 percent below it
	 * (those are the baskets a small nudge can lift).
	 *
	 * @param float[] $totals
	 * @return array threshold, nudge_orders, nudge_share, median
	 */
	public static function threshold_suggestion( array $totals ) {
		$n = count( $totals );
		if ( $n < 20 ) {
			return null;
		}
		sort( $totals, SORT_NUMERIC );
		$best = null;
		for ( $p = 50; $p <= 85; $p += 5 ) {
			$t = self::nice_round( self::percentile( $totals, $p ) );
			if ( $t <= 0 ) {
				continue;
			}
			$pool = 0;
			foreach ( $totals as $v ) {
				if ( $v >= 0.75 * $t && $v < $t ) {
					$pool++;
				}
			}
			if ( null === $best || $pool > $best['nudge_orders'] ) {
				$best = array( 'threshold' => $t, 'nudge_orders' => $pool, 'nudge_share' => $pool / $n );
			}
		}
		$best['median'] = self::percentile( $totals, 50 );
		return $best;
	}

	/**
	 * Revenue scenario for an audience.
	 *
	 * @return array [ low, mid, high ] extra revenue for uplift points $pts (e.g. [0.03,0.06,0.10])
	 */
	public static function scenario( $audience, $avg_basket, array $pts ) {
		return array_map( function ( $p ) use ( $audience, $avg_basket ) {
			return $audience * $p * $avg_basket;
		}, $pts );
	}

	/**
	 * Cohort retention matrix.
	 *
	 * @param array $customers  list of [ first_month_index, [active_month_index, ...] ]
	 * @param int   $now_month  index of the current month
	 * @param int   $max_cohorts number of most recent cohorts to return
	 * @param int   $max_offset  how many months after the first order to follow
	 * @return array [ month_index => [ 'size' => n, 'ret' => [ offset => pct|null ] ] ]
	 */
	public static function cohorts( array $customers, $now_month, $max_cohorts = 12, $max_offset = 11 ) {
		$by = array();
		foreach ( $customers as $c ) {
			list( $first, $active ) = $c;
			$by[ $first ][]         = array_flip( $active );
		}
		krsort( $by );
		$by  = array_slice( $by, 0, $max_cohorts, true );
		$out = array();
		foreach ( $by as $first => $members ) {
			$size = count( $members );
			$ret  = array();
			for ( $k = 0; $k <= $max_offset; $k++ ) {
				if ( $first + $k > $now_month ) {
					$ret[ $k ] = null; // not observable yet.
					continue;
				}
				$hit = 0;
				foreach ( $members as $act ) {
					if ( isset( $act[ $first + $k ] ) ) {
						$hit++;
					}
				}
				$ret[ $k ] = $size > 0 ? (float) ( 100 * $hit / $size ) : null;
			}
			$out[ $first ] = array( 'size' => $size, 'ret' => $ret );
		}
		ksort( $out );
		return $out;
	}
}
