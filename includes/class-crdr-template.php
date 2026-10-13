<?php
/**
 * SMS template parsing, validation and safe rendering.
 * Pure logic with no database or WordPress calls, so it is fully unit testable.
 *
 * A template may only contain the placeholders listed in catalog(). A placeholder that cannot be
 * filled for a customer must never reach the customer as raw text, so render() reports it instead.
 */
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

class CRDR_Template {

	const MAX_LENGTH = 400;

	/**
	 * Placeholder name (without braces) => the group it belongs to.
	 *   base  : always available
	 *   offer : only when the message carries a generated discount code
	 *   reco  : only in the "complementary product" campaign
	 */
	public static function catalog() {
		return array(
			'name'     => 'base',
			'store'    => 'base',
			'optout'   => 'base',
			'coupon'   => 'offer',
			'discount' => 'offer',
			'expire'   => 'offer',
			'product'  => 'reco',
			'url'      => 'reco',
			'bought'   => 'reco',
		);
	}

	/** Placeholder names usable in a given context. @param array $ctx offer (bool), reco (bool) */
	public static function allowed( array $ctx = array() ) {
		$out = array();
		foreach ( self::catalog() as $name => $group ) {
			if ( 'base' === $group || ( 'offer' === $group && ! empty( $ctx['offer'] ) ) || ( 'reco' === $group && ! empty( $ctx['reco'] ) ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Split a template into well-formed {tokens} and structural problems.
	 * Braces are ASCII, so scanning bytes is safe for UTF-8 text.
	 *
	 * @return array tokens: list of [ raw, name ]; problems: list of 'unclosed' | 'stray' | 'nested'
	 */
	public static function parse( $tpl ) {
		$tpl      = (string) $tpl;
		$len      = strlen( $tpl );
		$tokens   = array();
		$problems = array();
		$open     = -1;
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $tpl[ $i ];
			if ( '{' === $ch ) {
				if ( $open >= 0 ) {
					$problems['nested'] = true;
				}
				$open = $i;
			} elseif ( '}' === $ch ) {
				if ( $open < 0 ) {
					$problems['stray'] = true;
				} else {
					$tokens[] = array(
						'raw'  => substr( $tpl, $open, $i - $open + 1 ),
						'name' => substr( $tpl, $open + 1, $i - $open - 1 ),
					);
					$open = -1;
				}
			}
		}
		if ( $open >= 0 ) {
			$problems['unclosed'] = true;
		}
		return array( 'tokens' => $tokens, 'problems' => array_keys( $problems ) );
	}

	/**
	 * Check a template for a context. Returns understandable Persian messages; an empty list means valid.
	 *
	 * @param array $ctx offer (bool): a discount code is generated; reco (bool): a recommended product exists; require_optout (bool): the text is promotional and must contain {optout}
	 * @return string[]
	 */
	public static function validate( $tpl, array $ctx = array() ) {
		$tpl = (string) $tpl;
		$err = array();
		if ( '' === trim( $tpl ) ) {
			return array( 'متن پیام خالی است.' );
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $tpl ) : strlen( $tpl );
		if ( $len > self::MAX_LENGTH ) {
			$err[] = 'متن پیام بلندتر از ' . self::fa( self::MAX_LENGTH ) . ' نویسه است (' . self::fa( $len ) . ' نویسه). متن را کوتاه کنید.';
		}

		$parsed = self::parse( $tpl );
		$msgs   = array(
			'unclosed' => 'یک «{» باز شده ولی «}» بسته‌ی آن نیامده است.',
			'stray'    => 'یک «}» بدون «{» مقابلش در متن آمده است.',
			'nested'   => 'آکولادها تو در تو یا ناقص نوشته شده‌اند؛ هر متغیر باید دقیقاً به شکل {name} باشد.',
		);
		foreach ( $parsed['problems'] as $p ) {
			$err[] = $msgs[ $p ];
		}

		$catalog = self::catalog();
		$allowed = self::allowed( $ctx );
		$seen    = array();
		foreach ( $parsed['tokens'] as $t ) {
			if ( isset( $seen[ $t['raw'] ] ) ) {
				continue;
			}
			$seen[ $t['raw'] ] = true;
			$name = $t['name'];
			if ( '' === $name ) {
				$err[] = 'متغیر خالی «{}» مجاز نیست.';
			} elseif ( isset( $catalog[ $name ] ) && ! in_array( $name, $allowed, true ) ) {
				if ( 'offer' === $catalog[ $name ] ) {
					$err[] = 'متغیر «' . $t['raw'] . '» در این متن قابل استفاده نیست، چون برایش کد تخفیف ساخته نمی‌شود. (در کمپین، نوع پیشنهاد را «کد تخفیف درصدی» کنید یا این متغیر را بردارید.)';
				} else {
					$err[] = 'متغیر «' . $t['raw'] . '» فقط در کمپین «پیشنهاد محصول مکمل» قابل استفاده است.';
				}
			} elseif ( ! isset( $catalog[ $name ] ) ) {
				$hint  = '';
				$clean = strtolower( preg_replace( '/\s+/', '', $name ) );
				if ( $clean !== $name && in_array( $clean, $allowed, true ) ) {
					$hint = ' منظورتان «{' . $clean . '}» بود؟ (حروف کوچک و بدون فاصله)';
				}
				$err[] = 'متغیر «' . $t['raw'] . '» شناخته‌شده نیست.' . $hint . ' متغیرهای مجاز در این متن: ' . self::list_names( $allowed );
			}
		}
		// Last, so that the first message a person sees is about the text itself (a wrong token, a stray brace).
		// A promotional message must carry the opt-out phrase: without {optout} nobody is told how to stop the messages.
		if ( ! empty( $ctx['require_optout'] ) && ! in_array( '{optout}', array_column( $parsed['tokens'], 'raw' ), true ) ) {
			$err[] = 'متن پیامک تبلیغاتی باید شامل «{optout}» باشد (عبارت لغو عضویت، مثلاً «لغو۱۱»)؛ بدون آن مشتری نمی‌داند چگونه پیام‌ها را متوقف کند.';
		}
		return $err;
	}

	/**
	 * Fill a template in a single pass (substituted values are never scanned again, so customer data
	 * that happens to contain braces is harmless).
	 *
	 * @param string $tpl
	 * @param array  $vars     '{name}' => value
	 * @param array  $optional placeholders that may legitimately be empty
	 * @return array text, missing (placeholders with no value), malformed (bool), ok (bool)
	 */
	public static function render( $tpl, array $vars, array $optional = array( '{optout}' ) ) {
		$tpl    = (string) $tpl;
		$parsed = self::parse( $tpl );
		if ( $parsed['problems'] ) {
			return array( 'text' => $tpl, 'missing' => array(), 'malformed' => true, 'ok' => false );
		}
		$missing = array();
		$text    = preg_replace_callback(
			'/\{[^{}]*\}/',
			function ( $m ) use ( $vars, $optional, &$missing ) {
				$key = $m[0];
				if ( array_key_exists( $key, $vars ) && ( '' !== (string) $vars[ $key ] || in_array( $key, $optional, true ) ) ) {
					return (string) $vars[ $key ];
				}
				$missing[ $key ] = true;
				return $key;
			},
			$tpl
		);
		$missing = array_keys( $missing );
		return array( 'text' => $text, 'missing' => $missing, 'malformed' => false, 'ok' => ! $missing );
	}

	/** Persian explanation for a render() result that is not ok. */
	public static function explain( array $r ) {
		if ( ! empty( $r['malformed'] ) ) {
			return 'ساختار متن پیام درست نیست (آکولادها).';
		}
		if ( ! empty( $r['missing'] ) && array( '{optout}' ) === array_values( $r['missing'] ) ) {
			return 'عبارت لغو عضویت در «تنظیمات» خالی است؛ پیامک تبلیغاتی بدون آن فرستاده نمی‌شود.';
		}
		if ( ! empty( $r['missing'] ) ) {
			return 'مقدار این متغیرها ساخته نشد: ' . implode( ' ', $r['missing'] );
		}
		return '';
	}

	private static function list_names( array $names ) {
		return implode( ' ', array_map( function ( $n ) {
			return '{' . $n . '}';
		}, $names ) );
	}

	private static function fa( $n ) {
		return strtr( (string) $n, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}
}
