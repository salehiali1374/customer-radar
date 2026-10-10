<?php
/**
 * RFM scoring, segmentation and call-list priority.
 * Pure logic with no database or WordPress calls, so it is fully unit testable.
 */
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

class CRDR_RFM {

	const DAY = 86400;

	/** Segment keys => Persian label. */
	public static function segments() {
		return array(
			'champion'       => 'قهرمان',
			'loyal'          => 'وفادار',
			'new'            => 'جدید',
			'potential'      => 'بالقوه',
			'need_attention' => 'نیازمند توجه',
			'at_risk'        => 'در خطر ریزش',
			'cant_lose'      => 'ارزشمند در حال ریزش',
			'hibernating'    => 'خواب‌رفته',
			'lost'           => 'ازدست‌رفته',
		);
	}

	/** One-line explanation per segment (shown in the UI). */
	public static function segment_hints() {
		return array(
			'champion'       => 'اخیراً و زیاد خرید کرده؛ ارزش‌مندترین گروه.',
			'loyal'          => 'خرید تکرارشونده و منظم دارد.',
			'new'            => 'اولین خرید، در چند روز اخیر.',
			'potential'      => 'اخیراً خرید کرده ولی هنوز تکرار خرید جدی ندارد.',
			'need_attention' => 'فاصله از آخرین خرید در حال زیاد شدن است.',
			'at_risk'        => 'قبلاً مشتری تکراری بوده ولی مدتی است نخریده.',
			'cant_lose'      => 'پرخرید یا پرفروش بوده و مدتی است نخریده؛ اولویت تماس.',
			'hibernating'    => 'کم‌خرید و مدت زیادی است خرید نکرده.',
			'lost'           => 'تک‌خرید و بسیار قدیمی؛ بازگرداندنش کم‌احتمال است.',
		);
	}

	/** Relative weight of a segment in the call-list priority (heuristic, filterable by caller). */
	public static function segment_weight( $segment ) {
		$w = array(
			'champion'       => 1.0,
			'loyal'          => 1.0,
			'new'            => 0.0, // never call a brand-new customer.
			'potential'      => 0.7,
			'need_attention' => 0.8,
			'at_risk'        => 1.0,
			'cant_lose'      => 1.3,
			'hibernating'    => 0.4,
			'lost'           => 0.15,
		);
		return isset( $w[ $segment ] ) ? $w[ $segment ] : 0.5;
	}

	/**
	 * Cut points that split a list of numbers into 5 equal groups.
	 *
	 * @param float[] $values
	 * @return float[] four ascending cut points
	 */
	public static function cutoffs( array $values ) {
		$n = count( $values );
		if ( 0 === $n ) {
			return array( 0, 0, 0, 0 );
		}
		sort( $values, SORT_NUMERIC );
		$cuts = array();
		for ( $k = 1; $k <= 4; $k++ ) {
			$idx    = (int) floor( $k * ( $n - 1 ) / 5 );
			$cuts[] = $values[ $idx ];
		}
		return $cuts;
	}

	/**
	 * Score 1..5 where a larger value is better. Degenerate distributions (all equal) get a neutral 3.
	 */
	public static function score_high_better( $value, array $cuts ) {
		if ( $cuts[0] == $cuts[3] ) {
			return 3;
		}
		$score = 1;
		foreach ( $cuts as $c ) {
			if ( $value > $c ) {
				$score++;
			}
		}
		return min( 5, $score );
	}

	/** Recency score: fewer days since the last order is better. */
	public static function score_recency( $days, array $day_cuts ) {
		// Mirror the values so "high is better" logic can be reused.
		$neg = array_map( function ( $c ) {
			return -$c;
		}, $day_cuts );
		sort( $neg, SORT_NUMERIC );
		return self::score_high_better( -$days, $neg );
	}

	/** Frequency score from the absolute number of orders. */
	public static function score_frequency( $orders ) {
		$orders = (int) $orders;
		if ( $orders <= 1 ) {
			return 1;
		}
		if ( $orders == 2 ) {
			return 2;
		}
		if ( $orders == 3 ) {
			return 3;
		}
		if ( $orders <= 5 ) {
			return 4;
		}
		return 5;
	}

	/**
	 * @param int   $r
	 * @param int   $f
	 * @param int   $m
	 * @param int   $orders      total number of orders
	 * @param float $days_since  days since the last order
	 * @param array $opt         new_days (30), lost_days (365)
	 * @return string segment key
	 */
	public static function segment( $r, $f, $m, $orders, $days_since, array $opt = array() ) {
		$new_days  = isset( $opt['new_days'] ) ? (float) $opt['new_days'] : 30;
		$lost_days = isset( $opt['lost_days'] ) ? (float) $opt['lost_days'] : 365;

		if ( $orders <= 1 && $days_since <= $new_days ) {
			return 'new';
		}
		if ( $r >= 4 && $f >= 4 ) {
			return 'champion';
		}
		if ( $r >= 3 && $f >= 3 ) {
			return 'loyal';
		}
		if ( $r >= 4 && $f <= 2 ) {
			return 'potential';
		}
		if ( $r <= 2 && ( $f >= 4 || ( $f >= 3 && $m >= 4 ) ) ) {
			return 'cant_lose';
		}
		if ( $r <= 2 && $f >= 3 ) {
			return 'at_risk';
		}
		if ( $r == 3 && $f <= 2 ) {
			return 'need_attention';
		}
		if ( $r <= 2 && $f <= 2 ) {
			return ( $orders <= 1 && $days_since > $lost_days ) ? 'lost' : 'hibernating';
		}
		return 'need_attention';
	}

	/**
	 * Expected number of days between two orders for this customer.
	 * Repeat buyers use their own average gap; single-order customers use the store-wide median gap.
	 */
	public static function expected_gap_days( $orders, $first_ts, $last_ts, $store_median_gap ) {
		if ( $orders >= 2 && $last_ts > $first_ts ) {
			$gap = ( $last_ts - $first_ts ) / self::DAY / ( $orders - 1 );
			return (float) max( 1.0, $gap );
		}
		return (float) max( 1.0, (float) $store_median_gap );
	}

	/**
	 * Call-list priority. 0 means "do not call now".
	 *
	 * This is a transparent heuristic, NOT a statistical forecast:
	 *   priority = average order value x timing weight x segment weight
	 * Timing weight is 1 while the customer is 0.9..1.6 gaps overdue, then fades to 0.2 at 3 gaps.
	 *
	 * @return float
	 */
	public static function priority( $avg_order_value, $days_since, $gap_days, $segment, $min_ratio = 0.9 ) {
		$ratio = $days_since / max( 1.0, $gap_days );
		if ( $ratio < $min_ratio || $ratio > 3.0 ) {
			return 0.0;
		}
		if ( $ratio <= 1.6 ) {
			$timing = 1.0;
		} else {
			$timing = 1.0 - 0.8 * ( ( $ratio - 1.6 ) / 1.4 ); // 1.0 -> 0.2
		}
		return round( (float) $avg_order_value * $timing * self::segment_weight( $segment ), 2 );
	}

	public static function median( array $values ) {
		$n = count( $values );
		if ( 0 === $n ) {
			return 0.0;
		}
		sort( $values, SORT_NUMERIC );
		$mid = (int) floor( $n / 2 );
		return ( $n % 2 ) ? (float) $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	/**
	 * Score a whole customer set.
	 *
	 * @param array $rows  each: id, orders_count, total_spent, first_ts, last_ts
	 * @param int   $now   unix time
	 * @param array $opt   new_days, lost_days, min_ratio, default_gap
	 * @return array id => [r,f,m,segment,avg_gap_days,due_ts,priority]
	 */
	public static function score_all( array $rows, $now, array $opt = array() ) {
		$default_gap = isset( $opt['default_gap'] ) ? (float) $opt['default_gap'] : 60.0;
		$min_ratio   = isset( $opt['min_ratio'] ) ? (float) $opt['min_ratio'] : 0.9;

		$days = array();
		$spent = array();
		$gaps = array();
		foreach ( $rows as $row ) {
			$days[]  = max( 0, ( $now - (int) $row['last_ts'] ) / self::DAY );
			$spent[] = (float) $row['total_spent'];
			if ( (int) $row['orders_count'] >= 2 && $row['last_ts'] > $row['first_ts'] ) {
				$gaps[] = ( $row['last_ts'] - $row['first_ts'] ) / self::DAY / ( $row['orders_count'] - 1 );
			}
		}
		$day_cuts     = self::cutoffs( $days );
		$spent_cuts   = self::cutoffs( $spent );
		$store_median = $gaps ? self::median( $gaps ) : $default_gap;

		$out = array();
		foreach ( $rows as $row ) {
			$orders     = (int) $row['orders_count'];
			$days_since = max( 0, ( $now - (int) $row['last_ts'] ) / self::DAY );
			$r          = self::score_recency( $days_since, $day_cuts );
			$f          = self::score_frequency( $orders );
			$m          = self::score_high_better( (float) $row['total_spent'], $spent_cuts );
			$seg        = self::segment( $r, $f, $m, $orders, $days_since, $opt );
			$gap        = self::expected_gap_days( $orders, (int) $row['first_ts'], (int) $row['last_ts'], $store_median );
			$aov        = $orders > 0 ? (float) $row['total_spent'] / $orders : 0.0;
			$out[ $row['id'] ] = array(
				'r'            => $r,
				'f'            => $f,
				'm'            => $m,
				'segment'      => $seg,
				'avg_gap_days' => round( $gap, 1 ),
				'due_ts'       => (int) ( $row['last_ts'] + $gap * self::DAY ),
				'priority'     => self::priority( $aov, $days_since, $gap, $seg, $min_ratio ),
			);
		}
		return $out;
	}
}
