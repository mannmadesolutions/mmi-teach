<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The vacancies table. One row per source posting; rows are never deleted, a posting that
 * leaves its source gets `removed_at` so history (time open, re-posts) survives.
 */
final class Store {

	private const DB_VERSION        = '2';
	private const OPTION_DB_VERSION = 'mmi_teach_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmi_teach_vacancies';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(32) NOT NULL,
				source_id varchar(64) NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				employer varchar(255) NOT NULL DEFAULT '',
				location varchar(255) NOT NULL DEFAULT '',
				position_type varchar(64) NOT NULL DEFAULT '',
				appointment varchar(64) NOT NULL DEFAULT '',
				posted_date date DEFAULT NULL,
				updated_date date DEFAULT NULL,
				start_text varchar(64) NOT NULL DEFAULT '',
				close_text varchar(64) NOT NULL DEFAULT '',
				close_date date DEFAULT NULL,
				url varchar(255) NOT NULL DEFAULT '',
				apply_url varchar(255) NOT NULL DEFAULT '',
				county_code varchar(8) NOT NULL DEFAULT '',
				county_method varchar(16) NOT NULL DEFAULT '',
				subject varchar(48) NOT NULL DEFAULT '',
				grade_band varchar(32) NOT NULL DEFAULT '',
				sub_kind varchar(12) NOT NULL DEFAULT '',
				pay_text varchar(255) NOT NULL DEFAULT '',
				pay_min decimal(10,2) DEFAULT NULL,
				pay_max decimal(10,2) DEFAULT NULL,
				pay_unit varchar(12) NOT NULL DEFAULT '',
				pay_basis varchar(12) NOT NULL DEFAULT '',
				pay_day decimal(8,2) DEFAULT NULL,
				details longtext NULL,
				details_at datetime DEFAULT NULL,
				row_hash char(40) NOT NULL DEFAULT '',
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				removed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_posting (source,source_id),
				KEY open_county (removed_at,county_code)
			) {$charset};"
		);
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Inserts or refreshes one posting. Returns 'new', 'changed' or 'same'; an unchanged posting
	 * isn't written here, its id goes into $touch for one bulk touch() afterwards.
	 *
	 * @param array      $row      Normalized posting (see Sources\Source::fetch()) plus derived columns.
	 * @param array|null $existing This posting's entry from index(), or null if it's new.
	 */
	public static function upsert( array $row, ?array $existing, string $now, array &$touch ): string {
		global $wpdb;
		$table  = self::table();
		$fields = array_intersect_key( $row, array_flip( array_merge( self::source_columns(), self::derived_columns() ) ) );
		$hash   = sha1( (string) wp_json_encode( array_intersect_key( $row, array_flip( self::source_columns() ) ) ) );

		if ( ! $existing ) {
			$wpdb->insert(
				$table,
				$fields + array(
					'source'     => $row['source'],
					'source_id'  => $row['source_id'],
					'row_hash'   => $hash,
					'first_seen' => $now,
					'last_seen'  => $now,
				)
			);
			return 'new';
		}

		$changed = $existing['row_hash'] !== $hash;
		$derived = array_intersect_key( $fields, array_flip( self::derived_columns() ) );
		if ( ! $changed && $derived == array_intersect_key( $existing, $derived ) && empty( $existing['removed_at'] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- DB strings vs strings.
			$touch[] = (int) $existing['id'];
			return 'same';
		}
		$update  = array(
			'last_seen'  => $now,
			'removed_at' => null,
		);
		if ( $changed ) {
			// A changed posting gets its details re-read on the next detail pass.
			$update += $fields + array(
				'row_hash'   => $hash,
				'details_at' => null,
			);
		} else {
			// County/subject can change without the posting changing (new override, classifier update).
			$update += $derived;
		}
		$wpdb->update( $table, $update, array( 'id' => (int) $existing['id'] ) );
		return $changed ? 'changed' : 'same';
	}

	/**
	 * Every stored posting of a source, keyed by source_id, in one query, so a refresh never
	 * looks rows up one at a time.
	 *
	 * @return array<string, array<string, ?string>>
	 */
	public static function index( string $source ): array {
		global $wpdb;
		$table = self::table();
		$cols  = implode( ', ', self::derived_columns() );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT source_id, id, row_hash, details, removed_at, {$cols} FROM {$table} WHERE source = %s", $source ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list is a constant.
		return array_column( $rows, null, 'source_id' );
	}

	/** Stamps unchanged postings as seen this run, 500 ids per query. */
	public static function touch( array $ids, string $now ): void {
		global $wpdb;
		$table = self::table();
		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_seen = %s WHERE id IN (" . implode( ',', $chunk ) . ')', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are cast to int.
		}
	}

	/** Marks every open posting of a source that this run didn't see as removed. */
	public static function mark_unseen_removed( string $source, string $run_started ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET removed_at = %s WHERE source = %s AND removed_at IS NULL AND last_seen < %s",
				current_time( 'mysql', true ),
				$source,
				$run_started
			)
		);
	}

	public static function count_open( string $source ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s AND removed_at IS NULL", $source ) );
	}

	/** Open postings, optionally limited to some counties. */
	public static function open_rows( ?array $county_codes = null ): array {
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT * FROM {$table} WHERE removed_at IS NULL";
		if ( $county_codes ) {
			$sql .= ' AND county_code IN (' . implode( ',', array_fill( 0, count( $county_codes ), '%s' ) ) . ')';
			$sql  = $wpdb->prepare( $sql, $county_codes ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql . ' ORDER BY posted_date DESC, id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Open postings in the covered counties whose detail page hasn't been read since they changed. */
	public static function rows_needing_details( string $source, int $limit ): array {
		global $wpdb;
		$table  = self::table();
		$codes  = array_keys( Plugin::COUNTIES );
		$places = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_id FROM {$table} WHERE source = %s AND removed_at IS NULL AND details_at IS NULL AND county_code IN ({$places}) ORDER BY first_seen DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $source ), $codes, array( $limit ) )
			),
			ARRAY_A
		);
	}

	public static function save_details( int $id, array $details, array $extra = array() ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			$extra + array(
				'details'    => wp_json_encode( $details ),
				'details_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/** Postings whose employer (normalized) has no county yet, grouped. */
	public static function unmapped_employers(): array {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			"SELECT employer, COUNT(*) AS postings, MIN(location) AS sample_location FROM {$table} WHERE removed_at IS NULL AND county_code = '' GROUP BY employer ORDER BY postings DESC, employer", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/** Re-runs county and subject resolution over every open row (after an override change or a classifier update). */
	public static function reclassify_open(): int {
		global $wpdb;
		$table   = self::table();
		$cols    = implode( ', ', self::derived_columns() );
		$rows    = $wpdb->get_results( "SELECT id, title, employer, location, position_type, pay_text, details, {$cols} FROM {$table} WHERE removed_at IS NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$places  = Places::instance();
		$changed = 0;
		foreach ( $rows as $row ) {
			$details = $row['details'] ? (array) json_decode( $row['details'], true ) : array();
			$next    = Classifier::derive( $row, $details, $places );
			$current = array_intersect_key( $row, $next );
			if ( $current != $next ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- DB strings ("185.00") vs derived numbers.
				$wpdb->update( $table, $next, array( 'id' => (int) $row['id'] ) );
				++$changed;
			}
		}
		return $changed;
	}

	/** Columns a source supplies; a change in any of them means the posting changed. */
	private static function source_columns(): array {
		return array( 'title', 'employer', 'location', 'position_type', 'appointment', 'posted_date', 'updated_date', 'start_text', 'close_text', 'close_date', 'url', 'apply_url', 'pay_text' );
	}

	/** Columns this plugin works out from the source columns. */
	private static function derived_columns(): array {
		return array( 'county_code', 'county_method', 'subject', 'grade_band', 'sub_kind', 'pay_min', 'pay_max', 'pay_unit', 'pay_basis', 'pay_day' );
	}
}
