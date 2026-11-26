<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A visitor's answers (education, credential, license areas…) and checklist progress.
 *
 * Guests keep both in first-party cookies; signed-in applicants in user meta, and whatever a
 * guest saved is merged into the account on sign-up or sign-in. A sample persona is an overlay
 * cookie: while one is chosen the site shows the persona's answers, and the visitor's own are
 * left untouched. Every value is checked against a fixed list; there is no free text.
 */
final class Profile {

	public const COOKIE         = 'mt_profile';
	public const COOKIE_TASKS   = 'mt_tasks';
	public const COOKIE_PERSONA = 'mt_persona';
	public const META           = 'mt_profile';
	public const META_TASKS     = 'mt_tasks';
	private const TTL           = 180 * DAY_IN_SECONDS;

	/** Highest education, lowest first. */
	public const EDU = array(
		'none'         => 'No high-school diploma yet',
		'high_school'  => 'High-school diploma or GED/HSED',
		'some_college' => 'Some college (under 48 credits)',
		'credits48'    => '48 or more college credits',
		'associate'    => 'Associate degree',
		'bachelor'     => 'Bachelor’s degree',
		'master'       => 'Master’s degree or higher',
	);

	/** The DPI credential held now, from the quickest to get to the fullest. */
	public const CRED = array(
		'none'           => 'None yet',
		'sepa'           => 'Special Education Program Aide license',
		'permit'         => 'Wisconsin substitute permit (3-year)',
		'permit_expired' => 'Wisconsin substitute permit, now expired',
		'long_term'      => 'Wisconsin five-year substitute license',
		'wi_license'     => 'Wisconsin teaching license (any tier)',
		'expired'        => 'Wisconsin teaching license, now expired',
		'oos'            => 'Teaching license from another state',
	);

	public const AGES = array(
		'u18' => 'Under 18',
		'18'  => '18 or 19',
		'20'  => '20 or older',
	);

	public const ROLES = Classifier::ROLES;

	/** Task states stored per checklist item. */
	public const STATES = array( 'done', 'na' );

	private static ?array $personas = null;

	/** License areas offered in the check: the common ones from data/licenses.json. */
	public static function areas(): array {
		$keys = array( 'ec_regular', 'elem_middle', 'ela', 'math', 'science', 'social_studies', 'world_languages', 'esl', 'bilingual', 'art', 'music', 'pe', 'health', 'cross_cat', 'ecse', 'reading_teacher', 'tech_ed', 'business_ed', 'library_media' );
		$tax  = Requirements::taxonomy()['licenses'] ?? array();
		$out  = array();
		foreach ( $keys as $key ) {
			if ( isset( $tax[ $key ] ) ) {
				$out[ $key ] = $tax[ $key ]['short'] ?? $tax[ $key ]['title'];
			}
		}
		return $out;
	}

	/** Keeps only known values; anything else is dropped. */
	public static function sanitize( array $raw ): array {
		$pick  = static fn( $value, array $allowed, $fallback ) => is_scalar( $value ) && isset( $allowed[ (string) $value ] ) ? (string) $value : $fallback;
		$areas = array_values( array_intersect( array_map( 'strval', (array) ( $raw['areas'] ?? array() ) ), array_keys( self::areas() ) ) );
		$roles = array_values( array_intersect( array_map( 'strval', (array) ( $raw['roles'] ?? array() ) ), array_keys( self::ROLES ) ) );
		return array(
			'edu'        => $pick( $raw['edu'] ?? '', self::EDU, 'high_school' ),
			'cred'       => $pick( $raw['cred'] ?? '', self::CRED, 'none' ),
			'areas'      => $areas,
			'epp'        => empty( $raw['epp'] ) ? 0 : 1,
			'age'        => $pick( $raw['age'] ?? '', self::AGES, '20' ),
			'outside_wi' => empty( $raw['outside_wi'] ) ? 0 : 1,
			'roles'      => $roles ?: array_keys( self::ROLES ),
		);
	}

	/** Task states: id => [state, date, expiry, where], with unknown ids, states and dates dropped. */
	public static function sanitize_tasks( array $raw ): array {
		$items = Rules::items();
		$where = Rules::where_options();
		$date  = static fn( $d ) => is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) ) ? $d : '';
		$out   = array();
		foreach ( $raw as $id => $t ) {
			$t = array_values( (array) $t );
			if ( ! isset( $items[ $id ] ) || ! in_array( $t[0] ?? '', self::STATES, true ) ) {
				continue;
			}
			$out[ $id ] = array( $t[0], $date( $t[1] ?? '' ), $date( $t[2] ?? '' ), isset( $where[ $t[3] ?? '' ] ) ? $t[3] : '' );
		}
		return $out;
	}

	/** The answers the site uses now: a chosen persona's, else the visitor's own, else null. */
	public static function current(): ?array {
		$persona = self::persona();
		if ( $persona ) {
			return self::sanitize( self::personas()[ $persona ]['profile'] );
		}
		return self::own();
	}

	/** The visitor's own answers (never a persona's), or null if they haven't answered. */
	public static function own(): ?array {
		$user = get_current_user_id();
		$raw  = $user ? get_user_meta( $user, self::META, true ) : self::read_cookie( self::COOKIE );
		return is_array( $raw ) && $raw ? self::sanitize( $raw ) : null;
	}

	public static function save( array $raw ): array {
		$profile = self::sanitize( $raw );
		$user    = get_current_user_id();
		if ( $user ) {
			update_user_meta( $user, self::META, $profile );
		} else {
			self::write_cookie( self::COOKIE, $profile );
		}
		self::set_persona( '' ); // Saving your own answers leaves sample mode.
		return $profile;
	}

	/** Checklist states in use now (a persona's while one is chosen). */
	public static function tasks(): array {
		$persona = self::persona();
		if ( $persona ) {
			return self::persona_tasks( $persona );
		}
		$user = get_current_user_id();
		$raw  = $user ? get_user_meta( $user, self::META_TASKS, true ) : self::read_cookie( self::COOKIE_TASKS );
		return is_array( $raw ) ? self::sanitize_tasks( $raw ) : array();
	}

	/**
	 * Sets one checklist item. A persona's list is read-only, so this writes only the visitor's own.
	 *
	 * @param string $state 'done', 'na' or 'todo' (clears it).
	 */
	public static function set_task( string $id, string $state, string $date = '', string $expiry = '', string $where = '' ): void {
		$user  = get_current_user_id();
		$raw   = $user ? get_user_meta( $user, self::META_TASKS, true ) : self::read_cookie( self::COOKIE_TASKS );
		$tasks = self::sanitize_tasks( is_array( $raw ) ? $raw : array() );
		if ( 'todo' === $state ) {
			unset( $tasks[ $id ] );
		} else {
			$tasks[ $id ] = array( $state, $date, $expiry, $where );
		}
		$tasks = self::sanitize_tasks( $tasks );
		if ( $user ) {
			update_user_meta( $user, self::META_TASKS, $tasks );
		} else {
			self::write_cookie( self::COOKIE_TASKS, $tasks );
		}
	}

	/** State of one item for display: [state, date, expiry, where], state 'todo' when unset or expired. */
	public static function task( string $id, ?array $tasks = null ): array {
		$t = ( $tasks ?? self::tasks() )[ $id ] ?? null;
		if ( ! $t ) {
			return array( 'todo', '', '', '' );
		}
		if ( 'done' === $t[0] && '' !== $t[2] && $t[2] < wp_date( 'Y-m-d' ) ) {
			return array( 'expired', $t[1], $t[2], $t[3] );
		}
		return $t;
	}

	/** Copies a guest's answers and checklist into the account they just created or signed in to. */
	public static function merge_into_user( int $user_id ): void {
		$profile = self::read_cookie( self::COOKIE );
		if ( $profile && ! get_user_meta( $user_id, self::META, true ) ) {
			update_user_meta( $user_id, self::META, self::sanitize( $profile ) );
		}
		$guest = self::read_cookie( self::COOKIE_TASKS );
		if ( $guest ) {
			$mine = get_user_meta( $user_id, self::META_TASKS, true );
			// The account's own entries win; the guest's fill the gaps.
			update_user_meta( $user_id, self::META_TASKS, self::sanitize_tasks( ( is_array( $mine ) ? $mine : array() ) + $guest ) );
		}
		self::write_cookie( self::COOKIE, null );
		self::write_cookie( self::COOKIE_TASKS, null );
	}

	/* ---------- sample personas ---------- */

	public static function personas(): array {
		if ( null === self::$personas ) {
			$raw            = json_decode( (string) file_get_contents( MMI_TEACH_DIR . 'data/personas.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			self::$personas = is_array( $raw['personas'] ?? null ) ? $raw['personas'] : array();
		}
		return self::$personas;
	}

	public static function persona(): string {
		$key = isset( $_COOKIE[ self::COOKIE_PERSONA ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE_PERSONA ] ) ) : '';
		return isset( self::personas()[ $key ] ) ? $key : '';
	}

	public static function set_persona( string $key ): void {
		$key = isset( self::personas()[ $key ] ) ? $key : '';
		self::set_cookie( self::COOKIE_PERSONA, $key, '' === $key ? -1 : self::TTL );
		if ( '' === $key ) {
			unset( $_COOKIE[ self::COOKIE_PERSONA ] );
		} else {
			$_COOKIE[ self::COOKIE_PERSONA ] = $key;
		}
	}

	/** A persona's task list with relative day offsets ("-60", "+1035") turned into dates. */
	private static function persona_tasks( string $key ): array {
		$out = array();
		foreach ( self::personas()[ $key ]['tasks'] ?? array() as $id => $t ) {
			$rel        = static fn( $v ) => preg_match( '/^[+-]\d+$/', (string) $v ) ? wp_date( 'Y-m-d', time() + (int) $v * DAY_IN_SECONDS ) : '';
			$out[ $id ] = array( $t[0], $rel( $t[1] ?? '' ), $rel( $t[2] ?? '' ), $t[3] ?? '' );
		}
		return self::sanitize_tasks( $out );
	}

	/** Short fingerprint of what the site is personalised on (for caches keyed by profile). */
	public static function hash(): string {
		return substr( md5( (string) wp_json_encode( array( self::current(), self::tasks() ) ) ), 0, 12 );
	}

	/* ---------- cookies ---------- */

	private static function read_cookie( string $name ): ?array {
		if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) || strlen( $_COOKIE[ $name ] ) > 3000 ) {
			return null;
		}
		$json = base64_decode( strtr( wp_unslash( $_COOKIE[ $name ] ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- cookie transport encoding.
		$data = false === $json ? null : json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/** @param array|null $data null deletes the cookie. */
	private static function write_cookie( string $name, ?array $data ): void {
		if ( null === $data ) {
			self::set_cookie( $name, '', -1 );
			unset( $_COOKIE[ $name ] );
			return;
		}
		$value = rtrim( strtr( base64_encode( (string) wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		self::set_cookie( $name, $value, self::TTL );
		$_COOKIE[ $name ] = $value; // Same-request reads see the new value.
	}

	private static function set_cookie( string $name, string $value, int $ttl ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $ttl > 0 ? time() + $ttl : time() - YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => self::secure(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/** HTTPS at the visitor's end; Cloudflare terminates TLS, so the origin may see plain HTTP. */
	private static function secure(): bool {
		$visitor = isset( $_SERVER['HTTP_CF_VISITOR'] ) ? (string) wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
		return is_ssl() || str_contains( $visitor, 'https' ) || 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' );
	}
}
