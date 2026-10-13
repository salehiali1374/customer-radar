<?php
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

/**
 * What an order is worth: NET SALES (Phase 4.2 definition).
 *
 *   net sales of an order = the money for its PRODUCT LINES (after discounts, before tax: WooCommerce's line "total")
 *                           minus the refunds of those lines.
 *   Not in it: shipping, tax, fees. A refund of shipping or tax does not reduce it; neither does a refund recorded against a FEE item (a cash-on-delivery
 *   fee, a handling fee: get_total_refunded_for_item on the order's 'fee' items), which of_order() passes to allocate() as `refund_fee`.
 *
 * Refunds are applied to lines in two steps (allocate()):
 *   1. RECORDED line refunds first: what WooCommerce stored against each line (WC_Order::get_total_refunded_for_item).
 *   2. What is LEFT of the order's refunded amount (refund total minus the recorded line refunds minus the refunded shipping and tax) is spread over
 *      the lines in proportion to what each line is still worth after step 1. A refund made "by amount" (no lines chosen) is handled by this step.
 *      It can never take a line below zero, and it never takes more than the lines are worth.
 * Whatever cannot be explained is reported, never hidden: the UNRECONCILED amount (`gap`), with its reasons:
 *   line_over_refund      a line has more refunded than it was worth (the excess)
 *   exceeds_items         the refund left over after the lines is bigger than the lines' remaining value AND bigger than what shipping / tax / fees could
 *                         explain (order total - lines - refunded shipping and tax)
 *   components_exceed_total   the recorded line + shipping + tax refunds add up to more than the refund total WooCommerce reports
 *
 * Pure arithmetic in whole cents (integers), so the same numbers come out every time and the rounding is exactly reproducible
 * (tests/analytics/fixtures/generate_refund_scenarios.py does it independently). An order whose net sales are not positive is VOID
 * (a fully refunded sale, a free order): it is not a valid order for frequency, repeat counts or customers (see CRDR_Sync).
 */
class CRDR_Sales {

	/** @return int cents */
	private static function cents( $amount ) {
		return (int) round( max( 0.0, (float) $amount ) * 100 );
	}

	/**
	 * @param array $lines          each: total (line total after discount, excl. tax), refund (recorded refund of that line); keys are kept
	 * @param float $refund_total   the order's total refunded amount (WC_Order::get_total_refunded)
	 * @param float $refund_ship    refunded shipping (get_total_shipping_refunded)
	 * @param float $refund_tax     refunded tax (get_total_tax_refunded)
	 * @param float $order_total    the order's total (items + shipping + tax + fees)
	 * @param float $refund_fee     refunds recorded against fee items (get_total_refunded_for_item on 'fee' items); a fee is not revenue, so it is NOT taken from the lines
	 * @return array lines (key => net, float), net (float), gap (float), reasons (name => amount), void (bool)
	 */
	public static function allocate( array $lines, $refund_total, $refund_ship, $refund_tax, $order_total, $refund_fee = 0.0 ) {
		$T = array();
		$L = array();
		foreach ( $lines as $k => $l ) {
			$T[ $k ] = self::cents( isset( $l['total'] ) ? $l['total'] : 0 );
			$L[ $k ] = self::cents( isset( $l['refund'] ) ? $l['refund'] : 0 );
		}
		$reasons = array();
		// 1. recorded line refunds, clamped to the line
		$V          = array();
		$over       = 0;
		$recorded   = 0;
		foreach ( $T as $k => $t ) {
			$recorded += $L[ $k ];
			$eff       = min( $L[ $k ], $t );
			$over     += $L[ $k ] - $eff;
			$V[ $k ]   = $t - $eff;
		}
		if ( $over > 0 ) {
			$reasons['line_over_refund'] = $over;
		}
		// 2. what is left of the order-level refund
		$R_total = self::cents( $refund_total );
		$R_ship  = self::cents( $refund_ship );
		$R_tax   = self::cents( $refund_tax );
		$R_fee   = self::cents( $refund_fee );
		$left    = $R_total - ( $recorded + $R_ship + $R_tax + $R_fee );
		if ( $left < 0 ) {
			$reasons['components_exceed_total'] = -$left;
			$left                               = 0;
		}
		$sumV     = array_sum( $V );
		$eligible = min( $left, $sumV );
		$extra    = $left - $eligible; // refund that the lines cannot absorb
		if ( $extra > 0 ) {
			$capacity = max( 0, self::cents( $order_total ) - array_sum( $T ) - $R_ship - $R_tax - $R_fee ); // shipping, tax, fees not refunded yet
			$explained = min( $extra, $capacity );
			if ( $extra - $explained > 0 ) {
				$reasons['exceeds_items'] = $extra - $explained;
			}
		}
		// proportional allocation of $eligible over the lines, whole cents, largest remainder first.
		// Ties: the larger line first, then the earlier line (a TOTAL order, so PHP 7.4's unstable sort cannot change the result).
		// Exact integer arithmetic while the products fit in a PHP integer (any realistic order); a float estimate beyond that.
		$alloc = array_fill_keys( array_keys( $V ), 0 );
		if ( $eligible > 0 && $sumV > 0 ) {
			$rem  = array();
			$used = 0;
			foreach ( $V as $k => $v ) {
				if ( 0 === $v || $eligible <= intdiv( PHP_INT_MAX, $v ) ) {
					$alloc[ $k ] = intdiv( $eligible * $v, $sumV );
					$rem[ $k ]   = ( $eligible * $v ) % $sumV;      // remainder over the common denominator $sumV: compared exactly
				} else {
					$exact       = $eligible * ( $v / $sumV );
					$alloc[ $k ] = (int) floor( $exact );
					$rem[ $k ]   = ( $exact - $alloc[ $k ] ) * $sumV;
				}
				$used += $alloc[ $k ];
			}
			$pos   = array_flip( array_keys( $V ) );
			$order = array_keys( $V );
			usort( $order, function ( $a, $b ) use ( $rem, $V, $pos ) {
				return $rem[ $b ] <=> $rem[ $a ] ?: ( $V[ $b ] <=> $V[ $a ] ?: $pos[ $a ] <=> $pos[ $b ] );
			} );
			for ( $i = 0, $n = $eligible - $used; $n > 0 && $i < count( $order ); $i++, $n-- ) {
				$alloc[ $order[ $i ] ]++;
			}
			foreach ( $alloc as $k => $a ) { // (a rounding step can never take a line below zero)
				$alloc[ $k ] = min( $a, $V[ $k ] );
			}
		}
		$net   = array();
		$total = 0;
		foreach ( $V as $k => $v ) {
			$n          = $v - $alloc[ $k ];
			$net[ $k ]  = $n / 100;
			$total     += $n;
		}
		return array(
			'lines'   => $net,
			'net'     => $total / 100,
			'gap'     => array_sum( $reasons ) / 100,
			'reasons' => array_map( function ( $c ) {
				return $c / 100;
			}, $reasons ),
			'void'    => $total <= 0,
		);
	}

	/**
	 * The same, read from a WooCommerce order.
	 *
	 * @param WC_Order $order
	 * @return array allocate()'s answer plus `items`: list of [ product id, quantity, net ] for the lines of a real product
	 */
	public static function of_order( $order ) {
		$lines = array();
		$meta  = array();
		foreach ( $order->get_items( 'line_item' ) as $id => $item ) {
			$lines[ $id ] = array( 'total' => (float) $item->get_total(), 'refund' => (float) $order->get_total_refunded_for_item( $id ) );
			$meta[ $id ]  = array( (int) $item->get_product_id(), max( 1, (int) $item->get_quantity() ) );
		}
		$fee_refunded = 0.0;
		foreach ( $order->get_items( 'fee' ) as $fee_id => $fee_item ) {
			$fee_refunded += (float) $order->get_total_refunded_for_item( $fee_id, 'fee' );
		}
		$r = self::allocate(
			$lines,
			(float) $order->get_total_refunded(),
			(float) $order->get_total_shipping_refunded(),
			(float) $order->get_total_tax_refunded(),
			(float) $order->get_total(),
			$fee_refunded
		);
		$items = array();
		foreach ( $r['lines'] as $id => $net ) {
			if ( $meta[ $id ][0] > 0 ) { // a line whose product no longer exists counts in the order but has no product row
				$items[] = array( $meta[ $id ][0], $meta[ $id ][1], $net );
			}
		}
		$r['items'] = $items;
		return $r;
	}
}
