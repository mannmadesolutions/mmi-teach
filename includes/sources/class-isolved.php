<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cristo Rey Jesuit High School Milwaukee (iSolved Hire); "Faculty" postings are teaching jobs. */
final class Isolved extends Board {

	private const API = 'https://cristoreymilwaukee.isolvedhire.com/core/jobs/10632?getParams=%7B%7D';

	public function id(): string {
		return 'cristorey';
	}

	public function label(): string {
		return 'Cristo Rey Jesuit HS (iSolved Hire)';
	}

	public function home_url(): string {
		return 'https://cristoreymilwaukee.isolvedhire.com/jobs/';
	}

	public function fetch(): array {
		$data = Http::get_json( self::API );
		if ( ! isset( $data['data']['jobs'] ) || ! is_array( $data['data']['jobs'] ) ) {
			throw new \RuntimeException( 'iSolved returned no job list.' );
		}
		$rows = array();
		foreach ( $data['data']['jobs'] as $job ) {
			$title = (string) ( $job['title'] ?? '' );
			$fac   = 'Faculty' === ( $job['classification'] ?? '' );
			if ( empty( $job['id'] ) || ! ( $fac ? ! self::is_excluded( $title ) : self::is_teaching( $title ) ) ) {
				continue;
			}
			$rows[] = $this->row(
				(string) $job['id'],
				$title,
				'Cristo Rey Jesuit High School Milwaukee',
				(string) ( $job['jobLocation'] ?? '' ),
				array(
					'appointment' => self::appointment( (string) ( $job['employmentType'] ?? '' ) ),
					'posted_date' => self::date( $job['startDateRef'] ?? '' ),
					'close_text'  => '1' === (string) ( $job['untilFilled'] ?? '' ) ? 'Until filled' : '',
					'url'         => esc_url_raw( (string) ( $job['jobUrl'] ?? '' ) ),
					'apply_url'   => esc_url_raw( (string) ( $job['jobUrl'] ?? '' ) ),
				)
			);
		}
		return $rows;
	}
}
