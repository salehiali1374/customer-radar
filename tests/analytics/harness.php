<?php
/**
 * Test harness for the analytics characterization / regression tests.
 *
 * It runs the REAL plugin code (CRDR_Sync::extract, CRDR_Analytics::build, CRDR_RFM::score_all, CRDR_Playbooks::results,
 * CRDR_Insights::generate ...) with WordPress and WooCommerce replaced by small stand-ins. It needs no WordPress, no
 * WooCommerce, no database and no network, and it never writes anywhere.
 *
 * What is real and what is not (so nobody over-trusts a green test):
 *   REAL      every plugin class under includes/ that the tests call, and (when available) WordPress' own is_email().
 *   STAND-IN  WC_Order (only the getters CRDR_Sync::extract reads), wp_date(), get_option()/update_option() (an array),
 *             the database ($wpdb): it serves the rows a test gives it and RECORDS the SQL it is asked to run. SQL is never
 *             executed, so a test about a SQL statement checks its TEXT, and says so.
 */
if ( defined( 'CRDR_T_HARNESS' ) ) {
	return;
}
define( 'CRDR_T_HARNESS', true );
define( 'CRDR_TESTING', true );
define( 'ABSPATH', '/crdr-stub-abspath/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'crdr_test' );
}
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT_K', 'OBJECT_K' );
date_default_timezone_set( 'UTC' );

$crdr_inc = dirname( __DIR__, 2 ) . '/includes/';
foreach ( array( 'jalali', 'identity', 'sales', 'rfm', 'stats', 'template', 'send-rules', 'db', 'retention', 'site', 'settings', 'audience', 'send', 'sms', 'health', 'sync', 'actions', 'analytics', 'playbooks', 'insights' ) as $f ) {
	require_once $crdr_inc . "class-crdr-$f.php";
}

/* ------------------------------------------------------------------ WordPress stand-ins */

$GLOBALS['CRDR_T'] = array(
	'tz'      => new DateTimeZone( 'Asia/Tehran' ), // the site timezone (Settings > General)
	'options' => array(),
	'users'   => array(),                           // user id => billing phone (user meta)
);

function apply_filters( $tag, $value ) {
	// A test may force a filter's result: $GLOBALS['CRDR_T']['filters'][ $tag ] = value (unset again afterwards). Otherwise pass-through.
	return isset( $GLOBALS['CRDR_T']['filters'][ $tag ] ) ? $GLOBALS['CRDR_T']['filters'][ $tag ] : $value;
}
function sanitize_key( $k ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) );
}
function wp_unslash( $v ) {
	return $v;
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function wp_date( $format, $ts = null ) {
	$ts = null === $ts ? time() : (int) $ts;
	return ( new DateTime( '@' . $ts ) )->setTimezone( $GLOBALS['CRDR_T']['tz'] )->format( $format );
}
function wp_timezone() {
	return $GLOBALS['CRDR_T']['tz'];
}
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['CRDR_T']['options'] ) ? $GLOBALS['CRDR_T']['options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['CRDR_T']['options'][ $k ] = $v;
	return true;
}
/** The schema writer: the stand-in database "repairs" nothing, tests that need a repair say what is repaired. */
function dbDelta( $sql ) {
	$GLOBALS['CRDR_T']['dbdelta'][] = $sql;
	return array();
}
/** The site address (Settings > General). Tests move a "copy" of the shop by changing the option 'home'. */
function home_url( $path = '' ) {
	return rtrim( (string) get_option( 'home', 'https://shop.example.test' ), '/' ) . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}
function delete_option( $k ) {
	$had = array_key_exists( $k, $GLOBALS['CRDR_T']['options'] );
	unset( $GLOBALS['CRDR_T']['options'][ $k ] );
	return $had;
}
if ( ! function_exists( 'maybe_serialize' ) ) { // the real ones live in wp-includes/functions.php
	function maybe_serialize( $v ) {
		return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v;
	}
	function maybe_unserialize( $v ) {
		return is_string( $v ) && preg_match( '/^[aOsibd]:/', $v ) ? unserialize( $v ) : $v;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}
function delete_transient( $k ) {
	return true;
}
function get_user_meta( $uid, $key, $single ) {
	return isset( $GLOBALS['CRDR_T']['users'][ $uid ] ) ? $GLOBALS['CRDR_T']['users'][ $uid ] : '';
}
function get_the_title( $id ) {
	return 'P' . $id;
}
function wp_strip_all_tags( $s ) {
	return $s;
}
function get_woocommerce_currency_symbol() {
	return isset( $GLOBALS['CRDR_T']['currency'] ) ? $GLOBALS['CRDR_T']['currency'] : 'تومان';
}
function get_current_user_id() {
	return 0;
}
function wp_cache_delete( $k, $g = '' ) {
	return true;
}
function get_transient( $k ) {
	return isset( $GLOBALS['CRDR_T']['transients'][ $k ] ) ? $GLOBALS['CRDR_T']['transients'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['CRDR_T']['transients'][ $k ] = $v;
	return true;
}
function wp_next_scheduled( $hook ) {
	return false;
}
/** Records what the plugin schedules; nothing runs. */
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$GLOBALS['CRDR_T']['scheduled'][] = $hook;
	return true;
}
function current_user_can( $cap ) {
	return true;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return (string) $s;
}
function admin_url( $p = '' ) {
	return 'http://localhost/wp-admin/' . $p;
}
function get_bloginfo( $k = '' ) {
	return 'Test Shop';
}
function wp_specialchars_decode( $s, $q = 0 ) {
	return (string) $s;
}
function is_wp_error( $x ) {
	return false;
}
/** The SMS gateway: every call is recorded (a test that says "nothing was sent" checks this list is empty). */
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['CRDR_T']['gateway_calls'][] = array( 'url' => $url, 'receptor' => isset( $args['body']['receptor'] ) ? $args['body']['receptor'] : '' );
	return array( 'code' => 200, 'body' => '{"return":{"status":200,"message":"ok"},"entries":[{"messageid":1}]}' );
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['code'];
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
/**
 * WooCommerce order query used by CRDR_Sync: $GLOBALS['CRDR_T']['wc_orders'] is the whole store (dataset-format arrays).
 * Honours 'status' (the counted statuses), 'limit' (-1 = all), 'paged', 'return' => 'ids' | objects, and 'paginate' (a total).
 * $GLOBALS['CRDR_T']['wc_hook'], when set, is called with the query arguments BEFORE the query is answered: a test uses it to
 * change the store between two requests (an order is cancelled, another is completed), the way a busy shop does.
 */
function wc_get_orders( array $args ) {
	if ( ! empty( $GLOBALS['CRDR_T']['wc_hook'] ) ) {
		call_user_func( $GLOBALS['CRDR_T']['wc_hook'], $args );
	}
	$all = isset( $GLOBALS['CRDR_T']['wc_orders'] ) ? $GLOBALS['CRDR_T']['wc_orders'] : array();
	if ( ! empty( $args['status'] ) ) {
		$want = array_map( function ( $s ) {
			return 0 === strpos( $s, 'wc-' ) ? substr( $s, 3 ) : $s;
		}, (array) $args['status'] );
		$all  = array_values( array_filter( $all, function ( $o ) use ( $want ) {
			return in_array( isset( $o['status'] ) ? $o['status'] : 'completed', $want, true );
		} ) );
	}
	$GLOBALS['CRDR_T']['wc_queries'][] = $args;
	if ( ! empty( $args['paginate'] ) ) {
		return (object) array( 'total' => count( $all ) );
	}
	$page  = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
	$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
	if ( ! empty( $GLOBALS['CRDR_T']['wc_throw_on_page'] ) && (int) $GLOBALS['CRDR_T']['wc_throw_on_page'] === $page ) {
		throw new RuntimeException( 'simulated WooCommerce failure' );
	}
	$slice = $limit < 0 ? $all : array_slice( $all, ( $page - 1 ) * $limit, $limit );
	if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
		return array_map( function ( $o ) {
			return $o['id'];
		}, $slice );
	}
	return array_map( function ( $o ) {
		return new CRDR_T_Order( $o );
	}, array_values( array_filter( $slice, function ( $o ) {
		return empty( $o['unloadable'] );
	} ) ) );
}
/** One order by id: false when it does not exist or cannot be loaded (an entry with 'unloadable' => true). */
function wc_get_order( $id ) {
	foreach ( isset( $GLOBALS['CRDR_T']['wc_orders'] ) ? $GLOBALS['CRDR_T']['wc_orders'] : array() as $o ) {
		if ( (int) $o['id'] === (int) $id ) {
			return empty( $o['unloadable'] ) ? new CRDR_T_Order( $o ) : false;
		}
	}
	return false;
}

/** Use WordPress' own is_email() when its source is present (it is in the wordpress Docker image); else a close stand-in. */
function crdr_t_load_is_email() {
	$candidates = array( '/usr/src/wordpress/wp-includes/formatting.php', '/var/www/html/wp-includes/formatting.php' );
	if ( getenv( 'CRDR_T_WP_FORMATTING' ) ) {
		array_unshift( $candidates, getenv( 'CRDR_T_WP_FORMATTING' ) );
	}
	foreach ( getenv( 'CRDR_T_IS_EMAIL' ) === 'stand-in' ? array() : $candidates as $file ) { // CRDR_T_IS_EMAIL=stand-in forces the stand-in (used to test both paths)
		if ( is_readable( $file ) && preg_match( '/^function is_email\(.*?^}\n/ms', (string) file_get_contents( $file ), $m ) ) {
			eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval -- test harness only: the real function, copied out of WordPress core
			return 'wordpress-core (' . $file . ')';
		}
	}
	// Stand-in with the same outcome for ordinary addresses (checked by the tests against the cases they use).
	function is_email( $email, $deprecated = false ) {
		return (bool) preg_match( '/^[^@\s]{1,64}@([A-Za-z0-9][A-Za-z0-9\-]*\.)+[A-Za-z]{2,63}$/', (string) $email ) ? $email : false;
	}
	return 'stand-in';
}
$GLOBALS['CRDR_T']['is_email_source'] = crdr_t_load_is_email();

/* ------------------------------------------------------------------ WooCommerce order stand-in */

class CRDR_T_Date {
	private $t;
	public function __construct( $t ) {
		$this->t = (int) $t;
	}
	public function getTimestamp() {
		return $this->t;
	}
}

/** Only what CRDR_Sync::extract() reads from a WC_Order. */
class CRDR_T_Order {
	public $o;
	public function __construct( array $o ) {
		$this->o = $o;
	}
	public function get_billing_phone() {
		return $this->o['phone'];
	}
	public function get_billing_email() {
		return $this->o['email'];
	}
	public function get_customer_id() {
		return (int) $this->o['uid'];
	}
	public function get_formatted_billing_full_name() {
		return isset( $this->o['name'] ) ? $this->o['name'] : '';
	}
	public function get_shipping_first_name() {
		return '';
	}
	public function get_shipping_last_name() {
		return '';
	}
	public function get_date_created() {
		return new CRDR_T_Date( $this->o['ts'] );
	}
	public function get_total() {
		return $this->o['total'];
	}
	public function get_total_refunded() {
		return $this->o['refunded'];
	}
	public function get_id() {
		return $this->o['id'];
	}
	public function get_status() {
		return isset( $this->o['status'] ) ? $this->o['status'] : 'completed';
	}
	/** Lines keyed by item id (as WooCommerce does). A dataset line is [product, line total, refunded for that line (optional)]. */
	public function get_items( $type = 'line_item' ) {
		$out = array();
		if ( 'line_item' !== $type ) { // REVIEW 4.2: fees / shipping are not product lines (the fee-refund patch asks for 'fee')
			return $out;
		}
		foreach ( isset( $this->o['items'] ) ? $this->o['items'] : array() as $i => $it ) {
			$id         = (int) $this->o['id'] * 100 + $i + 1;
			$out[ $id ] = new CRDR_T_Item( $it[0], $it[1], $id );
		}
		return $out;
	}
	/** What WooCommerce recorded against ONE line (a refund that chose that line). */
	public function get_total_refunded_for_item( $item_id, $type = 'line_item' ) {
		foreach ( isset( $this->o['items'] ) ? $this->o['items'] : array() as $i => $it ) {
			if ( (int) $this->o['id'] * 100 + $i + 1 === (int) $item_id ) {
				return isset( $it[2] ) ? $it[2] : 0;
			}
		}
		return 0;
	}
	public function get_total_shipping_refunded() {
		return isset( $this->o['shipping_refunded'] ) ? $this->o['shipping_refunded'] : 0;
	}
	public function get_total_tax_refunded() {
		return isset( $this->o['tax_refunded'] ) ? $this->o['tax_refunded'] : 0;
	}
}

class CRDR_T_Item {
	private $p, $t, $id;
	public function __construct( $product, $total, $id = 0 ) {
		$this->p  = $product;
		$this->t  = $total;
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_product_id() {
		return $this->p;
	}
	public function get_quantity() {
		return 1;
	}
	public function get_total() {
		return $this->t;
	}
}

/* ------------------------------------------------------------------ database stand-in */

/**
 * Serves canned rows and records every statement. Statements are matched by a fragment of their text; the first matching
 * rule wins. Nothing is executed.
 */
class CRDR_T_DB {
	public $prefix = 'wp_';
	public $log    = array();
	public $rules  = array(); // fragment => value | callable( $sql ) -> value
	public $dbh    = null;
	public $options = 'wp_options';
	public $insert_id = 0;
	public $last_error = '';
	public $state_writes = 0;  // how many times the state write was ATTEMPTED (accepted or not)
	public $lock_row = null;   // value of the crdr_sync_lock row in the options table (null = no row)
	public $on_query = null;   // optional callable( $sql ) run before every statement (to observe the world at that moment)
	public $inserted = array();
	public $order_rows = array(); // order ids present in the (modelled) order table
	public $missing_tables  = array(); // schema model: short table names that "do not exist" ( 'contacts' )
	public $missing_columns = array(); // schema model: 'orders.uid' ... columns that "do not exist"
	public $claim_index     = true;    // schema model: the unique index on crdr_contacts.claim_key exists

	public function on( $fragment, $value ) {
		$this->rules[ $fragment ] = $value;
	}
	private function answer( $sql, $default ) {
		$this->log[] = $sql;
		if ( $this->on_query ) {
			call_user_func( $this->on_query, $sql );
		}
		// The schema checks of CRDR_DB (SHOW COLUMNS / SHOW INDEX): the tables are complete unless a test says otherwise.
		if ( preg_match( '/^SHOW COLUMNS FROM `' . preg_quote( $this->prefix, '/' ) . 'crdr_([a-z_]+)`/', $sql, $m ) ) {
			$req = CRDR_DB::required_schema();
			if ( in_array( $m[1], $this->missing_tables, true ) || ! isset( $req[ $m[1] ] ) ) {
				return array();
			}
			return array_values( array_filter( $req[ $m[1] ], function ( $c ) use ( $m ) {
				return ! in_array( $m[1] . '.' . $c, $this->missing_columns, true );
			} ) );
		}
		if ( 0 === strpos( $sql, 'SHOW INDEX FROM ' ) ) {
			return $this->claim_index ? array( array( 'Non_unique' => '0', 'Key_name' => 'claim_key' ) ) : array();
		}
		// The order table has order_id as its primary key: INSERT IGNORE answers 1 for a new order and 0 for one already there.
		if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_crdr_orders' ) && preg_match( '/VALUES \((\d+),/', $sql, $m ) ) {
			if ( isset( $this->order_rows[ $m[1] ] ) ) {
				return 0;
			}
			$this->order_rows[ $m[1] ] = true;
			return 1;
		}
		// The ids of the imported orders (CRDR_Sync::reconcile reads them).
		if ( 0 === strpos( $sql, 'SELECT order_id FROM wp_crdr_orders' ) ) {
			return array_map( 'strval', array_keys( $this->order_rows ) );
		}
		// Writing the sync state is ONE statement that also tests the lock (CRDR_Sync::save_state): it writes the option only if
		// the lock row still holds the value the caller names. Modelled here; tests/phase1-safety.php runs it on a real database.
		if ( 0 === strpos( $sql, 'INSERT INTO wp_options' ) && false !== strpos( $sql, "'crdr_sync_state'" ) && preg_match( "/SELECT 'crdr_sync_state', '((?:\\\\.|[^'\\\\])*)', 'no' FROM wp_options WHERE option_name = 'crdr_sync_lock' AND option_value = '([^']*)'/s", $sql, $m ) ) {
			$this->state_writes++;
			if ( $this->lock_row !== $m[2] ) {
				return 0;
			}
			$GLOBALS['CRDR_T']['options']['crdr_sync_state'] = maybe_unserialize( stripslashes( $m[1] ) );
			return 1;
		}
		// The sync lock lives in the options table. A tiny in-memory model of exactly the four statements CRDR_Sync uses, so
		// the PHP-side logic can be tested. It does NOT prove the database makes INSERT IGNORE atomic: tests/phase1-safety.php
		// does that with real processes and a real database.
		if ( false !== strpos( $sql, "'crdr_sync_lock'" ) ) {
			if ( 0 === strpos( $sql, 'INSERT IGNORE INTO' ) && preg_match( "/VALUES \('crdr_sync_lock', '([^']*)'/", $sql, $m ) ) {
				if ( null !== $this->lock_row ) {
					return 0;
				}
				$this->lock_row = $m[1];
				return 1;
			}
			if ( 0 === strpos( $sql, 'SELECT option_value' ) ) {
				return null === $this->lock_row ? null : $this->lock_row;
			}
			if ( 0 === strpos( $sql, 'UPDATE' ) && preg_match( "/SET option_value = '([^']*)' WHERE option_name = 'crdr_sync_lock' AND option_value = '([^']*)'/", $sql, $m ) ) {
				if ( $this->lock_row === $m[2] ) {
					$this->lock_row = $m[1];
					return 1;
				}
				return 0;
			}
			if ( 0 === strpos( $sql, 'DELETE' ) && preg_match( "/AND option_value = '([^']*)'/", $sql, $m ) ) {
				if ( $this->lock_row === $m[1] ) {
					$this->lock_row = null;
					return 1;
				}
				return 0;
			}
		}
		foreach ( $this->rules as $frag => $val ) {
			if ( false !== strpos( $sql, $frag ) ) {
				return is_callable( $val ) ? $val( $sql ) : $val;
			}
		}
		return $default;
	}
	public function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$v = isset( $args[ $i ] ) ? $args[ $i ] : '';
			$i++;
			return '%s' === $m[0] ? "'" . addslashes( (string) $v ) . "'" : (string) ( '%d' === $m[0] ? (int) $v : (float) $v );
		}, $q );
	}
	public function get_results( $sql, $mode = null ) {
		return $this->answer( $sql, array() );
	}
	public function get_col( $sql ) {
		return $this->answer( $sql, array() );
	}
	public function get_var( $sql ) {
		return $this->answer( $sql, 0 );
	}
	public function get_row( $sql, $mode = null ) {
		return $this->answer( $sql, null );
	}
	public function suppress_errors( $on = true ) {
		return false;
	}
	public function insert( $table, $data, $formats = null ) {
		$this->log[]      = 'INSERT ' . $table;
		$this->inserted[] = array( $table, $data );
		$this->insert_id  = count( $this->inserted );
		return 1;
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$this->log[] = 'UPDATE ' . $table;
		return 1;
	}
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}
	public function esc_like( $s ) {
		return addcslashes( (string) $s, '_%\\' );
	}
	public function query( $sql ) {
		return $this->answer( $sql, 0 );
	}
}

/* ------------------------------------------------------------------ helpers used by the tests */

class CRDR_T {

	public static function fixture( $name ) {
		$j = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $name ), true );
		if ( ! is_array( $j ) ) {
			throw new RuntimeException( "fixture {$name} is missing or is not valid JSON" );
		}
		return $j;
	}

	public static function set_timezone( $tz ) {
		$GLOBALS['CRDR_T']['tz'] = new DateTimeZone( $tz );
	}

	public static function reset() {
		// A properly installed site: its address is the one recorded by the installer (CRDR_Site), so automatic campaigns may run.
		$GLOBALS['CRDR_T']['options'] = array(
			'home'                => 'https://shop.example.test',
			CRDR_Site::OPTION     => array( 'url' => 'shop.example.test', 'set' => 1, 'by' => 0, 'how' => 'install' ),
		);
		$GLOBALS['CRDR_T']['tz']      = new DateTimeZone( 'Asia/Tehran' );
		$GLOBALS['wpdb']              = new CRDR_T_DB();
	}

	/**
	 * Import the reference orders the way CRDR_Sync::import_page() does: extract() each order of an accepted status.
	 *
	 * @param array $statuses statuses the store counts (CRDR_Settings default: completed, processing)
	 * @return array orders (ckey, ts, net, id), items (order_id, product_id, qty, total), customers (by ckey), extract (id => ckey, net)
	 */
	/**
	 * Import the dataset the way CRDR_Sync does, minus the database.
	 *
	 * @param bool $resolve false (default): every order keeps its PROVISIONAL key, the state after the page import and before the
	 *                      identity step (what all pins written before the identity fix describe). true: the identity step is applied
	 *                      too (CRDR_Identity::resolve), exactly as the "finalize" phase applies it in SQL: joined customers add up
	 *                      their orders and spend, keep the first and last dates, and their orders carry the canonical key.
	 */
	public static function import( array $dataset, array $statuses = array( 'completed', 'processing' ), $resolve = false ) {
		$out      = array( 'orders' => array(), 'items' => array(), 'customers' => array(), 'extract' => array(), 'identity' => null, 'void' => array() );
		$evidence = array();
		foreach ( $dataset['orders'] as $o ) {
			if ( ! in_array( $o['status'], $statuses, true ) ) {
				continue;
			}
			$GLOBALS['CRDR_T']['users'][ $o['uid'] ] = ''; // registered users have no extra phone in user meta here
			$r = CRDR_Sync::extract( new CRDR_T_Order( $o ) );
			$out['extract'][ $o['id'] ] = array( 'ckey' => $r['ckey'], 'net' => $r['net'], 'void' => $r['void'], 'gap' => $r['gap'] );
			if ( $r['void'] ) { // Phase 4.2: a fully refunded / free order is not an order, not evidence of a person, not a customer
				$out['void'][] = $o['id'];
				continue;
			}
			$evidence[]                 = array( $r['ckey'], $r['user_id'], $r['ident_email'] );
			$out['orders'][]            = array( 'id' => $o['id'], 'ckey' => $r['ckey'], 'ts' => $r['ts'], 'net' => $r['net'] );
			foreach ( $r['items'] as $it ) {
				$out['items'][] = array( $o['id'], $it[0], $it[1], $it[2] );
			}
			if ( ! isset( $out['customers'][ $r['ckey'] ] ) ) {
				$out['customers'][ $r['ckey'] ] = array( 'ckey' => $r['ckey'], 'orders_count' => 0, 'total_spent' => 0.0, 'first_ts' => $r['ts'], 'last_ts' => $r['ts'] );
			}
			$c = &$out['customers'][ $r['ckey'] ];
			$c['orders_count']++;
			$c['total_spent'] += $r['net'];
			$c['first_ts']     = min( $c['first_ts'], $r['ts'] );
			$c['last_ts']      = max( $c['last_ts'], $r['ts'] );
			unset( $c );
		}
		if ( $resolve ) {
			$clusters = array();
			foreach ( $out['customers'] as $k => $c ) {
				$clusters[ $k ] = $c['last_ts'];
			}
			$res = CRDR_Identity::resolve( $clusters, $evidence );
			$out['identity'] = $res['report'];
			foreach ( $res['map'] as $alias => $canon ) {
				$a = $out['customers'][ $alias ];
				$m = &$out['customers'][ $canon ];
				$m['orders_count'] += $a['orders_count'];
				$m['total_spent']  += $a['total_spent'];
				$m['first_ts']      = min( $m['first_ts'], $a['first_ts'] );
				$m['last_ts']       = max( $m['last_ts'], $a['last_ts'] );
				unset( $m, $out['customers'][ $alias ] );
			}
			foreach ( $out['orders'] as &$ord ) {
				if ( isset( $res['map'][ $ord['ckey'] ] ) ) {
					$ord['ckey'] = $res['map'][ $ord['ckey'] ];
				}
			}
			unset( $ord );
			foreach ( $out['extract'] as &$ex ) {
				if ( isset( $res['map'][ $ex['ckey'] ] ) ) {
					$ex['ckey'] = $res['map'][ $ex['ckey'] ];
				}
			}
			unset( $ex );
		}
		return $out;
	}

	/** RFM exactly as CRDR_Sync::finalize() calls it. @return array ckey => [r,f,m,segment,priority,avg_gap_days] */
	public static function rfm( array $customers, $asof ) {
		$rows = array();
		$id   = 1;
		foreach ( $customers as $c ) {
			$c['id']          = $id++;
			$rows[ $c['id'] ] = $c;
		}
		$set = CRDR_Settings::defaults();
		$res = CRDR_RFM::score_all( array_values( $rows ), $asof, array(
			'new_days'    => $set['new_days'],
			'lost_days'   => $set['lost_days'],
			'min_ratio'   => $set['min_ratio'],
			'default_gap' => $set['default_gap'],
		) );
		$out = array();
		foreach ( $rows as $i => $c ) {
			$out[ $c['ckey'] ] = $res[ $i ];
		}
		return $out;
	}

	/**
	 * Run CRDR_Analytics::build() over imported rows. The database stand-in serves exactly the rows the real queries would
	 * return for them. @return array the stats bundle build() stores
	 */
	public static function build( array $imported ) {
		$db        = new CRDR_T_DB();
		$GLOBALS['wpdb'] = $db;
		$orders    = array_map( function ( $o ) {
			return array( $o['ckey'], $o['ts'], $o['net'] );
		}, $imported['orders'] );
		usort( $orders, function ( $a, $b ) {
			return strcmp( $a[0], $b[0] ) ?: $a[1] <=> $b[1];
		} );
		$items = $imported['items'];
		$cust  = array_values( $imported['customers'] );
		$db->on( 'SELECT ckey, ts, net', $orders );
		$db->on( 'SELECT r, f, COUNT', array() );
		$db->on( 'SELECT total_spent', array_map( function ( $c ) {
			return $c['total_spent'];
		}, $cust ) );
		$db->on( 'COUNT(DISTINCT order_id) orders', function () use ( $items ) {
			$p = array();
			foreach ( $items as $x ) {
				$p[ $x[1] ]['o'][ $x[0] ] = 1;
				$p[ $x[1] ]['rev']        = ( isset( $p[ $x[1] ]['rev'] ) ? $p[ $x[1] ]['rev'] : 0 ) + $x[3];
				$p[ $x[1] ]['qty']        = ( isset( $p[ $x[1] ]['qty'] ) ? $p[ $x[1] ]['qty'] : 0 ) + $x[2];
			}
			$o = array();
			foreach ( $p as $id => $v ) {
				$o[] = array( 'product_id' => $id, 'orders' => count( $v['o'] ), 'revenue' => $v['rev'], 'qty' => $v['qty'] );
			}
			usort( $o, function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			} );
			return $o;
		} );
		$db->on( 'SELECT order_id, product_id', array_map( function ( $x ) {
			return array( $x[0], $x[1] );
		}, $items ) );
		// the real query: the 300 products with the most order lines (ties by id, which MySQL leaves undefined: the tests avoid depending on it)
		$cnt = array();
		foreach ( $items as $x ) {
			$cnt[ $x[1] ] = ( isset( $cnt[ $x[1] ] ) ? $cnt[ $x[1] ] : 0 ) + 1;
		}
		uksort( $cnt, function ( $a, $b ) use ( $cnt ) {
			return $cnt[ $b ] <=> $cnt[ $a ] ?: $a <=> $b;
		} );
		$db->on( 'SELECT product_id FROM', array_slice( array_keys( $cnt ), 0, 300 ) );
		return CRDR_Analytics::build();
	}

	/** Month key "1405-07" the way the monthly series labels it. */
	public static function month_key( $idx ) {
		return sprintf( '%d-%02d', intdiv( $idx, 12 ), $idx % 12 + 1 );
	}

	public static function monthly( array $stats ) {
		$o = array();
		foreach ( $stats['monthly'] as $m ) {
			$o[ self::month_key( $m['idx'] ) ] = $m;
		}
		return $o;
	}

	/** Full text of a plugin source file (for the few tests that must look at code structure, see README). */
	public static function source( $file ) {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $file );
	}
}

$GLOBALS['wpdb'] = new CRDR_T_DB();
