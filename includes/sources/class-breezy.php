<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Carmen Schools of Science & Technology (Breezy HR public JSON). */
final class Breezy extends Board {

	private const BASE = 'https://carmen-schools-of-science-technology.breezy.hr';

	public function id(): string {
		return 'carmen';
	}

	public function label(): string {
		return 'Carmen Schools (Breezy HR)';
	}

	public function home_url(): string {
		return self::BASE . '/';
	}

	public function fetch(): array {
		$rows = array();
		foreach ( Http::get_json( self::BASE . '/json' ) as $job ) {
			$title = (string) ( $job['name'] ?? '' );
			if ( empty( $job['id'] ) || ! self::is_teaching( $title ) ) {
				continue;
			}
			$campus = preg_match( '/\s[-–]\s*([^-–]*\bCampus)\s*$/i', $title, $m ) ? $m[1] : '';
			$rows[] = $this->row(
				(string) $job['id'],
				$title,
				'Carmen Schools of Science and Technology',
				trim( $campus . ' (' . ( $job['location']['city'] ?? 'Milwaukee' ) . ')' ),
				array(
					'appointment' => self::appointment( (string) ( $job['type']['name'] ?? '' ) ),
					'posted_date' => self::date( $job['published_date'] ?? '' ),
					'pay_text'    => self::clean( (string) ( $job['salary'] ?? '' ) ),
					'url'         => esc_url_raw( (string) ( $job['url'] ?? '' ) ),
					'apply_url'   => esc_url_raw( (string) ( $job['url'] ?? '' ) ),
				)
			);
		}
		return $rows;
	}
}
