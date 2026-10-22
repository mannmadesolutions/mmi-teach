<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out which Wisconsin county a posting is in, from its employer and school names.
 *
 * Reference data (data/places.json, built by `wp mmi-teach build-places`) holds every Wisconsin
 * public district and school (NCES Common Core of Data) and every private school (NCES Private
 * School Survey) with its county. data/employers.json holds hand-checked answers for employers
 * whose names match nothing there (networks, agencies, renamed schools). Overrides saved on the
 * settings screen win over both.
 *
 * Resolution order, first hit wins:
 *  1. override        – settings screen, then data/employers.json
 *  2. district-school – employer is a district and the school is one of its buildings
 *                       (multi-county districts resolve to the building's county)
 *  3. district        – employer is a district
 *  4. address         – the school field holds a ZIP code or "City, WI", or starts with a
 *                       city ("Racine - Green St."), or either name ends in "(City)"; an
 *                       explicit place beats a name that happens to match a school elsewhere
 *  5. school          – employer is itself a school (most private and charter employers)
 *  6. location        – the school/building name matches exactly one school statewide
 */
final class Places {

	private static ?Places $instance = null;

	/** @var array<string, string> normalized district name => county code */
	private array $districts = array();
	/** @var array<string, string> district name as NCES writes it => county code */
	private array $district_names = array();
	/** @var array<string, array<string, true>> normalized school name => county codes */
	private array $schools = array();
	/** @var array<string, true> normalized names of private schools (NCES Private School Survey) */
	private array $private = array();
	/** @var array<string, string> normalized district + '|' + school => county code */
	private array $district_schools = array();
	/** @var array<string, string> lowercase city => county code */
	private array $cities = array();
	/** @var array<string, string> 5-digit ZIP => county code */
	private array $zips = array();
	/** @var array<string, string> county code => county name */
	private array $county_names = array();
	/** @var array<string, string> normalized employer => county code | 'multi' | 'out' */
	private array $overrides = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Drops the cached instance (after overrides or reference data change). */
	public static function reset(): void {
		self::$instance = null;
	}

	private function __construct() {
		$data = self::read_json( MMI_TEACH_DIR . 'data/places.json' );
		foreach ( $data['districts'] ?? array() as [ $name, $code ] ) {
			$this->districts[ self::normalize( $name ) ] = $code;
			$this->district_names[ $name ]               = $code;
		}
		foreach ( $data['schools'] ?? array() as [ $name, $code, $district ] ) {
			$key                               = self::normalize( $name );
			$this->schools[ $key ][ $code ]    = true;
			if ( '' !== $district ) {
				$this->district_schools[ self::normalize( $district ) . '|' . $key ] = $code;
			} else {
				$this->private[ $key ] = true; // Private-survey schools carry no district.
			}
		}
		$this->cities       = $data['cities'] ?? array();
		$this->zips         = $data['zips'] ?? array();
		$this->county_names = $data['counties'] ?? array();

		$curated = self::read_json( MMI_TEACH_DIR . 'data/employers.json' );
		foreach ( $curated['employers'] ?? array() as $name => $code ) {
			$this->overrides[ self::normalize( $name ) ] = $code;
		}
		foreach ( Plugin::settings()['employer_overrides'] as $key => $code ) {
			$this->overrides[ $key ] = $code;
		}
	}

	/**
	 * @return array{code: string, method: string} code is a county FIPS code, 'out' (known to be
	 *                                              outside Wisconsin's county list) or '' (unknown).
	 */
	public function resolve( string $employer, string $location ): array {
		$emp = self::normalize( $employer );
		$loc = self::normalize( $location );

		$override = $this->overrides[ $emp ] ?? null;
		if ( null !== $override && 'multi' !== $override ) {
			return array( 'code' => $override, 'method' => 'override' );
		}

		if ( null === $override && isset( $this->districts[ $emp ] ) ) {
			if ( '' !== $loc && isset( $this->district_schools[ $emp . '|' . $loc ] ) ) {
				return array( 'code' => $this->district_schools[ $emp . '|' . $loc ], 'method' => 'district-school' );
			}
			return array( 'code' => $this->districts[ $emp ], 'method' => 'district' );
		}

		$code = $this->address_county( $location, $employer );
		if ( $code ) {
			return array( 'code' => $code, 'method' => 'address' );
		}

		if ( null === $override ) {
			$code = $this->unique_school_county( $emp );
			if ( $code ) {
				return array( 'code' => $code, 'method' => 'school' );
			}
		}

		$code = $this->unique_school_county( $loc );
		if ( $code ) {
			return array( 'code' => $code, 'method' => 'location' );
		}

		return array( 'code' => '', 'method' => 'unknown' );
	}

	/**
	 * public (a school district or district-run school), private (in the Private School Survey)
	 * or other (independent charters, agencies, networks the directories don't name).
	 */
	public function sector( string $employer ): string {
		$key = self::normalize( $employer );
		if ( isset( $this->districts[ $key ] ) ) {
			return 'public';
		}
		return isset( $this->private[ $key ] ) ? 'private' : 'other';
	}

	/** @return array<string, string> NCES district name => county code, for districts in the given counties. */
	public function districts_in( array $codes ): array {
		$codes = array_map( 'strval', $codes ); // County codes arrive as integer array keys.
		return array_filter( $this->district_names, static fn( $code ) => in_array( (string) $code, $codes, true ) );
	}

	public function county_name( string $code ): string {
		return $this->county_names[ $code ] ?? '';
	}

	private function address_county( string $location, string $employer ): string {
		if ( preg_match( '/\b(5[34]\d{3})\b/', $location, $m ) && isset( $this->zips[ $m[1] ] ) ) {
			return $this->zips[ $m[1] ];
		}
		$candidates = array();
		if ( preg_match( '/([a-z][a-z .]+?),\s*(wi|wisconsin)\b/i', $location, $m ) ) {
			$candidates[] = $m[1];
		}
		if ( preg_match( '/^([a-z][a-z .]+?)\s*[-–:,]/i', $location, $m ) ) {
			$candidates[] = $m[1];
		}
		foreach ( array( $location, $employer ) as $text ) {
			if ( preg_match( '/\(([^()]+)\)\s*$/', $text, $m ) ) {
				$candidates[] = $m[1];
			}
		}
		foreach ( $candidates as $city ) {
			$city = strtolower( trim( preg_replace( '/^.*\d+\s+[^,]*?\b(st|street|ave|avenue|rd|road|blvd|dr|drive|ln|way|ct)\.?\s+/i', '', $city ) ) );
			if ( isset( $this->cities[ $city ] ) ) {
				return $this->cities[ $city ];
			}
		}
		return '';
	}

	private function unique_school_county( string $key ): string {
		if ( '' === $key || ! isset( $this->schools[ $key ] ) || count( $this->schools[ $key ] ) !== 1 ) {
			return '';
		}
		return (string) array_key_first( $this->schools[ $key ] );
	}

	/**
	 * Reduces a school or district name to the words that identify it, so "School District of
	 * Waukesha", "Waukesha School District" and "WAUKESHA SCHOOL DIST" all become "waukesha".
	 */
	public static function normalize( string $name ): string {
		$s = strtolower( html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$s = str_replace( array( '&', '’', "'" ), array( ' and ', '', '' ), $s );
		$s = preg_replace( '/\bst\b\.?/', 'saint', $s );
		$s = preg_replace( '/\bmt\b\.?/', 'mount', $s );
		$s = preg_replace( '/\bdist\b\.?/', 'district', $s );
		$s = preg_replace( '/\bjt\b\.?/', 'joint', $s );
		$s = preg_replace( '/\bunion high( school)?\b|\bunion\s*$/', 'uhs', trim( $s ) ); // "Arrowhead Union High School" = NCES "Arrowhead UHS".
		$s = preg_replace( '/#\s*\d+|\bno\.?\s*\d+\b/', ' ', $s );                   // "Yorkville Jt. #2" = NCES "Yorkville J2".
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		$s = preg_replace(
			'/\b(the|of|inc|school|schools|district|public|area|community|unified|consolidated|joint|elementary|elem|sch|sd|no|j\d+|jt\d+)\b/',
			' ',
			$s
		);
		// Spaces go too, so "DeForest" and "De Forest" agree.
		return preg_replace( '/\s+/', '', $s );
	}

	private static function read_json( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		return is_array( $data ) ? $data : array();
	}
}
