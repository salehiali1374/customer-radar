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
	 * For a definite failure `scope` says who it is about, which decides what the campaign does with the customer
	 * (see CRDR_Send_Rules::code_scope):
	 *   recipient  this number is not valid           -> set aside until a person asks again
	 *   message    this text was refused              -> set aside until the text/settings are saved
	 *   fatal      account, key, credit, request      -> stop the run, nobody is penalised
	 *   gateway    network problem before the request left -> circuit breaker after three in a row, nobody penalised
	 *   uncertain  failed without saying why          -> set aside for a while, counts for the circuit breaker
	 * The error text is our own wording, with keys and phone numbers removed: never the provider's raw answer.
	 *
	 * @param string $localid numeric id passed to Kavenegar so that a repeated request is recognised
	 * @return array state, error (Persian text for the owner), scope
	 */
	public static function deliver( $phone, $message, $localid = '' ) {
		$phone = CRDR_Sync::normalize_phone( $phone );
		if ( '' === $phone ) {
			return self::result( 'failed', 'شماره موبایل مشتری معتبر نیست.', 'recipient' );
		}
		if ( '' === trim( $message ) ) {
			return self::result( 'failed', 'متن پیامک خالی است.', 'message' );
		}
		switch ( self::provider() ) {
			case 'pwsms':
				return self::send_pwsms( $phone, $message );
			case 'kavenegar':
				return self::send_kavenegar( $phone, $message, $localid );
		}
		return self::result( 'failed', 'سرویس پیامک تنظیم نشده است. از بخش تنظیمات یکی را انتخاب کنید.', 'fatal' );
	}

	/** The one place every outcome passes through: the text is cleaned of the API key and of phone numbers. */
	private static function result( $state, $error = '', $scope = '' ) {
		$error = CRDR_Send_Rules::redact( $error, array( CRDR_Settings::get( 'kavenegar_key' ) ) );
		return array( 'state' => $state, 'error' => $error, 'scope' => 'failed' === $state ? $scope : '' );
	}

	/** Owner-facing explanation of an uncertain outcome. */
	private static function unknown_text( $detail ) {
		return 'پاسخ سرویس پیامک مشخص نشد؛ ممکن است پیامک رفته باشد و ممکن است نرفته باشد. برای جلوگیری از پیام تکراری، دوباره فرستاده نمی‌شود و مشتری تا پایان دوره‌ی استراحت پیامی نمی‌گیرد. وضعیت را در پنل سرویس پیامک ببینید. (' . $detail . ')';
	}

	private static function send_pwsms( $phone, $message ) {
		if ( ! function_exists( 'PWSMS' ) ) {
			return self::result( 'failed', 'افزونه پیامک ووکامرس فعال نیست.', 'fatal' );
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
			return self::result( 'unknown', self::unknown_text( get_class( $e ) ) ); // the exception text is not kept
		}
		if ( true === $r ) {
			return self::result( 'sent' );
		}
		if ( is_string( $r ) && '' !== $r ) {
			// That plugin only reports a text; treat a timeout-like text as uncertain, anything else as a plain failure.
			if ( preg_match( '/time(d)?[ -]?out|cURL error (28|52|56)/i', $r ) ) {
				return self::result( 'unknown', self::unknown_text( 'زمان انتظار افزونه پیامک ووکامرس تمام شد' ) );
			}
			// A text only: it cannot tell a bad number from a broken account, so it is "uncertain" (a bounded set-aside).
			// The other plugin's wording is not copied (it may contain anything); the owner is pointed to where it is shown.
			return self::result( 'failed', 'افزونه پیامک ووکامرس خطا برگرداند؛ علتش را در تنظیمات یا گزارش همان افزونه ببینید.', 'uncertain' );
		}
		return self::result( 'unknown', self::unknown_text( 'پاسخ نامشخص از افزونه پیامک ووکامرس' ) );
	}

	private static function send_kavenegar( $phone, $message, $localid = '' ) {
		$key = CRDR_Settings::get( 'kavenegar_key' );
		if ( ! $key ) {
			return self::result( 'failed', 'کلید API کاوه‌نگار وارد نشده است.', 'fatal' );
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
			$msg  = $resp->get_error_message();
			$what = CRDR_Send_Rules::transport_summary( $msg, $resp->get_error_code() ); // only the cURL number is kept
			if ( 'definite' === CRDR_Send_Rules::classify_transport_error( $msg, $resp->get_error_code() ) ) {
				return self::result( 'failed', $what, 'gateway' );
			}
			return self::result( 'unknown', self::unknown_text( $what ) );
		}
		$c = CRDR_Send_Rules::classify_response( wp_remote_retrieve_response_code( $resp ), wp_remote_retrieve_body( $resp ) );
		if ( 'sent' === $c['state'] ) {
			return self::result( 'sent' );
		}
		if ( 'failed' === $c['state'] ) {
			return self::result( 'failed', CRDR_Send_Rules::failure_reason( $c['code'] ), $c['scope'] );
		}
		// Not in the provider's table, or no readable answer: only the numbers are kept, never the provider's text.
		return self::result( 'unknown', self::unknown_text( 'HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . ( $c['code'] ? ' / کد ' . (int) $c['code'] : ' / پاسخ ناخوانا' ) ) );
	}
}
