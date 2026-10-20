<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rocketship Public Schools (Lever): network-wide list, kept to its Milwaukee schools. */
final class Lever extends Board {

	private const API = 'https://api.lever.co/v0/postings/rocketship?mode=json';

	public function id(): string {
		return 'rocketship';
	}

	public function label(): string {
		return 'Rocketship Milwaukee (Lever)';
	}

	public function home_url(): string {
		return 'https://jobs.lever.co/rocketship';
	}

	public function fetch(): array {
		$rows = array();
		foreach ( Http::get_json( self::API ) as $job ) {
			$cat   = (array) ( $job['categories'] ?? array() );
			$where = (string) ( $cat['location'] ?? '' );
			$title = (string) ( $job['text'] ?? '' );
			if ( ! str_contains( $where, 'Milwaukee' ) || ! self::is_teaching( $title ) ) {
				continue;
			}
			$rows[] = $this->row(
				(string) $job['id'],
				$title,
				'Rocketship Public Schools',
				(string) ( $cat['team'] ?? '' ) . ' (Milwaukee)',
				array(
					'appointment' => self::appointment( (string) ( $cat['commitment'] ?? '' ) ),
					'posted_date' => self::date( $job['createdAt'] ?? null ),
					'pay_text'    => self::range_text( (array) ( $job['salaryRange'] ?? array() ) ),
					'url'         => esc_url_raw( (string) ( $job['hostedUrl'] ?? '' ) ),
					'apply_url'   => esc_url_raw( (string) ( $job['applyUrl'] ?? '' ) ),
				)
			);
		}
		return $rows;
	}

	/** {min, max, interval: "per-hour-wage"} => "$23.05 - $28.05 per hour". */
	private static function range_text( array $range ): string {
		if ( empty( $range['min'] ) ) {
			return '';
		}
		$per = preg_match( '/hour|day|year|month/', (string) ( $range['interval'] ?? '' ), $m ) ? ' per ' . $m[0] : '';
		return '$' . $range['min'] . ( ! empty( $range['max'] ) && $range['max'] > $range['min'] ? ' - $' . $range['max'] : '' ) . $per;
	}
}
