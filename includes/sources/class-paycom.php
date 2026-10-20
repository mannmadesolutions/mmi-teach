<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paycom applicant tracking, used by several Milwaukee charter and private schools. The
 * public job page hands out a short-lived session token, which the search API then accepts.
 */
final class Paycom extends Board {

	private const PAGE_URL   = 'https://www.paycomonline.net/v4/ats/web.php/jobs?clientkey=';
	private const SEARCH_URL = 'https://portal-applicant-tracking.us-cent.paycomonline.net/api/ats/job-posting-previews/search';

	/** Paycom client key => employer name (as WECAN spells it where the school is also there). */
	private const CLIENTS = array(
		'ED7FE791F1E19827B6098A2D9B4961E1' => 'Milwaukee College Preparatory School',
		'CA382F966749E8D17241DC2E78C3B094' => 'United Community Center Schools',
		'171E1302553D9FE41D2BE3E9F5858622' => 'Next Door Foundation',
		'5241567E0360ABC57CD458404DF87D32' => 'Penfield Children\'s Center',
		'35A75A95E82BE3D9B242D113166B2E41' => 'Messmer High School',
	);

	public function id(): string {
		return 'paycom';
	}

	public function label(): string {
		return 'Paycom (Milwaukee College Prep, UCC, Next Door, Penfield, Messmer)';
	}

	public function home_url(): string {
		return self::PAGE_URL . array_key_first( self::CLIENTS );
	}

	public function fetch(): array {
		$rows = array();
		foreach ( self::CLIENTS as $key => $employer ) {
			$page = Http::get( self::PAGE_URL . $key );
			if ( ! preg_match( '/var configsFromHost\s*=\s*(\{.*?\});/s', $page, $m ) ) {
				throw new \RuntimeException( "Paycom page for {$employer} had no session config." );
			}
			$config = json_decode( $m[1], true );
			$jwt    = is_array( $config ) ? (string) ( $config['sessionJWT'] ?? '' ) : '';
			if ( '' === $jwt ) {
				throw new \RuntimeException( "Paycom page for {$employer} had no session token." );
			}
			$data = Http::post_json(
				self::SEARCH_URL,
				array(
					'skip'            => 0,
					'take'            => 200,
					'filtersForQuery' => array(
						'distanceFrom'       => 0,
						'workEnvironments'   => array(),
						'positionTypes'      => array(),
						'educationLevels'    => array(),
						'categories'         => array(),
						'travelTypes'        => array(),
						'shiftTypes'         => array(),
						'otherFilters'       => array(),
						'keywordSearchText'  => '',
						'location'           => '',
						'sortOption'         => 'N',
					),
				),
				array(
					'Authorization' => $jwt,
					'Locale'        => 'en-US',
				)
			);
			if ( ! isset( $data['jobPostingPreviews'] ) || ! is_array( $data['jobPostingPreviews'] ) ) {
				throw new \RuntimeException( "Paycom search for {$employer} returned no job list." );
			}
			foreach ( $data['jobPostingPreviews'] as $job ) {
				$title = (string) ( $job['jobTitle'] ?? '' );
				$id    = (string) ( $job['jobId'] ?? '' );
				if ( '' === $id || ! self::is_teaching( $title ) ) {
					continue;
				}
				$url    = 'https://www.paycomonline.net/v4/ats/web.php/jobs/ViewJobDetails?job=' . rawurlencode( $id ) . '&clientkey=' . $key;
				$rows[] = $this->row(
					$key . '-' . $id,
					$title,
					$employer,
					(string) ( $job['locations'] ?? '' ),
					array(
						'appointment' => self::appointment( (string) ( $job['positionType'] ?? '' ) ),
						'posted_date' => self::date( $job['postedOn'] ?? '' ),
						'url'         => $url,
						'apply_url'   => $url,
					)
				);
			}
		}
		return $rows;
	}
}
