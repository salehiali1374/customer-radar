<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sends SMS through the free "Persian WooCommerce SMS" plugin when it is active,
 * or directly through Kavenegar. Returns array( bool ok, string error ).
 */
class CRDR_SMS {

	/** Which provider will actually be used. */
	public static function provider() {
		$p = CRDR_Settings::get( 'sms_provider' );
		if ( 'auto' === $p ) {
			if ( function_exists( 'PWSMS' ) ) {
				return 'pwsms';
			}
			if ( CRDR_Settings::get( 'kavenegar_key' ) ) {
				return 'kavenegar';
			}
			return 'none';
		}
		return $p;
	}

	public static function provider_label() {
		$map = array(
			'pwsms'     => 'افزونه پیامک ووکامرس',
			'kavenegar' => 'کاوه‌نگار (مستقیم)',
			'none'      => 'فعال نیست',
		);
		$p = self::provider();
		return isset( $map[ $p ] ) ? $map[ $p ] : $p;
	}

	/**
	 * @param string $phone   09xxxxxxxxx
	 * @param string $message
	 * @return array [ bool $ok, string $error ]
	 */
	public static function send( $phone, $message ) {
		$phone = CRDR_Sync::normalize_phone( $phone );
		if ( '' === $phone ) {
			return array( false, 'شماره موبایل معتبر نیست.' );
		}
		if ( '' === trim( $message ) ) {
			return array( false, 'متن پیامک خالی است.' );
		}
		switch ( self::provider() ) {
			case 'pwsms':
				return self::send_pwsms( $phone, $message );
			case 'kavenegar':
				return self::send_kavenegar( $phone, $message );
		}
		return array( false, 'سرویس پیامک تنظیم نشده است. از بخش تنظیمات یکی را انتخاب کنید.' );
	}

	private static function send_pwsms( $phone, $message ) {
		if ( ! function_exists( 'PWSMS' ) ) {
			return array( false, 'افزونه پیامک ووکامرس فعال نیست.' );
		}
		$type = class_exists( '\PW\PWSMS\Enums\EventsEnum' ) ? \PW\PWSMS\Enums\EventsEnum::BULK_SEND : 1;
		$r    = PWSMS()->send_sms( array(
			'type'    => $type,
			'mobile'  => array( $phone ),
			'message' => $message,
		) );
		if ( true === $r ) {
			return array( true, '' );
		}
		return array( false, is_string( $r ) && '' !== $r ? $r : 'ارسال ناموفق بود.' );
	}

	private static function send_kavenegar( $phone, $message ) {
		$key = CRDR_Settings::get( 'kavenegar_key' );
		if ( ! $key ) {
			return array( false, 'کلید API کاوه‌نگار وارد نشده است.' );
		}
		$body = array(
			'receptor' => $phone,
			'message'  => $message,
		);
		$from = CRDR_Settings::get( 'kavenegar_from' );
		if ( $from ) {
			$body['sender'] = $from;
		}
		$resp = wp_remote_post( 'https://api.kavenegar.com/v1/' . rawurlencode( $key ) . '/sms/send.json', array(
			'timeout' => 20,
			'body'    => $body,
		) );
		if ( is_wp_error( $resp ) ) {
			return array( false, $resp->get_error_message() );
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		$code = isset( $json['return']['status'] ) ? (int) $json['return']['status'] : 0;
		if ( 200 === $code ) {
			return array( true, '' );
		}
		$msg = isset( $json['return']['message'] ) ? $json['return']['message'] : 'پاسخ نامعتبر از کاوه‌نگار';
		return array( false, 'کاوه‌نگار (' . $code . '): ' . $msg );
	}
}
