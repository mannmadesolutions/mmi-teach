<?php
namespace MMI_Teach\Sources;

use MMI_Teach\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archdiocese of Milwaukee careers page: openings at Catholic schools across ten counties on
 * one HTML page. Each card's text names the school and city ("Second Grade Teacher - St.
 * Andrew School, Delavan, WI"), which is how Places puts it in a county.
 */
final class Archdiocese extends Board {

	private const URL = 'https://www.archmil.org/careers';

	public function id(): string {
		return 'archmil';
	}

	public function label(): string {
		return 'Archdiocese of Milwaukee careers';
	}

	public function home_url(): string {
		return self::URL;
	}

	public function fetch(): array {
		$html = Http::get( self::URL );
		if ( false === strpos( $html, 'careers-wrapper' ) ) {
			throw new \RuntimeException( 'Archdiocese careers page had no job list; the layout may have changed.' );
		}
		preg_match_all( '#<a href="([^"]+)" class="mini-node job-node[^"]*">(.*?)</a>#s', $html, $cards, PREG_SET_ORDER );
		$rows = array();
		foreach ( $cards as [ , $href, $body ] ) {
			$text    = preg_match( '#<p>(.*?)</p>#s', $body, $m ) ? self::clean( $m[1] ) : '';
			$heading = preg_match( '#<h6[^>]*>(.*?)</h6>#s', $body, $m ) ? self::clean( $m[1] ) : '';
			$type    = preg_match( '#class="job-category">(.*?)</span>#s', $body, $m ) ? self::clean( $m[1] ) : '';
			// Parish, cemetery and charity jobs share the page; only school categories count.
			if ( '' === $text || ! in_array( $type, array( '', 'Education', 'Teaching' ), true ) || ! self::is_teaching( $text ) ) {
				continue;
			}
			$url = str_starts_with( $href, 'http' ) ? $href : 'https://www.archmil.org' . $href;
			[ $title, $school, $city ] = self::split( $text, $heading );
			$rows[] = $this->row(
				md5( $url ),
				$title,
				$school ?: 'Archdiocese of Milwaukee',
				$city ? "{$school} ({$city})" : $school,
				array(
					'url'       => esc_url_raw( $url ),
					'apply_url' => esc_url_raw( $url ),
				)
			);
		}
		return $rows;
	}

	/**
	 * "Middle School ELA Teacher - St. Roman, Milwaukee, WI" => [title, school, city].
	 * The card's heading, when present, is the school's full name.
	 */
	private static function split( string $text, string $heading ): array {
		$city = '';
		if ( preg_match( '/([A-Za-z. ]+),\s*(WI|Wisconsin)\b/', $text, $m ) ) {
			$city = trim( $m[1] );
		} elseif ( preg_match( '/,\s*([A-Za-z. ]+)$/', $text, $m ) ) {
			$city = trim( $m[1] );
		}
		$parts  = preg_split( '/\s+[-–]\s+|-(?=St\.|Saint|Holy|Our)/', $text, 2 );
		$title  = trim( $parts[0] );
		$rest   = isset( $parts[1] ) ? trim( preg_replace( '/,?\s*[A-Za-z. ]+,\s*(WI|Wisconsin)\b.*$/', '', $parts[1] ) ) : '';
		$school = $heading && ! preg_match( '/,\s*WI$/', $heading ) ? $heading : $rest;
		return array( $title, trim( $school, " ,-" ), $city );
	}
}
