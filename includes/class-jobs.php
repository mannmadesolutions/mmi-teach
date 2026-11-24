<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Open postings as posts (mt_job), so Bricks query loops, filters and templates work natively.
 *
 * The vacancies table stays the source of truth; sync() mirrors its open, in-area, de-duplicated
 * rows after every refresh and writes only what changed. A posting that closes keeps its post,
 * marked _mt_open = 0, so applications that point at it still resolve. Never edit these posts
 * by hand: the next sync overwrites them.
 */
final class Jobs {

	public const POST_TYPE = 'mt_job';

	/** Taxonomy => [label, plural label]. Every one is a filter on /jobs/. */
	public const TAXONOMIES = array(
		'mt_role'     => array( 'Role', 'Roles' ),
		'mt_job_type' => array( 'Job type', 'Job types' ),
		'mt_county'   => array( 'County', 'Counties' ),
		'mt_employer' => array( 'Employer', 'Employers' ),
		'mt_subject'  => array( 'Subject', 'Subjects' ),
		'mt_grade'    => array( 'Grade level', 'Grade levels' ),
		'mt_degree'   => array( 'Minimum degree', 'Minimum degrees' ),
	);

	/** Job-type term per sub kind ('' = a regular, contracted job). */
	public const JOB_TYPES = array(
		'daily'     => 'Daily / on-call sub',
		'building'  => 'Building sub',
		'long_term' => 'Long-term sub',
		''          => 'Regular position',
	);

	private const OPTION_SYNC = 'mmi_teach_jobs_sync';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_types' ) );
	}

	public static function register_types(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Jobs', 'mmi-teach' ),
				'labels'              => array(
					'singular_name' => __( 'Job', 'mmi-teach' ),
					'all_items'     => __( 'All jobs', 'mmi-teach' ),
				),
				'description'         => __( 'Open school jobs, synced from the job boards after every refresh. Read-only: edits are overwritten.', 'mmi-teach' ),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-welcome-learn-more',
				'menu_position'       => 21,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => 'job',
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor' ),
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
				'exclude_from_search' => false,
			)
		);
		foreach ( self::TAXONOMIES as $tax => [ $singular, $plural ] ) {
			register_taxonomy(
				$tax,
				self::POST_TYPE,
				array(
					'labels'             => array(
						'name'          => $plural,
						'singular_name' => $singular,
					),
					'public'             => true,
					'publicly_queryable' => false,
					'show_ui'            => true,
					'show_in_menu'       => true,
					'show_admin_column'  => in_array( $tax, array( 'mt_role', 'mt_job_type', 'mt_county' ), true ),
					'show_in_rest'       => false,
					'hierarchical'       => false,
					'rewrite'            => false,
					'query_var'          => false,
				)
			);
		}
		register_post_meta(
			self::POST_TYPE,
			'_mt_pay_day',
			array(
				'type'   => 'number',
				'single' => true,
			)
		);
	}

	/**
	 * Mirrors the open postings into mt_job posts.
	 *
	 * @param int  $limit   Most posts to write this run (0 = no cap); the rest wait for the next.
	 * @param bool $dry_run Count changes without writing.
	 * @return array{created: int, updated: int, closed: int, reopened: int, same: int, open: int, deferred: int}
	 */
	public static function sync( int $limit = 0, bool $dry_run = false ): array {
		$stats    = array_fill_keys( array( 'created', 'updated', 'closed', 'reopened', 'same', 'open', 'deferred' ), 0 );
		$existing = self::existing();
		$by_key   = array();
		foreach ( $existing as $id => $post ) {
			foreach ( $post['keys'] as $key ) {
				$by_key[ $key ] = $id;
			}
		}
		$seen   = array();
		$writes = 0;

		if ( ! $dry_run ) {
			wp_defer_term_counting( true );
		}
		foreach ( self::postings() as $posting ) {
			++$stats['open'];
			$data = self::post_data( $posting );
			$id   = 0;
			foreach ( $data['meta']['_mt_keys'] as $key ) {
				if ( isset( $by_key[ $key ] ) && ! isset( $seen[ $by_key[ $key ] ] ) ) {
					$id = $by_key[ $key ];
					break;
				}
			}
			if ( $id ) {
				$seen[ $id ] = true;
				if ( $existing[ $id ]['hash'] === $data['meta']['_mt_row_hash'] && $existing[ $id ]['open'] ) {
					++$stats['same'];
					continue;
				}
			}
			if ( $limit && $writes >= $limit ) {
				++$stats['deferred'];
				continue;
			}
			++$writes;
			if ( ! $id ) {
				++$stats['created'];
			} else {
				++$stats[ $existing[ $id ]['open'] ? 'updated' : 'reopened' ];
			}
			if ( ! $dry_run ) {
				$id          = self::write( $id, $data );
				$seen[ $id ] = true;
			}
		}
		foreach ( $existing as $id => $post ) {
			if ( $post['open'] && ! isset( $seen[ $id ] ) && ! $stats['deferred'] ) {
				++$stats['closed'];
				if ( ! $dry_run ) {
					update_post_meta( $id, '_mt_open', '0' );
					update_post_meta( $id, '_mt_closed_at', current_time( 'mysql', true ) );
				}
			}
		}
		if ( ! $dry_run ) {
			wp_defer_term_counting( false );
			update_option( self::OPTION_SYNC, array( 'at' => time() ) + $stats, false );
			do_action( 'mmi_teach_jobs_synced', $stats );
		}
		return $stats;
	}

	/** When the last sync ran (Unix time), for cache keys. */
	public static function synced_at(): int {
		$s = get_option( self::OPTION_SYNC );
		return (int) ( $s['at'] ?? 0 );
	}

	/** Eligibility's job array for an mt_job post. */
	public static function job( int $post_id ): ?array {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return null;
		}
		$m = get_post_meta( $post_id );
		$v = static fn( $k ) => (string) ( $m[ $k ][0] ?? '' );
		return Eligibility::job_from_row(
			array(
				'role'         => $v( '_mt_role' ),
				'sub_kind'     => $v( '_mt_sub_kind' ),
				'employer'     => $v( '_mt_employer' ),
				'title'        => get_the_title( $post_id ),
				'sector'       => $v( '_mt_sector' ),
				'requirements' => $v( '_mt_requirements' ),
			)
		) + array(
			'id'     => $post_id,
			'agency' => $v( '_mt_agency' ),
			'open'   => '1' === $v( '_mt_open' ),
		);
	}

	/** IDs of open jobs, newest first (for eligibility lists). */
	public static function open_ids(): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_mt_open' AND m.meta_value = '1' WHERE p.post_type = %s AND p.post_status = 'publish' ORDER BY p.post_date DESC",
					self::POST_TYPE
				)
			)
		);
	}

	/* ---------- internals ---------- */

	/**
	 * Open in-area rows, earlier boards first, with cross-board duplicates folded in: the same
	 * employer and title on two boards is one job; on one board it's several openings.
	 *
	 * @return array[] each: row + 'also' => [[source, url], …]
	 */
	private static function postings(): array {
		$priority = array();
		foreach ( Refresher::sources() as $i => $source ) {
			$priority[ $source->id() ] = $i;
		}
		$rows = Store::open_rows( array_keys( Plugin::COUNTIES ) );
		usort( $rows, static fn( $a, $b ) => ( $priority[ $a['source'] ] ?? 99 ) <=> ( $priority[ $b['source'] ] ?? 99 ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
		$kept   = array();
		$by_job = array();
		foreach ( $rows as $row ) {
			$job  = self::job_key( $row['employer'], $row['title'] );
			$twin = null;
			foreach ( $by_job[ $job ] ?? array() as $i ) {
				if ( $kept[ $i ]['source'] !== $row['source'] && ! in_array( $row['source'], array_column( $kept[ $i ]['also'], 'source' ), true ) ) {
					$twin = $i;
					break;
				}
			}
			if ( null !== $twin ) {
				$kept[ $twin ]['also'][] = array(
					'source' => $row['source'],
					'url'    => $row['url'],
					'key'    => $row['source'] . ':' . $row['source_id'],
				);
				continue;
			}
			$by_job[ $job ][] = count( $kept );
			$kept[]           = $row + array( 'also' => array() );
		}
		return $kept;
	}

	/** Employer + title, ignoring school years, bracketed notes, punctuation and case. */
	private static function job_key( string $employer, string $title ): string {
		$title = strtolower( preg_replace( '/\b(20)?\d{2}\s*[-–\/]\s*(20)?\d{2}\b|\(.*?\)/', ' ', $title ) );
		return Places::normalize( $employer ) . '|' . preg_replace( '/[^a-z0-9]+/', '', $title );
	}

	/** Post fields, meta and terms for one posting. */
	private static function post_data( array $row ): array {
		$details = $row['details'] ? (array) json_decode( $row['details'], true ) : array();
		$req     = json_decode( (string) $row['requirements'], true );
		$req     = is_array( $req ) ? $req : array();
		$county  = Plugin::COUNTIES[ $row['county_code'] ] ?? '';
		$sector  = Places::instance()->sector( $row['employer'] );
		$labels  = array();
		foreach ( Refresher::sources() as $source ) {
			$labels[ $source->id() ] = $source->label();
		}
		$pay     = array(
			'min'   => null === $row['pay_min'] ? null : (float) $row['pay_min'],
			'max'   => null === $row['pay_max'] ? null : (float) $row['pay_max'],
			'unit'  => $row['pay_unit'],
			'basis' => $row['pay_basis'],
		);
		$posted  = $row['posted_date'] ?: get_date_from_gmt( $row['first_seen'], 'Y-m-d' );
		$summary = (string) ( $details['summary'] ?? '' );
		$school  = trim( (string) $row['location'] );
		$keys    = array_merge( array( $row['source'] . ':' . $row['source_id'] ), array_column( $row['also'], 'key' ) );
		$role    = (string) $row['role'] ?: ( '' === $row['sub_kind'] ? 'teacher' : 'substitute' );

		$meta = array(
			'_mt_key'           => $keys[0],
			'_mt_keys'          => $keys,
			'_mt_open'          => '1',
			'_mt_closed_at'     => '',
			'_mt_role'          => $role,
			'_mt_sub_kind'      => (string) $row['sub_kind'],
			'_mt_employer'      => $row['employer'],
			'_mt_school'        => $school,
			'_mt_county'        => $county,
			'_mt_sector'        => $sector,
			'_mt_posted'        => $posted,
			'_mt_closes'        => (string) ( $row['close_date'] ?? '' ),
			'_mt_close_text'    => (string) $row['close_text'],
			'_mt_start'         => (string) $row['start_text'],
			'_mt_appointment'   => (string) $row['appointment'],
			'_mt_url'           => $row['url'],
			'_mt_apply_url'     => $row['apply_url'] ?: $row['url'],
			'_mt_source'        => $labels[ $row['source'] ] ?? $row['source'],
			'_mt_also'          => array_map( static fn( $a ) => array( 'label' => $labels[ $a['source'] ] ?? $a['source'], 'url' => $a['url'] ), $row['also'] ),
			'_mt_pay_label'     => Pay::label( $pay ),
			'_mt_pay_text'      => $row['pay_text'] ?: (string) ( $details['salary'] ?? '' ),
			'_mt_pay_basis'     => (string) $row['pay_basis'],
			'_mt_agency'        => (string) $row['agency'],
			'_mt_agency_basis'  => (string) $row['agency_basis'],
			'_mt_requirements'  => (string) $row['requirements'],
			'_mt_credential'    => self::credential_label( $role, (string) $row['sub_kind'], $sector, $req ),
			'_mt_contact'       => array_intersect_key( (array) ( $details['contact'] ?? array() ), array_flip( array( 'name', 'title' ) ) ),
			'_mt_openings'      => (string) ( $details['openings'] ?? '' ),
			'_mt_display_id'    => ctype_digit( (string) $row['source_id'] ) ? (string) $row['source_id'] : ( preg_match( '/^[0-9A-F]{32}-(\d+)$/', (string) $row['source_id'], $m ) ? $m[1] : '' ),
		);
		// Pay as a number only when posted, so the pay filter's range covers real figures.
		if ( null !== $row['pay_day'] && 'posted' === $row['pay_basis'] ) {
			$meta['_mt_pay_day'] = (string) round( (float) $row['pay_day'] );
		}
		$terms = array(
			'mt_role'     => array( Classifier::ROLES[ $role ] ?? '' ),
			'mt_job_type' => array( self::JOB_TYPES[ (string) $row['sub_kind'] ] ?? '' ),
			'mt_county'   => array( $county ? $county . ' County' : '' ),
			'mt_employer' => array( $row['employer'] ),
			'mt_subject'  => array( Classifier::OTHER === $row['subject'] ? '' : $row['subject'] ),
			'mt_grade'    => array( 'Unspecified' === $row['grade_band'] ? '' : $row['grade_band'] ),
			'mt_degree'   => array( Requirements::DEGREES[ $req['degree'] ?? '' ] ?? '' ),
		);
		$content = implode(
			"\n\n",
			array_filter(
				array(
					$summary,
					sprintf( '%s%s.', $row['employer'], $school && $school !== $row['employer'] ? ' — ' . $school : '' ),
				)
			)
		);
		$post = array(
			'post_type'     => self::POST_TYPE,
			'post_status'   => 'publish',
			'post_title'    => $row['title'],
			'post_name'     => sanitize_title( $row['title'] . ' ' . self::short_employer( $row['employer'] ) ),
			'post_content'  => wp_kses( $content, array() ),
			'post_date'     => $posted . ' 08:00:00',
			'post_date_gmt' => get_gmt_from_date( $posted . ' 08:00:00' ),
			'post_author'   => 0,
			'comment_status' => 'closed',
			'ping_status'   => 'closed',
		);
		$meta['_mt_row_hash'] = sha1( (string) wp_json_encode( array( $post, $meta, $terms ) ) );
		return array(
			'post'  => $post,
			'meta'  => $meta,
			'terms' => $terms,
		);
	}

	private static function write( int $id, array $data ): int {
		$post = $data['post'];
		if ( $id ) {
			$post['ID'] = $id;
			unset( $post['post_name'] ); // Keep the URL people may have shared.
			wp_update_post( wp_slash( $post ) );
		} else {
			$id = (int) wp_insert_post( wp_slash( $post ), true );
			if ( ! $id ) {
				return 0;
			}
		}
		foreach ( $data['meta'] as $key => $value ) {
			update_post_meta( $id, $key, is_array( $value ) ? $value : wp_slash( $value ) );
		}
		if ( ! isset( $data['meta']['_mt_pay_day'] ) ) {
			delete_post_meta( $id, '_mt_pay_day' );
		}
		// wp_set_object_terms, not tax_input: tax_input is skipped when no user is logged in (cron).
		foreach ( $data['terms'] as $tax => $names ) {
			wp_set_object_terms( $id, array_values( array_filter( $names ) ), $tax );
		}
		return $id;
	}

	/** Every mt_job post: id => [keys, hash, open], in two queries. */
	private static function existing(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, m.meta_key, m.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_mt_keys','_mt_row_hash','_mt_open') WHERE p.post_type = %s",
				self::POST_TYPE
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$id                 = (int) $r['ID'];
			$out[ $id ]       ??= array(
				'keys' => array(),
				'hash' => '',
				'open' => false,
			);
			if ( '_mt_keys' === $r['meta_key'] ) {
				$out[ $id ]['keys'] = (array) maybe_unserialize( $r['meta_value'] );
			} elseif ( '_mt_row_hash' === $r['meta_key'] ) {
				$out[ $id ]['hash'] = $r['meta_value'];
			} else {
				$out[ $id ]['open'] = '1' === $r['meta_value'];
			}
		}
		return $out;
	}

	/** What a job asks for, in a few words, for the job card. */
	private static function credential_label( string $role, string $kind, string $sector, array $req ): string {
		$keys = array_column( (array) ( $req['licenses'] ?? array() ), 'key' );
		if ( 'aide' === $role ) {
			return in_array( 'sepa', $keys, true ) ? __( 'High-school diploma + program aide license', 'mmi-teach' ) : __( 'High-school diploma; no license', 'mmi-teach' );
		}
		if ( 'substitute' === $role && 'long_term' !== $kind ) {
			return 'bachelor' === ( $req['degree'] ?? '' ) ? __( 'Substitute permit + bachelor’s degree', 'mmi-teach' ) : __( 'Substitute permit or any license', 'mmi-teach' );
		}
		$areas = array_values(
			array_filter(
				(array) ( $req['licenses'] ?? array() ),
				static fn( $l ) => '' !== ( $l['key'] ?? '' ) && ! in_array( $l['category'] ?? '', array( 'substitute', 'aide' ), true ) && ! in_array( $l['key'], array( 'assignment', 'any_license' ), true )
			)
		);
		$name  = $areas ? ( $areas[0]['short'] ?? $areas[0]['title'] ) . ( count( $areas ) > 1 ? ' +' . ( count( $areas ) - 1 ) : '' ) : '';
		if ( 'private' === $sector && 'teacher' === $role ) {
			return __( 'DPI license or bachelor’s (private school)', 'mmi-teach' );
		}
		if ( 'substitute' === $role ) {
			return $name ? sprintf( __( 'License in %s (permit: up to 45 days)', 'mmi-teach' ), $name ) : __( 'Teaching license (permit: up to 45 days)', 'mmi-teach' );
		}
		return $name ? sprintf( __( 'Wisconsin license: %s', 'mmi-teach' ), $name ) : __( 'Wisconsin teaching license', 'mmi-teach' );
	}

	private static function short_employer( string $employer ): string {
		return trim( preg_replace( '/\b(school district( of)?|public schools|area school district|joint school district|unified|schools?|district)\b/i', ' ', $employer ) );
	}
}
