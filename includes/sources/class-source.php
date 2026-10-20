<?php
namespace MMI_Teach\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A job board the dashboard reads from.
 *
 * fetch() returns every open teaching posting the board lists, as rows with these keys:
 * source, source_id, title, employer, location, position_type, appointment, posted_date,
 * updated_date (Y-m-d or null), start_text, close_text, close_date (Y-m-d or null), url, apply_url.
 * It throws if the board can't be read completely; a partial list must never be returned,
 * because postings missing from it would be marked removed.
 */
interface Source {

	public function id(): string;

	public function label(): string;

	/** Public page people can browse the board on. */
	public function home_url(): string;

	/** @return array<int, array<string, mixed>> */
	public function fetch(): array;

	/**
	 * Reads one posting's detail page. Return keys (all optional): salary, licenses (list),
	 * openings, experience, degree, contact (name, title, email, phone), summary.
	 */
	public function fetch_details( string $source_id ): array;
}
