<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Milwaukee Public Schools' own board (Oracle PeopleSoft Candidate Gateway). MPS posts only
 * some openings to WECAN, so this reads the full external list. The list page is plain HTML;
 * job detail pages need a session cookie, so links point at the list.
 */
final class Mps extends Board {

	private const LIST_URL = 'https://candidate.milwaukee.k12.wi.us:4443/psc/gw/SELFSERVICE/PSFT_HR/c/HRS_HRAM_FL.HRS_CG_SEARCH_FL.GBL?Page=HRS_APP_SCHJOB_FL&Action=U&FOCUS=Applicant&SiteId=1000';

	public function id(): string {
		return 'mps';
	}

	public function label(): string {
		return 'Milwaukee Public Schools careers';
	}

	public function home_url(): string {
		return self::LIST_URL;
	}

	public function fetch(): array {
		$html = Http::get( self::LIST_URL, 90 );
		if ( ! preg_match( '#(\d+)\s*(?:</b>)?\s*jobs?\s+found#i', $html, $m ) ) {
			throw new \RuntimeException( 'MPS job list had no "jobs found" count; the page layout may have changed.' );
		}
		$fields = array();
		preg_match_all( "#id='(SCH_JOB_TITLE|HRS_APP_JBSCH_I_HRS_JOB_OPENING_ID|LOCATION|HRS_APP_JBSCH_I_HRS_DEPT_DESCR|SCH_OPENED)\\$(\\d+)'\\s*>(.*?)</span>#s", $html, $cells, PREG_SET_ORDER );
		foreach ( $cells as [ , $field, $n, $value ] ) {
			$fields[ (int) $n ][ $field ] = self::clean( $value );
		}
		if ( count( $fields ) !== (int) $m[1] ) {
			throw new \RuntimeException( sprintf( 'MPS said %d jobs but %d were read.', $m[1], count( $fields ) ) );
		}

		$rows = array();
		foreach ( $fields as $f ) {
			$title = $f['SCH_JOB_TITLE'] ?? '';
			$id    = $f['HRS_APP_JBSCH_I_HRS_JOB_OPENING_ID'] ?? '';
			if ( '' === $id || ! self::is_teaching( $title ) ) {
				continue;
			}
			$rows[] = $this->row(
				$id,
				self::readable_title( $title ),
				'Milwaukee Public Schools',
				$f['HRS_APP_JBSCH_I_HRS_DEPT_DESCR'] ?? ( $f['LOCATION'] ?? '' ),
				array(
					'posted_date' => self::date( $f['SCH_OPENED'] ?? '' ),
					'url'         => self::LIST_URL,
					'apply_url'   => self::LIST_URL,
				)
			);
		}
		return $rows;
	}

	/** "TEACHER - SPECIAL EDUCATION (2026-27)" => "Teacher - Special Education (2026-27)". */
	private static function readable_title( string $title ): string {
		return preg_replace_callback( '/\b([A-Z])([A-Z]+)\b/', static fn( $m ) => $m[1] . strtolower( $m[2] ), $title );
	}
}
