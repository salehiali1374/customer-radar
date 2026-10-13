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
require __DIR__ . '/../includes/class-crdr-health.php';
require __DIR__ . '/../includes/class-crdr-site.php';

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
// PHASE 4.2: re-read against the provider's current table: + 416, 420, 427, 429, 451 (they were missing and fell into "unknown").
check( 'response: failure codes list matches the provider\'s documented table (kavenegar.com/rest.html)', CRDR_Send_Rules::definite_failure_codes(), array( 400, 401, 403, 404, 405, 406, 407, 411, 412, 413, 414, 416, 417, 418, 420, 422, 424, 426, 427, 428, 429, 431, 432, 451, 501, 607 ) ); // REVIEW 4.2: + 501 (test account: only the owner's number); RELEASE FREEZE: - 402 ("operation failed" does not say the SMS was not processed: unknown)

/* ---- localid ---- */
check( 'localid layout (ms + 6 digits)', CRDR_Send_Rules::localid( 1790000000000, 42 ), '1790000000000000042' );
check( 'localid fits a signed 64-bit integer at the far end', CRDR_Send_Rules::localid( 9223372036854, 775807 ), '9223372036854775807' );
check( 'localid generated value is 19 digits', 1 === preg_match( '/^\d{19}$/', CRDR_Send_Rules::localid() ), true );
check( 'localid differs between milliseconds', CRDR_Send_Rules::localid( 1790000000001, 5 ) !== CRDR_Send_Rules::localid( 1790000000002, 5 ), true );
check( 'minimum resend floor is one day', CRDR_Send_Rules::MIN_RESEND, 86400 );

/* ---- health of automatic (cron) campaign runs: pure state transitions ---- */
list( $st, $ch ) = CRDR_Health::apply_block( array(), 'template', 1000 );
check( 'health: first block is a change, count 1, since = last', array( $ch, $st['blocked'] ), array( true, array( 'code' => 'template', 'since' => 1000, 'last' => 1000, 'count' => 1 ) ) );
list( $st2, $ch ) = CRDR_Health::apply_block( $st, 'template', 4600 );
check( 'health: the same block again is NOT a change; only count and last move', array( $ch, $st2['blocked'] ), array( false, array( 'code' => 'template', 'since' => 1000, 'last' => 4600, 'count' => 2 ) ) );
list( $st3, $ch ) = CRDR_Health::apply_block( $st2, 'no_provider', 8200 );
check( 'health: a different reason is a change and restarts since/count', array( $ch, $st3['blocked'] ), array( true, array( 'code' => 'no_provider', 'since' => 8200, 'last' => 8200, 'count' => 1 ) ) );
list( $st4, $ch ) = CRDR_Health::apply_block( array(), 'something-new', 5 );
check( 'health: an unknown reason code is stored as "other" (never free text)', $st4['blocked']['code'], 'other' );
list( $st5, $rec ) = CRDR_Health::apply_run( $st3, array( 'sent' => 4, 'held_out' => 1, 'skipped' => 2, 'failed' => 0, 'unknown' => 0, 'error' => 'free text with 09120000001', 'phone' => '09120000001' ), 9000 );
check( 'health: an executed run clears the block and reports recovery', array( $rec, isset( $st5['blocked'] ) ), array( true, false ) );
check( 'health: an executed run keeps counts only (no error text, no personal data)', $st5['last_run'], array( 'ts' => 9000, 'sent' => 4, 'held_out' => 1, 'skipped' => 2, 'failed' => 0, 'unknown' => 0 ) );
list( $st6, $rec ) = CRDR_Health::apply_run( array(), array( 'sent' => 0 ), 9100 );
check( 'health: a run with nothing to send is still an executed run, no recovery', array( $rec, $st6['last_run']['sent'] ), array( false, 0 ) );
check( 'health: a block does not erase the record of the last executed run', CRDR_Health::apply_block( $st5, 'busy', 9500 )[0]['last_run']['sent'], 4 );
list( $st7, $cl ) = CRDR_Health::clear_code( CRDR_Health::apply_block( array(), 'window_invalid', 1 )[0], 'window_invalid' );
check( 'health: clear_code removes only the named reason', array( $cl, isset( $st7['blocked'] ) ), array( true, false ) );
list( $st8, $cl ) = CRDR_Health::clear_code( CRDR_Health::apply_block( array(), 'template', 1 )[0], 'window_invalid' );
check( 'health: clear_code leaves a different reason alone', array( $cl, $st8['blocked']['code'] ), array( false, 'template' ) );
check( 'health: window validity (start < end)', array( CRDR_Health::window_valid( 9, 21 ), CRDR_Health::window_valid( 0, 24 ), CRDR_Health::window_valid( 9, 9 ), CRDR_Health::window_valid( 22, 6 ) ), array( true, true, false, false ) );
check( 'health: a single busy tick does not call for attention', CRDR_Health::needs_attention( array( 'code' => 'busy', 'count' => 1 ) ), false );
check( 'health: busy three ticks in a row does', CRDR_Health::needs_attention( array( 'code' => 'busy', 'count' => 3 ) ), true );
foreach ( array( 'template', 'no_provider', 'window_invalid', 'other' ) as $code ) {
	check( "health: $code calls for attention at once", CRDR_Health::needs_attention( array( 'code' => $code, 'count' => 1 ) ), true );
	check( "health: $code has a Persian, actionable message", 1 === preg_match( '/\p{Arabic}/u', CRDR_Health::message( $code ) ), true );
}
check( 'health: outside the sending hours is expected, never recorded', CRDR_Health::is_recorded( 'outside_window' ), false );
check( 'health: every real reason is recorded', array_map( array( 'CRDR_Health', 'is_recorded' ), array( 'template', 'no_provider', 'busy', 'window_invalid', 'not_auto', 'mode_off' ) ), array( true, true, true, true, true, true ) );

/* ---- run log: which entries matter, and how many of each are kept ---- */
$e = function ( $trigger, $result, $override = false, $extra = array() ) {
	return array_merge( array( 'run' => 'r', 'ts' => 1, 'playbook' => 'dormant', 'trigger' => $trigger, 'user' => 'manual' === $trigger ? 1 : 0, 'mode' => 'auto', 'in_window' => true, 'override' => $override, 'result' => $result ), $extra );
};
check( 'log class: an override of the hours is the audit class', CRDR_Send_Rules::log_class( $e( 'manual', 'ran', true ) ), 'override' );
check( 'log class: a refused override attempt is NOT an override (nothing was bypassed)', CRDR_Send_Rules::log_class( $e( 'manual', 'blocked:override_forbidden', false ) ), 'action' );
check( 'log class: any other manual click is an action', array( CRDR_Send_Rules::log_class( $e( 'manual', 'ran' ) ), CRDR_Send_Rules::log_class( $e( 'manual', 'blocked:mode_off' ) ) ), array( 'action', 'action' ) );
check( 'log class: cron state changes are events', array( CRDR_Send_Rules::log_class( $e( 'cron', 'blocked:template' ) ), CRDR_Send_Rules::log_class( $e( 'cron', 'recovered' ) ) ), array( 'event', 'event' ) );
check( 'log class: a routine cron tick is routine', CRDR_Send_Rules::log_class( $e( 'cron', 'ran' ) ), 'routine' );
check( 'log class: a malformed entry is never routine (kept as an action)', CRDR_Send_Rules::log_class( array( 'result' => 'ran' ) ), 'action' );
check( 'log class: override wins even if the trigger is odd', CRDR_Send_Rules::log_class( $e( 'weird', 'ran', true ) ), 'override' );
check( 'log store: people and cron write to different options (they cannot overwrite each other)', array(
	CRDR_Send_Rules::log_store( 'override' ), CRDR_Send_Rules::log_store( 'action' ), CRDR_Send_Rules::log_store( 'event' ), CRDR_Send_Rules::log_store( 'routine' ),
), array( 'run', 'run', 'cron', 'cron' ) );
$caps = CRDR_Send_Rules::log_caps();
check( 'log caps: the audit class is the largest and every class is bounded', $caps['override'] > $caps['action'] && $caps['override'] > $caps['event'] && $caps['routine'] <= $caps['event'] && min( $caps ) > 0 && array_sum( $caps ) <= 600, true );

$clean = CRDR_Send_Rules::log_clean( $e( 'manual', 'ran', true, array( 'phone' => '09120000001', 'message' => 'سلام', 'api_key' => 'SECRET', 'counts' => array( 'sent' => '4', 'failed' => 1, 'evil' => 9, 'phone' => '0912' ) ) ) );
check( 'log clean: only the known keys survive (no phone, message, key)', array_keys( $clean ), array( 'run', 'ts', 'playbook', 'trigger', 'user', 'mode', 'in_window', 'override', 'result', 'counts' ) );
check( 'log clean: counts keep only known counters, as integers', $clean['counts'], array( 'sent' => 4, 'failed' => 1 ) );
check( 'log clean: free text is length-limited', strlen( CRDR_Send_Rules::log_clean( $e( 'cron', str_repeat( 'x', 500 ) ) )['result'] ) <= 60, true );

// retention
$mk = function ( $n, $trigger, $result, $override, $ts0 ) use ( $e ) {
	$o = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$o[] = $e( $trigger, $result, $override, array( 'ts' => $ts0 + $i ) );
	}
	return $o;
};
$log = array_merge( $mk( 5, 'manual', 'ran', true, 100 ), $mk( 1000, 'cron', 'ran', false, 1000 ) );
$t   = CRDR_Send_Rules::log_trim( $log );
$by  = array_count_values( array_map( array( 'CRDR_Send_Rules', 'log_class' ), $t['log'] ) );
check( 'trim: a flood of routine ticks never evicts an override entry', array( $by['override'], $by['routine'] ), array( 5, $caps['routine'] ) );
check( 'trim: the routine tier keeps the NEWEST entries', end( $t['log'] )['ts'], 1999 );
check( 'trim: only routine entries were evicted, and they are counted', $t['evicted'], array( 'routine' => 1000 - $caps['routine'] ) );
$t = CRDR_Send_Rules::log_trim( $mk( $caps['override'] + 7, 'manual', 'ran', true, 1 ) );
check( 'trim: the audit class itself is bounded, oldest first, and the loss is reported', array( count( $t['log'] ), $t['log'][0]['ts'], $t['evicted'] ), array( $caps['override'], 8, array( 'override' => 7 ) ) );
$t = CRDR_Send_Rules::log_trim( array_merge( $mk( 3, 'cron', 'blocked:template', false, 1 ), $mk( 500, 'cron', 'ran', false, 100 ) ) );
check( 'trim: cron state changes survive routine noise', count( array_filter( $t['log'], function ( $x ) {
	return 'blocked:template' === $x['result'];
} ) ), 3 );
$t = CRDR_Send_Rules::log_trim( array_merge( $mk( 2, 'manual', 'ran', true, 1 ), $mk( 300, 'manual', 'blocked:mode_off', false, 10 ) ) );
check( 'trim: ordinary manual clicks do not evict overrides either', array( count( array_filter( $t['log'], function ( $x ) {
	return $x['override'];
} ) ), $t['evicted'] ), array( 2, array( 'action' => 300 - $caps['action'] ) ) );
check( 'trim: chronological order is preserved', array_column( CRDR_Send_Rules::log_trim( array_merge( $mk( 3, 'manual', 'ran', true, 5 ), $mk( 3, 'cron', 'ran', false, 1 ) ) )['log'], 'ts' ), array( 5, 6, 7, 1, 2, 3 ) );
check( 'trim: a small log is returned untouched', CRDR_Send_Rules::log_trim( $mk( 3, 'manual', 'ran', false, 1 ) )['evicted'], array() );
check( 'trim: an empty log is fine', CRDR_Send_Rules::log_trim( array() ), array( 'log' => array(), 'evicted' => array() ) );
check( 'merge: two stores read back as one chronological list', array_column( CRDR_Send_Rules::log_merge( $mk( 2, 'manual', 'ran', false, 10 ), $mk( 3, 'cron', 'ran', false, 9 ) ), 'ts' ), array( 9, 10, 10, 11, 11 ) );

/* ---- customers whose message cannot be built are set aside for a while, not retried on every run ---- */
check( 'set-aside: lasts six hours by default', CRDR_Send_Rules::BUILD_BACKOFF, 21600 );
check( 'set-aside: a run gives up on building after ten failures in a row', CRDR_Send_Rules::BUILD_FAIL_STREAK, 10 );
check( 'set-aside: counted from (now - 6h) when nothing was changed since', CRDR_Send_Rules::build_backoff_since( 100000, 0 ), 100000 - 21600 );
check( 'set-aside: a settings/campaign change AFTER that point lifts every earlier set-aside', CRDR_Send_Rules::build_backoff_since( 100000, 99000 ), 99000 );
check( 'set-aside: an old change does not shorten the wait', CRDR_Send_Rules::build_backoff_since( 100000, 10 ), 100000 - 21600 );
check( 'set-aside: a row made at the very moment of the change is lifted too (strictly newer only)', array( 99000 > CRDR_Send_Rules::build_backoff_since( 100000, 99000 ), 99001 > CRDR_Send_Rules::build_backoff_since( 100000, 99000 ) ), array( false, true ) );
check( 'set-aside: none of the "nothing was sent" statuses is a contact', CRDR_Send_Rules::NOT_CONTACT, array( 'failed', 'nobuild', 'retry', 'rejected' ) );

/* ---- WHO is at fault for a definite failure: recipient / message / fatal / uncertain (and ambiguous = unknown) ---- */
$sc = function ( $status ) {
	$r = CRDR_Send_Rules::classify_response( 200, '{"return":{"status":' . $status . ',"message":"x"}}' );
	return array( $r['state'], $r['scope'] );
};
check( 'scope: 411 (receptor invalid) is about the RECIPIENT only', $sc( 411 ), array( 'failed', 'recipient' ) );
check( 'scope: 413 (message empty / too long) and 422 (unsuitable characters) are about the MESSAGE', array( $sc( 413 ), $sc( 422 ) ), array( array( 'failed', 'message' ), array( 'failed', 'message' ) ) );
foreach ( array( 400, 401, 403, 404, 405, 406, 407, 412, 414, 416, 417, 418, 424, 426, 427, 428, 429, 431, 432, 501, 607 ) as $code ) { // PHASE 4.2: + 416 (source IP), 427 (line access level), 429 (IP restricted)
	check( "scope: $code is an account / key / credit / request problem (fatal: stop, nobody is at fault)", $sc( $code ), array( 'failed', 'fatal' ) );
}
check( 'scope: 402 ("operation failed", no reason) does not say it was NOT processed: stays ambiguous (unknown, never retried, never re-sent with a fresh localid)', $sc( 402 ), array( 'unknown', '' ) );
check( 'scope: 402 is not a definite failure and has no scope', array( in_array( 402, CRDR_Send_Rules::definite_failure_codes(), true ), CRDR_Send_Rules::code_scope( 402 ) ), array( false, '' ) );
check( 'scope: 409 ("try later") does not say it was NOT processed: stays ambiguous (unknown, never retried)', $sc( 409 ), array( 'unknown', '' ) );
// PHASE 4.2: 451 is in the provider's current table ("too many calls in a time window"): a documented, definite, retry-later failure; the ambiguity pin moves to a code that is NOT documented.
check( 'scope: a code that is not in the provider\'s table (499) is ambiguous', $sc( 499 ), array( 'unknown', '' ) );
check( 'scope: 451 (too many calls from this IP) is a RATE limit: nothing sent, stop, try again later', $sc( 451 ), array( 'failed', 'rate' ) );
check( 'scope: 420 (links restricted for this account) is about the MESSAGE text', $sc( 420 ), array( 'failed', 'message' ) );
check( 'scope: sent has no scope', CRDR_Send_Rules::classify_response( 200, '{"return":{"status":200}}' )['scope'], '' );
check( 'scope: every documented definite code has exactly one scope', array_values( array_unique( array_map( function ( $c ) {
	return CRDR_Send_Rules::code_scope( $c );
}, CRDR_Send_Rules::definite_failure_codes() ) ) ), array( 'fatal', 'recipient', 'message', 'rate' ) ); // PHASE 4.2: + 'rate'; RELEASE FREEZE: - 'uncertain' (402 is unknown now)
check( 'scope: the scope table and the definite list never disagree', array_filter( CRDR_Send_Rules::definite_failure_codes(), function ( $c ) {
	return '' === CRDR_Send_Rules::code_scope( $c );
} ), array() );
check( 'scope: an undocumented code has no scope', CRDR_Send_Rules::code_scope( 499 ), '' );

check( 'failure status: recipient -> rejected', CRDR_Send_Rules::failure_status( 'recipient' ), 'rejected' );
check( 'failure status: message -> nobuild (same set-aside as an unbuildable message)', CRDR_Send_Rules::failure_status( 'message' ), 'nobuild' );
check( 'failure status: there is no scope that leads to a timed "retry" any more (402 is unknown)', CRDR_Send_Rules::failure_status( 'uncertain' ), 'failed' );
check( 'failure status: fatal and gateway stay plain failed (the customer is not penalised)', array( CRDR_Send_Rules::failure_status( 'fatal' ), CRDR_Send_Rules::failure_status( 'gateway' ), CRDR_Send_Rules::failure_status( '' ) ), array( 'failed', 'failed', 'failed' ) );
check( 'no status that records a failure counts as a contact', array_diff( array( 'failed', 'nobuild', 'retry', 'rejected' ), CRDR_Send_Rules::NOT_CONTACT ), array() );
check( 'a contact status is never in the not-contact list', array_intersect( array( 'ok', 'unknown', 'pending', 'holdout' ), CRDR_Send_Rules::NOT_CONTACT ), array() );
check( 'status names fit the varchar(10) column', max( array_map( 'strlen', CRDR_Send_Rules::NOT_CONTACT ) ) <= 10, true );

foreach ( array( 411, 418, 403, 413, 422, 501 ) as $code ) {
	$why = CRDR_Send_Rules::failure_reason( $code );
	check( "reason $code: Persian, names the code, no provider text", 1 === preg_match( '/\p{Arabic}/u', $why ) && false !== strpos( $why, (string) $code ), true );
}
check( 'reason: an unlisted code still gets a safe text with its number', false !== strpos( CRDR_Send_Rules::failure_reason( 999 ), '999' ), true );

/* ---- nothing sensitive in a stored error ---- */
check( 'redact: the API key is removed wherever it appears', CRDR_Send_Rules::redact( 'GET https://api.kavenegar.com/v1/SECRETKEY123/sms/send.json failed', array( 'SECRETKEY123' ) ), 'GET https://api.kavenegar.com/v1/***/sms/send.json failed' );
check( 'redact: Iranian mobile numbers in all common spellings are removed', CRDR_Send_Rules::redact( 'to 09123456789, +989123456789, 00989123456789, 9123456789 end', array() ), 'to [شماره], [شماره], [شماره], [شماره] end' );
check( 'redact: an empty or very short secret is ignored (it would blank the text)', CRDR_Send_Rules::redact( 'abc', array( '', 'ab' ) ), 'abc' );
check( 'redact: ordinary numbers (codes, counts) are left alone', CRDR_Send_Rules::redact( 'کد 411 و 20001 میلی‌ثانیه', array() ), 'کد 411 و 20001 میلی‌ثانیه' );
check( 'redact: Persian digits in a phone number are removed too', CRDR_Send_Rules::redact( 'شماره ۰۹۱۲۳۴۵۶۷۸۹ نامعتبر', array() ), 'شماره [شماره] نامعتبر' );

/* ---- how long a rejected recipient stays aside ---- */
check( 'rejected: set aside for 30 days unless something lifts it', CRDR_Send_Rules::REJECT_BACKOFF, 30 * 86400 );
check( 'rejected: counted from (now - 30 days) when nobody pressed "retry"', CRDR_Send_Rules::reject_backoff_since( 5000000, 0 ), 5000000 - 30 * 86400 );
check( 'rejected: pressing "retry" later lifts every earlier rejection', CRDR_Send_Rules::reject_backoff_since( 5000000, 4900000 ), 4900000 );
check( 'rejected: an old "retry" does not shorten the wait', CRDR_Send_Rules::reject_backoff_since( 5000000, 1000 ), 5000000 - 30 * 86400 );

/* ---- what each stored outcome means, in words the data can actually back up ---- */
$all_statuses = array( 'pending', 'ok', 'unknown', 'failed', 'retry', 'nobuild', 'rejected', 'holdout', 'norec' );
$oc = function ( $s ) {
	return CRDR_Send_Rules::outcome( $s );
};
check( 'outcome: pending is "attempting", not an outcome yet', $oc( 'pending' )['group'], 'attempting' );
check( 'outcome: ok is "accepted by the provider" and NOT "delivered"', array( $oc( 'ok' )['group'], $oc( 'ok' )['delivered'] ), array( 'accepted', false ) );
check( 'outcome: unknown is its own group', $oc( 'unknown' )['group'], 'unknown' );
check( 'outcome: failed / retry / nobuild are "failed" (nothing was sent)', array( $oc( 'failed' )['group'], $oc( 'retry' )['group'], $oc( 'nobuild' )['group'] ), array( 'failed', 'failed', 'failed' ) );
check( 'outcome: rejected is its own group', $oc( 'rejected' )['group'], 'rejected' );
check( 'outcome: holdout is the control group, not a failure', $oc( 'holdout' )['group'], 'control' );
check( 'outcome: there are no delivery receipts, so no outcome is ever "delivered"', array_unique( array_map( function ( $s ) use ( $oc ) {
	return $oc( $s )['delivered'];
}, $all_statuses ) ), array( false ) );
check( 'outcome: every group is distinct where the owner must tell them apart', count( array_unique( array_map( function ( $s ) use ( $oc ) {
	return $oc( $s )['label'];
}, array( 'ok', 'unknown', 'failed', 'rejected', 'pending', 'holdout' ) ) ) ), 6 );
foreach ( $all_statuses as $s ) {
	$o = $oc( $s );
	check( "outcome $s: Persian label and help", 1 === preg_match( '/\p{Arabic}/u', $o['label'] ) && 1 === preg_match( '/\p{Arabic}/u', $o['help'] ), true );
}
check( 'outcome: "unknown" never reads like success', 1 === preg_match( '/نامشخص/u', $oc( 'unknown' )['label'] ) && 0 === preg_match( '/پذیرفته|ارسال شد|تحویل|موفق/u', $oc( 'unknown' )['label'] ), true );
check( 'outcome: "unknown" help says it may have gone out, is not retried, and the customer rests', 1 === preg_match( '/ممکن است/u', $oc( 'unknown' )['help'] ) && 1 === preg_match( '/دوباره فرستاده نمی/u', $oc( 'unknown' )['help'] ), true );
check( 'outcome: "ok" says provider, and the help says arrival at the phone is not confirmed', 1 === preg_match( '/سرویس پیامک/u', $oc( 'ok' )['label'] ) && 1 === preg_match( '/تأیید نشده/u', $oc( 'ok' )['help'] ), true );
check( 'outcome: no label claims delivery to the customer', 0, count( array_filter( $all_statuses, function ( $s ) use ( $oc ) {
	return 1 === preg_match( '/به دست مشتری|دریافت کرد|رسید به|تحویل مشتری|تحویل گیرنده/u', $oc( $s )['label'] );
} ) ) );
check( 'outcome: an unrecognised status is shown as unclear, never as success', array( $oc( 'zzz' )['group'], 0 === preg_match( '/پذیرفته|ارسال شد/u', $oc( 'zzz' )['label'] ) ), array( 'other', true ) );
check( 'outcome: which statuses count as a contact for cooldown', array_values( array_filter( $all_statuses, function ( $s ) use ( $oc ) {
	return $oc( $s )['contact'];
} ) ), array( 'pending', 'ok', 'unknown', 'holdout', 'norec' ) );
check( 'outcome: which statuses use up the daily cap (possibly sent)', array_values( array_filter( $all_statuses, function ( $s ) use ( $oc ) {
	return $oc( $s )['cap'];
} ) ), array( 'pending', 'ok', 'unknown' ) );
// RELEASE FREEZE (D4): the comparison is by INTENTION TO TREAT (CRDR_Playbooks::results): everybody first assigned to the message or to the control is measured,
// whatever happened to the SMS. The old expectation ('ok' and 'holdout' only) described the pre-ITT definition.
check( 'outcome: "measured" = every status that can be a first ASSIGNMENT (intention to treat), not only accepted messages', array_values( array_filter( $all_statuses, function ( $s ) use ( $oc ) {
	return $oc( $s )['measured'];
} ) ), array( 'pending', 'ok', 'unknown', 'failed', 'retry', 'nobuild', 'rejected', 'holdout' ) );
check( 'outcome: not measured = nobody was assigned (no recommendation found) and an unrecognised status', array( $oc( 'norec' )['measured'], $oc( 'something-new' )['measured'] ), array( false, false ) );
check( 'outcome: the list the results query uses is the one "measured" is derived from', CRDR_Send_Rules::ASSIGNED, array_values( array_filter( $all_statuses, function ( $s ) use ( $oc ) {
	return $oc( $s )['measured'];
} ) ) );
check( 'outcome: only an ACCEPTED message is in the secondary "accepted" group (the primary comparison does not need it)', array_values( array_filter( $all_statuses, function ( $s ) use ( $oc ) {
	return 'accepted' === $oc( $s )['group'];
} ) ), array( 'ok' ) );
check( 'outcome: the contact flag agrees with the not-contact list used by the SQL', array_values( array_filter( $all_statuses, function ( $s ) use ( $oc ) {
	return ! $oc( $s )['contact'];
} ) ), array( 'failed', 'retry', 'nobuild', 'rejected' ) );

/* ---- data minimisation: what may stay in a stored error text ---- */
check( 'network error: only the cURL number is kept, never the text (it may carry the request URL)', CRDR_Send_Rules::transport_summary( 'cURL error 28: Operation timed out after 20001 milliseconds for https://api.kavenegar.com/v1/KEY/sms/send.json?receptor=09123456789' ), 'خطای شبکه (cURL 28)' );
check( 'network error: a WordPress error code without a cURL number keeps only a safe code', CRDR_Send_Rules::transport_summary( 'A valid URL was not provided.', 'http_request_failed' ), 'خطای اتصال (http_request_failed)' );
check( 'network error: an odd code is cleaned, nothing is left to leak', CRDR_Send_Rules::transport_summary( 'x', "bad code <script> 0912" ), 'خطای اتصال' );
check( 'network error: no text at all is fine', CRDR_Send_Rules::transport_summary( '' ), 'خطای اتصال' );
check( 'clean_error: an old "provider text" error becomes our own wording with the code', CRDR_Send_Rules::clean_error( 'کاوه‌نگار (411): شماره گیرنده پیام معتبر نمی باشد 09123456789', array() ), CRDR_Send_Rules::failure_reason( 411 ) );
check( 'clean_error: key and phone numbers are removed from any other text', CRDR_Send_Rules::clean_error( 'failed https://x/v1/SECRETKEY99/y for 09123456789', array( 'SECRETKEY99' ) ), 'failed https://x/v1/***/y for [شماره]' );
check( 'clean_error: a clean text is returned unchanged (idempotent)', CRDR_Send_Rules::clean_error( CRDR_Send_Rules::failure_reason( 418 ), array( 'SECRETKEY99' ) ), CRDR_Send_Rules::failure_reason( 418 ) );
check( 'clean_error: running it twice changes nothing', CRDR_Send_Rules::clean_error( CRDR_Send_Rules::clean_error( 'کاوه‌نگار (418): x', array() ), array() ), CRDR_Send_Rules::failure_reason( 418 ) );
check( 'clean_error: the result fits the 255-character column', mb_strlen( CRDR_Send_Rules::clean_error( str_repeat( 'ا', 600 ), array() ) ) <= 255, true );

/* ---- is the scheduler (WP-Cron) running? judged from when the next hourly run was due ---- */
$H = 3600;
$now = 2000000000;
check( 'cron: nothing scheduled gives no verdict (WordPress re-schedules on the next request)', CRDR_Health::cron_overdue( 0, $now, true ), 0 );
check( 'cron: the next run is in the future: fine', CRDR_Health::cron_overdue( $now + 600, $now, true ), 0 );
check( 'cron, host cron expected: 2h59m overdue is still fine, 3h01m is a problem', array( CRDR_Health::cron_overdue( $now - ( 3 * $H - 60 ), $now, true ), CRDR_Health::cron_overdue( $now - ( 3 * $H + 60 ), $now, true ) ), array( 0, 3 * $H + 60 ) );
check( 'cron, WP-Cron on: 5 hours overdue is normal on a quiet site (the next visit runs it)', CRDR_Health::cron_overdue( $now - 5 * $H, $now, false ), 0 );
check( 'cron, WP-Cron on: 47h is fine, 49h is a problem (a visit would have run it)', array( CRDR_Health::cron_overdue( $now - 47 * $H, $now, false ), CRDR_Health::cron_overdue( $now - 49 * $H, $now, false ) ), array( 0, 49 * $H ) );
foreach ( array( true, false ) as $disabled ) {
	check( 'cron message (' . ( $disabled ? 'host cron' : 'WP-Cron' ) . '): Persian, says the hours, says automatic campaigns send nothing, says what to do', 1 === preg_match( '/\p{Arabic}/u', CRDR_Health::cron_message( 6 * $H, $disabled ) ) && false !== strpos( CRDR_Health::cron_message( 6 * $H, $disabled ), '۶' ) && false !== mb_strpos( CRDR_Health::cron_message( 6 * $H, $disabled ), 'پیامکی نمی‌فرستند' ) && false !== strpos( CRDR_Health::cron_message( 6 * $H, $disabled ), 'cron' ), true );
}

// Phase 4.1: a promotional template must carry the opt-out phrase
$promo = array( 'require_optout' => true );
check( 'optout required: a text without {optout} is refused, with a message that names {optout}', array( count( CRDR_Template::validate( 'سلام {name} عزیز، تخفیف ویژه', $promo ) ), false !== mb_strpos( CRDR_Template::validate( 'سلام {name}', $promo )[0], '{optout}' ) ), array( 1, true ) );
check( 'optout required: a text with {optout} (anywhere) is accepted', array( CRDR_Template::validate( 'سلام {name} {optout}', $promo ), CRDR_Template::validate( '{optout} سلام', $promo ) ), array( array(), array() ) );
check( 'optout required: not asked for when the context does not say so (system texts)', CRDR_Template::validate( 'سلام {name}', array() ), array() );
check( 'optout required: "{ optout }" or "{Optout}" is not {optout} (and is also unknown)', count( CRDR_Template::validate( 'سلام {Optout}', $promo ) ) >= 2, true );
check( 'optout required: both messages are reported (missing phrase + other problems)', count( CRDR_Template::validate( 'سلام {coupon}', $promo ) ), 2 );
$r = CRDR_Template::render( 'سلام {name} {optout}', array( '{name}' => 'علی', '{optout}' => '' ), array() );
check( 'optout phrase empty in the settings: the message is NOT buildable (it is not an optional value)', array( $r['ok'], $r['missing'] ), array( false, array( '{optout}' ) ) );
check( 'optout phrase empty: the explanation says what is wrong in the settings', false !== mb_strpos( CRDR_Template::explain( $r ), 'عبارت لغو عضویت' ), true );
$r = CRDR_Template::render( 'سلام {name} {optout}', array( '{name}' => 'علی', '{optout}' => 'لغو۱۱' ), array() );
check( 'optout phrase set: the message is built with it', array( $r['ok'], $r['text'] ), array( true, 'سلام علی لغو۱۱' ) );

// ---- Phase 4.2: every code of the provider's "return codes" table (kavenegar.com/rest.html, re-read for this phase), one by one ----------
// state / scope / contact status that follows. The provider's page does not say for any code whether the message was processed; "failed" below is an
// inference from what the code IS (access, content-policy, rate-limit, validation), "unknown" is what is not safely so.
$table = array(
	400 => array( 'failed', 'fatal' ), 401 => array( 'failed', 'fatal' ), 402 => array( 'unknown', '' ),   // RELEASE FREEZE: "operation failed" without a reason: not proven unprocessed, so never retried
	403 => array( 'failed', 'fatal' ),
	404 => array( 'failed', 'fatal' ), 405 => array( 'failed', 'fatal' ), 406 => array( 'failed', 'fatal' ), 407 => array( 'failed', 'fatal' ),
	409 => array( 'unknown', '' ),   // "server cannot respond now, try later": says nothing about whether it processed the request
	411 => array( 'failed', 'recipient' ), 412 => array( 'failed', 'fatal' ), 413 => array( 'failed', 'message' ), 414 => array( 'failed', 'fatal' ),
	415 => array( 'unknown', '' ),   // list endpoints only (start index): cannot come from a single send; unknown if it ever does
	416 => array( 'failed', 'fatal' ),   // source IP does not match the configured settings: authorisation, every customer
	417 => array( 'failed', 'fatal' ), 418 => array( 'failed', 'fatal' ),
	419 => array( 'unknown', '' ),   // array lengths differ: SendArray only, cannot come from a single send
	420 => array( 'failed', 'message' ), // links in the text are restricted for this account: the text, not the customer
	422 => array( 'failed', 'message' ), 424 => array( 'failed', 'fatal' ), 426 => array( 'failed', 'fatal' ),
	427 => array( 'failed', 'fatal' ),   // this line needs an access level set up: every customer
	428 => array( 'failed', 'fatal' ),
	429 => array( 'failed', 'fatal' ),   // IP restricted: every customer, an administrator must act
	431 => array( 'failed', 'fatal' ), 432 => array( 'failed', 'fatal' ),
	451 => array( 'failed', 'rate' ),    // too many calls in a time window: throttled, nothing wrong with customer or text; stop and try again later
	501 => array( 'failed', 'fatal' ),   // REVIEW 4.2: in the provider's Table 1 (only test messages to the account owner's number): refused for everybody
	607 => array( 'failed', 'fatal' ),
);
foreach ( $table as $code => $want ) {
	$r = CRDR_Send_Rules::classify_response( $code, json_encode( array( 'return' => array( 'status' => $code, 'message' => 'x' ) ) ) );
	check( "provider table: code $code -> {$want[0]} / " . ( '' === $want[1] ? '(no scope)' : $want[1] ), array( $r['state'], $r['scope'] ), $want );
}
check( 'provider table: all 30 documented codes are covered by this test (a code added to the code but not here is caught by the next line)', count( $table ), 30 );
check( 'provider table: every "failed" code has a Persian reason that carries the code and none of the provider\'s own wording', count( array_filter( array_keys( array_filter( $table, function ( $w ) {
	return 'failed' === $w[0];
} ) ), function ( $c ) {
	$m = CRDR_Send_Rules::failure_reason( $c );
	return false === strpos( $m, (string) $c ) || false !== strpos( $m, 'documented code' );
} ) ), 0 );
$generic = CRDR_Send_Rules::failure_reason( 499 );
check( 'provider table: the five codes added in Phase 4.2 each have their own explanation (not the generic one), and the explanation says what to do', count( array_filter( array( 416, 420, 427, 429, 451, 501 ), function ( $c ) use ( $generic ) {
	return CRDR_Send_Rules::failure_reason( $c ) === str_replace( '499', (string) $c, $generic );
} ) ), 0 );
check( 'provider table: the new scopes lead to contact statuses that are NOT contacts (nothing was sent): rate -> failed, message -> nobuild', array( CRDR_Send_Rules::failure_status( 'rate' ), CRDR_Send_Rules::failure_status( 'message' ), in_array( 'failed', CRDR_Send_Rules::NOT_CONTACT, true ) ), array( 'failed', 'nobuild', true ) );

// ---- nothing unrecognised is ever "sent", and nothing unrecognised is ever a safe retry --------------------------------------------------
$bad = array();
for ( $s = 0; $s <= 999; $s++ ) {
	if ( 200 === $s || isset( $table[ $s ] ) ) {
		continue;
	}
	foreach ( array( 200, 201, 202, 400, 404, 429, 500, 502, 504, 302 ) as $http ) {
		$r = CRDR_Send_Rules::classify_response( $http, json_encode( array( 'return' => array( 'status' => $s ) ) ) );
		if ( 'unknown' !== $r['state'] ) {
			$bad[] = "$http/$s -> " . $r['state'];
		}
	}
}
check( 'unrecognised: for every status 0..999 that is neither 200 nor in the provider\'s table, under ten different HTTP codes, the result is "unknown" (not sent, not a safe retry)', $bad, array() );
$bad = array();
foreach ( array( '', 'not json', '[]', '{}', '{"return":null}', '{"return":{}}', '{"return":{"status":"ok"}}', '{"return":{"status":"200"}}x', 'null', '200' ) as $body ) {
	foreach ( array( 200, 201, 204, 500, 0 ) as $http ) {
		$r = CRDR_Send_Rules::classify_response( $http, $body );
		if ( 'sent' === $r['state'] || 'failed' === $r['state'] ) {
			$bad[] = "$http / " . substr( $body, 0, 20 ) . ' -> ' . $r['state'];
		}
	}
}
check( 'unrecognised: an unreadable, empty or malformed answer is "unknown" under any HTTP code', $bad, array() );
check( 'unrecognised: ONLY "HTTP 200 and return.status 200" is accepted: 200 with a different status, and a different HTTP code with status 200, are not', array(
	CRDR_Send_Rules::classify_response( 200, '{"return":{"status":200}}' )['state'],
	CRDR_Send_Rules::classify_response( 200, '{"return":{"status":201}}' )['state'],
	CRDR_Send_Rules::classify_response( 202, '{"return":{"status":200}}' )['state'],
	CRDR_Send_Rules::classify_response( 500, '{"return":{"status":200}}' )['state'],
), array( 'sent', 'unknown', 'unknown', 'unknown' ) );

// ---- the masked excerpt of another plugin's error text ------------------------------------------------------------------------------
$ex = CRDR_Send_Rules::safe_excerpt( 'HTTP 500 from https://api.example.com/v1/send?key=abcdefghijklmnop1234567890XYZ&to=09121234567 token=SECRETSECRETSECRET99 failed', array( 'SECRETKEY1' ) );
check( 'excerpt: no link, no long token, no phone number survive', array( false === strpos( $ex, 'http' ), false === strpos( $ex, 'abcdefghijklmnop' ), false === strpos( $ex, 'SECRETSECRET' ), false === strpos( $ex, '09121234567' ) ), array( true, true, true, true ) );
check( 'excerpt: what is left is still readable (the status words stay)', false !== strpos( $ex, 'HTTP 500' ) && false !== strpos( $ex, 'failed' ), true );
check( 'excerpt: a known secret is removed even when short', false === strpos( CRDR_Send_Rules::safe_excerpt( 'bad key SECRETKEY1 here', array( 'SECRETKEY1' ) ), 'SECRETKEY1' ), true );
check( 'excerpt: cut to the limit, control characters become spaces', array( mb_strlen( CRDR_Send_Rules::safe_excerpt( str_repeat( 'خطا ', 100 ), array(), 40 ) ) <= 41, false === strpos( CRDR_Send_Rules::safe_excerpt( "a\0b\x07c\r\nd" ), "\0" ) ), array( true, true ) );
check( 'excerpt: empty and Persian text', array( CRDR_Send_Rules::safe_excerpt( '' ), CRDR_Send_Rules::safe_excerpt( 'اعتبار کافی نیست' ) ), array( '', 'اعتبار کافی نیست' ) );

// ---- Phase 4.2: the site address that identifies "this shop" (CRDR_Site::normalize) -------------------------------------------------------
$n = array( 'CRDR_Site', 'normalize' );
check( 'site address: scheme, www, case and a trailing slash do not make a different site', array( $n( 'https://Shop.Example.COM/' ), $n( 'http://shop.example.com' ), $n( 'https://www.shop.example.com/' ), $n( 'HTTP://WWW.SHOP.EXAMPLE.COM//' ) ), array_fill( 0, 4, 'shop.example.com' ) );
check( 'site address: moving to https (same host, same path) is not a move', $n( 'http://example.ir/shop' ) === $n( 'https://example.ir/shop/' ), true );
check( 'site address: a sub-directory install keeps its path (it is a different site from the root)', array( $n( 'https://example.ir/shop' ), $n( 'https://example.ir' ) ), array( 'example.ir/shop', 'example.ir' ) );
check( 'site address: a staging sub-domain is a different site', $n( 'https://staging.example.ir' ) !== $n( 'https://example.ir' ), true );
check( 'site address: a non-default port counts, the default ports do not', array( $n( 'http://localhost:8091' ), $n( 'https://example.ir:443' ), $n( 'http://example.ir:80/x' ) ), array( 'localhost:8091', 'example.ir', 'example.ir/x' ) );
check( 'site address: query string and fragment are not part of it', $n( 'https://example.ir/shop/?a=1#top' ), 'example.ir/shop' );
check( 'site address: a different port on the same host is a different site', $n( 'http://localhost:8091' ) !== $n( 'http://localhost:8092' ), true );
check( 'site address: a trailing dot on the host and repeated slashes in the path', array( $n( 'https://example.ir./a//b/' ) ), array( 'example.ir/a/b' ) );
check( 'site address: an address without a scheme, an empty one and nonsense never throw', array( $n( 'example.ir/shop' ), $n( '' ), $n( '   ' ), is_string( $n( '::::' ) ), is_string( $n( 'http://' ) ) ), array( 'example.ir/shop', '', '', true, true ) );
check( 'site address: an IP address host and an IPv6 one', array( $n( 'http://192.168.1.10/shop' ), $n( 'http://[::1]:8080/' ) ), array( '192.168.1.10/shop', '[::1]:8080' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
