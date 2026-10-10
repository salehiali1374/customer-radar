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
	 * Legacy two-value form of deliver(): an uncertain outcome counts as "not ok" here, so callers that
	 * cannot tell the difference must treat it as failed. Campaign sending uses deliver() directly.
	 *
	 * @param string $phone   09xxxxxxxxx
	 * @param string $message
	 * @return array [ bool $ok, string $error ]
	 */
	public static function send( $phone, $message ) {
		$r = self::deliver( $phone, $message );
		return array( 'sent' === $r['state'], $r['error'] );
	}

	/**
	 * Send one SMS and say what we actually know about the result.
	 *
	 * state:
	 *   sent    the gateway accepted it
	 *   failed  definitely NOT sent (invalid input, rejected by the gateway, request never left this server)
	 *   unknown the request may have reached the gateway (timeout, dropped connection, unreadable answer):
	 *           the SMS may well have been sent, so the caller must not retry it
	 *
	 * @param string $localid numeric id passed to Kavenegar so that a repeated request is recognised
	 * @return array state, error (Persian text for the owner)
	 */
	public static function deliver( $phone, $message, $localid = '' ) {
		$phone = CRDR_Sync::normalize_phone( $phone );
		if ( '' === $phone ) {
			return self::result( 'failed', 'شماره موبایل معتبر نیست.' );
		}
		if ( '' === trim( $message ) ) {
			return self::result( 'failed', 'متن پیامک خالی است.' );
		}
		switch ( self::provider() ) {
			case 'pwsms':
				return self::send_pwsms( $phone, $message );
			case 'kavenegar':
				return self::send_kavenegar( $phone, $message, $localid );
		}
		return self::result( 'failed', 'سرویس پیامک تنظیم نشده است. از بخش تنظیمات یکی را انتخاب کنید.' );
	}

	private static function result( $state, $error = '' ) {
		return array( 'state' => $state, 'error' => $error );
	}

	/** Owner-facing explanation of an uncertain outcome. */
	private static function unknown_text( $detail ) {
		return 'پاسخ پیامک مشخص نشد؛ ممکن است پیامک ارسال شده باشد. برای جلوگیری از پیام تکراری، دوباره ارسال نمی‌شود و مشتری «ارسال‌شده» حساب می‌شود. وضعیت را در پنل سرویس پیامک ببینید. (' . $detail . ')';
	}

	private static function send_pwsms( $phone, $message ) {
		if ( ! function_exists( 'PWSMS' ) ) {
			return self::result( 'failed', 'افزونه پیامک ووکامرس فعال نیست.' );
		}
		$type = class_exists( '\PW\PWSMS\Enums\EventsEnum' ) ? \PW\PWSMS\Enums\EventsEnum::BULK_SEND : 1;
		try {
			$r = PWSMS()->send_sms( array(
				'type'    => $type,
				'mobile'  => array( $phone ),
				'message' => $message,
			) );
		} catch ( \Throwable $e ) {
			// Thrown after the call started: we cannot know whether the gateway got it.
			return self::result( 'unknown', self::unknown_text( $e->getMessage() ) );
		}
		if ( true === $r ) {
			return self::result( 'sent' );
		}
		if ( is_string( $r ) && '' !== $r ) {
			// That plugin only reports a text; treat a timeout-like text as uncertain, anything else as a plain failure.
			if ( preg_match( '/time(d)?[ -]?out|cURL error (28|52|56)/i', $r ) ) {
				return self::result( 'unknown', self::unknown_text( $r ) );
			}
			return self::result( 'failed', $r );
		}
		return self::result( 'unknown', self::unknown_text( 'پاسخ نامشخص از افزونه پیامک ووکامرس' ) );
	}

	private static function send_kavenegar( $phone, $message, $localid = '' ) {
		$key = CRDR_Settings::get( 'kavenegar_key' );
		if ( ! $key ) {
			return self::result( 'failed', 'کلید API کاوه‌نگار وارد نشده است.' );
		}
		$body = array(
			'receptor' => $phone,
			'message'  => $message,
		);
		$from = CRDR_Settings::get( 'kavenegar_from' );
		if ( $from ) {
			$body['sender'] = $from;
		}
		if ( preg_match( '/^\d{1,19}$/', (string) $localid ) ) {
			// Kavenegar: a localid it has already seen is not sent again (unique per account). Extra protection only.
			$body['localid'] = (string) $localid;
		}
		$resp = wp_remote_post( 'https://api.kavenegar.com/v1/' . rawurlencode( $key ) . '/sms/send.json', array(
			'timeout'     => 20,
			'redirection' => 0, // never let an HTTP redirect silently replay the POST.
			'body'        => $body,
		) );
		if ( is_wp_error( $resp ) ) {
			$msg = $resp->get_error_message();
			if ( 'definite' === CRDR_Send_Rules::classify_transport_error( $msg, $resp->get_error_code() ) ) {
				return self::result( 'failed', $msg );
			}
			return self::result( 'unknown', self::unknown_text( $msg ) );
		}
		$c = CRDR_Send_Rules::classify_response( wp_remote_retrieve_response_code( $resp ), wp_remote_retrieve_body( $resp ) );
		if ( 'sent' === $c['state'] ) {
			return self::result( 'sent' );
		}
		$msg = '' !== $c['message'] ? $c['message'] : 'پاسخ نامعتبر از کاوه‌نگار';
		if ( 'failed' === $c['state'] ) {
			return self::result( 'failed', 'کاوه‌نگار (' . $c['code'] . '): ' . $msg );
		}
		return self::result( 'unknown', self::unknown_text( 'HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . ' / کاوه‌نگار (' . $c['code'] . '): ' . $msg ) );
	}
}
