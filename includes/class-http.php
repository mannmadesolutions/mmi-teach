<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polite outbound HTTP: one request at a time per host with a minimum gap, an honest
 * User-Agent, and a short retry on timeouts and 5xx. Throws on failure so callers can't
 * mistake an error page for an empty result.
 */
final class Http {

	/** Minimum seconds between requests to the same host. */
	private const MIN_GAP = 2.0;
	private const RETRIES = 2;

	/** @var array<string, float> host => microtime of the last request */
	private static array $last = array();

	public static function get( string $url, int $timeout = 60, array $headers = array() ): string {
		return self::request( 'GET', $url, null, $timeout, $headers );
	}

	/** POSTs a JSON body and decodes the JSON answer; throws if it isn't JSON. */
	public static function post_json( string $url, array $data, array $headers = array(), int $timeout = 60 ): array {
		$body    = self::request( 'POST', $url, (string) wp_json_encode( $data ), $timeout, $headers + array( 'Content-Type' => 'application/json' ) );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Expected JSON from ' . wp_parse_url( $url, PHP_URL_HOST ) );
		}
		return $decoded;
	}

	/** GETs and decodes JSON; throws if it isn't JSON. */
	public static function get_json( string $url, array $headers = array(), int $timeout = 60 ): array {
		$decoded = json_decode( self::get( $url, $timeout, $headers + array( 'Accept' => 'application/json' ) ), true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Expected JSON from ' . wp_parse_url( $url, PHP_URL_HOST ) );
		}
		return $decoded;
	}

	/** @param array|string $body Form fields (repeated keys allowed when passed pre-encoded). */
	public static function post( string $url, array|string $body, int $timeout = 60 ): string {
		return self::request( 'POST', $url, $body, $timeout );
	}

	private static function request( string $method, string $url, array|string|null $body, int $timeout, array $headers = array() ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'user-agent' => 'MMI-Teach/' . MMI_TEACH_VERSION . ' (teaching-vacancy dashboard; +' . home_url( '/' ) . ')',
			'headers'    => array( 'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8' ),
		);
		if ( null !== $body ) {
			$args['body']                        = $body;
			$args['headers']['Content-Type']     = 'application/x-www-form-urlencoded; charset=UTF-8';
			$args['headers']['X-Requested-With'] = 'XMLHttpRequest';
		}
		$args['headers'] = array_merge( $args['headers'], $headers );

		$error = '';
		for ( $attempt = 0; $attempt <= self::RETRIES; $attempt++ ) {
			self::wait_turn( $host, $attempt );
			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				$error = $response->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				return (string) wp_remote_retrieve_body( $response );
			}
			$error = "HTTP {$code}";
			if ( $code < 500 && 429 !== $code ) {
				break; // A 4xx won't fix itself on retry.
			}
		}
		throw new \RuntimeException( sprintf( '%s %s failed: %s', $method, $host, $error ) );
	}

	/** Sleeps until this host's gap has passed; backs off further on retries. */
	private static function wait_turn( string $host, int $attempt ): void {
		$gap     = self::MIN_GAP * ( 1 + 2 * $attempt );
		$elapsed = microtime( true ) - ( self::$last[ $host ] ?? 0.0 );
		if ( $elapsed < $gap ) {
			usleep( (int) ( ( $gap - $elapsed ) * 1e6 ) );
		}
		self::$last[ $host ] = microtime( true );
	}
}
