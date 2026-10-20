<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WECAN — the Wisconsin Education Career Access Network, the statewide K-12 application
 * system most Wisconsin districts and many private schools post to.
 *
 * Reads the public vacancy search (no account). The grid endpoint ignores its employer
 * filter, so this reads every teaching posting in the state and leaves county filtering to
 * Places; that's one or two requests per refresh.
 */
final class Wecan implements Source {

	private const BASE      = 'https://wecan.waspa.org';
	private const PAGE_SIZE = 500;

	/** WECAN position types read: Teacher, Teacher Leader, Substitute Teachers. */
	private const POSITION_TYPES = array( 2, 21, 17 );

	public function id(): string {
		return 'wecan';
	}

	public function label(): string {
		return 'WECAN';
	}

	public function home_url(): string {
		return self::BASE . '/';
	}

	public function fetch(): array {
		$rows  = array();
		$total = null;
		$page  = 1;
		do {
			$html = Http::post( self::BASE . '/Vacancy/ShowPublicSearchGrid', $this->grid_query( $page ), 120 );
			if ( ! preg_match( '/Displaying\s+[\d,]+\s*-\s*[\d,]+\s+of\s+([\d,]+)\s+records/i', $html, $m ) ) {
				if ( stripos( $html, 'no-results' ) !== false || stripos( $html, 'No records' ) !== false ) {
					throw new \RuntimeException( 'WECAN returned no vacancies at all, which it never does; treating as an outage.' );
				}
				throw new \RuntimeException( 'WECAN results page had no record count; the page layout may have changed.' );
			}
			$total = (int) str_replace( ',', '', $m[1] );
			$batch = $this->parse_grid( $html );
			if ( ! $batch ) {
				break;
			}
			array_push( $rows, ...$batch );
			++$page;
		} while ( count( $rows ) < $total && $page <= 20 );

		if ( count( $rows ) !== $total ) {
			throw new \RuntimeException( sprintf( 'WECAN said %d vacancies but %d were read.', $total, count( $rows ) ) );
		}
		return $rows;
	}

	public function fetch_details( string $source_id ): array {
		$html = Http::get( self::BASE . '/Vacancy/' . rawurlencode( $source_id ) );
		$dom  = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		$xpath  = new \DOMXPath( $dom );
		$values = array();
		foreach ( $xpath->query( '//div[contains(@class,"display-label")]/label[@for]' ) as $label ) {
			$box = $label->parentNode->nextSibling;
			while ( $box && XML_ELEMENT_NODE !== $box->nodeType ) {
				$box = $box->nextSibling;
			}
			if ( ! $box ) {
				continue;
			}
			$key = $label->getAttribute( 'for' );
			if ( 'Licenses' === $key ) {
				$values[ $key ] = array_values( array_filter( array_map( static fn( $li ) => self::clean( $li->textContent ), iterator_to_array( $xpath->query( './/li', $box ) ) ) ) );
			} else {
				$values[ $key ] = self::clean( $box->textContent );
			}
		}
		if ( ! $values ) {
			throw new \RuntimeException( "WECAN vacancy {$source_id} page had no details." );
		}

		$summary = $values['Description'] ?? '';
		if ( mb_strlen( $summary ) > 700 ) {
			$summary = rtrim( mb_substr( $summary, 0, 700 ) ) . '…';
		}
		return array_filter(
			array(
				'salary'     => $values['Salary'] ?? '',
				'licenses'   => $values['Licenses'] ?? array(),
				'openings'   => $values['NumberOfOpenings'] ?? '',
				'experience' => $values['YearsOfExperience'] ?? '',
				'degree'     => $values['DegreeType'] ?? '',
				'contact'    => array_filter(
					array(
						'name'  => $values['ContactName'] ?? '',
						'title' => $values['ContactTitle'] ?? '',
						'email' => $values['ContactEmail'] ?? '',
						'phone' => $values['ContactPhone'] ?? '',
					)
				),
				'summary'    => $summary,
			)
		);
	}

	/** Form body for one page of the public grid; repeated keys need manual encoding. */
	private function grid_query( int $page ): string {
		$pairs = array(
			array( 'KeywordSearchField', '' ),
			array( 'pageNumber', (string) $page ),
			array( 'pageSize', (string) self::PAGE_SIZE ),
			array( 'actionTarget', 'PublicSearchGrid' ),
			array( 'sortField', 'VacancyId' ),
			array( 'isSortAscending', 'true' ),
			array( 'isMobile', 'false' ),
		);
		foreach ( self::POSITION_TYPES as $type ) {
			$pairs[] = array( 'SelectedPositionTypes', (string) $type );
		}
		return implode( '&', array_map( static fn( $p ) => rawurlencode( $p[0] ) . '=' . rawurlencode( $p[1] ), $pairs ) );
	}

	private function parse_grid( string $html ): array {
		$rows = array();
		if ( ! preg_match_all( '#<tr[^>]*>(.*?)</tr>#s', $html, $trs ) ) {
			return $rows;
		}
		foreach ( $trs[1] as $tr ) {
			if ( ! preg_match_all( '#data-label="([^"]+)"[^>]*>(.*?)</td>#s', $tr, $cells, PREG_SET_ORDER ) ) {
				continue; // Header row.
			}
			$c = array();
			foreach ( $cells as [ , $label, $value ] ) {
				$c[ $label ] = self::clean( $value );
			}
			$id = $c['VacancyId'] ?? '';
			if ( ! ctype_digit( $id ) ) {
				continue;
			}
			$close  = $c['CloseDate'] ?? '';
			$rows[] = array(
				'source'        => $this->id(),
				'source_id'     => $id,
				'title'         => $c['PositionTitle'] ?? '',
				'employer'      => $c['Employer'] ?? '',
				'location'      => $c['Location'] ?? '',
				'position_type' => $c['PositionType'] ?? '',
				'appointment'   => $c['AppointmentType'] ?? '',
				'posted_date'   => self::date( $c['CreatedDateString'] ?? '' ),
				'updated_date'  => self::date( $c['UpdatedDateString'] ?? '' ),
				'start_text'    => $c['StartDate'] ?? '',
				'close_text'    => $close,
				'close_date'    => self::date( $close ),
				'url'           => self::BASE . '/Vacancy/' . $id,
				'apply_url'     => self::BASE . '/Vacancy/Apply/' . $id,
			);
		}
		return $rows;
	}

	private static function clean( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** "10/19/2026" => "2026-10-19"; anything else => null. */
	private static function date( string $text ): ?string {
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim( $text ), $m ) && checkdate( (int) $m[1], (int) $m[2], (int) $m[3] ) ) {
			return sprintf( '%04d-%02d-%02d', $m[3], $m[1], $m[2] );
		}
		return null;
	}
}
