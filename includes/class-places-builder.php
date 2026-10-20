<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds data/places.json from free public NCES data:
 *  - Common Core of Data district and school directories, via the Urban Institute Education
 *    Data API (no key);
 *  - the Private School Survey public-use CSV from nces.ed.gov.
 * Run by hand (`wp mmi-teach build-places`) when NCES publishes a new year; the output is committed.
 */
final class Places_Builder {

	private const STATE_FIPS = '55';
	private const CCD_YEAR   = 2023;
	private const CCD_API    = 'https://educationdata.urban.org/api/v1/';
	private const PSS_ZIP    = 'https://nces.ed.gov/surveys/pss/zip/pss2324_pu_csv.zip';

	/** @return array{districts:int, schools:int, private:int, cities:int} */
	public function build( string $target ): array {
		$counties  = array();
		$districts = array();
		foreach ( $this->api_results( 'school-districts/ccd/directory/' . self::CCD_YEAR . '/?fips=' . self::STATE_FIPS ) as $d ) {
			$code = (string) ( $d['county_code'] ?? '' );
			if ( '' === $code || empty( $d['lea_name'] ) ) {
				continue;
			}
			$districts[] = array( $d['lea_name'], $code );
			if ( ! empty( $d['county_name'] ) ) {
				$counties[ $code ] = preg_replace( '/ County$/', '', $d['county_name'] );
			}
		}

		$schools     = array();
		$city_counts = array();
		$zip_counts  = array();
		foreach ( $this->api_results( 'schools/ccd/directory/' . self::CCD_YEAR . '/?fips=' . self::STATE_FIPS ) as $s ) {
			$code = (string) ( $s['county_code'] ?? '' );
			if ( '' === $code || empty( $s['school_name'] ) ) {
				continue;
			}
			$schools[] = array( $s['school_name'], $code, (string) ( $s['lea_name'] ?? '' ) );
			$this->count_city( $city_counts, (string) ( $s['city_location'] ?? '' ), $code );
			$this->count_city( $zip_counts, substr( (string) ( $s['zip_location'] ?? '' ), 0, 5 ), $code );
		}

		$private = 0;
		foreach ( $this->private_schools() as [ $name, $code, $city, $zip ] ) {
			$schools[] = array( $name, $code, '' );
			$this->count_city( $city_counts, $city, $code );
			$this->count_city( $zip_counts, $zip, $code );
			++$private;
		}

		$cities = self::majority( $city_counts );
		$zips   = self::majority( $zip_counts );
		ksort( $counties );

		$json = wp_json_encode(
			array(
				'built'     => gmdate( 'Y-m-d' ),
				'sources'   => array(
					'districts_schools' => 'NCES Common Core of Data ' . self::CCD_YEAR . ' via Urban Institute Education Data API',
					'private_schools'   => 'NCES Private School Survey 2023-24 public-use file',
				),
				'counties'  => $counties,
				'districts' => $districts,
				'schools'   => $schools,
				'cities'    => $cities,
				'zips'      => $zips,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! $json || count( $districts ) < 300 || count( $schools ) < 1500 ) {
			throw new \RuntimeException( 'NCES data looked incomplete; places.json was left unchanged.' );
		}
		$tmp = $target . '.tmp';
		file_put_contents( $tmp, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		rename( $tmp, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

		return array(
			'districts' => count( $districts ),
			'schools'   => count( $schools ) - $private,
			'private'   => $private,
			'cities'    => count( $cities ),
		);
	}

	/** A city or ZIP belongs to the county most of its schools are in. */
	private static function majority( array $counts ): array {
		$out = array();
		foreach ( $counts as $place => $by_county ) {
			arsort( $by_county );
			$out[ (string) $place ] = (string) array_key_first( $by_county );
		}
		ksort( $out );
		return $out;
	}

	private function count_city( array &$counts, string $city, string $code ): void {
		$city = strtolower( trim( $city ) );
		if ( '' !== $city ) {
			$counts[ $city ][ $code ] = ( $counts[ $city ][ $code ] ?? 0 ) + 1;
		}
	}

	/** Follows the API's `next` links and returns every result row. */
	private function api_results( string $path ): array {
		$url  = self::CCD_API . $path;
		$rows = array();
		while ( $url ) {
			$body = Http::get( $url, 180 );
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || ! isset( $data['results'] ) ) {
				throw new \RuntimeException( 'Unexpected response from the Education Data API.' );
			}
			array_push( $rows, ...$data['results'] );
			$url = is_string( $data['next'] ?? null ) ? $data['next'] : '';
		}
		return $rows;
	}

	/** @return \Generator<array{0:string,1:string,2:string,3:string}> name, county code, city, ZIP */
	private function private_schools(): \Generator {
		$zip_path = wp_tempnam( 'mmi-teach-pss' );
		file_put_contents( $zip_path, Http::get( self::PSS_ZIP, 300 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			wp_delete_file( $zip_path );
			throw new \RuntimeException( 'Could not open the Private School Survey zip.' );
		}
		$csv_name = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			if ( str_ends_with( strtolower( (string) $zip->getNameIndex( $i ) ), '.csv' ) ) {
				$csv_name = (string) $zip->getNameIndex( $i );
				break;
			}
		}
		$handle = $csv_name ? $zip->getStream( $csv_name ) : false;
		if ( ! $handle ) {
			$zip->close();
			wp_delete_file( $zip_path );
			throw new \RuntimeException( 'No CSV inside the Private School Survey zip.' );
		}
		$header = array_flip( (array) fgetcsv( $handle, null, ',', '"', '' ) );
		foreach ( array( 'PINST', 'PSTABB', 'PCNTY', 'PCITY', 'PZIP' ) as $col ) {
			if ( ! isset( $header[ $col ] ) ) {
				throw new \RuntimeException( "Private School Survey CSV has no {$col} column." );
			}
		}
		while ( ( $row = fgetcsv( $handle, null, ',', '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ( $row[ $header['PSTABB'] ] ?? '' ) !== 'WI' ) {
				continue;
			}
			$county = str_pad( (string) $row[ $header['PCNTY'] ], 3, '0', STR_PAD_LEFT );
			yield array(
				mb_convert_encoding( (string) $row[ $header['PINST'] ], 'UTF-8', 'ISO-8859-1' ),
				self::STATE_FIPS . $county,
				(string) $row[ $header['PCITY'] ],
				substr( (string) $row[ $header['PZIP'] ], 0, 5 ),
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip->close();
		wp_delete_file( $zip_path );
	}
}
