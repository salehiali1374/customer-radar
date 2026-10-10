<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Customers who bought X usually also buy Y" recommendations built from the stored product pairs.
 */
class CRDR_Recommend {

	/**
	 * Best complementary product for a customer: something people with the same purchases usually
	 * buy as well, which this customer has not bought yet, and which is in stock.
	 *
	 * @return array|null id, name, url, because (product name), score
	 */
	public static function for_customer( $ckey ) {
		global $wpdb;
		$stats = CRDR_Analytics::get();
		$pairs = isset( $stats['pairs'] ) ? $stats['pairs'] : array();
		if ( ! $pairs ) {
			return null;
		}
		$bought = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT i.product_id FROM ' . CRDR_DB::orders() . ' o JOIN ' . CRDR_DB::items() . ' i ON i.order_id = o.order_id WHERE o.ckey = %s', // phpcs:ignore WordPress.DB.PreparedSQL
			$ckey
		) ) );
		if ( ! $bought ) {
			return null;
		}
		$have = array_flip( $bought );
		$cand = array();
		foreach ( $pairs as $p ) {
			if ( isset( $have[ $p['a'] ] ) && ! isset( $have[ $p['b'] ] ) ) {
				$cand[] = array( 'id' => $p['b'], 'because' => $p['a'], 'score' => $p['conf_ab'] * $p['lift'] );
			} elseif ( isset( $have[ $p['b'] ] ) && ! isset( $have[ $p['a'] ] ) ) {
				$cand[] = array( 'id' => $p['a'], 'because' => $p['b'], 'score' => $p['conf_ba'] * $p['lift'] );
			}
		}
		usort( $cand, function ( $x, $y ) {
			return $y['score'] <=> $x['score'];
		} );
		foreach ( $cand as $c ) {
			$product = wc_get_product( $c['id'] );
			if ( $product && 'publish' === $product->get_status() && $product->is_purchasable() && $product->is_in_stock() ) {
				return array(
					'id'      => $c['id'],
					'name'    => CRDR_Analytics::product_name( $c['id'] ),
					'url'     => get_permalink( $c['id'] ),
					'because' => CRDR_Analytics::product_name( $c['because'] ),
					'score'   => $c['score'],
				);
			}
		}
		return null;
	}
}
