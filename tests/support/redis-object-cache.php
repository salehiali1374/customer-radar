<?php
/**
 * TEST DOUBLE of a persistent object cache (Redis), for validating Customer Radar against a persistent cache. NOT a product and not
 * the "Redis Object Cache" plugin: a ~100-line drop-in that speaks the Redis protocol over a socket (the test image has no PHP Redis
 * extension) with the same observable behaviour that matters here: a per-request runtime cache in front of a store shared by every
 * request and process, entries surviving between requests, non-persistent groups, add() = SET NX, group/prefix keys.
 */
defined( 'ABSPATH' ) || exit;

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->add( $key, $data, $group, (int) $expire ); }
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) { $v = array(); foreach ( $data as $k => $d ) { $v[ $k ] = wp_cache_add( $k, $d, $group, $expire ); } return $v; }
function wp_cache_close() { return true; }
function wp_cache_decr( $key, $offset = 1, $group = '' ) { global $wp_object_cache; return $wp_object_cache->decr( $key, $offset, $group ); }
function wp_cache_delete( $key, $group = '' ) { global $wp_object_cache; return $wp_object_cache->delete( $key, $group ); }
function wp_cache_delete_multiple( array $keys, $group = '' ) { $v = array(); foreach ( $keys as $k ) { $v[ $k ] = wp_cache_delete( $k, $group ); } return $v; }
function wp_cache_flush() { global $wp_object_cache; return $wp_object_cache->flush(); }
function wp_cache_flush_group( $group ) { global $wp_object_cache; return $wp_object_cache->flush_group( $group ); }
function wp_cache_flush_runtime() { global $wp_object_cache; return $wp_object_cache->flush_runtime(); }
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { global $wp_object_cache; return $wp_object_cache->get( $key, $group, $force, $found ); }
function wp_cache_get_multiple( $keys, $group = '', $force = false ) { $v = array(); foreach ( $keys as $k ) { $v[ $k ] = wp_cache_get( $k, $group, $force ); } return $v; }
function wp_cache_incr( $key, $offset = 1, $group = '' ) { global $wp_object_cache; return $wp_object_cache->incr( $key, $offset, $group ); }
function wp_cache_init() { $GLOBALS['wp_object_cache'] = new WP_Object_Cache(); }
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->replace( $key, $data, $group, (int) $expire ); }
function wp_cache_reset() { _deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' ); return true; }
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->set( $key, $data, $group, (int) $expire ); }
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) { $v = array(); foreach ( $data as $k => $d ) { $v[ $k ] = wp_cache_set( $k, $d, $group, $expire ); } return $v; }
function wp_cache_switch_to_blog( $blog_id ) { global $wp_object_cache; $wp_object_cache->switch_to_blog( $blog_id ); }
function wp_cache_add_global_groups( $groups ) { global $wp_object_cache; $wp_object_cache->add_global_groups( $groups ); }
function wp_cache_add_non_persistent_groups( $groups ) { global $wp_object_cache; $wp_object_cache->add_non_persistent_groups( $groups ); }
function wp_cache_supports( $feature ) { return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ), true ); }

class WP_Object_Cache {
	private $sock;
	private $runtime = array();
	private $global_groups = array();
	private $non_persistent = array( 'counts' => 1, 'plugins' => 1 );
	private $blog = 1;
	public $cache_hits = 0;
	public $cache_misses = 0;

	public function __construct() {
		$this->sock = @stream_socket_client( 'tcp://crdr-p6-redis:6379', $e, $s, 2 );
		if ( $this->sock ) {
			stream_set_timeout( $this->sock, 2 );
		}
	}

	private function cmd() {
		if ( ! $this->sock ) {
			return null;
		}
		$a   = func_get_args();
		$out = '*' . count( $a ) . "\r\n";
		foreach ( $a as $x ) {
			$x    = (string) $x;
			$out .= '$' . strlen( $x ) . "\r\n" . $x . "\r\n";
		}
		fwrite( $this->sock, $out );
		return $this->reply();
	}

	private function reply() {
		$l = fgets( $this->sock );
		if ( false === $l ) {
			return null;
		}
		$t = $l[0];
		$r = rtrim( substr( $l, 1 ), "\r\n" );
		if ( '+' === $t || ':' === $t ) {
			return ':' === $t ? (int) $r : $r;
		}
		if ( '$' === $t ) {
			if ( '-1' === $r ) {
				return null;
			}
			$n = (int) $r;
			$d = '';
			while ( strlen( $d ) < $n + 2 ) {
				$d .= fread( $this->sock, $n + 2 - strlen( $d ) );
			}
			return substr( $d, 0, $n );
		}
		return null; // errors / arrays are not used here
	}

	private function k( $key, $group ) {
		$group = $group ? $group : 'default';
		$b     = isset( $this->global_groups[ $group ] ) ? 0 : $this->blog;
		return array( $group, "crdr:{$b}:{$group}:{$key}" );
	}

	private function persistent( $group ) {
		return ! isset( $this->non_persistent[ $group ] );
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		list( $g, $rk ) = $this->k( $key, $group );
		if ( isset( $this->runtime[ $rk ] ) ) {
			return false;
		}
		if ( $this->persistent( $g ) && $this->sock ) {
			$args = array( 'SET', $rk, serialize( $data ), 'NX' );
			if ( $expire > 0 ) {
				array_push( $args, 'EX', $expire );
			}
			if ( null === call_user_func_array( array( $this, 'cmd' ), $args ) ) {
				return false; // key exists in the shared store
			}
		}
		$this->runtime[ $rk ] = is_object( $data ) ? clone $data : $data;
		return true;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		list( $g, $rk ) = $this->k( $key, $group );
		$this->runtime[ $rk ] = is_object( $data ) ? clone $data : $data;
		if ( $this->persistent( $g ) && $this->sock ) {
			$args = array( 'SET', $rk, serialize( $data ) );
			if ( $expire > 0 ) {
				array_push( $args, 'EX', $expire );
			}
			call_user_func_array( array( $this, 'cmd' ), $args );
		}
		return true;
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$f = false;
		$this->get( $key, $group, false, $f );
		return $f ? $this->set( $key, $data, $group, $expire ) : false;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		list( $g, $rk ) = $this->k( $key, $group );
		if ( ! $force && array_key_exists( $rk, $this->runtime ) ) {
			$found = true;
			$this->cache_hits++;
			return is_object( $this->runtime[ $rk ] ) ? clone $this->runtime[ $rk ] : $this->runtime[ $rk ];
		}
		if ( $this->persistent( $g ) && $this->sock ) {
			$v = $this->cmd( 'GET', $rk );
			if ( null !== $v ) {
				$found                = true;
				$this->cache_hits++;
				$this->runtime[ $rk ] = unserialize( $v );
				return is_object( $this->runtime[ $rk ] ) ? clone $this->runtime[ $rk ] : $this->runtime[ $rk ];
			}
		}
		$found = false;
		$this->cache_misses++;
		return false;
	}

	public function delete( $key, $group = 'default' ) {
		list( $g, $rk ) = $this->k( $key, $group );
		$had = array_key_exists( $rk, $this->runtime );
		unset( $this->runtime[ $rk ] );
		if ( $this->persistent( $g ) && $this->sock ) {
			return (int) $this->cmd( 'DEL', $rk ) > 0 || $had;
		}
		return $had;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$f = false;
		$v = $this->get( $key, $group, true, $f );
		if ( ! $f ) {
			return false;
		}
		$v = max( 0, (int) $v + (int) $offset );
		$this->set( $key, $v, $group );
		return $v;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->incr( $key, -$offset, $group );
	}

	public function flush() {
		$this->runtime = array();
		if ( $this->sock ) {
			$this->cmd( 'FLUSHDB' );
		}
		return true;
	}

	public function flush_group( $group ) {
		foreach ( array_keys( $this->runtime ) as $rk ) {
			if ( false !== strpos( $rk, ":{$group}:" ) ) {
				unset( $this->runtime[ $rk ] );
			}
		}
		return true;
	}

	public function flush_runtime() {
		$this->runtime = array();
		return true;
	}

	public function add_global_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->global_groups[ $g ] = 1;
		}
	}

	public function add_non_persistent_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->non_persistent[ $g ] = 1;
		}
	}

	public function switch_to_blog( $id ) {
		$this->blog = (int) $id;
	}

	public function stats() {}
}
