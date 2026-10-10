<?php
defined( 'ABSPATH' ) || exit;

class CRDR_Settings {

	const OPTION = 'crdr_settings';

	public static function defaults() {
		return array(
			'statuses'       => array( 'completed', 'processing' ),
			'new_days'       => 30,
			'lost_days'      => 365,
			'min_ratio'      => 0.9,
			'default_gap'    => 60,
			'cooldown_days'  => 30,
			'sms_provider'   => 'auto', // auto | pwsms | kavenegar | none
			'kavenegar_key'  => '',
			'kavenegar_from' => '',
			'sms_template'   => 'سلام {name} عزیز، مدتی است از شما در {store} خبری نداریم. دلتنگتان شدیم!',
			'sms_template_coupon' => 'سلام {name} عزیز، برای شما در {store} کد تخفیف {discount}٪ با کد {coupon} فعال کردیم (تا {expire}).',
			'coupon_percent' => 10,
			'coupon_days'    => 7,
			'coupon_min'     => 0,
			'optout_text'    => 'لغو۱۱',
			'send_from'      => 9,
			'send_to'        => 21,
			'margin_pct'     => 30,
			'bundle_discount' => 8,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Validate and store the settings form. */
	public static function save( array $in ) {
		$out = self::sanitize( $in );
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/** Persian problems with the two manual-SMS templates of a sanitized settings array; empty = valid. */
	public static function template_errors( array $s ) {
		$errors = array();
		foreach ( array(
			'sms_template'        => array( 'متن پیامک ساده', false ),
			'sms_template_coupon' => array( 'متن پیامک با کد تخفیف', true ),
		) as $key => $def ) {
			foreach ( CRDR_Template::validate( isset( $s[ $key ] ) ? $s[ $key ] : '', array( 'offer' => $def[1] ) ) as $e ) {
				$errors[] = $def[0] . ': ' . $e;
			}
		}
		return $errors;
	}

	public static function draft_key() {
		return 'crdr_settings_draft_' . get_current_user_id();
	}

	/** The rejected form values and their errors (one-shot), or null. */
	public static function take_draft() {
		$d = get_transient( self::draft_key() );
		delete_transient( self::draft_key() );
		return is_array( $d ) ? $d : null;
	}

	/** Clean the submitted settings form without storing anything. */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();

		$valid_statuses = array_keys( wc_get_order_statuses() );
		$st             = isset( $in['statuses'] ) ? (array) $in['statuses'] : $d['statuses'];
		$st             = array_map( 'sanitize_key', $st );
		$st             = array_values( array_filter( array_map( function ( $s ) {
			return 0 === strpos( $s, 'wc-' ) ? substr( $s, 3 ) : $s;
		}, $st ) ) );
		$st             = array_values( array_intersect( $st, array_map( function ( $s ) {
			return substr( $s, 3 );
		}, $valid_statuses ) ) );
		$out['statuses'] = $st ? $st : $d['statuses'];

		$out['new_days']      = max( 1, min( 365, (int) ( $in['new_days'] ?? $d['new_days'] ) ) );
		$out['lost_days']     = max( 60, min( 3650, (int) ( $in['lost_days'] ?? $d['lost_days'] ) ) );
		$out['min_ratio']     = max( 0.5, min( 2.0, (float) ( $in['min_ratio'] ?? $d['min_ratio'] ) ) );
		$out['default_gap']   = max( 7, min( 365, (int) ( $in['default_gap'] ?? $d['default_gap'] ) ) );
		$out['cooldown_days'] = max( 0, min( 365, (int) ( $in['cooldown_days'] ?? $d['cooldown_days'] ) ) );

		$prov                 = isset( $in['sms_provider'] ) ? sanitize_key( $in['sms_provider'] ) : 'auto';
		$out['sms_provider']  = in_array( $prov, array( 'auto', 'pwsms', 'kavenegar', 'none' ), true ) ? $prov : 'auto';
		$out['kavenegar_key'] = isset( $in['kavenegar_key'] ) ? preg_replace( '/[^A-Za-z0-9=+\/_\-]/', '', (string) $in['kavenegar_key'] ) : '';
		$out['kavenegar_from'] = isset( $in['kavenegar_from'] ) ? preg_replace( '/[^0-9A-Za-z]/', '', (string) $in['kavenegar_from'] ) : '';

		// The caller passes already-unslashed input.
		$out['sms_template']        = isset( $in['sms_template'] ) ? sanitize_textarea_field( $in['sms_template'] ) : $d['sms_template'];
		$out['sms_template_coupon'] = isset( $in['sms_template_coupon'] ) ? sanitize_textarea_field( $in['sms_template_coupon'] ) : $d['sms_template_coupon'];

		$out['coupon_percent'] = max( 1, min( 90, (int) ( $in['coupon_percent'] ?? $d['coupon_percent'] ) ) );
		$out['coupon_days']    = max( 1, min( 90, (int) ( $in['coupon_days'] ?? $d['coupon_days'] ) ) );
		$out['coupon_min']     = max( 0, (float) ( $in['coupon_min'] ?? $d['coupon_min'] ) );

		$out['optout_text']     = mb_substr( sanitize_text_field( isset( $in['optout_text'] ) ? $in['optout_text'] : $d['optout_text'] ), 0, 30 );
		$out['send_from']       = max( 0, min( 23, (int) ( $in['send_from'] ?? $d['send_from'] ) ) );
		$out['send_to']         = max( 1, min( 24, (int) ( $in['send_to'] ?? $d['send_to'] ) ) );
		$out['margin_pct']      = max( 1, min( 95, (int) ( $in['margin_pct'] ?? $d['margin_pct'] ) ) );
		$out['bundle_discount'] = max( 0, min( 50, (int) ( $in['bundle_discount'] ?? $d['bundle_discount'] ) ) );

		return $out;
	}
}
