<?php
/**
 * Customer identity tests: who counts as the same customer (CRDR_Identity, CRDR_Sync::extract).
 *
 *   php tests/identity.php
 *
 * No WordPress, no database, no network. The rules are the ones written at the top of includes/class-crdr-identity.php. The test
 * names say what is deterministic (joined), what is ambiguous (deliberately NOT joined) and what cannot be told from order data.
 * The SQL that applies the result (re-keying orders and contact history) is tested on a real database in tests/phase1-safety.php,
 * section "customer identity".
 */
require __DIR__ . '/analytics/harness.php';

$GLOBALS['CRDR_R'] = array( 'pass' => 0, 'fail' => 0 );
function check( $name, $got, $want ) {
	if ( $got === $want ) {
		$GLOBALS['CRDR_R']['pass']++;
		return;
	}
	$GLOBALS['CRDR_R']['fail']++;
	echo "FAIL  $name\n      got:  " . json_encode( $got, JSON_UNESCAPED_UNICODE ) . "\n      want: " . json_encode( $want, JSON_UNESCAPED_UNICODE ) . "\n";
}
function section( $t ) {
	echo "== $t\n";
}

/** An order as the dataset format has it. */
function o( $id, $uid, $phone, $email, $day = 0, $total = 100000 ) {
	return array( 'id' => $id, 'status' => 'completed', 'ts' => 1790000000 + $day * 86400, 'uid' => $uid, 'phone' => $phone, 'email' => $email,
		'items' => array( array( 1, $total ) ), 'shipping' => 0, 'tax' => 0, 'total' => $total, 'refunded' => 0 );
}
/** Run the whole identity pipeline on these orders. @return array order id => final customer key (plus the report under 'report') */
function keys_of( array $orders, &$report = null ) {
	CRDR_T::reset();
	$imp    = CRDR_T::import( array( 'orders' => $orders ), array( 'completed' ), true );
	$report = $imp['identity'];
	$out    = array();
	foreach ( $imp['extract'] as $id => $e ) {
		$out[ $id ] = $e['ckey'];
	}
	return $out;
}
function provisional( array $orders ) {
	CRDR_T::reset();
	$imp = CRDR_T::import( array( 'orders' => $orders ), array( 'completed' ) );
	$out = array();
	foreach ( $imp['extract'] as $id => $e ) {
		$out[ $id ] = $e['ckey'];
	}
	return $out;
}
function same( array $keys, array $ids ) { // are all these orders one customer?
	$k = array();
	foreach ( $ids as $i ) {
		$k[ $keys[ $i ] ] = true;
	}
	return 1 === count( $k );
}
function distinct( array $keys ) {
	return count( array_unique( $keys ) );
}

/* ================================================================================ 1. what an order says about who bought */
section( 'identifiers: usable phone, usable email, placeholders' );
check( 'phone formats are one number', array( CRDR_Identity::phone( '0912 000 0001' ), CRDR_Identity::phone( '+98 912 000 0001' ), CRDR_Identity::phone( '989120000001' ), CRDR_Identity::phone( '9120000001' ), CRDR_Identity::phone( '۰۹۱۲۰۰۰۰۰۰۱' ) ), array_fill( 0, 5, '09120000001' ) );
check( 'not a mobile number: empty, letters, too short, all zeros (the number WordPress writes when it erases), a landline', array( CRDR_Identity::phone( '' ), CRDR_Identity::phone( 'abc' ), CRDR_Identity::phone( '12345' ), CRDR_Identity::phone( '00000000000' ), CRDR_Identity::phone( '02188776655' ) ), array( '', '', '', '', '' ) );
check( 'the plugin\'s old normalize_phone() is the same function', CRDR_Sync::normalize_phone( '0912-345-6789' ), '09123456789' );
check( 'email: lower-cased and trimmed', CRDR_Identity::email( "  G2@Example.TEST \n" ), 'g2@example.test' );
check( 'email: malformed addresses are no email', array( CRDR_Identity::email( 'not-an-email' ), CRDR_Identity::email( 'a@@b.com' ), CRDR_Identity::email( '@x.com' ), CRDR_Identity::email( 'a b@x.com' ), CRDR_Identity::email( '' ) ), array( '', '', '', '', '' ) );
check( 'email: the address WordPress and WooCommerce write when they erase a person is a placeholder', array( CRDR_Identity::is_placeholder_email( 'deleted@site.invalid' ), CRDR_Identity::is_placeholder_email( 'DELETED@Site.Invalid' ), CRDR_Identity::email( 'deleted@site.invalid' ) ), array( true, true, '' ) );
check( 'email: anything under the reserved .invalid domain is a placeholder (it can never be a real mailbox)', array( CRDR_Identity::email( 'someone@anonymous.invalid' ), CRDR_Identity::email( 'someone@a.b.invalid' ), CRDR_Identity::email( 'x@invalid' ) ), array( '', '', '' ) );
check( 'email: real look-alikes are NOT placeholders', array( CRDR_Identity::email( 'invalid@example.com' ), CRDR_Identity::email( 'deleted@example.com' ), CRDR_Identity::email( 'a@invalidstore.ir' ) ), array( 'invalid@example.com', 'deleted@example.com', 'a@invalidstore.ir' ) );
check( 'provisional key precedence: phone, then email, then user id, then the order itself', array(
	CRDR_Identity::provisional_key( '09120000001', 'a@b.com', 5, 9 ), CRDR_Identity::provisional_key( '', 'a@b.com', 5, 9 ), CRDR_Identity::provisional_key( '', '', 5, 9 ), CRDR_Identity::provisional_key( '', '', 0, 9 ),
), array( 'p:09120000001', 'e:a@b.com', 'u:5', 'o:9' ) );
$p = provisional( array( o( 1, 0, '', 'deleted@site.invalid' ), o( 2, 0, '00000000000', 'deleted@site.invalid' ), o( 3, 0, 'abc', 'not-an-email' ), o( 4, 7, '', 'deleted@site.invalid' ) ) );
check( 'orders: a placeholder email and a zero phone leave NO identity (own key); a registered user keeps the account', array( $p[1], $p[2], $p[3], $p[4] ), array( 'o:1', 'o:2', 'o:3', 'u:7' ) );
$r = CRDR_Sync::extract( new CRDR_T_Order( o( 5, 0, '', 'deleted@site.invalid' ) ) );
check( 'extract(): the placeholder is not shown as the customer\'s email and is not stored as evidence', array( $r['email'], $r['ident_email'] ), array( '', '' ) );
$r = CRDR_Sync::extract( new CRDR_T_Order( o( 6, 0, '', 'not-an-email' ) ) );
check( 'extract(): a malformed email is still shown as typed (unchanged) but is never evidence', array( $r['email'], $r['ident_email'] ), array( 'not-an-email', '' ) );

/* ======================================================================================== 2. changed phone numbers */
section( 'a person whose phone changes' );
$k = keys_of( array( o( 1, 0, '0912 345 6789', 'g2@example.test', 0 ), o( 2, 0, '', 'G2@Example.test', 30 ), o( 3, 0, '09351234567', 'g2@example.test', 60 ) ) );
check( 'phone A -> email only (other case) -> phone B, same email: one person', same( $k, array( 1, 2, 3 ) ), true );
check( '... who keeps the LATEST phone as the key (the number to send to)', $k[1], 'p:09351234567' );
$k = keys_of( array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09350000002', 'b@y.com', 30 ) ) );
check( 'phone AND email both different, no account: two customers (no evidence they are one person)', distinct( $k ), 2 );
$k = keys_of( array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09120000001', 'b@y.com', 30 ) ) );
check( 'same phone, email changed: one customer (the phone is the recipient)', distinct( $k ), 1 );
$k = keys_of( array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09120000002', 'a@x.com', 30 ), o( 3, 0, '09120000003', 'a@x.com', 60 ) ), $rep );
check( 'one email used with THREE phones is a shared mailbox, not a person: nobody is joined', array( distinct( $k ), $rep['emails_shared'], $rep['joined'] ), array( 3, 1, 0 ) );
$k = keys_of( array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09120000002', 'a@x.com', 30 ) ), $rep );
check( 'one email with TWO phones is treated as one person who changed number (documented, probable not certain)', array( distinct( $k ), $rep['joined'], $rep['people'] ), array( 1, 1, 1 ) );
$k = keys_of( array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09120000002', 'a@x.com', 30 ), o( 3, 0, '', 'a@x.com', 60 ) ) );
check( '... and an email-only order with that email joins them too', distinct( $k ), 1 );
check( 'a shared mailbox keeps its email-only orders together but apart from every phone customer', ( function () {
	$k = keys_of( array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09120000002', 'a@x.com', 1 ), o( 3, 0, '09120000003', 'a@x.com', 2 ), o( 4, 0, '', 'a@x.com', 3 ), o( 5, 0, '', 'a@x.com', 4 ) ) );
	return array( distinct( $k ), $k[4] === $k[5], $k[4] );
} )(), array( 4, true, 'e:a@x.com' ) );

/* ======================================================================================= 3. email-only and mixed case */
section( 'email-only orders and mixed-case emails' );
$k = keys_of( array( o( 1, 0, '', 'Ali@Shop.IR', 0 ), o( 2, 0, '', ' ali@shop.ir ', 5 ) ) );
check( 'email-only orders with the same address in different case / spacing are one customer', array( distinct( $k ), $k[1] ), array( 1, 'e:ali@shop.ir' ) );
$k = keys_of( array( o( 1, 0, '', 'ali@shop.ir', 0 ), o( 2, 0, '09123334444', 'ALI@shop.ir', 5 ) ) );
check( 'an email-only order joins the phone customer who used that email; the key is the phone', array( distinct( $k ), $k[1] ), array( 1, 'p:09123334444' ) );
$k = keys_of( array( o( 1, 0, '', 'ali@shop.ir', 0 ), o( 2, 0, '09123334444', 'other@shop.ir', 5 ) ) );
check( 'an email-only order and a phone order with a DIFFERENT email are two customers', distinct( $k ), 2 );
$k = keys_of( array( o( 1, 0, '', 'a@x.com', 0 ), o( 2, 0, '09120000001', 'a@x.com', 5 ), o( 3, 0, '09120000009', 'b@x.com', 6 ) ) );
check( 'an unrelated third customer is untouched', array( $k[3], $k[1] === $k[2] ), array( 'p:09120000009', true ) );

/* ============================================================================================ 4. malformed contacts */
section( 'malformed contacts' );
$k = keys_of( array( o( 1, 0, 'abc', 'not-an-email' ), o( 2, 0, '12345', 'a@@b' ), o( 3, 0, '', '' ), o( 4, 0, '0', ' ' ) ), $rep );
check( 'orders with nothing usable are NEVER merged with each other: four orders, four customers', array( distinct( $k ), $k[1], $k[4], $rep['unidentified'] ), array( 4, 'o:1', 'o:4', 4 ) );
$k = keys_of( array( o( 1, 0, 'abc', 'a@x.com', 0 ), o( 2, 0, '09120000001', 'a@x.com', 5 ) ) );
check( 'a malformed phone does not stop the valid email from recognising the person', distinct( $k ), 1 );
$k = keys_of( array( o( 1, 0, '09120000001', 'not-an-email', 0 ), o( 2, 0, '09120000001', '', 5 ) ) );
check( 'a malformed email does not stop the phone from recognising the person', distinct( $k ), 1 );
$k = keys_of( array( o( 1, 0, '', 'not-an-email', 0 ), o( 2, 0, '', 'not-an-email', 5 ) ) );
check( 'the same MALFORMED text is not evidence of the same person', distinct( $k ), 2 );

/* ================================================================================ 5. registered users, changing details */
section( 'registered users with changing contact details' );
$k = keys_of( array( o( 1, 77, '09121110001', 'x1@a.com', 0 ), o( 2, 77, '09122220002', 'x2@b.com', 30 ), o( 3, 77, '', '', 60 ) ) );
check( 'the same account with a different phone AND email each time is one person (the account is the identity)', distinct( $k ), 1 );
check( '... keyed by the latest phone', $k[1], 'p:09122220002' );
$k = keys_of( array( o( 1, 77, '', '', 0 ), o( 2, 77, '', 'x@a.com', 30 ) ) );
check( 'an account with no phone: user-only order and email order are one person; the key is the email', array( distinct( $k ), $k[1] ), array( 1, 'e:x@a.com' ) );
$k = keys_of( array( o( 1, 77, '09121110001', 'same@a.com', 0 ), o( 2, 78, '09122220002', 'same@a.com', 30 ) ), $rep );
check( 'two DIFFERENT registered users with the same email are two people (the email is contested), whatever the phones', array( distinct( $k ), $rep['emails_contested'] ), array( 2, 1 ) );
$k = keys_of( array( o( 1, 77, '09121110001', 'a@a.com', 0 ), o( 2, 78, '09121110001', 'b@b.com', 30 ) ) );
check( 'two accounts that typed the SAME phone are one customer: a number is one recipient (documented exception to "accounts are people")', distinct( $k ), 1 );
$k = keys_of( array( o( 1, 77, '09121110001', 'a@a.com', 0 ), o( 2, 0, '', 'a@a.com', 5 ), o( 3, 78, '09122220002', 'b@b.com', 6 ), o( 4, 0, '', 'b@b.com', 7 ) ) );
check( 'a guest order with the email of an account joins that account only', array( $k[2] === $k[1], $k[4] === $k[3], $k[1] !== $k[3] ), array( true, true, true ) );
// a guest customer that used the email of account 77 on one order and the email of account 78 on another must not join the two accounts
$k = keys_of( array( o( 1, 77, '09121110001', 'a@a.com', 0 ), o( 2, 78, '09122220002', 'b@b.com', 1 ), o( 3, 0, '09123330003', 'a@a.com', 2 ), o( 4, 0, '09123330003', 'b@b.com', 3 ) ), $rep );
check( 'bridging two accounts through a guest is refused: the guest joins the first account (by email a), the second account stays a separate customer, and the refusal is counted', array( $k[1] === $k[3], $k[2] !== $k[1], $k[4] === $k[1], $rep['refused'] ), array( true, true, true, 1 ) );

/* ==================================================================================================== 6. anonymised */
section( 'anonymised orders (WooCommerce privacy eraser)' );
$erased = function ( $id, $day ) {
	return o( $id, 0, '00000000000', 'deleted@site.invalid', $day );
};
$k = keys_of( array( $erased( 1, 0 ), $erased( 2, 1 ), $erased( 3, 2 ) ), $rep );
check( 'three erased orders are three customers, never one fake customer', array( distinct( $k ), $rep['unidentified'], $rep['joined'] ), array( 3, 3, 0 ) );
$k = keys_of( array( $erased( 1, 0 ), o( 2, 0, '09120000001', 'deleted@site.invalid', 1 ), o( 3, 0, '09120000002', 'deleted@site.invalid', 2 ) ) );
check( 'the placeholder never joins anybody: even two ordinary phone customers that carry it stay apart', distinct( $k ), 3 );
$k = keys_of( array( $erased( 1, 0 ), o( 2, 0, '09120000001', 'a@a.com', 1 ), o( 3, 0, '09120000001', 'a@a.com', 2 ) ) );
check( 'an erased order is not attached to a real customer', array( distinct( $k ), $k[1] ), array( 2, 'o:1' ) );
$k = keys_of( array( o( 1, 55, '00000000000', 'deleted@site.invalid', 0 ), o( 2, 55, '00000000000', 'deleted@site.invalid', 9 ) ) );
check( 'two erased orders of the SAME registered account are one customer (the account still identifies them), keyed by the account', array( distinct( $k ), $k[1] ), array( 1, 'u:55' ) );
$k = keys_of( array( o( 1, 55, '00000000000', 'deleted@site.invalid', 0 ), o( 2, 56, '00000000000', 'deleted@site.invalid', 9 ) ) );
check( 'erased orders of two different accounts are two customers', distinct( $k ), 2 );
check( 'is_unidentified(): only the order-scoped keys', array( CRDR_Identity::is_unidentified( 'o:14' ), CRDR_Identity::is_unidentified( 'p:0912' ), CRDR_Identity::is_unidentified( 'e:a@b.c' ), CRDR_Identity::is_unidentified( 'u:3' ) ), array( true, false, false, false ) );
$GLOBALS['CRDR_T']['filters']['crdr_placeholder_emails'] = array( 'deleted@site.invalid', 'shop-noreply@mystore.ir' );
check( 'the list of placeholder addresses can be extended with the crdr_placeholder_emails filter', array( CRDR_Identity::email( 'shop-noreply@mystore.ir' ), CRDR_Identity::email( 'real@mystore.ir' ) ), array( '', 'real@mystore.ir' ) );
unset( $GLOBALS['CRDR_T']['filters']['crdr_placeholder_emails'] );
$three = array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09120000002', 'a@x.com', 30 ), o( 3, 0, '09120000003', 'a@x.com', 60 ) );
$GLOBALS['CRDR_T']['filters']['crdr_identity_email_max_phones'] = 5;
check( 'filter crdr_identity_email_max_phones = 5: an email with three phones now joins them (for a shop that wants it)', distinct( keys_of( $three ) ), 1 );
$GLOBALS['CRDR_T']['filters']['crdr_identity_email_max_phones'] = 1;
check( 'filter crdr_identity_email_max_phones = 1 (the safe fallback): an email never joins two different phones', distinct( keys_of( array( o( 1, 0, '09120000001', 'a@x.com', 0 ), o( 2, 0, '09120000002', 'a@x.com', 30 ) ) ) ), 2 );
check( '... but the registered-account rule still joins an account\'s phones', distinct( keys_of( array( o( 1, 5, '09120000001', 'a@x.com', 0 ), o( 2, 5, '09120000002', 'b@x.com', 30 ) ) ) ), 1 );
unset( $GLOBALS['CRDR_T']['filters']['crdr_identity_email_max_phones'] );

/* =========================================================================================== 7. totals and determinism */
section( 'nothing is lost, and the result does not depend on the order of the input' );
$orders = array( o( 1, 0, '0912 345 6789', 'g2@example.test', 0, 300000 ), o( 2, 0, '', 'G2@Example.test', 30, 200000 ), o( 3, 0, '09351234567', 'g2@example.test', 60, 250000 ),
	o( 4, 0, '00000000000', 'deleted@site.invalid', 5, 120000 ), o( 5, 0, '00000000000', 'deleted@site.invalid', 6, 130000 ), o( 6, 0, 'abc', 'not-an-email', 7, 100000 ), o( 7, 9, '09120000001', 'u@x.com', 8, 500000 ) );
CRDR_T::reset();
$imp0 = CRDR_T::import( array( 'orders' => $orders ), array( 'completed' ) );
$imp1 = CRDR_T::import( array( 'orders' => $orders ), array( 'completed' ), true );
$sum  = function ( $imp, $f ) {
	return array_sum( array_column( $imp['customers'], $f ) );
};
check( 'joining customers keeps every order and every toman: order count and revenue are identical before and after', array( $sum( $imp0, 'orders_count' ), $sum( $imp0, 'total_spent' ) ), array( $sum( $imp1, 'orders_count' ), $sum( $imp1, 'total_spent' ) ) );
check( '... and the anonymous and unidentifiable orders are still in the totals (7 orders, 1,600,000)', array( $sum( $imp1, 'orders_count' ), $sum( $imp1, 'total_spent' ) ), array( 7, 1600000.0 ) );
check( '... 7 provisional customers become 5 (G2 joined; 3 unidentified orders stay apart; one ordinary customer)', array( count( $imp0['customers'] ), count( $imp1['customers'] ) ), array( 7, 5 ) );
$merged = $imp1['customers']['p:09351234567'];
check( 'the joined customer adds up its orders, spend and dates', array( $merged['orders_count'], $merged['total_spent'], $merged['first_ts'], $merged['last_ts'] ), array( 3, 750000.0, 1790000000, 1790000000 + 60 * 86400 ) );
$clusters = array();
$evidence = array();
foreach ( $imp0['customers'] as $key => $c ) {
	$clusters[ $key ] = $c['last_ts'];
}
CRDR_T::reset();
foreach ( $orders as $o ) {
	$r          = CRDR_Sync::extract( new CRDR_T_Order( $o ) );
	$evidence[] = array( $r['ckey'], $r['user_id'], $r['ident_email'] );
}
$base = CRDR_Identity::resolve( $clusters, $evidence );
mt_srand( 7 );
$same_every_time = true;
for ( $i = 0; $i < 25; $i++ ) {
	$c2 = $clusters;
	$e2 = $evidence;
	$keys = array_keys( $c2 );
	shuffle( $keys );
	$c3 = array();
	foreach ( $keys as $kk ) {
		$c3[ $kk ] = $c2[ $kk ];
	}
	shuffle( $e2 );
	if ( CRDR_Identity::resolve( $c3, $e2 ) !== $base ) {
		$same_every_time = false;
	}
}
check( 'resolve() gives the same answer for any order of its input (25 shuffles)', $same_every_time, true );
$after = array();
foreach ( $clusters as $key => $ts ) {
	$after[ isset( $base['map'][ $key ] ) ? $base['map'][ $key ] : $key ] = $ts;
}
$ev2 = array();
foreach ( $evidence as $e ) {
	$ev2[] = array( isset( $base['map'][ $e[0] ] ) ? $base['map'][ $e[0] ] : $e[0], $e[1], $e[2] );
}
check( 'resolving the already-joined result again joins nothing more (for this data)', CRDR_Identity::resolve( $after, $ev2 )['map'], array() );
// NOT a general property, and documented as such: joining lowers the number of phones an email is seen with, so a second pass over
// ALREADY JOINED data can join more. The sync never does that: it applies resolve() once per fresh import (an interrupted step fails the
// rebuild, a new rebuild re-imports), and the remembered joins keep every later rebuild identical.
$cl = array( 'p:09120000001' => 100, 'p:09120000002' => 200, 'p:09120000003' => 300 );
$ev = array( array( 'p:09120000001', 7, 'e@x.com' ), array( 'p:09120000002', 7, 'e@x.com' ), array( 'p:09120000003', 0, 'e@x.com' ) );
$p1 = CRDR_Identity::resolve( $cl, $ev );
check( 'first pass: the account joins two phones; the email (three phones) is a shared mailbox and joins nobody else', array( $p1['map'], $p1['report']['emails_shared'] ), array( array( 'p:09120000001' => 'p:09120000002' ), 1 ) );
$p2 = CRDR_Identity::resolve( array( 'p:09120000002' => 200, 'p:09120000003' => 300 ), array( array( 'p:09120000002', 7, 'e@x.com' ), array( 'p:09120000003', 0, 'e@x.com' ) ) );
check( 'a second pass over the ALREADY JOINED data would join the third phone too (two phones now share the email): this is why the sync applies resolve() once per import', $p2['map'], array( 'p:09120000002' => 'p:09120000003' ) );
$p3 = CRDR_Identity::resolve( $cl, $ev, $p1['links'] );
check( 'a new rebuild (provisional customers again, with the remembered joins) repeats the first answer exactly', array( $p3['map'], $p3['links'] ), array( $p1['map'], $p1['links'] ) );
check( 'the report counts what happened', $base['report'], array( 'joined' => 2, 'people' => 1, 'emails_shared' => 0, 'emails_contested' => 0, 'refused' => 0, 'unidentified' => 3, 'links_dropped' => 0 ) );

/* ====================================================================================== 7b. joins are sticky */
section( 'joins are remembered: a person never silently falls apart again' );
$cl  = array( 'p:09120000001' => 100, 'p:09120000002' => 200 );
$ev  = array( array( 'p:09120000001', 0, 'a@x.com' ), array( 'p:09120000002', 0, 'a@x.com' ) );
$r1  = CRDR_Identity::resolve( $cl, $ev );
check( 'first rebuild: one email, two phones: joined, and the join is remembered (member => canonical)', array( $r1['map'], $r1['links'] ), array( array( 'p:09120000001' => 'p:09120000002' ), array( 'p:09120000001' => 'p:09120000002' ) ) );
// a third phone appears with the same email: the email is now a "shared mailbox" for any NEW join
$cl3 = $cl + array( 'p:09120000003' => 300 );
$ev3 = array_merge( $ev, array( array( 'p:09120000003', 0, 'a@x.com' ) ) );
$fresh = CRDR_Identity::resolve( $cl3, $ev3 );
check( 'WITHOUT memory a third phone would take the first two apart (this is what stickiness prevents): nobody joined', $fresh['map'], array() );
$kept = CRDR_Identity::resolve( $cl3, $ev3, $r1['links'] );
check( 'WITH memory the first two stay one customer and the third phone is a separate customer', array( $kept['map'], $kept['links'], $kept['report']['emails_shared'] ), array( array( 'p:09120000001' => 'p:09120000002' ), array( 'p:09120000001' => 'p:09120000002' ), 1 ) );
// the same person later gets an account: the join with the new phone follows the account, the old join stays, the key follows the latest phone
$cl4 = $cl3;
$ev4 = array( array( 'p:09120000001', 0, 'a@x.com' ), array( 'p:09120000002', 9, 'a@x.com' ), array( 'p:09120000003', 9, 'a@x.com' ) );
$k4  = CRDR_Identity::resolve( $cl4, $ev4, $kept['links'] );
ksort( $k4['map'] );
check( 'a new join made through the account (same user id) extends the old one: all three, keyed by the newest phone; the memory covers every member', array( $k4['map'], $k4['links'] ), array( array( 'p:09120000001' => 'p:09120000003', 'p:09120000002' => 'p:09120000003' ), array( 'p:09120000001' => 'p:09120000003', 'p:09120000002' => 'p:09120000003' ) ) );
check( 'remembered joins whose customers no longer exist are forgotten (no orders left, nothing to join)', CRDR_Identity::resolve( array( 'p:09120000002' => 200 ), array(), $r1['links'] )['links'], array() );
check( 'remembered joins are applied even for a person whose evidence is gone (the orders still carry no email now)', CRDR_Identity::resolve( $cl, array(), $r1['links'] )['map'], array( 'p:09120000001' => 'p:09120000002' ) );
check( 'a remembered join is idempotent: resolving the result again changes nothing', ( function () use ( $cl, $ev, $r1 ) {
	$again = CRDR_Identity::resolve( array( 'p:09120000002' => 200 ), array( array( 'p:09120000002', 0, 'a@x.com' ) ), $r1['links'] );
	return $again['map'];
} )(), array() );
check( '[static] uninstalling removes the remembered joins', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-crdr-uninstall.php' ), "'crdr_identity_links'" ), true );

/* ====================================================================================== 7c. damaged remembered joins */
section( 'a damaged or hand-edited list of remembered joins can neither break a rebuild nor join the wrong people' );
$cl = array( 'p:09120000001' => 100, 'p:09120000002' => 200, 'p:09120000003' => 300 );
foreach ( array(
	'an array as a value (this threw a TypeError before)' => array( 'p:09120000001' => array( 'x' ) ),
	'a number as a value'                                  => array( 'p:09120000001' => 5 ),
	'null'                                                 => array( 'p:09120000001' => null ),
	'a key of no known kind'                               => array( 'x:09120000001' => 'p:09120000002' ),
	'a key with a space or newline'                        => array( "p:0912 1" => 'p:09120000002', "p:0912\n2" => 'p:09120000003' ),
	'a key that is far too long'                           => array( 'p:' . str_repeat( '9', 150 ) => 'p:09120000002' ),
	'a key joined to itself'                               => array( 'p:09120000001' => 'p:09120000001' ),
	'a number as a key'                                    => array( 7 => 'p:09120000002' ),
) as $what => $bad ) {
	try {
		$r = CRDR_Identity::resolve( $cl, array(), $bad );
		check( "links: $what: no exception, nothing joined, the entry is counted as dropped", array( $r['map'], $r['links'], $r['report']['links_dropped'] >= 1 ), array( array(), array(), true ) );
	} catch ( \Throwable $e ) {
		check( "links: $what: no exception", get_class( $e ) . ': ' . $e->getMessage(), 'no exception' );
	}
}
$r = CRDR_Identity::resolve( $cl, array(), array( 'p:09120000001' => 'p:09120000002', 'junk' => array( 1 ), 'p:09120000003' => 7 ) );
check( 'links: the usable entry is kept, the unusable ones are dropped and counted', array( $r['map'], $r['links'], $r['report']['links_dropped'] ), array( array( 'p:09120000001' => 'p:09120000002' ), array( 'p:09120000001' => 'p:09120000002' ), 2 ) );
foreach ( array( 'a string' => 'x', 'a number' => 3, 'true' => true ) as $what => $raw ) {
	check( "links: an option that is $what is treated as no joins (and counted)", array( CRDR_Identity::clean_links( $raw )['links'], CRDR_Identity::clean_links( $raw )['dropped'] ), array( array(), 1 ) );
}
check( 'links: an option that does not exist yet is not "damaged"', CRDR_Identity::clean_links( false ), array( 'links' => array(), 'dropped' => 0 ) );
$r = CRDR_Identity::resolve( $cl, array(), array( 'p:09120000001' => 'p:09120000002', 'p:09120000002' => 'p:09120000001' ) );
check( 'links: a cycle (A joined to B, B joined to A) is harmless: one group, one key', array( count( $r['map'] ), array_values( $r['map'] ) ), array( 1, array( 'p:09120000002' ) ) );
$r = CRDR_Identity::resolve( $cl, array(), array( 'p:09120000001' => 'p:09120000002', 'p:09120000002' => 'p:09120000003' ) );
check( 'links: a chain A -> B -> C is one group keyed by the strongest/latest key', array( $r['map'], $r['links'] ), array( array( 'p:09120000001' => 'p:09120000003', 'p:09120000002' => 'p:09120000003' ), array( 'p:09120000001' => 'p:09120000003', 'p:09120000002' => 'p:09120000003' ) ) );
check( 'links: entries whose customers do not exist any more are forgotten, not an error', CRDR_Identity::resolve( array( 'p:09120000001' => 1 ), array(), array( 'p:09120000001' => 'p:09120000009', 'p:09120000007' => 'p:09120000008' ) )['links'], array() );
check( 'valid_key(): the five forms the plugin writes, and nothing else', array( CRDR_Identity::valid_key( 'p:09120000001' ), CRDR_Identity::valid_key( 'e:a@b.com' ), CRDR_Identity::valid_key( 'u:5' ), CRDR_Identity::valid_key( 'o:14' ), CRDR_Identity::valid_key( 'o:abc' ), CRDR_Identity::valid_key( '' ), CRDR_Identity::valid_key( null ), CRDR_Identity::valid_key( array() ) ), array( true, true, true, true, false, false, false, false ) );

section( 'customers kept apart on purpose (repair of a wrong join)' );
$cl = array( 'p:09120000001' => 100, 'p:09120000002' => 200 );
$ev = array( array( 'p:09120000001', 0, 'a@x.com' ), array( 'p:09120000002', 0, 'a@x.com' ) );
check( 'without the repair list the shared email joins the two phones (the case to repair)', count( CRDR_Identity::resolve( $cl, $ev )['map'] ), 1 );
check( 'a customer kept apart is never joined by the email rule, in either position', array( CRDR_Identity::resolve( $cl, $ev, array(), array( 'p:09120000002' ) )['map'], CRDR_Identity::resolve( $cl, $ev, array(), array( 'p:09120000001' ) )['map'] ), array( array(), array() ) );
check( 'ever by the account rule', CRDR_Identity::resolve( $cl, array( array( 'p:09120000001', 9, '' ), array( 'p:09120000002', 9, '' ) ), array(), array( 'p:09120000001' ) )['map'], array() );
check( 'nor by a remembered join (it is ignored, not deleted)', CRDR_Identity::resolve( $cl, array(), array( 'p:09120000001' => 'p:09120000002' ), array( 'p:09120000002' ) )['map'], array() );
check( 'and keeping one customer apart does not stop the others from being joined', count( CRDR_Identity::resolve( $cl + array( 'p:09120000003' => 300, 'p:09120000004' => 400 ), array_merge( $ev, array( array( 'p:09120000003', 0, 'b@x.com' ), array( 'p:09120000004', 0, 'b@x.com' ) ) ), array(), array( 'p:09120000001' ) )['map'] ), 1 );
check( 'clean_keys(): invalid and duplicate keys removed, sorted', CRDR_Identity::clean_keys( array( 'p:09120000002', 'bad', 'p:09120000001', 'p:09120000002', 5, array() ) ), array( 'p:09120000001', 'p:09120000002' ) );
check( 'components(): joins from several maps are combined into groups', CRDR_Identity::components( array( 'p:2' => 'p:1' ), array( 'p:3' => 'p:2', 'p:9' => 'p:8' ) ), array( array( 'p:1', 'p:2', 'p:3' ), array( 'p:8', 'p:9' ) ) );
check( 'group_of(): every key of the person; a never-joined key is alone; garbage in the map is ignored', array( CRDR_Identity::group_of( 'p:3', array( 'p:2' => 'p:1', 'p:3' => 'p:2' ) ), CRDR_Identity::group_of( 'p:7', array( 'p:2' => 'p:1' ) ), CRDR_Identity::group_of( 'p:7', array( 'x' => array() ) ) ), array( array( 'p:1', 'p:2', 'p:3' ), array( 'p:7' ), array( 'p:7' ) ) );
check( '[static] uninstalling removes the repair list too', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-crdr-uninstall.php' ), "'crdr_identity_apart'" ), true );

/* ====================================================================== 8. the stale-customer protection still holds */
section( 'interaction with the stale-id protection' );
check( 'the identity token is derived from the key the person has NOW: after a join, the old key\'s token no longer matches the joined customer', CRDR_Actions::identity( 'p:09123456789' ) !== CRDR_Actions::identity( 'p:09351234567' ), true );
check( 'a customer that did not change keeps the same token', CRDR_Actions::identity( 'p:09120000001' ), CRDR_Actions::identity( 'p:09120000001' ) );

$r = $GLOBALS['CRDR_R'];
printf( "\nPASS %d   FAIL %d\n", $r['pass'], $r['fail'] );
exit( $r['fail'] ? 1 : 0 );
