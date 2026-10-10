<?php
/**
 * Gregorian <-> Jalali helpers and Persian digit formatting.
 * Pure PHP, no WordPress dependency (so it can be unit tested on the CLI).
 */
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

class CRDR_Jalali {

	const MONTHS = array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );

	/**
	 * @return int[] [jy, jm, jd]
	 */
	public static function to_jalali( $gy, $gm, $gd ) {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + (int) ( ( $gy2 + 3 ) / 4 ) - (int) ( ( $gy2 + 99 ) / 100 ) + (int) ( ( $gy2 + 399 ) / 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * (int) ( $days / 12053 ) );
		$days %= 12053;
		$jy   += 4 * (int) ( $days / 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy  += (int) ( ( $days - 1 ) / 365 );
			$days = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + (int) ( $days / 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + (int) ( ( $days - 186 ) / 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}

	/** [jy, jm, jd] of a unix timestamp (site timezone inside WordPress, UTC otherwise). */
	public static function ymd( $ts ) {
		$ymd = function_exists( 'wp_date' ) ? wp_date( 'Y-n-j', (int) $ts ) : gmdate( 'Y-n-j', (int) $ts );
		list( $gy, $gm, $gd ) = array_map( 'intval', explode( '-', $ymd ) );
		return self::to_jalali( $gy, $gm, $gd );
	}

	/** Absolute Jalali month number (year*12 + month-1) of a timestamp. */
	public static function month_index( $ts ) {
		list( $jy, $jm ) = self::ymd( $ts );
		return $jy * 12 + $jm - 1;
	}

	/** "مهر ۱۴۰۵" for a month index. */
	public static function month_label( $idx, $short_year = false ) {
		$y = intdiv( $idx, 12 );
		$m = $idx % 12;
		$ys = $short_year ? substr( (string) $y, -2 ) : (string) $y;
		return self::MONTHS[ $m ] . ' ' . self::digits( $ys );
	}

	public static function digits( $str ) {
		return strtr( (string) $str, array(
			'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
			'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
		) );
	}

	public static function latin_digits( $str ) {
		return strtr( (string) $str, array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		) );
	}

	/**
	 * Format a unix timestamp as a Jalali date (site timezone inside WordPress, UTC otherwise).
	 *
	 * @param int  $ts
	 * @param bool $persian_digits
	 * @return string e.g. ۱۴۰۵/۰۷/۱۶
	 */
	public static function date( $ts, $persian_digits = true ) {
		$ts = (int) $ts;
		if ( $ts <= 0 ) {
			return '—';
		}
		$ymd = function_exists( 'wp_date' ) ? wp_date( 'Y-n-j', $ts ) : gmdate( 'Y-n-j', $ts );
		list( $gy, $gm, $gd ) = array_map( 'intval', explode( '-', $ymd ) );
		list( $jy, $jm, $jd ) = self::to_jalali( $gy, $gm, $gd );
		$out = sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
		return $persian_digits ? self::digits( $out ) : $out;
	}

	/** Thousands-separated number with Persian digits. */
	public static function number( $n, $decimals = 0 ) {
		return self::digits( number_format( (float) $n, $decimals, '.', ',' ) );
	}
}
