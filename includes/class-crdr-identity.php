<?php
defined( 'ABSPATH' ) || exit;

/**
 * Who is a "customer"? Pure functions (no WordPress, no database), so every rule is unit-tested.
 *
 * Step 1, per order (CRDR_Sync::extract): take the identifiers out of the order and give the order a PROVISIONAL key, by the
 * first that is usable:   p:<phone>  >  e:<email>  >  u:<user id>  >  o:<order id>.
 *   - a phone is usable when it normalises to a mobile number (09xxxxxxxxx);
 *   - an email is usable when it is a valid address and is NOT an anonymisation placeholder (deleted@site.invalid, anything
 *     under the reserved .invalid domain): a placeholder is "no email", exactly like an empty field;
 *   - an order with none of those is its own customer (o:<id>): it is never merged with another order.
 *
 * Step 2, once all orders are in (CRDR_Sync, phase "finalize"): provisional customers that are shown to be the SAME PERSON are
 * joined (resolve()). Nothing is ever split, and a join needs evidence that two different people cannot produce:
 *   R1  same registered user id                     -> same person (an account is one person).
 *   R2  same valid email address                    -> same person, when the address is not ambiguous:
 *         - used by two or more different registered users: ambiguous, never links;
 *         - used with more than EMAIL_MAX_PHONES different phone numbers (default 2): a shared mailbox (an office, an agency, the
 *           shop's own address typed for phone orders), never links.
 *   R3  a join that would put two different registered users in one customer is refused.
 *   Same phone number is already one customer from step 1 (p:<phone>): a number is what an SMS is sent to, so one number is one
 *   recipient whoever typed it. Two different phone numbers are NEVER joined by anything but R1 or R2.
 * Not evidence: the same name, the same address, the same IP, similar numbers.
 *
 * Joins are STICKY. A rule such as "an email with more than two phones is a shared mailbox" depends on the whole order history, so a
 * person who gets a third phone could flip from "one customer" back to "three", and the old numbers would come back as customers
 * with no opt-out and no cooldown. Every join that was applied is therefore remembered (CRDR_Sync keeps it in the option
 * crdr_identity_links) and applied first, whatever the rules say today. Only new joins follow the rules. A join that is wrong is
 * repaired with CRDR_Sync::unjoin() (docs/customer-identity.md). Do NOT delete the option: every join the rules no longer support would fall
 * apart at the next rebuild, with its cooldown history left on the joined key.
 *
 * What cannot be known from order data is listed in docs/customer-identity.md (two people who share one email and two phones look
 * exactly like one person who changed numbers; a person who changes both phone and email and has no account is two customers).
 */
class CRDR_Identity {

	/** An email linked to more than this many different phones is a shared mailbox, not a person. Filter: crdr_identity_email_max_phones. */
	const EMAIL_MAX_PHONES = 2;

	/** Kind of key, strongest first (decides which member's key a joined customer keeps). */
	const KIND_RANK = array( 'p' => 0, 'e' => 1, 'u' => 2, 'o' => 3 );

	/**
	 * Normalise an Iranian mobile number to 09xxxxxxxxx. Returns '' if it is not a mobile number.
	 */
	public static function phone( $raw ) {
		$s = CRDR_Jalali::latin_digits( (string) $raw );
		$s = preg_replace( '/\D+/', '', $s );
		if ( 0 === strpos( $s, '0098' ) ) {
			$s = '0' . substr( $s, 4 );
		} elseif ( 0 === strpos( $s, '98' ) && 12 === strlen( $s ) ) {
			$s = '0' . substr( $s, 2 );
		} elseif ( 10 === strlen( $s ) && 0 === strpos( $s, '9' ) ) {
			$s = '0' . $s;
		}
		return preg_match( '/^09\d{9}$/', $s ) ? $s : '';
	}

	/** The address text WordPress / WooCommerce write when they erase a person, or any address under the reserved .invalid domain. */
	public static function is_placeholder_email( $email ) {
		$e = strtolower( trim( (string) $email ) );
		if ( '' === $e ) {
			return false;
		}
		$known = apply_filters( 'crdr_placeholder_emails', array( 'deleted@site.invalid' ) );
		if ( in_array( $e, array_map( 'strtolower', (array) $known ), true ) ) {
			return true;
		}
		$at = strrpos( $e, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain = substr( $e, $at + 1 ); // the reserved .invalid top-level domain (RFC 2606) can never be a real mailbox
		return 'invalid' === $domain || '.invalid' === substr( $domain, -8 );
	}

	/** A usable email: lower-case, valid, not a placeholder. '' otherwise. */
	public static function email( $raw ) {
		$e = strtolower( trim( (string) $raw ) );
		if ( '' === $e || ! is_email( $e ) || self::is_placeholder_email( $e ) ) {
			return '';
		}
		return $e;
	}

	/**
	 * The provisional key of one order.
	 *
	 * @param string $phone usable phone or ''
	 * @param string $email usable email or ''
	 */
	public static function provisional_key( $phone, $email, $uid, $order_id ) {
		if ( '' !== $phone ) {
			return 'p:' . $phone;
		}
		if ( '' !== $email ) {
			return 'e:' . $email;
		}
		if ( (int) $uid > 0 ) {
			return 'u:' . (int) $uid;
		}
		return 'o:' . (int) $order_id;
	}

	/** An order that could not be tied to anybody (no phone, no email, no account): its key is o:<order id>. */
	public static function is_unidentified( $ckey ) {
		return 0 === strpos( (string) $ckey, 'o:' );
	}

	private static function rank( $ckey ) {
		$k = substr( (string) $ckey, 0, 1 );
		return isset( self::KIND_RANK[ $k ] ) ? self::KIND_RANK[ $k ] : 9;
	}

	/** A customer key as this class writes it: p:/e: followed by the value, u:/o: followed by a number. At most 100 characters (the column). */
	public static function valid_key( $key ) {
		return is_string( $key ) && strlen( $key ) <= 100 && 1 === preg_match( '/^(?:[pe]:\S{1,98}|[uo]:\d{1,20})$/', $key );
	}

	/**
	 * The remembered joins as read from the database, made safe: only "valid key => other valid key", never a key joined to itself.
	 * Anything else (an array or a number as a value, a key of no known kind, an option that is not an array at all) is dropped and counted,
	 * so a damaged or hand-edited option can never make a rebuild fail or join the wrong customers.
	 *
	 * @return array [ 'links' => clean map, 'dropped' => number of entries that were not usable ]
	 */
	public static function clean_links( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array( 'links' => array(), 'dropped' => false === $raw ? 0 : 1 );
		}
		$out     = array();
		$dropped = 0;
		foreach ( $raw as $a => $b ) {
			$a = (string) $a;
			if ( ! self::valid_key( $a ) || ! self::valid_key( $b ) || $a === $b ) {
				$dropped++;
				continue;
			}
			$out[ $a ] = $b;
		}
		ksort( $out );
		return array( 'links' => $out, 'dropped' => $dropped );
	}

	/** A list of keys (the "kept apart" list) made safe: valid, unique, sorted. */
	public static function clean_keys( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $k ) {
			if ( self::valid_key( $k ) ) {
				$out[ $k ] = true;
			}
		}
		$out = array_keys( $out );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The groups of keys that are connected by these joins (alias => key it was joined with), each group sorted.
	 *
	 * @param array ...$maps one or several maps of joins; they are combined
	 * @return array list of lists of keys, ordered by their first key
	 */
	public static function components( array ...$maps ) {
		$parent = array();
		$find   = function ( $k ) use ( &$parent ) {
			while ( $parent[ $k ] !== $k ) {
				$parent[ $k ] = $parent[ $parent[ $k ] ];
				$k            = $parent[ $k ];
			}
			return $k;
		};
		foreach ( $maps as $pairs ) {
			foreach ( $pairs as $a => $b ) {
				$a = (string) $a;
				$b = (string) $b;
				foreach ( array( $a, $b ) as $k ) {
					if ( ! isset( $parent[ $k ] ) ) {
						$parent[ $k ] = $k;
					}
				}
				$ra = $find( $a );
				$rb = $find( $b );
				if ( $ra !== $rb ) {
					$lo            = strcmp( $ra, $rb ) < 0 ? $ra : $rb;
					$parent[ $lo === $ra ? $rb : $ra ] = $lo;
				}
			}
		}
		$groups = array();
		foreach ( $parent as $k => $_ ) {
			$groups[ $find( (string) $k ) ][] = (string) $k;
		}
		foreach ( $groups as &$g ) {
			sort( $g, SORT_STRING );
		}
		unset( $g );
		ksort( $groups );
		return array_values( $groups );
	}

	/** All the keys of the person this key belongs to according to the remembered joins (the key itself when it was never joined). */
	public static function group_of( $ckey, array $links ) {
		$ckey = (string) $ckey;
		foreach ( self::components( self::clean_links( $links )['links'] ) as $g ) {
			if ( in_array( $ckey, $g, true ) ) {
				return $g;
			}
		}
		return array( $ckey );
	}

	/**
	 * Decide which provisional customers are one person.
	 *
	 * @param array $clusters ckey => last_ts   every provisional customer
	 * @param array $evidence list of [ ckey, uid, email ]   what its orders carried (usable email or '', uid or 0); duplicates are fine
	 * @param mixed $links    joins applied by earlier rebuilds, member ckey => ckey it was joined with (kept joined whatever the rules say now);
	 *                        read defensively, see clean_links()
	 * @param mixed $apart    keys that must never be joined to anybody (see CRDR_Sync::unjoin)
	 * @return array [
	 *   'map'    => alias ckey => canonical ckey (only for customers that are joined into another),
	 *   'links'  => the joins to remember for the next rebuild (every member of every joined customer => its canonical key),
	 *   'report' => counts: joined (customers removed by joining), people (customers that absorbed others), emails_shared,
	 *               emails_contested, refused (joins refused because they would merge two registered users), unidentified (o: customers)
	 * ]
	 */
	public static function resolve( array $clusters, array $evidence, $links = array(), $apart = array() ) {
		$cleaned = self::clean_links( $links );
		$links   = $cleaned['links'];
		$apart   = array_flip( self::clean_keys( $apart ) ); // customers the owner separated on purpose: nothing joins them
		$max_phones = (int) apply_filters( 'crdr_identity_email_max_phones', self::EMAIL_MAX_PHONES );

		$uids_of    = array(); // ckey => set of user ids
		$by_uid     = array(); // uid  => set of ckeys
		$by_email   = array(); // email => set of ckeys
		$email_uids = array(); // email => set of user ids
		foreach ( $evidence as $e ) {
			list( $ckey, $uid, $email ) = array( (string) $e[0], (int) $e[1], (string) $e[2] );
			if ( ! isset( $clusters[ $ckey ] ) || isset( $apart[ $ckey ] ) ) {
				continue;
			}
			if ( $uid > 0 ) {
				$uids_of[ $ckey ][ $uid ] = true;
				$by_uid[ $uid ][ $ckey ]  = true;
			}
			if ( '' !== $email ) {
				$by_email[ $email ][ $ckey ] = true;
				if ( $uid > 0 ) {
					$email_uids[ $email ][ $uid ] = true;
				}
			}
		}

		// Union-find over provisional keys. The root is always the smallest key, so the result does not depend on input order.
		$parent = array();
		$uids   = array();
		foreach ( $clusters as $k => $_ ) {
			$parent[ $k ] = $k;
			$uids[ $k ]   = isset( $uids_of[ $k ] ) ? $uids_of[ $k ] : array();
		}
		$find = function ( $k ) use ( &$parent ) {
			while ( $parent[ $k ] !== $k ) {
				$parent[ $k ] = $parent[ $parent[ $k ] ];
				$k            = $parent[ $k ];
			}
			return $k;
		};
		$refused = 0;
		$union   = function ( $a, $b ) use ( &$parent, &$uids, $find, &$refused ) {
			$ra = $find( $a );
			$rb = $find( $b );
			if ( $ra === $rb ) {
				return;
			}
			if ( $uids[ $ra ] && $uids[ $rb ] && ! array_intersect_key( $uids[ $ra ], $uids[ $rb ] ) ) {
				$refused++; // R3: two different registered users
				return;
			}
			$lo            = strcmp( $ra, $rb ) < 0 ? $ra : $rb;
			$hi            = $lo === $ra ? $rb : $ra;
			$parent[ $hi ] = $lo;
			$uids[ $lo ]  += $uids[ $hi ];
		};

		// Joins made earlier stay (see the class comment). A member that has no orders any more is simply not there.
		ksort( $links );
		foreach ( $links as $a => $b ) {
			if ( isset( $clusters[ $a ], $clusters[ $b ] ) && ! isset( $apart[ $a ] ) && ! isset( $apart[ $b ] ) ) {
				$ra = $find( (string) $a );
				$rb = $find( (string) $b );
				if ( $ra !== $rb ) {
					$lo            = strcmp( $ra, $rb ) < 0 ? $ra : $rb;
					$hi            = $lo === $ra ? $rb : $ra;
					$parent[ $hi ] = $lo;
					$uids[ $lo ]  += $uids[ $hi ];
				}
			}
		}
		// R1: the same registered user
		ksort( $by_uid );
		foreach ( $by_uid as $keys ) {
			$keys = array_keys( $keys );
			sort( $keys, SORT_STRING );
			for ( $i = 1; $i < count( $keys ); $i++ ) {
				$union( $keys[0], $keys[ $i ] );
			}
		}
		// R2: the same email, unless it is ambiguous
		ksort( $by_email );
		$shared    = 0;
		$contested = 0;
		foreach ( $by_email as $email => $keys ) {
			if ( isset( $email_uids[ $email ] ) && count( $email_uids[ $email ] ) >= 2 ) {
				$contested++;
				continue;
			}
			$phones = 0;
			foreach ( $keys as $k => $_ ) {
				if ( 'p' === substr( (string) $k, 0, 1 ) ) {
					$phones++;
				}
			}
			if ( $phones > $max_phones ) {
				$shared++;
				continue;
			}
			$keys = array_map( 'strval', array_keys( $keys ) );
			sort( $keys, SORT_STRING );
			for ( $i = 1; $i < count( $keys ); $i++ ) {
				$union( $keys[0], $keys[ $i ] );
			}
		}

		// Components -> the key each joined customer keeps: strongest kind, then the most recent order, then the smaller key.
		$groups = array();
		foreach ( $clusters as $k => $_ ) {
			$groups[ $find( $k ) ][] = $k;
		}
		$map    = array();
		$keep   = array();
		$people = 0;
		foreach ( $groups as $members ) {
			if ( count( $members ) < 2 ) {
				continue;
			}
			usort( $members, function ( $a, $b ) use ( $clusters ) {
				$r = self::rank( $a ) <=> self::rank( $b );
				if ( 0 !== $r ) {
					return $r;
				}
				$t = (int) $clusters[ $b ] <=> (int) $clusters[ $a ];
				return 0 !== $t ? $t : strcmp( $a, $b );
			} );
			$canon = array_shift( $members );
			foreach ( $members as $alias ) {
				$map[ $alias ]  = $canon;
				$keep[ $alias ] = $canon;
			}
			$people++;
		}
		$unidentified = 0;
		foreach ( $clusters as $k => $_ ) {
			if ( self::is_unidentified( $k ) ) {
				$unidentified++;
			}
		}
		ksort( $keep );
		ksort( $map );
		return array(
			'map'    => $map,
			'links'  => $keep,
			'report' => array(
				'joined'           => count( $map ),
				'people'           => $people,
				'emails_shared'    => $shared,
				'emails_contested' => $contested,
				'refused'          => $refused,
				'unidentified'     => $unidentified,
				'links_dropped'    => $cleaned['dropped'],
			),
		);
	}
}
