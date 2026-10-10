<?php
/**
 * CLI unit tests for the pure-logic classes.   Run:  php tests/unit.php
 */
define( 'CRDR_TESTING', true );
require __DIR__ . '/../includes/class-crdr-jalali.php';
require __DIR__ . '/../includes/class-crdr-rfm.php';
require __DIR__ . '/../includes/class-crdr-stats.php';

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

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
