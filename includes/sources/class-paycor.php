<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** HOPE Christian Schools (Paycor Recruiting career page, server-rendered HTML). */
final class Paycor extends Board {

	private const URL = 'https://recruitingbypaycor.com/career/CareerHome.action?clientId=8a7883d09397efbb0193ac4b96b706a5';

	public function id(): string {
		return 'hope';
	}

	public function label(): string {
		return 'HOPE Christian Schools (Paycor)';
	}

	public function home_url(): string {
		return self::URL;
	}

	public function fetch(): array {
		$html = Http::get( self::URL );
		if ( false === strpos( $html, 'gnewtonCareerBody' ) ) {
			throw new \RuntimeException( 'HOPE career page had no job list; the layout may have changed.' );
		}
		preg_match_all( '#<div class="gnewtonCareerGroupJobTitleClass">\s*<a href="([^"]+)"[^>]*>(.*?)</a>\s*</div>\s*<div class="gnewtonCareerGroupJobDescriptionClass">(.*?)</div>#s', $html, $jobs, PREG_SET_ORDER );
		$rows = array();
		foreach ( $jobs as [ , $href, $title, $where ] ) {
			$href  = html_entity_decode( $href, ENT_QUOTES );
			$title = self::clean( $title );
			if ( ! preg_match( '/[?&]id=([a-z0-9]+)/i', $href, $m ) || ! self::is_teaching( $title ) ) {
				continue;
			}
			$rows[] = $this->row(
				$m[1],
				$title,
				'HOPE Christian Schools',
				self::clean( $where ),
				array(
					'url'       => esc_url_raw( $href ),
					'apply_url' => esc_url_raw( $href ),
				)
			);
		}
		return $rows;
	}
}
