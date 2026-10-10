<?php
/**
 * CLI unit tests for the pure-logic classes.   Run:  php tests/unit.php
 */
define( 'CRDR_TESTING', true );
require __DIR__ . '/../includes/class-crdr-jalali.php';
require __DIR__ . '/../includes/class-crdr-rfm.php';
require __DIR__ . '/../includes/class-crdr-stats.php';
require __DIR__ . '/../includes/class-crdr-template.php';
require __DIR__ . '/../includes/class-crdr-send-rules.php';

$fail = 0;
$pass = 0;
function check( $name, $got, $want ) {
	global $fail, $pass;
	if ( $got === $want ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n";
	}
}

/* ---- Jalali ---- */
$vectors = array(
	array( 2026, 3, 21, array( 1405, 1, 1 ) ),   // Nowruz 1405
	array( 2025, 3, 21, array( 1404, 1, 1 ) ),
	array( 2024, 3, 20, array( 1403, 1, 1 ) ),
	array( 2025, 3, 20, array( 1403, 12, 30 ) ), // 1403 is a leap year
	array( 2026, 9, 23, array( 1405, 7, 1 ) ),   // 1 Mehr
	array( 2026, 10, 8, array( 1405, 7, 16 ) ),
	array( 2021, 12, 21, array( 1400, 9, 30 ) ), // Yalda eve
	array( 2000, 1, 1, array( 1378, 10, 11 ) ),
	array( 2026, 3, 20, array( 1404, 12, 29 ) ), // last day of 1404
);
foreach ( $vectors as $v ) {
	check( "jalali {$v[0]}-{$v[1]}-{$v[2]}", CRDR_Jalali::to_jalali( $v[0], $v[1], $v[2] ), $v[3] );
}
check( 'digits', CRDR_Jalali::digits( '1405/07/16' ), '۱۴۰۵/۰۷/۱۶' );
check( 'latin digits', CRDR_Jalali::latin_digits( '۰۹۱۲٣' ), '09123' );
check( 'number', CRDR_Jalali::number( 1234567 ), '۱,۲۳۴,۵۶۷' );

/* ---- RFM primitives ---- */
check( 'freq 1', CRDR_RFM::score_frequency( 1 ), 1 );
check( 'freq 2', CRDR_RFM::score_frequency( 2 ), 2 );
check( 'freq 3', CRDR_RFM::score_frequency( 3 ), 3 );
check( 'freq 5', CRDR_RFM::score_frequency( 5 ), 4 );
check( 'freq 9', CRDR_RFM::score_frequency( 9 ), 5 );
check( 'median odd', CRDR_RFM::median( array( 5, 1, 3 ) ), 3.0 );
check( 'median even', CRDR_RFM::median( array( 1, 2, 3, 4 ) ), 2.5 );
check( 'cutoffs empty', CRDR_RFM::cutoffs( array() ), array( 0, 0, 0, 0 ) );
$cuts = CRDR_RFM::cutoffs( range( 1, 100 ) );
check( 'score top', CRDR_RFM::score_high_better( 100, $cuts ), 5 );
check( 'score bottom', CRDR_RFM::score_high_better( 1, $cuts ), 1 );
check( 'score degenerate', CRDR_RFM::score_high_better( 50, CRDR_RFM::cutoffs( array( 7, 7, 7, 7, 7 ) ) ), 3 );
$day_cuts = CRDR_RFM::cutoffs( range( 1, 100 ) );
check( 'recency recent=5', CRDR_RFM::score_recency( 1, $day_cuts ), 5 );
check( 'recency old=1', CRDR_RFM::score_recency( 100, $day_cuts ), 1 );

/* ---- segmentation ---- */
check( 'seg new', CRDR_RFM::segment( 5, 1, 2, 1, 10 ), 'new' );
check( 'seg champion', CRDR_RFM::segment( 5, 5, 5, 8, 5 ), 'champion' );
check( 'seg loyal', CRDR_RFM::segment( 3, 3, 3, 3, 60 ), 'loyal' );
check( 'seg potential', CRDR_RFM::segment( 5, 1, 2, 1, 45 ), 'potential' );
check( 'seg cant_lose', CRDR_RFM::segment( 1, 5, 5, 8, 200 ), 'cant_lose' );
check( 'seg at_risk', CRDR_RFM::segment( 2, 3, 2, 3, 120 ), 'at_risk' );
check( 'seg need_attention', CRDR_RFM::segment( 3, 1, 1, 1, 90 ), 'need_attention' );
check( 'seg hibernating', CRDR_RFM::segment( 1, 2, 2, 2, 300 ), 'hibernating' );
check( 'seg lost', CRDR_RFM::segment( 1, 1, 1, 1, 500 ), 'lost' );
check( 'seg single old 200d is hibernating', CRDR_RFM::segment( 1, 1, 1, 1, 200 ), 'hibernating' );

/* ---- gap and priority ---- */
check( 'gap repeat', CRDR_RFM::expected_gap_days( 3, 0, 60 * 86400, 99 ), 30.0 );
check( 'gap single uses store median', CRDR_RFM::expected_gap_days( 1, 10, 10, 45 ), 45.0 );
check( 'prio not yet due', CRDR_RFM::priority( 1000, 10, 30, 'loyal' ), 0.0 );
check( 'prio due', CRDR_RFM::priority( 1000, 33, 30, 'loyal' ), 1000.0 );
check( 'prio too late', CRDR_RFM::priority( 1000, 100, 30, 'loyal' ), 0.0 );
check( 'prio never call new', CRDR_RFM::priority( 1000, 33, 30, 'new' ), 0.0 );
check( 'prio fading', CRDR_RFM::priority( 1000, 2.3 * 30, 30, 'loyal' ) < 1000.0, true );
check( 'prio cant_lose bonus', CRDR_RFM::priority( 1000, 33, 30, 'cant_lose' ), 1300.0 );

/* ---- end to end ---- */
$now  = strtotime( '2026-10-08 12:00:00 UTC' );
$rows = array();
mt_srand( 42 );
for ( $i = 1; $i <= 500; $i++ ) {
	$orders = ( mt_rand( 1, 100 ) <= 60 ) ? 1 : mt_rand( 2, 9 );
	$first  = $now - mt_rand( 10, 540 ) * 86400;
	$last   = ( 1 === $orders ) ? $first : $first + mt_rand( 0, (int) ( ( $now - $first ) / 86400 ) ) * 86400;
	$rows[] = array(
		'id'           => $i,
		'orders_count' => $orders,
		'total_spent'  => $orders * mt_rand( 200000, 3000000 ),
		'first_ts'     => $first,
		'last_ts'      => $last,
	);
}
$res = CRDR_RFM::score_all( $rows, $now );
check( 'score_all count', count( $res ), 500 );
$valid = true;
$segs  = array();
foreach ( $res as $r ) {
	$valid = $valid && $r['r'] >= 1 && $r['r'] <= 5 && $r['f'] >= 1 && $r['f'] <= 5 && $r['m'] >= 1 && $r['m'] <= 5 && isset( CRDR_RFM::segments()[ $r['segment'] ] ) && $r['priority'] >= 0;
	$segs[ $r['segment'] ] = ( $segs[ $r['segment'] ] ?? 0 ) + 1;
}
check( 'score_all ranges valid', $valid, true );
check( 'score_all produces several segments', count( $segs ) >= 5, true );
echo 'segments: ' . json_encode( $segs ) . "\n";
$called = array_filter( $res, function ( $r ) {
	return $r['priority'] > 0;
} );
echo 'callable customers: ' . count( $called ) . " of 500\n";


/* ---- Stats ---- */
check( 'percentile median', CRDR_Stats::percentile( array( 1, 2, 3, 4, 5 ), 50 ), 3.0 );
check( 'percentile p25', CRDR_Stats::percentile( array( 1, 2, 3, 4, 5 ), 25 ), 2.0 );
check( 'percentile p90', round( CRDR_Stats::percentile( array( 1, 2, 3, 4, 5 ), 90 ), 6 ), 4.6 );
check( 'percentile empty', CRDR_Stats::percentile( array(), 50 ), 0.0 );
check( 'histogram', CRDR_Stats::histogram( array( 1, 5, 8, 20, 100 ), array( 7, 14, 30 ) ), array( 2, 1, 1, 1 ) );
check( 'reorder_prob', CRDR_Stats::reorder_prob( array( 10, 20, 30, 40, 50, 60 ), 25, 30 ), array( 0.75, 4 ) );
check( 'reorder_prob none open', CRDR_Stats::reorder_prob( array( 10 ), 25, 30 ), array( null, 0 ) );
check( 'survival_table length', count( CRDR_Stats::survival_table( array( 10, 20, 30 ), 15, 60, 30 ) ), 5 );
check( 'pareto top25', CRDR_Stats::pareto_share( array( 100, 50, 25, 25 ), 0.25 ), 0.5 );
check( 'pareto top50', CRDR_Stats::pareto_share( array( 100, 50, 25, 25 ), 0.5 ), 0.75 );
check( 'pareto empty', CRDR_Stats::pareto_share( array(), 0.1 ), 0.0 );

$aff = CRDR_Stats::affinity( array( array( 1, 2 ), array( 1, 2 ), array( 1, 2, 3 ), array( 3 ), array( 1 ), array( 2 ) ), 2 );
check( 'affinity orders', $aff['orders'], 6 );
check( 'affinity one pair', count( $aff['pairs'] ), 1 );
check( 'affinity support', $aff['pairs'][0]['support'], 3 );
check( 'affinity conf', $aff['pairs'][0]['conf_ab'], 0.75 );
check( 'affinity lift', round( $aff['pairs'][0]['lift'], 4 ), 1.125 );
check( 'affinity dedupes products in a basket', CRDR_Stats::affinity( array( array( 5, 5, 6 ), array( 5, 6 ) ), 2 )['pairs'][0]['support'], 2 );

$t = CRDR_Stats::two_prop( 60, 500, 30, 500 );
check( 'two_prop diff', round( $t['diff'], 4 ), 0.06 );
check( 'two_prop z', round( $t['z'], 2 ), 3.31 );
check( 'two_prop significant', $t['p'] < 0.01, true );
check( 'two_prop enough', $t['enough'], true );
check( 'two_prop ci contains diff', $t['ci_low'] < 0.06 && $t['ci_high'] > 0.06, true );
$t0 = CRDR_Stats::two_prop( 30, 500, 30, 500 );
check( 'two_prop no difference', abs( $t0['p'] - 1 ) < 1e-6, true );
check( 'two_prop small sample flagged', CRDR_Stats::two_prop( 3, 10, 1, 10 )['enough'], false );
check( 'two_prop zero n', CRDR_Stats::two_prop( 0, 0, 0, 0 )['p'], 1.0 );
check( 'norm_cdf 1.96', abs( CRDR_Stats::norm_cdf( 1.96 ) - 0.975 ) < 0.0005, true );
check( 'norm_cdf -1.96', abs( CRDR_Stats::norm_cdf( -1.96 ) - 0.025 ) < 0.0005, true );

check( 'nice_round big', CRDR_Stats::nice_round( 1234567 ), 1200000 );
check( 'nice_round mid', CRDR_Stats::nice_round( 87000 ), 85000 );
check( 'nice_round zero', CRDR_Stats::nice_round( 0 ), 0 );
check( 'threshold too few', CRDR_Stats::threshold_suggestion( range( 1, 10 ) ), null );
$ts = CRDR_Stats::threshold_suggestion( range( 100000, 3000000, 29000 ) );
check( 'threshold has fields', isset( $ts['threshold'], $ts['nudge_orders'], $ts['nudge_share'], $ts['median'] ), true );
check( 'threshold is nice and plausible', $ts['threshold'] > $ts['median'] * 0.9 && $ts['threshold'] % 1000 === 0, true );
check( 'scenario', CRDR_Stats::scenario( 100, 500000, array( 0.03, 0.06, 0.1 ) ), array( 1500000.0, 3000000.0, 5000000.0 ) );

$co = CRDR_Stats::cohorts( array( array( 10, array( 10, 11, 12 ) ), array( 10, array( 10 ) ), array( 11, array( 11, 12 ) ) ), 12, 12, 3 );
check( 'cohort size', $co[10]['size'], 2 );
check( 'cohort m0', $co[10]['ret'][0], 100.0 );
check( 'cohort m1', $co[10]['ret'][1], 50.0 );
check( 'cohort m3 unobservable', $co[10]['ret'][3], null );
check( 'cohort later', $co[11]['ret'][1], 100.0 );

/* ================= Phase 1: campaign safety ================= */

/* ---- template validation ---- */
$none  = array();
$offer = array( 'offer' => true );
$reco  = array( 'reco' => true );
check( 'tpl valid base tokens', CRDR_Template::validate( 'سلام {name}، از {store}. {optout}', $none ), array() );
check( 'tpl valid offer tokens', CRDR_Template::validate( '{coupon} {discount} {expire}', $offer ), array() );
check( 'tpl valid reco tokens', CRDR_Template::validate( '{product} {url} {bought}', $reco ), array() );
check( 'tpl valid with no tokens at all', CRDR_Template::validate( 'سلام دوست عزیز', $none ), array() );
check( 'tpl exactly 400 chars is valid', CRDR_Template::validate( str_repeat( 'ا', 400 ), $none ), array() );
check( 'tpl 401 chars rejected', count( CRDR_Template::validate( str_repeat( 'ا', 401 ), $none ) ), 1 );
check( 'tpl empty rejected', CRDR_Template::validate( "  \n ", $none ), array( 'متن پیام خالی است.' ) );

$e = CRDR_Template::validate( 'سلام {nmae}', $none );
check( 'tpl unknown token: one error', count( $e ), 1 );
check( 'tpl unknown token: names it', false !== strpos( $e[0], '{nmae}' ), true );
check( 'tpl unknown token: lists allowed ones', false !== strpos( $e[0], '{name}' ), true );
$e = CRDR_Template::validate( '{Name}', $none );
check( 'tpl wrong case gets a hint', false !== mb_strpos( $e[0], 'منظورتان «{name}»' ), true );
$e = CRDR_Template::validate( '{ name }', $none );
check( 'tpl spaced token gets a hint', false !== mb_strpos( $e[0], 'منظورتان «{name}»' ), true );
check( 'tpl same unknown token reported once', count( CRDR_Template::validate( '{x} و {x}', $none ) ), 1 );

$e = CRDR_Template::validate( 'کد شما {coupon}', $none );
check( 'tpl {coupon} without an offer is an error', count( $e ), 1 );
check( 'tpl {coupon} error explains the offer', false !== mb_strpos( $e[0], 'کد تخفیف' ), true );
check( 'tpl {discount}/{expire} also need an offer', count( CRDR_Template::validate( '{discount} {expire}', $none ) ), 2 );
$e = CRDR_Template::validate( '{product}', $none );
check( 'tpl {product} without cross-sell is an error', false !== mb_strpos( $e[0], 'پیشنهاد محصول مکمل' ), true );
check( 'tpl {coupon} is fine once the offer exists', CRDR_Template::validate( '{coupon}', $offer ), array() );

check( 'tpl malformed: unclosed', CRDR_Template::parse( 'سلام {name' )['problems'], array( 'unclosed' ) );
check( 'tpl malformed: stray close', CRDR_Template::parse( 'سلام name}' )['problems'], array( 'stray' ) );
check( 'tpl malformed: nested', in_array( 'nested', CRDR_Template::parse( '{{name}}' )['problems'], true ), true );
check( 'tpl malformed: unclosed is rejected', count( CRDR_Template::validate( 'سلام {name', $none ) ) >= 1, true );
check( 'tpl malformed: stray close is rejected', count( CRDR_Template::validate( 'سلام name}', $none ) ) >= 1, true );
check( 'tpl malformed: {{name}} is rejected', count( CRDR_Template::validate( '{{name}}', $none ) ) >= 2, true );
check( 'tpl malformed: empty {} is rejected', false !== mb_strpos( CRDR_Template::validate( 'سلام {}', $none )[0], 'خالی' ), true );
check( 'tpl parse keeps well-formed tokens', array_column( CRDR_Template::parse( '{a} x {b}' )['tokens'], 'name' ), array( 'a', 'b' ) );

/* ---- safe rendering ---- */
$vars = array( '{name}' => 'علی', '{store}' => 'فروشگاه', '{optout}' => 'لغو۱۱' );
$r    = CRDR_Template::render( 'سلام {name} از {store}. {optout}', $vars );
check( 'render fills everything', $r['text'], 'سلام علی از فروشگاه. لغو۱۱' );
check( 'render ok when nothing is missing', $r['ok'], true );

$r = CRDR_Template::render( 'کد شما {coupon}', $vars );
check( 'render: missing coupon value is reported', $r['missing'], array( '{coupon}' ) );
check( 'render: missing coupon -> not ok (must not be sent)', $r['ok'], false );
$r = CRDR_Template::render( 'کد شما {coupon}', $vars + array( '{coupon}' => '' ) );
check( 'render: an EMPTY coupon value counts as missing', $r['missing'], array( '{coupon}' ) );
$r = CRDR_Template::render( 'سلام {name}{optout}', array_merge( $vars, array( '{optout}' => '' ) ) );
check( 'render: opt-out text may legitimately be empty', $r['ok'], true );
$r = CRDR_Template::render( '{foo} و {foo} و {bar}', $vars );
check( 'render: unknown tokens listed once each', $r['missing'], array( '{foo}', '{bar}' ) );
$r = CRDR_Template::render( 'سلام {name', $vars );
check( 'render: malformed template flagged', array( $r['malformed'], $r['ok'] ), array( true, false ) );
$r = CRDR_Template::render( 'سلام {name}', array_merge( $vars, array( '{name}' => '{coupon}' ) ) );
check( 'render: braces inside customer data are not re-scanned', array( $r['text'], $r['ok'] ), array( 'سلام {coupon}', true ) );
check( 'render: explain names the missing token', false !== mb_strpos( CRDR_Template::explain( CRDR_Template::render( '{coupon}', $vars ) ), '{coupon}' ), true );
check( 'render: explain is empty when ok', CRDR_Template::explain( CRDR_Template::render( '{name}', $vars ) ), '' );

/* ---- when may a campaign run? ---- */
$g = function ( $mode, $trigger, $in, $req = false, $allow = false ) {
	$r = CRDR_Send_Rules::gate( $mode, $trigger, $in, $req, $allow );
	return array( $r['allowed'], $r['code'], $r['override'] );
};
check( 'gate: off blocks manual', $g( 'off', 'manual', true ), array( false, 'mode_off', false ) );
check( 'gate: off blocks cron', $g( 'off', 'cron', true ), array( false, 'mode_off', false ) );
check( 'gate: unknown mode is treated as off', $g( 'bogus', 'manual', true ), array( false, 'mode_off', false ) );
check( 'gate: cron needs auto mode', $g( 'manual', 'cron', true ), array( false, 'not_auto', false ) );
check( 'gate: cron in window ok', $g( 'auto', 'cron', true ), array( true, 'ok', false ) );
check( 'gate: cron outside window blocked', $g( 'auto', 'cron', false ), array( false, 'outside_window', false ) );
check( 'gate: cron can NEVER override the window', $g( 'auto', 'cron', false, true, true ), array( false, 'outside_window', false ) );
check( 'gate: manual in window ok (manual mode)', $g( 'manual', 'manual', true ), array( true, 'ok', false ) );
check( 'gate: manual in window ok (auto mode)', $g( 'auto', 'manual', true ), array( true, 'ok', false ) );
check( 'gate: manual outside window blocked', $g( 'manual', 'manual', false ), array( false, 'outside_window', false ) );
check( 'gate: manual outside window blocked in auto mode too', $g( 'auto', 'manual', false ), array( false, 'outside_window', false ) );
check( 'gate: override requested without permission is refused', $g( 'manual', 'manual', false, true, false ), array( false, 'override_forbidden', false ) );
check( 'gate: override with permission is allowed AND flagged', $g( 'manual', 'manual', false, true, true ), array( true, 'ok', true ) );
check( 'gate: permission alone is not an override', $g( 'manual', 'manual', false, false, true ), array( false, 'outside_window', false ) );
check( 'gate: override is not recorded when the window was open anyway', $g( 'manual', 'manual', true, true, true ), array( true, 'ok', false ) );
check( 'gate: override can never switch an OFF campaign on', $g( 'off', 'manual', false, true, true ), array( false, 'mode_off', false ) );
check( 'gate: an unknown trigger gets the strict (cron) rules', $g( 'manual', 'weird', true ), array( false, 'not_auto', false ) );
foreach ( array( 'mode_off', 'not_auto', 'outside_window', 'override_forbidden' ) as $code ) {
	check( "gate message exists: $code", '' !== CRDR_Send_Rules::gate_message( $code ), true );
}
check( 'gate message for ok is empty', CRDR_Send_Rules::gate_message( 'ok' ), '' );

/* ---- one-off (single customer) promotional SMS: sending hours only, never an override ---- */
$o = function ( $in ) {
	$r = CRDR_Send_Rules::gate_one_off( $in );
	return array( $r['allowed'], $r['code'], $r['override'] );
};
check( 'one-off gate: inside the hours is allowed', $o( true ), array( true, 'ok', false ) );
check( 'one-off gate: outside the hours is blocked, with no override', $o( false ), array( false, 'outside_window', false ) );
check( 'one-off gate: its refusal has a Persian message', '' !== CRDR_Send_Rules::gate_message( CRDR_Send_Rules::gate_one_off( false )['code'] ), true );
check( 'one-off gate: boundaries 9..21 (from inclusive, to exclusive)', array(
	$o( CRDR_Send_Rules::in_window( 8, 9, 21 ) )[0], $o( CRDR_Send_Rules::in_window( 9, 9, 21 ) )[0],
	$o( CRDR_Send_Rules::in_window( 20, 9, 21 ) )[0], $o( CRDR_Send_Rules::in_window( 21, 9, 21 ) )[0],
), array( false, true, true, false ) );
check( 'one-off gate: a window that wraps midnight (22..6) blocks every hour (unchanged, same as campaigns)', array_unique( array_map( function ( $h ) use ( $o ) {
	return $o( CRDR_Send_Rules::in_window( $h, 22, 6 ) )[0];
}, range( 0, 23 ) ) ), array( false ) );

/* ---- sending hours, in the SITE timezone ---- */
$teh = new DateTimeZone( 'Asia/Tehran' );
$win = function ( $ts, $tz, $from = 9, $to = 21 ) {
	return CRDR_Send_Rules::in_window( CRDR_Send_Rules::hour_in_tz( $ts, $tz ), $from, $to );
};
check( 'window: 20:59:59 Tehran is inside 9-21', $win( gmmktime( 17, 29, 59, 10, 8, 2026 ), $teh ), true );
check( 'window: 21:00:00 Tehran is outside (end is exclusive)', $win( gmmktime( 17, 30, 0, 10, 8, 2026 ), $teh ), false );
check( 'window: 08:59:59 Tehran is outside', $win( gmmktime( 5, 29, 59, 10, 8, 2026 ), $teh ), false );
check( 'window: 09:00:00 Tehran is inside (start is inclusive)', $win( gmmktime( 5, 30, 0, 10, 8, 2026 ), $teh ), true );
check( 'window: same instant, UTC site -> 17:30 is inside', $win( gmmktime( 17, 30, 0, 10, 8, 2026 ), new DateTimeZone( 'UTC' ) ), true );
check( 'window: midnight Tehran is hour 0', CRDR_Send_Rules::hour_in_tz( gmmktime( 20, 30, 0, 10, 8, 2026 ), $teh ), 0 );
check( 'window: midnight outside 9-21, inside 0-24', array( $win( gmmktime( 20, 30, 0, 10, 8, 2026 ), $teh ), $win( gmmktime( 20, 30, 0, 10, 8, 2026 ), $teh, 0, 24 ) ), array( false, true ) );
check( 'window: 23:29 Tehran is inside 0-24', $win( gmmktime( 19, 59, 59, 10, 8, 2026 ), $teh, 0, 24 ), true );
check( 'window: Tehran 2026 has no DST (12:00 UTC = 15:30)', CRDR_Send_Rules::hour_in_tz( gmmktime( 12, 0, 0, 7, 1, 2026 ), $teh ), 15 );
check( 'window: Tehran 2021 still had DST (12:00 UTC = 16:30)', CRDR_Send_Rules::hour_in_tz( gmmktime( 12, 0, 0, 7, 1, 2021 ), $teh ), 16 );
$ny = new DateTimeZone( 'America/New_York' );
check( 'window: spring-forward, 06:59:59 UTC is 01:59 EST', CRDR_Send_Rules::hour_in_tz( gmmktime( 6, 59, 59, 3, 8, 2026 ), $ny ), 1 );
check( 'window: spring-forward, 07:00:00 UTC is 03:00 EDT', CRDR_Send_Rules::hour_in_tz( gmmktime( 7, 0, 0, 3, 8, 2026 ), $ny ), 3 );
check( 'window: fall-back, both 01:xx hours exist', array( CRDR_Send_Rules::hour_in_tz( gmmktime( 5, 59, 59, 11, 1, 2026 ), $ny ), CRDR_Send_Rules::hour_in_tz( gmmktime( 6, 0, 0, 11, 1, 2026 ), $ny ) ), array( 1, 1 ) );
check( 'window: from == to is always closed (unchanged behaviour)', array( CRDR_Send_Rules::in_window( 9, 9, 9 ), CRDR_Send_Rules::in_window( 10, 9, 9 ) ), array( false, false ) );
check( 'window: wrap-around windows are NOT supported (unchanged behaviour)', array( CRDR_Send_Rules::in_window( 22, 21, 6 ), CRDR_Send_Rules::in_window( 3, 21, 6 ) ), array( false, false ) );

/* ---- reading what the gateway said ---- */
$tr = array( 'CRDR_Send_Rules', 'classify_transport_error' );
check( 'transport: DNS failure is definite', $tr( 'cURL error 6: Could not resolve host: api.kavenegar.com' ), 'definite' );
check( 'transport: connection refused is definite', $tr( 'cURL error 7: Failed to connect to api.kavenegar.com port 443' ), 'definite' );
check( 'transport: TLS handshake failure is definite', $tr( 'cURL error 35: OpenSSL SSL_connect' ), 'definite' );
check( 'transport: bad certificate is definite', $tr( 'cURL error 60: SSL certificate problem' ), 'definite' );
check( 'transport: connect timeout is definite', $tr( 'cURL error 28: Connection timed out after 10001 milliseconds' ), 'definite' );
check( 'transport: DNS timeout is definite', $tr( 'cURL error 28: Resolving timed out after 5000 milliseconds' ), 'definite' );
check( 'transport: TIMEOUT WAITING FOR THE ANSWER IS AMBIGUOUS', $tr( 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received' ), 'ambiguous' );
check( 'transport: partial answer then timeout is ambiguous', $tr( 'cURL error 28: Operation timed out after 20000 milliseconds with 123 bytes received' ), 'ambiguous' );
check( 'transport: empty reply is ambiguous', $tr( 'cURL error 52: Empty reply from server' ), 'ambiguous' );
check( 'transport: receive failure is ambiguous', $tr( 'cURL error 56: Recv failure: Connection reset by peer' ), 'ambiguous' );
check( 'transport: unrecognised text is ambiguous (the safe default)', $tr( 'something odd happened' ), 'ambiguous' );
check( 'transport: blocked before any network I/O is definite', $tr( 'User has blocked requests through HTTP.', 'http_request_not_executed' ), 'definite' );

$cl = function ( $http, $body ) {
	$r = CRDR_Send_Rules::classify_response( $http, $body );
	return array( $r['state'], $r['code'] );
};
check( 'response: 200 + status 200 is sent', $cl( 200, '{"return":{"status":200,"message":"تایید شد"},"entries":[{"messageid":1}]}' ), array( 'sent', 200 ) );
check( 'response: 418 (no credit) in a 200 reply is a definite failure', $cl( 200, '{"return":{"status":418,"message":"اعتبار کافی نیست"}}' ), array( 'failed', 418 ) );
check( 'response: HTTP 4xx with a documented code is definite', $cl( 412, '{"return":{"status":412,"message":"x"}}' ), array( 'failed', 412 ) );
check( 'response: invalid API key is definite', $cl( 403, '{"return":{"status":403}}' ), array( 'failed', 403 ) );
check( 'response: HTTP 500 is unknown', $cl( 500, '' )[0], 'unknown' );
check( 'response: 502 even with a body that says 200 is unknown', $cl( 502, '{"return":{"status":200}}' )[0], 'unknown' );
check( 'response: unreadable body is unknown', $cl( 200, 'not json' )[0], 'unknown' );
check( 'response: empty body is unknown', $cl( 200, '' )[0], 'unknown' );
check( 'response: an undocumented status is unknown', $cl( 200, '{"return":{"status":999}}' )[0], 'unknown' );
check( 'response: a redirect is unknown', $cl( 302, '' )[0], 'unknown' );
check( 'response: failure codes list matches the documented table', CRDR_Send_Rules::definite_failure_codes(), array( 400, 401, 402, 403, 411, 412, 413, 414, 417, 418, 424, 426, 428, 431, 432, 607 ) );

/* ---- localid ---- */
check( 'localid layout (ms + 6 digits)', CRDR_Send_Rules::localid( 1790000000000, 42 ), '1790000000000000042' );
check( 'localid fits a signed 64-bit integer at the far end', CRDR_Send_Rules::localid( 9223372036854, 775807 ), '9223372036854775807' );
check( 'localid generated value is 19 digits', 1 === preg_match( '/^\d{19}$/', CRDR_Send_Rules::localid() ), true );
check( 'localid differs between milliseconds', CRDR_Send_Rules::localid( 1790000000001, 5 ) !== CRDR_Send_Rules::localid( 1790000000002, 5 ), true );
check( 'minimum resend floor is one day', CRDR_Send_Rules::MIN_RESEND, 86400 );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
