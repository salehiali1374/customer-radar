<?php
/**
 * Analytics characterization + regression tests (no WordPress, no WooCommerce, no database, no network).
 *
 *   php tests/analytics.php            (see tests/analytics/README.md for the Docker command and the labels)
 *
 * Every result has one label:
 *   PASS   an ordinary check of behaviour that is correct (or of the harness itself)
 *   CHAR   characterization: pins what the plugin does TODAY, even where that is wrong. If it changes, someone changed behaviour.
 *   XFAIL  a KNOWN DEFECT / pending decision: asserts the CORRECT behaviour and is EXPECTED to fail today.
 *   XPASS  an XFAIL test that now passes = the defect was fixed: the run fails until the test is promoted to PASS.
 *   FAIL   anything else that went wrong.
 * Exit code 0 only when there is no FAIL and no XPASS.
 */
require __DIR__ . '/analytics/harness.php';

$R = array( 'PASS' => 0, 'CHAR' => 0, 'XFAIL' => array(), 'XPASS' => array(), 'FAIL' => 0 );

function crdr_t_dump( $v ) {
	return is_scalar( $v ) || null === $v ? var_export( $v, true ) : json_encode( $v, JSON_UNESCAPED_UNICODE );
}
function crdr_t_same( $got, $want, $tol = 1e-9 ) {
	if ( is_array( $got ) || is_array( $want ) ) { // compare element by element; 570000 and 570000.0 are the same number
		if ( ! is_array( $got ) || ! is_array( $want ) || array_keys( $got ) !== array_keys( $want ) ) {
			return false;
		}
		foreach ( $want as $k => $v ) {
			if ( ! crdr_t_same( $got[ $k ], $v, $tol ) ) {
				return false;
			}
		}
		return true;
	}
	if ( is_numeric( $got ) && is_numeric( $want ) && ! is_string( $got ) && ! is_string( $want ) ) {
		return abs( (float) $got - (float) $want ) <= $tol;
	}
	return $got === $want;
}
/** An ordinary check. */
function check( $name, $got, $want, $tol = 1e-9 ) {
	global $R;
	if ( crdr_t_same( $got, $want, $tol ) ) {
		$R['PASS']++;
		return;
	}
	$R['FAIL']++;
	echo "FAIL  $name\n      got:  " . crdr_t_dump( $got ) . "\n      want: " . crdr_t_dump( $want ) . "\n";
}
/** Characterization: pin today's behaviour (which may be wrong; the name should say so). */
function char( $name, $got, $want, $tol = 1e-9 ) {
	global $R;
	if ( crdr_t_same( $got, $want, $tol ) ) {
		$R['CHAR']++;
		return;
	}
	$R['FAIL']++;
	echo "FAIL  [CHAR] $name\n      got:  " . crdr_t_dump( $got ) . "\n      want: " . crdr_t_dump( $want ) . "\n      (the plugin's behaviour changed: update this pin ONLY if the change was intended)\n";
}
/**
 * A known defect or an undecided product definition. $correct is TRUE when the plugin behaves the way it should.
 * Today it must be false; when it becomes true the run fails (XPASS) so the test gets promoted.
 *
 * @param string $kind DEFECT (confirmed bug) | DECISION (the right answer depends on a product decision; this asserts the recommended one)
 */
function xfail( $id, $kind, $name, $correct, $detail = '' ) {
	global $R;
	if ( $correct ) {
		$R['XPASS'][] = "$id $name";
		echo "XPASS $id [$kind] $name\n      this known-defect test now PASSES: the defect looks fixed. Move it to check() and drop the xfail.\n";
		return;
	}
	$R['XFAIL'][] = array( $id, $kind, $name, $detail );
}
function crdr_t_section( $t ) {
	echo "== $t\n";
}

/* ======================================================================================== load */
$DS  = CRDR_T::fixture( 'reference-dataset.json' );
$EX  = CRDR_T::fixture( 'reference-expected.json' );
$GT  = CRDR_T::fixture( 'reference-ground-truth.json' );
$ASOF = (int) $DS['asof'];
CRDR_T::reset();
// PHASE 3A: two pipelines. $IMP0 = the state after the page import and BEFORE the identity step (every order keeps the provisional
// key CRDR_Sync::extract() gave it). $IMP = the whole pipeline, as a rebuild ends: the identity step has joined the orders of one
// person (CRDR_Identity::resolve). Analytics, RFM and every pin below run on $IMP, the production result.
$IMP0  = CRDR_T::import( $DS );
$IMP   = CRDR_T::import( $DS, array( 'completed', 'processing' ), true );
$STATS = CRDR_T::build( $IMP );
$RFM   = CRDR_T::rfm( $IMP['customers'], $ASOF );
$KEY0  = function ( $order_id ) use ( $IMP0 ) {
	return $IMP0['extract'][ $order_id ]['ckey'];
};
$KEY   = function ( $order_id ) use ( $IMP ) {
	return $IMP['extract'][ $order_id ]['ckey'];
};

// The plugin's customer keys against the ground truth's people: label => [keys], key => [labels]
$KEYS_OF_PERSON = array();
$PERSON_OF_KEY  = array();
foreach ( $IMP['extract'] as $oid => $ex ) {
	if ( ! empty( $ex['void'] ) ) { // PHASE 4.2: a void order (REV-3) belongs to no customer
		continue;
	}
	$label = $GT['people_by_order'][ (string) $oid ];
	$KEYS_OF_PERSON[ $label ][ $ex['ckey'] ] = true;
	$PERSON_OF_KEY[ $ex['ckey'] ][ $label ]  = true;
}
$KEYOF = function ( $label ) use ( $KEYS_OF_PERSON ) { // the one key the plugin uses for this person
	return isset( $KEYS_OF_PERSON[ $label ] ) && 1 === count( $KEYS_OF_PERSON[ $label ] ) ? key( $KEYS_OF_PERSON[ $label ] ) : null;
};

/* ====================================================================================== 0. harness */
crdr_t_section( 'harness and fixture integrity' );
echo '   is_email() source: ' . $GLOBALS['CRDR_T']['is_email_source'] . "\n";
check( 'timezone data: Asia/Tehran is UTC+3:30 all through 2026 (PHP needs tzdata 2022b or newer, otherwise every month boundary in this suite is wrong)', array(
	( new DateTime( '2026-07-01 12:00:00', new DateTimeZone( 'Asia/Tehran' ) ) )->getOffset(),
	( new DateTime( '2026-01-01 12:00:00', new DateTimeZone( 'Asia/Tehran' ) ) )->getOffset(),
), array( 12600, 12600 ) );
check( 'dataset has 18 orders', count( $DS['orders'] ), 18 );
$bad = array();
foreach ( $DS['orders'] as $o ) {
	if ( $o['total'] !== array_sum( array_column( $o['items'], 1 ) ) + $o['shipping'] + $o['tax'] ) {
		$bad[] = $o['id'];
	}
}
check( 'every order: total = items + shipping + tax', $bad, array() );
check( 'is_email accepts the address WordPress writes when it erases a customer', (bool) is_email( 'deleted@site.invalid' ), true );
check( 'is_email rejects a malformed address', (bool) is_email( 'not-an-email' ), false );
check( 'the erased-customer address is the one WordPress core uses', 'deleted@site.invalid', 'deleted@site.invalid' );
// PHASE 4.2: the 13 orders of the default statuses are still read; one of them (17, fully refunded) is VOID under the approved REV-3 definition, so the
// independently computed list of valid orders has 12 and the void list has 1. The status filter itself is what this line tests.
$imported_ids = array_keys( $IMP['extract'] );
sort( $imported_ids );
$expected_ids = array_merge( $EX['valid_order_ids'], $EX['void_order_ids'] );
sort( $expected_ids );
check( 'only the default statuses are imported (13 of 18 orders): 12 valid + 1 void', array( $imported_ids, $IMP['void'] ), array( $expected_ids, $EX['void_order_ids'] ) );

/* ================================================================== 1. current behaviour matches the independent calculation */
crdr_t_section( 'characterization: plugin output equals the independently computed expectations (reference-expected.json)' );
char( 'orders_total', $STATS['orders_total'], $EX['orders_total'] );
char( 'customers_total', $STATS['customers_total'], $EX['customers_total'] );
char( 'repeat customers', $STATS['repeat']['customers'], $EX['repeat_customers'] );
char( 'repeat customers share', $STATS['repeat']['customers_pct'], $EX['repeat_customers_pct'] );
char( 'repeat customers revenue share', $STATS['repeat']['revenue_pct'], $EX['repeat_revenue_pct'] );
char( 'median days to second purchase (returners only)', $STATS['repeat']['second_median_days'], $EX['second_median_days'], 1e-6 );
char( 'median gap between orders', $STATS['gap_median'], $EX['gap_median_days'], 1e-6 );
char( 'AOV mean', $STATS['aov']['mean'], $EX['aov_plugin_mean'], 1e-6 );
char( 'Pareto top 10%', $STATS['pareto']['top10'], $EX['pareto_top10'] );
char( 'Pareto top 20%', $STATS['pareto']['top20'], $EX['pareto_top20'] );
char( 'Pareto top-10% customer count', $STATS['pareto']['top10_count'], $EX['pareto_top10_count'] );
$mon = CRDR_T::monthly( $STATS );
foreach ( $EX['monthly'] as $k => $v ) {
	char( "month $k revenue / new / returning", array( $mon[ $k ]['revenue'], $mon[ $k ]['new_rev'], $mon[ $k ]['ret_rev'] ), array( $v['revenue'], $v['new_rev'], $v['ret_rev'] ) );
}
char( 'monthly series has exactly the expected months', array_keys( $mon ), array_keys( $EX['monthly'] ) );
$prod_total = array_sum( array_column( $STATS['products'], 'revenue' ) );
char( 'product revenue total', $prod_total, $EX['product_revenue_total'] );
$cust_now = array(); // keyed by PERSON (ground-truth label): the plugin's keys are looked up, a key that mixes two people has no label
foreach ( $IMP['customers'] as $k => $c ) {
	$label            = isset( $PERSON_OF_KEY[ $k ] ) && 1 === count( $PERSON_OF_KEY[ $k ] ) ? key( $PERSON_OF_KEY[ $k ] ) : $k . ' (mixes people)';
	$cust_now[ $label ] = array( 'orders' => $c['orders_count'], 'spent' => (int) $c['total_spent'] );
}
$cust_exp = $EX['customers'];
ksort( $cust_now );
ksort( $cust_exp );
char( 'customers (by person), order counts and spend: the plugin has exactly the 9 people of the ground truth', $cust_now, $cust_exp );
foreach ( $EX['rfm'] as $label => $v ) {
	$g = isset( $RFM[ $KEYOF( $label ) ] ) ? $RFM[ $KEYOF( $label ) ] : array( 'r' => null, 'f' => null, 'm' => null, 'segment' => null );
	char( "RFM $label", array( $g['r'], $g['f'], $g['m'], $g['segment'] ), array( $v['r'], $v['f'], $v['m'], $v['segment'] ) );
}

crdr_t_section( 'cohort retention (cells after the last month with data depend on today and are not pinned)' );
$coh_now = array();
foreach ( $STATS['cohorts'] as $idx => $c ) {
	$k = CRDR_T::month_key( $idx );
	if ( isset( $EX['cohorts'][ $k ] ) ) {
		$n = count( $EX['cohorts'][ $k ]['ret'] );
		$coh_now[ $k ] = array( 'size' => $c['size'], 'ret' => array_map( function ( $x ) {
			return round( $x, 1 );
		}, array_slice( array_values( $c['ret'] ), 0, $n ) ) );
	}
}
// PHASE 3A: 7 cohorts, not 6: with the erased orders no longer merged into one customer, the second erased order starts its own cohort.
char( 'cohort sizes and retention equal the independent calculation (7 cohorts)', $coh_now, $EX['cohorts'] );
check( 'the plugin reports exactly the 7 cohorts that exist', count( $STATS['cohorts'] ), 7 );

// more than 12 monthly cohorts: only the 12 most recent are kept
$co_ord  = array();
$co_cust = array();
$co_first = array();
for ( $i = 0; $i < 14; $i++ ) {
	$ts  = $ASOF - ( 14 - $i ) * 31 * 86400;
	$key = "c$i";
	$co_ord[]        = array( 'id' => $i + 1, 'ckey' => $key, 'ts' => $ts, 'net' => 1000 );
	$co_cust[ $key ] = array( 'ckey' => $key, 'orders_count' => 1, 'total_spent' => 1000.0, 'first_ts' => $ts, 'last_ts' => $ts );
	$co_first[ CRDR_Jalali::month_index( $ts ) ] = true;
}
$many = CRDR_T::build( array( 'orders' => $co_ord, 'items' => array(), 'customers' => $co_cust ) );
$want = array_slice( array_keys( $co_first ), -12 ); // ascending month indexes; the 12 most recent
sort( $want );
check( 'with more than 12 monthly cohorts the 12 most recent are kept and the oldest are dropped (days apart: 31, so each is its own month)', array( count( $co_first ) >= 13, array_keys( $many['cohorts'] ) ), array( true, $want ) );

crdr_t_section( 'product pairs: the lift denominator' );
// 10 orders [A,B], 10 [A], 10 [B]; 298 one-off long-tail products (they fill the "top 300"); 40 more orders whose only product
// ranks below the top 300. The plugin drops those 40 orders when it counts orders, so n is 328 instead of 368.
$aff_items = array();
$aff_ord   = array();
$oid       = 0;
$add = function ( array $products ) use ( &$aff_items, &$aff_ord, &$oid ) {
	$oid++;
	$aff_ord[] = array( 'id' => $oid, 'ckey' => "a$oid", 'ts' => 1790000000 + $oid, 'net' => 100000 );
	foreach ( $products as $p ) {
		$aff_items[] = array( $oid, $p, 1, 100000 );
	}
};
for ( $i = 0; $i < 10; $i++ ) { $add( array( 1, 2 ) ); }
for ( $i = 0; $i < 10; $i++ ) { $add( array( 1 ) ); }
for ( $i = 0; $i < 10; $i++ ) { $add( array( 2 ) ); }
for ( $i = 0; $i < 298; $i++ ) { $add( array( 1000 + $i ) ); }
for ( $i = 0; $i < 40; $i++ ) { $add( array( 5000 + $i ) ); }
$aff_cust = array();
foreach ( $aff_ord as $o ) {
	$aff_cust[ $o['ckey'] ] = array( 'ckey' => $o['ckey'], 'orders_count' => 1, 'total_spent' => 100000.0, 'first_ts' => $o['ts'], 'last_ts' => $o['ts'] );
}
$aff = CRDR_T::build( array( 'orders' => $aff_ord, 'items' => $aff_items, 'customers' => $aff_cust ) );
$pair = $aff['pairs'][0];
$total_orders = count( $aff_ord );
check( 'the pair A+B is found with support 10 and confidence 50%', array( $pair['a'], $pair['b'], $pair['support'], $pair['conf_ab'], $pair['conf_ba'] ), array( 1, 2, 10, 0.5, 0.5 ) );
// PHASE 4.1 (analytics defect fix): the pin that recorded the wrong denominator (328 orders, lift 8.2) is replaced; basket_orders (328, orders with a listed product) is unchanged.
char( 'basket_orders is still the 328 orders that contain a top-300 product (it is no longer the lift denominator)', $aff['basket_orders'], 328 );
check( 'AFF-1 (was DEFECT, fixed in Phase 4.1): lift uses ALL 368 orders: 10 x 368 / (20 x 20) = 9.2', crdr_t_same( $pair['lift'], 10 * $total_orders / ( 20 * 20 ), 1e-9 ), true );
// PHASE 4.1 (analytics defect fix): the pin for the restricted denominator (10 / 328) is replaced by AFF-2 below.
check( 'AFF-2 (was DEFECT, fixed in Phase 4.1): the "orders with two or more products" share is over all orders: 10 / 368', crdr_t_same( $aff['multi_item_pct'], 10 / $total_orders, 1e-9 ), true );

/* ======================================================================================= 2. identity */
crdr_t_section( 'customer identity' );
check( 'phone formats "0912 000 0001", "09120000001" and "+98 912 000 0001" are one customer', array( $KEY( 1 ), $KEY( 2 ), $KEY( 3 ) ), array( 'p:09120000001', 'p:09120000001', 'p:09120000001' ) );
check( 'a phone with dashes is normalised', $KEY0( 11 ), 'p:09123456789' );
// PHASE 3A. Before the identity step every order has a PROVISIONAL key from its own fields (these pins are about that step and are unchanged):
char( 'before the identity step the same person (G2) has THREE provisional keys: phone, then email only, then a new phone', array( $KEY0( 11 ), $KEY0( 12 ), $KEY0( 13 ) ), array( 'p:09123456789', 'e:g2@example.test', 'p:09351234567' ) );
char( 'an order with malformed phone and email is its own one-order customer', array( $KEY0( 18 ), $KEY( 18 ) ), array( 'o:18', 'o:18' ) );
// RECLASSIFIED in Phase 3A (was: char 'two different ERASED orders share one customer key today', expecting e:deleted@site.invalid twice):
// the placeholder address WordPress writes is "no email", so each erased order is its own unidentified customer.
check( 'the erased-customer placeholder (deleted@site.invalid) is no identity: two erased orders get two keys (was CHAR: one shared key)', array( $KEY0( 14 ), $KEY0( 15 ), $KEY( 14 ), $KEY( 15 ) ), array( 'o:14', 'o:15', 'o:14', 'o:15' ) );
// PROMOTED in Phase 3A (were xfail ID-1..ID-4): the identity step joins G2 and keeps the erased orders apart.
$g2 = array( $KEY( 11 ), $KEY( 12 ), $KEY( 13 ) );
check( 'ID-1 (was DEFECT): orders that share an email (G2: orders 11, 12, 13) belong to one customer, keyed by the latest phone', array( 1 === count( array_unique( $g2 ) ), $g2[0] ), array( true, 'p:09351234567' ) );
check( 'ID-2 (was DEFECT): erased orders (no identity left) are not merged into one customer', $KEY( 14 ) !== $KEY( 15 ), true );
// PHASE 4.2: REV-3 removes the person whose only order was fully refunded from the customers (the ground-truth FILE still lists 9 people because it lists everybody
// who appears in a counted order, and is not edited). The same people-and-orders data of that file, restricted to the orders that are real sales
// (its own list `orders_that_are_real_sales`), gives the ground truth under the approved definition.
$valid_orders_of  = array();
foreach ( $GT['orders_that_are_real_sales'] as $oid ) {
	$valid_orders_of[ $GT['people_by_order'][ (string) $oid ] ][] = $oid;
}
$gt_people_valid  = count( $valid_orders_of );
$gt_repeat_valid  = count( array_filter( $valid_orders_of, function ( $l ) {
	return count( $l ) >= 2;
} ) );
$net_of_order     = function ( $oid ) use ( $DS ) { // order-level refund against the product lines (this dataset has no per-line refund): items - refunded
	foreach ( $DS['orders'] as $o ) {
		if ( $o['id'] === $oid ) {
			return array_sum( array_column( $o['items'], 1 ) ) - $o['refunded'];
		}
	}
	return 0;
};
$gt_rev_total  = array_sum( array_map( $net_of_order, $GT['orders_that_are_real_sales'] ) );
$gt_rev_repeat = 0;
foreach ( $valid_orders_of as $l ) {
	if ( count( $l ) >= 2 ) {
		$gt_rev_repeat += array_sum( array_map( $net_of_order, $l ) );
	}
}
check( 'ID-3 (was DEFECT, Phase 4.2 basis): repeat-customer share equals the ground truth restricted to real sales (2 of 8 people)', crdr_t_same( $STATS['repeat']['customers_pct'], $gt_repeat_valid / $gt_people_valid, 1e-9 ), true );
check( 'ID-4 (was DEFECT, Phase 4.2 basis): repeat-customer revenue share equals the ground truth restricted to real sales, in net sales', crdr_t_same( $STATS['repeat']['revenue_pct'], $gt_rev_repeat / $gt_rev_total, 1e-9 ), true );
// PHASE 4.2: the people with at least one real sale: the 9 of the ground-truth file minus the one whose only order was fully refunded (REV-3).
check( 'the plugin\'s customers are EXACTLY the people of the ground truth who have a real sale (8 of its 9): one key per person, one person per key', array( count( $IMP['customers'] ), array_map( 'count', $KEYS_OF_PERSON ) === array_fill_keys( array_keys( $KEYS_OF_PERSON ), 1 ), array_map( 'count', $PERSON_OF_KEY ) === array_fill_keys( array_keys( $PERSON_OF_KEY ), 1 ), count( $KEYS_OF_PERSON ) ), array( $gt_people_valid, true, true, $gt_people_valid ) );
check( 'the ground-truth file is unchanged: it still lists 9 people (the refunded-only one is among them)', $GT['truth_people'], 9 );
// a registered user who types a different phone on a later order
$two = array( 'orders' => array(
	array( 'id' => 901, 'status' => 'completed', 'ts' => $ASOF - 90 * 86400, 'uid' => 777, 'phone' => '09121110001', 'email' => 'x1@example.test', 'items' => array( array( 1, 100000 ) ), 'shipping' => 0, 'tax' => 0, 'total' => 100000, 'refunded' => 0 ),
	array( 'id' => 902, 'status' => 'completed', 'ts' => $ASOF - 30 * 86400, 'uid' => 777, 'phone' => '09122220002', 'email' => 'x1@example.test', 'items' => array( array( 1, 100000 ) ), 'shipping' => 0, 'tax' => 0, 'total' => 100000, 'refunded' => 0 ),
) );
$imp2 = CRDR_T::import( $two );
char( 'before the identity step the same registered user (id 777) with two phones has two provisional keys (the user id is ignored when a phone exists)', array_column( $imp2['extract'], 'ckey' ), array( 'p:09121110001', 'p:09122220002' ) );
$imp2r = CRDR_T::import( $two, array( 'completed', 'processing' ), true );
check( 'ID-5 (was DECISION, promoted): the same registered WordPress user is one customer; the key follows the latest phone, orders and spend add up', array( array_values( array_unique( array_column( $imp2r['extract'], 'ckey' ) ) ), $imp2r['customers']['p:09122220002']['orders_count'], $imp2r['customers']['p:09122220002']['total_spent'] ), array( array( 'p:09122220002' ), 2, 200000.0 ) );
// the user-meta phone fallback
$GLOBALS['CRDR_T']['users'][555] = '0935 777 8899';
$r555 = CRDR_Sync::extract( new CRDR_T_Order( array( 'id' => 903, 'phone' => '', 'email' => '', 'uid' => 555, 'ts' => 1, 'total' => 1, 'refunded' => 0 ) ) );
check( 'a registered user with an empty order phone falls back to the phone saved in the account', $r555['ckey'], 'p:09357778899' );
$GLOBALS['CRDR_T']['users'] = array();

/* ================================================================================ 3. status filter */
crdr_t_section( 'order statuses' );
$all_status = CRDR_T::import( $DS, array( 'completed', 'processing', 'on-hold', 'cancelled', 'failed', 'pending', 'refunded' ) );
check( 'with every status counted there are 18 orders', count( $all_status['extract'] ), 18 );
char( 'if a store also counts "refunded", a fully refunded order enters as an order with net 0', $all_status['extract'][6]['net'], 0 );
char( 'default statuses exclude refunded / cancelled / failed / pending / on-hold', array_diff( array( 6, 7, 8, 9, 10 ), array_keys( $IMP['extract'] ) ), array( 6, 7, 8, 9, 10 ) );

/* =========================================================================== 4. revenue definitions */
crdr_t_section( 'revenue definitions' );
$monthly_total = array_sum( array_column( $STATS['monthly'], 'revenue' ) );
// PHASE 4.2: REV-1 is approved: the dashboard revenue is NET SALES. The pin that said 4,612,000 (order total less refunds, shipping and tax included) is replaced by
// REV-1 below, which compares with the independent ground truth (4,450,000), and by the explicit 'shipping + tax are not revenue' line.
check( 'the gap between the old basis (order total less refunds) and net sales is exactly the shipping and tax of the counted orders, and it is no longer in the revenue', array( 4612000 - $monthly_total, $EX['shipping_in_counted'] ), array( 162000, 0 ) );
// PHASE 4.2: REV-1: shipping and tax are NOT revenue (order 2: 500,000 of items; its 50,000 shipping and 45,000 tax are outside).
check( 'an order with shipping + tax enters revenue WITHOUT them (order 2: 500,000 items; 50,000 shipping and 45,000 tax stay out)', $IMP['extract'][2]['net'], 500000 );
char( 'a partial refund is subtracted from the order (order 5: 1,000,000 - 400,000)', $IMP['extract'][5]['net'], 600000 );
// PHASE 4.2: REV-2: product revenue now subtracts the refunds (replaced by the REV-2 check below; 5,050,000 was the items total before refunds).
// PHASE 4.2: REV-3: an order with no net sales is VOID: net 0, no customer, no RFM row (replaces the pin that said it was counted with net 0).
check( 'an order fully refunded whose status stayed "completed" is VOID: net 0, not an order for anybody, no customer (order 17)', array( $IMP['extract'][17]['net'], $IMP['extract'][17]['void'], isset( $IMP['customers']['p:09120000006'] ), in_array( 17, array_column( $IMP['orders'], 'id' ), true ) ), array( 0.0, true, false, false ) );
// PHASE 4.2: revenue IS net sales now, so there is no gap to the ground truth any more.
check( 'revenue equals the ground-truth net sales (no shipping or tax inside it any more)', $monthly_total - $GT['net_sales_excl_tax_shipping'], 0, 1e-6 );
check( 'REV-1 (was DECISION, approved in Phase 4.2): revenue is "net sales": items after discounts, excluding shipping and tax, net of refunds (4,450,000)', crdr_t_same( $monthly_total, $GT['net_sales_excl_tax_shipping'] ), true );
check( 'REV-2 (was DEFECT, fixed in Phase 4.2): product revenue and order revenue use the same refund treatment (they reconcile on a common basis)', crdr_t_same( $prod_total, $GT['net_sales_excl_tax_shipping'] ), true );
check( 'REV-3 (was DECISION, approved in Phase 4.2): a fully refunded order is not counted as an order or a customer', 12 === $STATS['orders_total'] && ! isset( $RFM[ $KEY( 17 ) ] ), true );

/* ================================================================================ 5. repeat curve */
crdr_t_section( 'repeat-purchase curve and second-purchase timing (customers who never return)' );
$SV  = CRDR_T::fixture( 'survival-scenario.json' );
$sv_orders = array();
$sv_cust   = array();
foreach ( $SV['customers'] as $c ) {
	$sv_orders[] = array( 'id' => count( $sv_orders ) + 1, 'ckey' => $c['key'], 'ts' => $SV['base_ts'], 'net' => 100000 );
	$n = 1;
	$last = $SV['base_ts'];
	if ( null !== $c['second_after_days'] ) {
		$last         = $SV['base_ts'] + $c['second_after_days'] * 86400;
		$sv_orders[]  = array( 'id' => count( $sv_orders ) + 1, 'ckey' => $c['key'], 'ts' => $last, 'net' => 100000 );
		$n            = 2;
	}
	$sv_cust[ $c['key'] ] = array( 'ckey' => $c['key'], 'orders_count' => $n, 'total_spent' => 100000.0 * $n, 'first_ts' => $SV['base_ts'], 'last_ts' => $last );
}
$svs  = CRDR_T::build( array( 'orders' => $sv_orders, 'items' => array(), 'customers' => $sv_cust ) );
$surv = array();
foreach ( $svs['survival'] as $p ) {
	$surv[ $p['d'] ] = $p;
}
/** Truth: of ALL customers still without a next order after d days, the share that ordered within the next 30 days. */
$truth = function ( $d ) use ( $SV ) {
	$at = 0;
	$hit = 0;
	foreach ( $SV['customers'] as $c ) {
		$g = $c['second_after_days'];
		if ( null === $g || $g > $d ) {
			$at++;
			if ( null !== $g && $g <= $d + 30 ) {
				$hit++;
			}
		}
	}
	return $at ? $hit / $at : null;
};
// PHASE 4.1 (analytics defect fix): the pins 'curve at 0 days = 60%' and 'at 180 days = 100%' recorded the bias (customers who never returned were missing); CURVE-1/2 below are the corrected, independently computed values.
char( 'median days to second purchase today: 28 (only the 5 returners are in the list)', $svs['repeat']['second_median_days'], 28.0, 1e-9 );
check( 'the truth for the scenario (independent calculation): 33% at 0 days, 33% at 180 days, 0% at 30 days', array( round( $truth( 0 ), 4 ), round( $truth( 180 ), 4 ), $truth( 30 ) ), array( 0.3333, 0.3333, 0.0 ) );
check( 'CURVE-1 (was DEFECT, fixed in Phase 4.1): the re-purchase curve at 0 days is the share of ALL customers who return within 30 days (33%)', crdr_t_same( $surv[0]['p'], $truth( 0 ), 0.01 ), true );
check( 'CURVE-1: ... and its sample is every customer of the scenario (9), not only the 5 who came back', $surv[0]['n'], 9 );
check( 'CURVE-2 (was DEFECT, fixed in Phase 4.1): the re-purchase curve at 180 days is the real 33%, not 100%', crdr_t_same( $surv[180]['p'], $truth( 180 ), 0.01 ), true );
check( 'CURVE-2: ... computed from the 6 customers still without a next order at 180 days (2 returned late, 4 never)', $surv[180]['n'], 6 );
// PHASE 4.2, CURVE-3 resolved by the approved definition: the median stays "among customers who came back" (the insight card says so in its words); the
// customers who have not come back are in the 30/60/90-day proportions, which respect how long each customer has been observed. The pin above (28) is
// therefore CORRECT as defined, and the expected failure that wanted 210 (a median over everybody, which does not exist when fewer than half return) is retired.
// Independent truth for the proportions: the fixture's own list of second purchases (9 customers, all observed 400 days).
$rw = array();
foreach ( $svs['return_within'] as $x ) {
	$rw[ $x['days'] ] = $x;
}
foreach ( array( 30, 60, 90 ) as $d ) {
	$hit = 0;
	foreach ( $SV['customers'] as $c ) {
		if ( null !== $c['second_after_days'] && $c['second_after_days'] <= $d ) {
			$hit++;
		}
	}
	check( "CURVE-3 (resolved in Phase 4.2): $d-day return proportion = $hit of all " . count( $SV['customers'] ) . ' customers (all observed for 400 days)', array( $rw[ $d ]['hit'], $rw[ $d ]['n'], round( $rw[ $d ]['p'], 6 ) ), array( $hit, count( $SV['customers'] ), round( $hit / count( $SV['customers'] ), 6 ) ) );
}
check( 'CURVE-3 (resolved): the median among returners is still 28 days (3 of the 5 returners came back within 28 days)', $svs['repeat']['second_median_days'], 28.0, 1e-9 );
// repeat share, tenure blind: 6 old customers (3 repeat) + 4 customers who ordered yesterday
$ten_c = array();
$ten_o = array();
$t0 = $ASOF - 400 * 86400;
for ( $i = 1; $i <= 10; $i++ ) {
	$rep = $i <= 3;
	$new = $i > 6;
	$first = $new ? $ASOF - 86400 : $t0;
	$ten_o[] = array( 'id' => count( $ten_o ) + 1, 'ckey' => "t$i", 'ts' => $first, 'net' => 1000 );
	if ( $rep ) {
		$ten_o[] = array( 'id' => count( $ten_o ) + 1, 'ckey' => "t$i", 'ts' => $first + 30 * 86400, 'net' => 1000 );
	}
	$ten_c["t$i"] = array( 'ckey' => "t$i", 'orders_count' => $rep ? 2 : 1, 'total_spent' => $rep ? 2000.0 : 1000.0, 'first_ts' => $first, 'last_ts' => $rep ? $first + 30 * 86400 : $first );
}
$tn = CRDR_T::build( array( 'orders' => $ten_o, 'items' => array(), 'customers' => $ten_c ) );
char( 'repeat share counts everybody, including customers acquired yesterday: 3 of 10 = 30% (mature customers: 3 of 6 = 50%)', $tn['repeat']['customers_pct'], 0.3 );
// PHASE 4.2, CURVE-4 approved: BOTH rates are shown. The raw rate above (30%) stays as the pin; the rate among customers whose first order is at least 90 days old is the new key.
check( 'CURVE-4 (was DECISION, approved in Phase 4.2): the repeat rate among customers with at least 90 days of tenure is 50% (3 of the 6 who had time), next to the raw 30%', array( $tn['repeat']['customers_pct_mature'], $tn['repeat']['mature_customers'], $tn['repeat']['customers_pct'] ), array( 0.5, 6, 0.3 ) );
// the observation window is respected: the 4 customers acquired yesterday are in neither the mature rate nor the 30/60/90 proportions
$tw = array();
foreach ( $tn['return_within'] as $x ) {
	$tw[ $x['days'] ] = $x;
}
check( 'CURVE-3/4: customers who have not been observed for N days and have not returned are left OUT of the N-day proportion (4 acquired yesterday: 6 eligible, 3 returned)', array( $tw[30]['n'], $tw[30]['hit'], $tw[60]['n'], $tw[90]['n'], $tw[90]['hit'] ), array( 6, 3, 6, 6, 3 ) );

/* ========================================================================================== 6. RFM */
crdr_t_section( 'RFM scoring' );
$stale_c = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$ts = $ASOF - ( 400 + 10 * $i ) * 86400;
	$stale_c[ "s$i" ] = array( 'ckey' => "s$i", 'orders_count' => 1, 'total_spent' => 100000.0, 'first_ts' => $ts, 'last_ts' => $ts );
}
$stale = CRDR_T::rfm( $stale_c, $ASOF );
$stale_segments = array_count_values( array_column( $stale, 'segment' ) );
ksort( $stale_segments );
// PHASE 4.2 (RFM-1 approved): recency is still scored relative to the store (quintiles 5,4,4,3,3,2,2,1,1,1 for these ten), but past lost_days (365) the
// score is capped at 2: nobody a year or more away counts as recent. The previous pin recorded the unguarded ranks.
char( 'recency is relative to the store, but capped at 2 past lost_days: ten single-order customers last seen 410-500 days ago get R = 2,2,2,2,2,2,2,1,1,1', array_column( $stale, 'r' ), array( 2, 2, 2, 2, 2, 2, 2, 1, 1, 1 ) );
// PHASE 4.2: all ten are single-order customers past lost_days: all "lost" (before: lost 5, need_attention 2, potential 3 = "bought recently").
char( 'those customers are all labelled lost', $stale_segments, array( 'lost' => 10 ) );
$recent_labels = 0;
foreach ( $stale as $k => $x ) {
	if ( in_array( $x['segment'], array( 'potential', 'champion', 'loyal', 'new' ), true ) && ( $ASOF - $stale_c[ $k ]['last_ts'] ) / 86400 > 365 ) {
		$recent_labels++;
	}
}
check( 'RFM-1 (was DECISION, approved in Phase 4.2): no customer inactive for more than a year is labelled as a recent / healthy segment (potential, loyal, champion, new)', $recent_labels, 0 );
$mono = CRDR_RFM::score_all( array(
	array( 'id' => 1, 'orders_count' => 1, 'total_spent' => 1000000, 'first_ts' => $ASOF - 50 * 86400, 'last_ts' => $ASOF - 50 * 86400 ),
	array( 'id' => 2, 'orders_count' => 1, 'total_spent' => 1090000, 'first_ts' => $ASOF - 50 * 86400, 'last_ts' => $ASOF - 50 * 86400 ),
	array( 'id' => 3, 'orders_count' => 1, 'total_spent' => 300000, 'first_ts' => $ASOF - 50 * 86400, 'last_ts' => $ASOF - 50 * 86400 ),
	array( 'id' => 4, 'orders_count' => 1, 'total_spent' => 400000, 'first_ts' => $ASOF - 50 * 86400, 'last_ts' => $ASOF - 50 * 86400 ),
	array( 'id' => 5, 'orders_count' => 1, 'total_spent' => 500000, 'first_ts' => $ASOF - 50 * 86400, 'last_ts' => $ASOF - 50 * 86400 ),
	array( 'id' => 6, 'orders_count' => 1, 'total_spent' => 600000, 'first_ts' => $ASOF - 50 * 86400, 'last_ts' => $ASOF - 50 * 86400 ),
), $ASOF );
char( 'M is a ranking of spend as given: a 1,090,000 customer (same goods + 90,000 shipping and tax) outranks a 1,000,000 one', array( $mono[1]['m'], $mono[2]['m'] ), array( 4, 5 ) );
check( 'frequency bands are fixed (1,2,3,4-5,6+)', array_map( array( 'CRDR_RFM', 'score_frequency' ), array( 1, 2, 3, 4, 5, 6 ) ), array( 1, 2, 3, 4, 4, 5 ) );

/* ====================================================================== 7. month boundaries / timezone */
crdr_t_section( 'month boundaries and the site timezone' );
check( 'order 3 (23:59:59 Tehran) is in Shahrivar 1405 and order 4 (00:00:00 Tehran) in Mehr 1405, when the site timezone is Asia/Tehran', array( $mon['1405-06']['revenue'], $mon['1405-07']['revenue'] ), array( $EX['monthly']['1405-06']['revenue'], $EX['monthly']['1405-07']['revenue'] ) ); // PHASE 4.2: from the independent expected values (were literals 627,000 / 790,000 on the old basis)
CRDR_T::set_timezone( 'UTC' );
$stats_utc = CRDR_T::build( CRDR_T::import( $DS ) );
$mon_utc   = CRDR_T::monthly( $stats_utc );
CRDR_T::set_timezone( 'Asia/Tehran' );
// PHASE 4.2: order 4 is worth its 500,000 of items now (it was 540,000 with shipping): the move is derived from the independent monthly values.
char( 'with the WordPress default timezone (UTC) order 4 moves from Mehr into Shahrivar (500,000 of net sales)', array( $mon_utc['1405-06']['revenue'], $mon_utc['1405-07']['revenue'] ), array( $EX['monthly']['1405-06']['revenue'] + 500000, $EX['monthly']['1405-07']['revenue'] - 500000 ) );
char( 'the total does not change with the timezone, only the month it lands in', array_sum( array_column( $stats_utc['monthly'], 'revenue' ) ), array_sum( array_column( $STATS['monthly'], 'revenue' ) ) );
$tz_mentions = 0;
foreach ( array( 'class-crdr-analytics.php', 'class-crdr-sync.php', 'class-crdr-jalali.php', 'class-crdr-page-dashboard.php' ) as $f ) {
	$tz_mentions += preg_match_all( '/timezone_string|wp_timezone\(|get_option\(\s*.timezone/', CRDR_T::source( $f ) );
}
char( '[static] nothing in the analytics/dashboard code checks or warns about the site timezone', $tz_mentions, 0 );
$ts_mehr1 = ( new DateTime( '2026-09-23 00:00:00', new DateTimeZone( 'Asia/Tehran' ) ) )->getTimestamp();
check( 'Jalali conversion at the boundary: 2026-09-23 00:00 Tehran is 1405/07/01, and one second earlier is 1405/06/31', array( implode( '/', CRDR_Jalali::ymd( $ts_mehr1 ) ), implode( '/', CRDR_Jalali::ymd( $ts_mehr1 - 1 ) ) ), array( '1405/7/1', '1405/6/31' ) );

/* ============================================================================== 8. campaign comparison */
crdr_t_section( 'campaign measurement (SQL is recorded, never executed: these tests read the statement TEXT)' );
CRDR_T::reset();
$db = $GLOBALS['wpdb'];
$db->on( 't.grp', array(
	array( 'grp' => 'treated', 'n' => 700, 'conv' => 84, 'rev' => 8400000 ),
	array( 'grp' => 'holdout', 'n' => 200, 'conv' => 20, 'rev' => 2000000 ),
) );
$res = CRDR_Playbooks::results( 'second_purchase' );
check( 'results() turns the group tallies into rates and a difference (12% vs 10%)', array( $res['treated']['n'], $res['treated']['conv'], $res['holdout']['n'], round( $res['test']['diff'], 6 ) ), array( 700, 84, 200, 0.02 ) );
$group_sql = '';
foreach ( $db->log as $q ) {
	if ( false !== strpos( $q, 't.grp' ) ) {
		$group_sql = $q;
	}
}
// PHASE 4.2 (CAMP-1 / CAMP-2 approved): the groups are CUSTOMERS at their FIRST assignment, with every assignment status, not the rows with status ok/holdout.
// The pin that read the old statement is replaced by the two text checks below; the real behaviour is tested on a real database (tests/release-safety.php, section J).
check( '[SQL text] the compared groups are built from each customer\'s FIRST assigned row (MIN(id) per customer), over every assignment status', array( false !== strpos( $group_sql, 'MIN(id)' ), false !== strpos( $group_sql, 'GROUP BY ckey' ), false !== strpos( $group_sql, "'failed'" ), false !== strpos( $group_sql, "'rejected'" ), false !== strpos( $group_sql, "'unknown'" ), false === strpos( $group_sql, "t.status IN ('ok','holdout')" ) ), array( true, true, true, true, true, true ) );
// the audit's example: 1000 selected -> 200 holdout (20 convert), 800 assigned (100 rejected numbers: 2 convert; 700 accepted: 84 convert)
$plugin_view = CRDR_Stats::two_prop( 84, 700, 20, 200 );
$itt         = CRDR_Stats::two_prop( 84 + 2, 800, 20, 200 );
check( 'arithmetic of the audit example: the plugin compares 12.0% with 10.0% = +2.00 points', round( $plugin_view['diff'], 4 ), 0.02 );
check( 'arithmetic of the audit example: by assignment (all 800) the difference is 10.75% - 10.0% = +0.75 points', round( $itt['diff'], 4 ), 0.0075 );
check( 'CAMP-1 (was DECISION, approved in Phase 4.2): the treated group is every customer ASSIGNED to the message (intention to treat), not only those the SMS service accepted [SQL text; behaviour: release-safety J]', false === strpos( $group_sql, "t.status IN ('ok','holdout')" ) && false !== strpos( $group_sql, "'nobuild'" ), true );
check( 'CAMP-2 (was DECISION, approved in Phase 4.2): the unit of comparison is the customer, not the contact row (a customer contacted twice counts once) [SQL text; behaviour: release-safety J]', false === strpos( $group_sql, 'GROUP BY t.grp' ) && false !== strpos( $group_sql, 'GROUP BY ckey' ) && false !== strpos( $group_sql, 'GROUP BY t.id, t.grp, m.acc' ), true );
// holdout assignment
$hold = 0;
for ( $i = 0; $i < 10000; $i++ ) {
	$hold += CRDR_Playbooks::is_holdout( 'p:0912' . sprintf( '%07d', $i ), 'second_purchase', 20 ) ? 1 : 0;
}
check( 'holdout assignment: about 20% of 10,000 keys at 20% (within 2 points)', abs( $hold / 10000 - 0.20 ) < 0.02, true );
check( 'holdout assignment is deterministic for a customer within the month', CRDR_Playbooks::is_holdout( 'p:09120000001', 'x', 20 ) === CRDR_Playbooks::is_holdout( 'p:09120000001', 'x', 20 ), true );
check( 'holdout 0% means nobody is held out', CRDR_Playbooks::is_holdout( 'p:09120000001', 'x', 0 ), false );

/* ========================================================================================= 9. rebuild */
crdr_t_section( 'rebuild safety (STATIC: reads the source text; a behavioural proof needs a database)' );
$sync_src = CRDR_T::source( 'class-crdr-sync.php' );
// PHASE 3: the TRUNCATE moved from the body of start() into start_locked() (start() takes the lock, then calls it). The pin is
// unchanged (same classification, same expected value); the text searched is now start() plus start_locked(). It used to be start() alone.
preg_match( '/public static function start\(\).*?^\t}\n/ms', $sync_src, $m );
preg_match( '/private static function start_locked\(\).*?^\t}\n/ms', $sync_src, $m2 );
char( '[static] CRDR_Sync::start() empties the customer, order and item tables before the rebuild', 1 === preg_match( '/TRUNCATE TABLE/', ( isset( $m[0] ) ? $m[0] : '' ) . ( isset( $m2[0] ) ? $m2[0] : '' ) ), true );
$consumers = array( 'class-crdr-playbooks.php', 'class-crdr-audience.php', 'class-crdr-actions.php', 'class-crdr-page-customers.php', 'class-crdr-send.php' );
$guarded   = array();
foreach ( $consumers as $f ) {
	// PHASE 4.1: the paths that SEND call CRDR_Sync::send_guard(), which is guard() plus the 'update waits for its first rebuild' check
	// (CRDR_Sync::send_guard returns guard()'s answer first). The pin is unchanged: the same files must ask the guard, under either name.
	$src_f = CRDR_T::source( $f );
	if ( false !== strpos( $src_f, 'CRDR_Sync::guard' ) || false !== strpos( $src_f, 'CRDR_Sync::send_guard' ) ) { // PHASE 2: the text searched for was 'CRDR_Sync::state' (see the note below)
		$guarded[] = $f;
	}
}
/*
 * PHASE 2 RECLASSIFICATION (sync guard), two tests, reported in the Phase 2 report:
 *   - The pin "[static] no campaign, audience, call-list or one-off-SMS code checks whether a rebuild is in progress" (CHAR, value
 *     array()) described the code before the guard existed. It is now false, so it was replaced by the PASS below, which records
 *     which files DO check it. class-crdr-audience.php stays unguarded on purpose: it only builds queries; every function that runs
 *     them for a campaign, a list or an SMS is guarded (proved per function in tests/sync-guard.php).
 *   - REBUILD-1 (XFAIL / DEFECT) is promoted to a PASS: the defect it asserted is fixed. It was NOT left as an XFAIL, which would now
 *     have been a false statement. Its behavioural proof lives in tests/sync-guard.php and in tests/phase1-safety.php (real database).
 */
check( '[static] the campaign, call-list, export and one-off-SMS code asks the sync guard (the audience class only builds queries)', $guarded, array( 'class-crdr-playbooks.php', 'class-crdr-actions.php', 'class-crdr-page-customers.php', 'class-crdr-send.php' ) );
check( 'REBUILD-1 (was XFAIL, fixed in Phase 2): campaign runs and the call list refuse to use the customer table while the sync phase is not idle or done', in_array( 'class-crdr-playbooks.php', $guarded, true ) && in_array( 'class-crdr-page-customers.php', $guarded, true ) && in_array( 'class-crdr-send.php', $guarded, true ), true );

/* =================================================================================== 10. insights */
crdr_t_section( 'insight wording (stats are the real build() output plus the injected values noted)' );
$ins = function ( array $inject = array(), $one_time = 3 ) use ( $IMP, $DS ) {
	CRDR_T::reset();
	$imp = CRDR_T::import( $DS );
	CRDR_T::build( $imp );
	$st = get_option( 'crdr_stats' );
	foreach ( $inject as $k => $v ) {
		$st[ $k ] = $v;
	}
	update_option( 'crdr_stats', $st );
	$db = $GLOBALS['wpdb'];
	$db->on( 'WHERE orders_count = 1', $one_time );
	$db->on( "WHERE phone = ''", 0 );
	$db->on( 'SUM(total_spent) spent', array( 'n' => 5, 'spent' => 2000000, 'orders' => 5 ) );
	$cards = array();
	foreach ( CRDR_Insights::generate() as $c ) {
		$cards[ $c['id'] ] = $c;
	}
	return $cards;
};
$cards = $ins();
check( 'insight cards are generated from the reference stats', isset( $cards['second'], $cards['pareto'] ), true );
// PHASE 4.1 (analytics defect fix): the pin that recorded 'most customers bought once' for a 30% share is replaced by INS-1.
check( 'INS-1 (was DEFECT, fixed in Phase 4.1): the "most customers bought only once" title is shown only when the one-time share is above 50% (3 one-time buyers among the build\'s customers = under half: the title gives the number)', array( false === mb_strpos( $cards['second']['title'], 'بیشتر مشتریانتان' ), 0 === mb_strpos( $cards['second']['title'], CRDR_Jalali::number( 100 * 3 / get_option( 'crdr_stats' )['customers_total'] ) . '٪' ) ), array( true, true ) );
// PHASE 3A: 9 customers now (10 before), so the top 1 is 11.1%, not exactly 10%: the title still says "less than 10%" (same wording defect).
// PHASE 4.1 (analytics defect fix): the pin that recorded the title 'less than 10% of customers' is replaced by INS-2; the top-group size is still pinned (1 of 9 = 11.1%).
char( 'card "pareto": the top group is 1 of 8 customers (12.5%), never under 10%  [PHASE 4.2: 8 customers, REV-3; was 1 of 9]', $STATS['pareto']['top10_count'] / $STATS['customers_total'], 1 / 8 );
$never_below = true;
for ( $n = 1; $n <= 500; $n++ ) {
	if ( max( 1, (int) ceil( $n * 0.10 ) ) / $n < 0.10 ) {
		$never_below = false;
	}
}
check( 'math: the "top 10%" group (rounded up) is never strictly smaller than 10% of the customers, for any n up to 500', $never_below, true );
check( 'INS-2 (was DEFECT, fixed in Phase 4.1): the Pareto title does not say "less than 10% of customers" for a group that is 10% or more', false === mb_strpos( $cards['pareto']['title'], 'کمتر از ۱۰٪' ), true );
$cards2 = $ins( array(
	'pairs'     => array( array( 'a' => 1, 'b' => 2, 'support' => 3, 'conf_ab' => 0.5, 'conf_ba' => 0.4, 'lift' => 3.3 ) ),
	'threshold' => array( 'threshold' => 1000000, 'nudge_orders' => 5, 'nudge_share' => 0.25, 'median' => 800000 ),
	'gap_hist'  => array( 'edges' => array( 7 ), 'counts' => array( 20, 20 ), 'n' => 40 ),
	'gap_median' => 30.0,
) );
// PHASE 4.1 (analytics defect fix): the pin that recorded 'people who buy A' (the figure is a share of ORDERS) is replaced by INS-3.
check( 'INS-3 (was DEFECT, fixed in Phase 4.1): the cross-sell sentence says "of orders that contain A", not "of people who buy A"', array( false === mb_strpos( $cards2['cross']['body'], 'نفری که' ), false !== mb_strpos( $cards2['cross']['body'], 'سفارش' ) ), array( true, true ) );
// PHASE 4.1 (analytics defect fix): the pin that recorded 'exactly 25% below' is replaced by INS-4.
check( 'INS-4 (was DEFECT, fixed in Phase 4.1): the threshold sentence says "up to 25% below", not "exactly 25% below"', array( false === mb_strpos( $cards2['threshold']['body'], 'دقیقاً ۲۵٪' ), false !== mb_strpos( $cards2['threshold']['body'], 'تا ۲۵٪' ) ), array( true, true ) );
$GLOBALS['CRDR_T']['currency'] = 'ریال';
$cards3 = $ins( array( 'threshold' => array( 'threshold' => 1000000, 'nudge_orders' => 5, 'nudge_share' => 0.25, 'median' => 800000 ) ) );
unset( $GLOBALS['CRDR_T']['currency'] );
$hard = false !== mb_strpos( implode( ' ', $cards3['threshold']['steps'] ), 'تومان' );
// PHASE 4.1 (analytics defect fix): the pin that recorded the hard-coded word is replaced by INS-5, which also checks that the store's own symbol is used.
check( 'INS-5 (was DEFECT, fixed in Phase 4.1): suggested sentences use the store currency, not a hard-coded "تومان"', array( ! $hard, false !== mb_strpos( implode( ' ', $cards3['threshold']['steps'] ), 'ریال' ) ), array( true, true ) );
// PHASE 4.2 (INS-6 approved): the pin that recorded the unsupported claim ("a reminder before the median is ineffective") is replaced.
check( 'INS-6 (was DECISION, approved in Phase 4.2): the timing card describes the observed timing, does not claim an effect, and points to a controlled campaign', array( false === mb_strpos( $cards2['timing']['body'], 'بی‌اثر' ), false !== mb_strpos( $cards2['timing']['body'], 'الگوی دیده‌شده' ), false !== mb_strpos( $cards2['timing']['body'], 'گروه شاهد' ) ), array( true, true, true ) );

/* =================================================================== Phase 4.1: edges of the analytics fixes */
crdr_t_section( 'analytics fixes (Phase 4.1): edges' );
// repurchase curve: customers who ordered once and have not come back
check( 'curve: no open intervals -> same as before (3 of 4 gaps longer than 10 days, 1 reorders within 30 more days)', CRDR_Stats::reorder_prob( array( 5.0, 20.0, 50.0, 90.0 ), 10, 30 ), array( 1 / 3, 3 ) );
check( 'curve: a customer who has been waiting 40 days is a non-return at d = 0 (window 30)', CRDR_Stats::reorder_prob( array(), 0, 30, array( 40.0 ) ), array( 0.0, 1 ) );
check( 'curve: ... but one who ordered 29 days ago is NOT counted yet (the window is not over: unknown, not a miss)', CRDR_Stats::reorder_prob( array(), 0, 30, array( 29.0 ) ), array( null, 0 ) );
check( 'curve: boundary: exactly d + window days of observation is enough', CRDR_Stats::reorder_prob( array(), 0, 30, array( 30.0 ) ), array( 0.0, 1 ) );
check( 'curve: an open interval shorter than d is not at risk at d', CRDR_Stats::reorder_prob( array(), 60, 30, array( 50.0, 100.0 ) ), array( 0.0, 1 ) );
check( 'curve: returners and non-returners together (1 hit among 1 gap + 3 old non-returners = 25%)', CRDR_Stats::reorder_prob( array( 20.0 ), 0, 30, array( 100.0, 200.0, 300.0 ) ), array( 0.25, 4 ) );
check( 'curve: survival_table passes the open intervals through', CRDR_Stats::survival_table( array( 20.0 ), 30, 30, 30, array( 100.0 ) )[0], array( 'd' => 0, 'p' => 0.5, 'n' => 2 ) );
// lift
$bk = array( array( 1, 2 ), array( 1, 2 ), array( 1 ), array( 2 ) );
$a  = CRDR_Stats::affinity( $bk, 2 );
$b  = CRDR_Stats::affinity( $bk, 2, 12, 40 );
check( 'lift: without the store total it is computed over the baskets given (4): (2/4) / ((3/4) x (3/4)) = 8/9', round( $a['pairs'][0]['lift'], 6 ), round( 8 / 9, 6 ) );
check( 'lift: with 40 orders in all it is (2/40) / ((3/40) x (3/40)) = 40 x 2 / 9', round( $b['pairs'][0]['lift'], 6 ), round( 80 / 9, 6 ) );
check( 'lift: a store total SMALLER than the baskets cannot make the lift larger than possible (the baskets win)', round( CRDR_Stats::affinity( $bk, 2, 12, 2 )['pairs'][0]['lift'], 6 ), round( 8 / 9, 6 ) );
check( 'lift: support and confidence are not affected by the total', array( $b['pairs'][0]['support'], $b['pairs'][0]['conf_ab'] ), array( 2, 2 / 3 ) );
// insight title: "most" only when it is true
$many = $ins( array(), 9 );
check( 'insight "second": with most customers (9 of 11) bought once the title may say "most"', 0 === mb_strpos( $many['second']['title'], 'بیشتر مشتریانتان' ), true );
$half = $ins( array(), 0 );
check( 'insight "second": no one-time buyers -> no card', isset( $half['second'] ), false );

/* =================================================================== Phase 4.2: RFM guard, censoring edges */
crdr_t_section( 'analytics definitions (Phase 4.2): RFM guard, censoring' );
$mk = function ( array $days_old, $orders = 1 ) use ( $ASOF ) {
	$c = array();
	foreach ( $days_old as $i => $d ) {
		$last          = $ASOF - (int) round( $d * 86400 );
		$c[ "g$i" ]    = array( 'ckey' => "g$i", 'orders_count' => $orders, 'total_spent' => 100000.0 * ( 1 + $i ), 'first_ts' => $orders > 1 ? $last - 60 * 86400 : $last, 'last_ts' => $last );
	}
	return $c;
};
// an ordinary store: nobody is past 365 days, so the guard changes nothing
$ord = CRDR_T::rfm( $mk( array( 3, 10, 20, 40, 80, 120, 200, 300, 360 ), 5 ), $ASOF ); // (5 orders: frequency score 4, so the recent ones are champions)
check( 'RFM guard: in an ordinary store (everybody within lost_days) the ranks are the relative ones (R 5..1 spread, champions exist)', array( max( array_column( $ord, 'r' ) ), min( array_column( $ord, 'r' ) ), in_array( 'champion', array_column( $ord, 'segment' ), true ) ), array( 5, 1, true ) );
// a stale store, repeat customers: nobody can be champion / loyal / potential / new
$stale_rep = CRDR_T::rfm( $mk( array( 400, 420, 450, 500, 600, 700, 800, 900 ), 5 ), $ASOF );
check( 'RFM guard: eight 5-order customers all last seen 400-900 days ago: none is champion, loyal, potential or new, and no R is above 2', array( array_values( array_intersect( array_column( $stale_rep, 'segment' ), array( 'champion', 'loyal', 'potential', 'new' ) ) ), max( array_column( $stale_rep, 'r' ) ) <= 2 ), array( array(), true ) );
check( 'RFM guard: ... they are cant_lose / at_risk (frequent customers who went away), not "lost" (that word is for single-order customers)', array_unique( array_column( $stale_rep, 'segment' ) ) === array_values( array_unique( array_column( $stale_rep, 'segment' ) ) ) && ! in_array( 'lost', array_column( $stale_rep, 'segment' ), true ), true );
// the boundary: exactly lost_days is not stale, one hour more is
$edge_a = CRDR_RFM::segment( 5, 5, 5, 6, 365.0, array( 'lost_days' => 365 ) );
$edge_b = CRDR_RFM::segment( 5, 5, 5, 6, 365.05, array( 'lost_days' => 365 ) );
check( 'RFM guard boundary: exactly 365 days is still a champion, 365.05 days is not', array( $edge_a, $edge_b ), array( 'champion', 'cant_lose' ) );
check( 'RFM guard: the setting lost_days is what counts (100 days)', array( CRDR_RFM::segment( 5, 5, 5, 6, 99.0, array( 'lost_days' => 100 ) ), CRDR_RFM::segment( 5, 5, 5, 6, 101.0, array( 'lost_days' => 100 ) ) ), array( 'champion', 'cant_lose' ) );
$one = CRDR_RFM::score_all( array( array( 'id' => 1, 'orders_count' => 1, 'total_spent' => 1000, 'first_ts' => $ASOF - 500 * 86400, 'last_ts' => $ASOF - 500 * 86400 ) ), $ASOF, array( 'lost_days' => 365 ) );
check( 'RFM guard: a store whose only customer is 500 days away (relative R would be neutral 3): R is capped, the label is "lost"', array( $one[1]['r'] <= 2, $one[1]['segment'] ), array( true, 'lost' ) );
check( 'RFM guard: a brand-new customer is still "new"', CRDR_RFM::segment( 5, 1, 1, 1, 3.0, array() ), 'new' );

// censoring: a single-order customer is a non-return for N only if watched for N days
$cx = function ( array $ages ) use ( $ASOF ) {
	$o = array();
	$c = array();
	foreach ( $ages as $i => $age ) {
		$ts = time() - (int) round( $age * 86400 );
		$o[] = array( 'id' => $i + 1, 'ckey' => "x$i", 'ts' => $ts, 'net' => 1000 );
		$c[ "x$i" ] = array( 'ckey' => "x$i", 'orders_count' => 1, 'total_spent' => 1000.0, 'first_ts' => $ts, 'last_ts' => $ts );
	}
	$b = CRDR_T::build( array( 'orders' => $o, 'items' => array(), 'customers' => $c ) );
	$r = array();
	foreach ( $b['return_within'] as $x ) {
		$r[ $x['days'] ] = array( $x['hit'], $x['n'] );
	}
	return array( $r, $b['repeat']['mature_customers'], $b['repeat']['customers_pct_mature'] );
};
list( $r, $mat, $pm ) = $cx( array( 5, 29, 31, 61, 91, 200 ) );
check( 'censoring: six customers who never came back, aged 5/29/31/61/91/200 days: 30-day n = 4 (31, 61, 91, 200), 60-day n = 3, 90-day n = 2; no hits', $r, array( 30 => array( 0, 4 ), 60 => array( 0, 3 ), 90 => array( 0, 2 ) ) );
check( 'tenure: the customers older than 90 days are 2 (91 and 200), none repeated -> mature rate 0 of 2', array( $mat, $pm ), array( 2, 0.0 ) );
list( $r, $mat, $pm ) = $cx( array( 2, 3 ) );
check( 'censoring: everybody younger than 30 days: no N is answerable (n = 0, p = null), and no mature customers (rate null)', array( $r[30], $r[90], $mat, $pm ), array( array( 0, 0 ), array( 0, 0 ), 0, null ) );
list( $r ) = $cx( array() );
check( 'censoring: an empty shop gives empty proportions without errors', $r, array( 30 => array( 0, 0 ), 60 => array( 0, 0 ), 90 => array( 0, 0 ) ) );

// ---- REV-2: refunds applied to lines (CRDR_Sales::allocate), against worked answers computed independently (fixtures/refund-scenarios.json) -------
crdr_t_section( 'refund allocation (Phase 4.2, REV-2): line-level first, then proportional; unreconciled amounts reported' );
$RS = CRDR_T::fixture( 'refund-scenarios.json' );
check( 'refund scenarios: the fixture has its 16 scenarios', count( $RS['scenarios'] ), 16 );
foreach ( $RS['scenarios'] as $i => $sc ) {
	$r = CRDR_Sales::allocate( $sc['lines'], $sc['refund_total'], $sc['refund_shipping'], $sc['refund_tax'], $sc['order_total'] );
	$e = $sc['expect'];
	check( 'refund scenario ' . ( $i + 1 ) . ' (' . $sc['name'] . '): lines, net, void', array( array_map( function ( $x ) {
		return round( $x, 2 );
	}, array_values( $r['lines'] ) ), round( $r['net'], 2 ), $r['void'] ), array( $e['lines'], round( $e['net'], 2 ), $e['void'] ) );
	$got_reasons = array_map( function ( $x ) {
		return round( $x, 2 );
	}, $r['reasons'] );
	ksort( $got_reasons );
	$want_reasons = $e['reasons'];
	ksort( $want_reasons );
	check( 'refund scenario ' . ( $i + 1 ) . ': unreconciled amount and its reasons', array( round( $r['gap'], 2 ), $got_reasons ), array( round( $e['gap'], 2 ), $want_reasons ) );
	check( 'refund scenario ' . ( $i + 1 ) . ': the lines add up to the net, and no line is negative or above its value', array( abs( array_sum( $r['lines'] ) - $r['net'] ) < 1e-6, min( array_merge( array( 0 ), $r['lines'] ) ) >= 0, array_reduce( array_keys( $r['lines'] ), function ( $ok, $k ) use ( $r, $sc ) {
		return $ok && $r['lines'][ $k ] <= $sc['lines'][ $k ]['total'] + 1e-9;
	}, true ) ), array( true, true, true ) );
}
// a refund never changes the order of the lines or loses one, and the result does not depend on the order the lines are listed in (up to which line takes an odd cent)
$a = CRDR_Sales::allocate( array( 'a' => array( 'total' => 100, 'refund' => 0 ), 'b' => array( 'total' => 100, 'refund' => 0 ), 'c' => array( 'total' => 100, 'refund' => 0 ) ), 100, 0, 0, 300 );
check( 'refund allocation: the keys of the lines are kept, and the total is exact to the cent', array( array_keys( $a['lines'] ), round( $a['net'], 2 ) ), array( array( 'a', 'b', 'c' ), 200.0 ) );
// RELEASE FREEZE (D1): a refund recorded against a FEE item is not a refund of product sales (a fee is not revenue)
$f0 = CRDR_Sales::allocate( array( 'a' => array( 'total' => 100000, 'refund' => 0 ) ), 5000, 0, 0, 105000 );
check( 'fee refund, old call shape (no fee amount known): the 5,000 still lands on the product (95,000) - this is why the importer must pass the fee refund', array( $f0['net'], $f0['gap'] ), array( 95000.0, 0.0 ) );
$f1 = CRDR_Sales::allocate( array( 'a' => array( 'total' => 100000, 'refund' => 0 ) ), 5000, 0, 0, 105000, 5000 );
check( 'fee refund: a refund of the COD fee alone leaves product net sales at 100,000 and nothing unreconciled', array( $f1['net'], $f1['gap'], $f1['void'] ), array( 100000.0, 0.0, false ) );
$f2 = CRDR_Sales::allocate( array( 'a' => array( 'total' => 100000, 'refund' => 0 ) ), 5500, 0, 500, 105500, 5000 );
check( 'fee refund with the tax on the fee (5,000 + 500): product net sales stay 100,000', array( $f2['net'], $f2['gap'] ), array( 100000.0, 0.0 ) );
$f3 = CRDR_Sales::allocate( array( 'a' => array( 'total' => 100000, 'refund' => 20000 ) ), 25000, 0, 0, 105000, 5000 );
check( 'fee refund together with a recorded line refund: only the line refund (20,000) reduces product net sales', array( $f3['net'], $f3['gap'] ), array( 80000.0, 0.0 ) );
$f4 = CRDR_Sales::allocate( array( 'a' => array( 'total' => 100000, 'refund' => 0 ) ), 10000, 0, 0, 105000, 5000 );
check( 'fee refund plus an amount-only refund of 5,000: only the amount-only part reduces product net sales', array( $f4['net'], $f4['gap'] ), array( 95000.0, 0.0 ) );
$f5 = CRDR_Sales::allocate( array( 'a' => array( 'total' => 100000, 'refund' => 0 ) ), 5000, 0, 0, 105000, 9000 );
check( 'fee refund larger than the total refunded (inconsistent data): reported as unreconciled, product net sales untouched', array( $f5['net'], $f5['reasons']['components_exceed_total'] ?? null ), array( 100000.0, 4000.0 ) );
// big amounts (no integer overflow, exact cents)
$big = CRDR_Sales::allocate( array( 1 => array( 'total' => 40000000000, 'refund' => 0 ), 2 => array( 'total' => 60000000000, 'refund' => 0 ) ), 25000000001, 0, 0, 100000000000 );
check( 'refund allocation: amounts of tens of billions (cents beyond 32 bits, products beyond 64 bits) still add up exactly', array( round( $big['net'], 2 ), round( $big['lines'][1] + $big['lines'][2], 2 ), $big['gap'] ), array( 74999999999.0, 74999999999.0, 0.0 ) );
// the importer: the plugin reads this from a WooCommerce order (line ids, recorded refunds, shipping and tax refunds)
$od = array( 'id' => 900, 'status' => 'completed', 'ts' => 1790000000, 'uid' => 0, 'phone' => '09120000900', 'email' => 'r@example.test', 'items' => array( array( 11, 100000, 30000 ), array( 12, 200000 ) ), 'shipping' => 50000, 'tax' => 27000, 'total' => 377000, 'refunded' => 60000, 'shipping_refunded' => 0, 'tax_refunded' => 0 );
$x  = CRDR_Sync::extract( new CRDR_T_Order( $od ) );
check( 'extract(): reads the recorded line refund (30,000 on product 11) and spreads the other 30,000 over what is left (70,000 and 200,000)', array( $x['net'], $x['void'], $x['gap'], array_map( function ( $l ) {
	return array( $l[0], round( $l[2], 2 ) );
}, $x['items'] ) ), array( 240000.0, false, 0.0, array( array( 11, 62222.22 ), array( 12, 177777.78 ) ) ) );
$od2 = array_merge( $od, array( 'refunded' => 30000, 'items' => array( array( 11, 100000, 30000 ), array( 12, 200000 ) ) ) );
$x2  = CRDR_Sync::extract( new CRDR_T_Order( $od2 ) );
check( 'extract(): only the recorded line refund and nothing else to spread: product 12 is untouched', array( $x2['net'], round( $x2['items'][0][2], 2 ), round( $x2['items'][1][2], 2 ) ), array( 270000.0, 70000.0, 200000.0 ) );
$od3 = array_merge( $od, array( 'refunded' => 377000, 'items' => array( array( 11, 100000 ), array( 12, 200000 ) ) ) );
$x3  = CRDR_Sync::extract( new CRDR_T_Order( $od3 ) );
check( 'extract(): a full refund of the whole order (items + shipping + tax) leaves net 0 and the order is void, with nothing unreconciled', array( $x3['net'], $x3['void'], $x3['gap'] ), array( 0.0, true, 0.0 ) );

/* =================================================================================== summary */
$x = count( $R['XFAIL'] );
echo "\n----------------------------------------------------------------------------------------------\n";
printf( "PASS %d   CHAR %d   XFAIL %d (known defects / pending decisions)   XPASS %d   FAIL %d\n", $R['PASS'], $R['CHAR'], $x, count( $R['XPASS'] ), $R['FAIL'] );
echo "\nKnown defects and pending decisions asserted as EXPECTED FAILURES:\n";
foreach ( $R['XFAIL'] as $f ) {
	printf( "  %-10s %-8s %s\n             today: %s\n", $f[0], $f[1], $f[2], $f[3] );
}
exit( ( $R['FAIL'] || $R['XPASS'] ) ? 1 : 0 );
