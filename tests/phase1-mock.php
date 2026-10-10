<?php
/**
 * Fake Kavenegar gateway for the Phase 1 tests. NEVER talks to the internet: it answers the plugin's
 * wp_remote_post() through the `pre_http_request` filter and records every request it would have received
 * in a log file shared by all test processes.
 *
 * Behaviour is chosen by the option `crdr_t_mock` (JSON):
 *   mode            ok | timeout | connect_fail | http500 | credit        (default for every phone)
 *                   invalid_receptor (411) | bad_text (422) | too_long (413) | op_failed (402) | bad_key (403)
 *                   server_busy (409) | rate_limit (451, not in the provider's table) | echo | leaky
 *                   echo  = 411 whose message repeats the phone number and the API key
 *                   leaky = a transport error whose text contains the request URL (key) and the phone number
 *   by_phone        { "09120000001": "timeout", ... }                      (per-phone override)
 *   delay_ms        pause before answering, so parallel processes really overlap
 */
if ( ! function_exists( 'crdr_t_log_path' ) ) {
	function crdr_t_log_path() {
		return ABSPATH . 'crdr-t-http.log';
	}

	function crdr_t_requests() {
		$f = crdr_t_log_path();
		if ( ! file_exists( $f ) ) {
			return array();
		}
		return array_map( function ( $l ) {
			return json_decode( $l, true );
		}, array_filter( file( $f, FILE_IGNORE_NEW_LINES ) ) );
	}

	function crdr_t_install_mock() {
		static $installed = false; // idempotent: a second registration would log every request twice.
		if ( $installed ) {
			return;
		}
		$installed = true;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'api.kavenegar.com' ) ) {
				return new WP_Error( 'crdr_t_blocked', 'test shop: unexpected outbound request to ' . $url );
			}
			$cfg   = json_decode( (string) get_option( 'crdr_t_mock', '{}' ), true );
			$cfg   = is_array( $cfg ) ? $cfg : array();
			$phone = isset( $args['body']['receptor'] ) ? $args['body']['receptor'] : '';
			$mode  = isset( $cfg['by_phone'][ $phone ] ) ? $cfg['by_phone'][ $phone ] : ( isset( $cfg['mode'] ) ? $cfg['mode'] : 'ok' );
			// The request "reached the gateway" in every mode except connect_fail, so log it first.
			if ( 'connect_fail' !== $mode ) {
				file_put_contents( crdr_t_log_path(), wp_json_encode( array(
					'pid'      => getmypid(),
					'receptor' => $phone,
					'message'  => isset( $args['body']['message'] ) ? $args['body']['message'] : '',
					'localid'  => isset( $args['body']['localid'] ) ? $args['body']['localid'] : '',
					'redirect' => isset( $args['redirection'] ) ? $args['redirection'] : null,
					'mode'     => $mode,
				), JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX );
			}
			if ( ! empty( $cfg['delay_ms'] ) ) {
				usleep( (int) $cfg['delay_ms'] * 1000 );
			}
			$reply = function ( $code, $body ) {
				return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			};
			switch ( $mode ) {
				case 'timeout':
					return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received' );
				case 'connect_fail':
					return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.kavenegar.com' );
				case 'http500':
					return $reply( 500, '' );
				case 'credit':
					return $reply( 200, '{"return":{"status":418,"message":"اعتبار کافی نیست"}}' );
				case 'invalid_receptor':
					return $reply( 411, '{"return":{"status":411,"message":"شماره گیرنده پیام معتبر نمی باشد"}}' );
				case 'bad_text':
					return $reply( 422, '{"return":{"status":422,"message":"داده ها به دلیل وجود کاراکتر نامناسب قابل پردازش نیست"}}' );
				case 'too_long':
					return $reply( 413, '{"return":{"status":413,"message":"پیام خالی است و یا طول پیام بیش از حد مجاز می‌باشد"}}' );
				case 'op_failed':
					return $reply( 402, '{"return":{"status":402,"message":"عملیات ناموفق بود"}}' );
				case 'bad_key':
					return $reply( 403, '{"return":{"status":403,"message":"حساب کاربری معتبر نمی‌باشد"}}' );
				case 'server_busy':
					return $reply( 409, '{"return":{"status":409,"message":"سرور قادر به پاسخگوئی نیست بعدا تلاش کنید"}}' );
				case 'rate_limit':
					return $reply( 451, '{"return":{"status":451,"message":"too many calls"}}' );
				case 'echo':
					return $reply( 411, wp_json_encode( array( 'return' => array( 'status' => 411, 'message' => 'receptor ' . $phone . ' invalid, key TESTKEY' ) ) ) );
				case 'leaky':
					return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received for ' . $url . '?receptor=' . $phone );
			}
			return $reply( 200, '{"return":{"status":200,"message":"تایید شد"},"entries":[{"messageid":1}]}' );
		}, 10, 3 );
	}
}
