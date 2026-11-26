<?php
namespace MMI_Teach\Bricks;

use MMI_Teach\Applications;
use MMI_Teach\Checklist;
use MMI_Teach\Eligibility;
use MMI_Teach\Front;
use MMI_Teach\Jobs;
use MMI_Teach\Pages;
use MMI_Teach\Profile;
use MMI_Teach\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks query loop types for lists that aren't posts. Each row is a flat array of display-ready
 * strings read with {mt_row_<field>} tags; fields ending in _html are built escaped, the rest are
 * escaped when rendered. Rows are scoped in PHP to the current visitor: no loop here can be
 * pointed at someone else's data.
 */
final class Queries {

	public const TYPES = array(
		'mt_task_groups' => 'MMI Teach: checklist sections',
		'mt_tasks'       => 'MMI Teach: checklist items (inside sections)',
		'mt_needs'       => 'MMI Teach: what this job needs',
		'mt_paths'       => 'MMI Teach: my routes by role',
		'mt_my_apps'     => 'MMI Teach: my applications',
		'mt_personas'    => 'MMI Teach: sample people',
	);

	/** The checklist section being rendered by the outer loop (mt_task_groups). */
	private static string $group = '';

	private static ?array $tasks = null;

	public static function register(): void {
		add_filter( 'bricks/setup/control_options', array( self::class, 'types' ) );
		add_filter( 'bricks/query/run', array( self::class, 'run' ), 10, 2 );
		add_filter( 'bricks/query/loop_object', array( self::class, 'loop_object' ), 10, 3 );
		add_filter( 'bricks/query/loop_object_id', array( self::class, 'loop_object_id' ), 10, 3 );
		add_filter( 'bricks/query/loop_object_type', array( self::class, 'loop_object_type' ), 10, 3 );
	}

	public static function types( array $options ): array {
		foreach ( self::TYPES as $key => $label ) {
			$options['queryTypes'][ $key ] = $label;
		}
		return $options;
	}

	/** @param array $results @param \Bricks\Query $query */
	public static function run( $results, $query ) {
		$type = (string) ( $query->object_type ?? '' );
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return $results;
		}
		switch ( $type ) {
			case 'mt_task_groups':
				return self::task_groups();
			case 'mt_tasks':
				return array_values( array_filter( self::task_rows(), static fn( $r ) => '' === self::$group || $r['group'] === self::$group ) );
			case 'mt_needs':
				return self::needs();
			case 'mt_paths':
				return self::paths();
			case 'mt_my_apps':
				return self::my_apps();
			case 'mt_personas':
				return self::personas();
		}
		return array();
	}

	/** Remembers the section the outer checklist loop is on, for the inner item loop. */
	public static function loop_object( $object, $key, $query ) {
		if ( 'mt_task_groups' === ( $query->object_type ?? '' ) && is_array( $object ) ) {
			self::$group = (string) $object['key'];
		}
		return $object;
	}

	/** Rows aren't posts: post-scoped tags inside these loops keep resolving to the page or job. */
	public static function loop_object_id( $object_id, $object, $query_id ) {
		return isset( self::TYPES[ (string) \Bricks\Query::get_query_object_type( $query_id ) ] ) ? (int) get_the_ID() : $object_id;
	}

	public static function loop_object_type( $object_type, $object, $query_id ) {
		return isset( self::TYPES[ (string) \Bricks\Query::get_query_object_type( $query_id ) ] ) ? 'mt_row' : $object_type;
	}

	/** The current loop row, when inside one of these loops. */
	public static function row(): ?array {
		if ( ! class_exists( '\Bricks\Query' ) || ! \Bricks\Query::is_any_looping() ) {
			return null;
		}
		$row = \Bricks\Query::get_loop_object();
		return is_array( $row ) && isset( $row['_row'] ) ? $row : null;
	}

	/* ---------- contexts ---------- */

	/** The job a checklist or needs list is about: the job page itself, or ?job= on the checklist. */
	public static function context_job(): ?array {
		static $job = false;
		if ( false !== $job ) {
			return $job;
		}
		$id = is_singular( Jobs::POST_TYPE ) ? (int) get_queried_object_id() : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
		if ( ! $id && isset( $_GET['job'] ) ) {
			$id = absint( $_GET['job'] );
		}
		$job = $id ? Jobs::job( $id ) : null;
		return $job;
	}

	/* ---------- rows ---------- */

	private static function task_rows(): array {
		if ( null !== self::$tasks ) {
			return self::$tasks;
		}
		$fmt         = static fn( $d ) => $d ? wp_date( get_option( 'date_format' ), strtotime( $d . ' 12:00' ) ) : '';
		$where_opts  = Rules::where_options();
		self::$tasks = array();
		foreach ( Checklist::rows( Profile::current(), self::context_job() ) as $r ) {
			$state_label = array(
				'todo'    => __( 'To do', 'mmi-teach' ),
				'done'    => __( 'Done', 'mmi-teach' ),
				'na'      => __( 'Not needed', 'mmi-teach' ),
				'held'    => __( 'You have this', 'mmi-teach' ),
				'expired' => __( 'Expired', 'mmi-teach' ),
			)[ $r['state'] ] ?? '';
			$when = array_filter(
				array(
					$r['date'] ? sprintf( __( 'on %s', 'mmi-teach' ), $fmt( $r['date'] ) ) : '',
					$r['expiry'] ? sprintf( 'expired' === $r['state'] ? __( 'expired %s', 'mmi-teach' ) : __( 'valid until %s', 'mmi-teach' ), $fmt( $r['expiry'] ) ) : '',
					$r['where_done'] ? strtolower( $where_opts[ $r['where_done'] ] ?? '' ) : '',
				)
			);
			self::$tasks[] = array(
				'_row'         => 'task',
				'id'           => $r['id'],
				'title'        => $r['title'],
				'group'        => $r['group'],
				'group_label'  => $r['group_label'],
				'why'          => $r['why'],
				'blocks'       => $r['blocks'],
				'blocks_label' => $r['blocks_label'],
				'time'         => $r['time_label'],
				'cost'         => $r['cost_label'],
				'payer'        => $r['payer_label'],
				'proof'        => $r['proof'],
				'valid'        => $r['valid_label'],
				'never'        => $r['never'],
				'conflict'     => $r['conflict'] ? __( 'Confirm with the employer:', 'mmi-teach' ) . ' ' . $r['conflict'] : '',
				'state'        => $r['maybe'] && 'todo' === $r['state'] ? 'maybe' : $r['state'],
				'state_label'  => $r['maybe'] && 'todo' === $r['state'] ? __( 'Maybe; ask the employer', 'mmi-teach' ) : $state_label,
				'when'         => implode( ', ', $when ),
				'date'         => $fmt( $r['date'] ),
				'expiry'       => $fmt( $r['expiry'] ),
				'where_done'   => $r['where_done'],
				'collect'      => $r['collect'],
				'has_expiry'   => 'date+expiry' === $r['collect'] ? '1' : '',
				'editable'     => 'held' === $r['state'] ? '' : '1',
				'where_html'   => self::links_html( $r['where'] ),
				'how_html'     => $r['how'] ? '<ol>' . implode( '', array_map( static fn( $s ) => '<li>' . esc_html( $s ) . '</li>', $r['how'] ) ) . '</ol>' : '',
				'source_html'  => $r['source'] ? sprintf(
					/* translators: 1: link, 2: date */
					esc_html__( 'Source: %1$s, checked %2$s.', 'mmi-teach' ),
					'<a href="' . esc_url( $r['source'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) wp_parse_url( $r['source'], PHP_URL_HOST ) ) . '</a>',
					esc_html( $fmt( $r['verified_on'] ) )
				) : '',
			);
		}
		return self::$tasks;
	}

	private static function task_groups(): array {
		$groups = array();
		foreach ( self::task_rows() as $r ) {
			$g = &$groups[ $r['group'] ];
			$g ??= array(
				'_row'  => 'group',
				'key'   => $r['group'],
				'title' => $r['group_label'],
				'total' => 0,
				'done'  => 0,
			);
			if ( 'maybe' !== $r['state'] ) {
				++$g['total'];
				$g['done'] += in_array( $r['state'], array( 'done', 'na', 'held' ), true ) ? 1 : 0;
			}
			unset( $g );
		}
		foreach ( $groups as &$g ) {
			$g['count'] = $g['total'] ? sprintf( __( '%1$d of %2$d done', 'mmi-teach' ), $g['done'], $g['total'] ) : '';
		}
		unset( $g );
		self::$group = '';
		return array_values( $groups );
	}

	private static function needs(): array {
		$job = self::context_job();
		if ( ! $job ) {
			return array();
		}
		$labels = array(
			'have'    => __( 'You have this', 'mmi-teach' ),
			'need'    => __( 'You need this', 'mmi-teach' ),
			'maybe'   => __( 'Ask the employer', 'mmi-teach' ),
			'unknown' => __( 'Needed', 'mmi-teach' ),
		);
		return array_map(
			static fn( $n ) => array(
				'_row'        => 'need',
				'id'          => $n['id'],
				'title'       => $n['title'],
				'state'       => $n['state'],
				'state_label' => $labels[ $n['state'] ] ?? '',
				'note'        => $n['note'],
				'detail'      => $n['detail'],
			),
			Eligibility::needs( $job, Profile::current(), Profile::tasks() )
		);
	}

	private static function paths(): array {
		$p = Profile::current();
		if ( null === $p ) {
			return array();
		}
		$fit_ids = Front::applicable_ids();
		$by_role = array();
		foreach ( $fit_ids as $id ) {
			$role             = (string) get_post_meta( $id, '_mt_role', true );
			$by_role[ $role ] = ( $by_role[ $role ] ?? 0 ) + 1;
		}
		$rows = array();
		foreach ( Eligibility::paths( $p, Profile::tasks() ) as $role => $path ) {
			$steps = array_map(
				static function ( $id ) {
					$item = Rules::item( $id );
					if ( ! $item ) {
						return '';
					}
					// Where to start; the full how-to is on the checklist.
					$where = $item['where'][0] ?? null;
					return '<li><strong>' . esc_html( $item['short'] ?? $item['title'] ) . '</strong> · ' . esc_html( $item['time']['label'] ?? '' ) . ' · ' . esc_html( $item['cost']['label'] ?? '' )
						. ( $where ? '<br>' . esc_html__( 'Start here:', 'mmi-teach' ) . ' <a href="' . esc_url( $where['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $where['label'] ) . '</a>' : '' ) . '</li>';
				},
				$path['gaps']
			);
			$count  = $by_role[ $role ] ?? 0;
			$term   = get_term_by( 'name', $path['role_label'], 'mt_role' );
			$rows[] = array(
				'_row'         => 'path',
				'role'         => $role,
				'title'        => $path['role_label'],
				'state'        => $path['status'],
				'state_label'  => $path['label'],
				'summary'      => $path['summary'],
				'reasons_html' => '<ul>' . implode( '', array_map( static fn( $r ) => '<li>' . esc_html( $r ) . '</li>', $path['reasons'] ) ) . '</ul>',
				'steps_html'   => $steps ? '<ol>' . implode( '', $steps ) . '</ol>' : '',
				'count'        => (string) $count,
				'count_label'  => $count ? sprintf( _n( '%d job you can apply to now', '%d jobs you can apply to now', $count, 'mmi-teach' ), $count ) : __( 'No open jobs you can apply to yet', 'mmi-teach' ),
				'jobs_url'     => add_query_arg( array_filter( array( 'fit' => $count ? 'now' : '', 'role' => $term ? $term->slug : '' ) ), Pages::url( 'jobs' ) ),
				'wanted'       => $path['wanted'] ? '1' : '',
			);
		}
		return $rows;
	}

	private static function my_apps(): array {
		$user = get_current_user_id();
		if ( ! $user ) {
			return array();
		}
		$rows = array();
		foreach ( Applications::ids_for_user( $user ) as $id ) {
			$a = Applications::get( $id );
			if ( ! $a || $a['user'] !== $user ) {
				continue;
			}
			$left   = count( $a['readiness'] );
			$rows[] = array(
				'_row'          => 'app',
				'id'            => (string) $id,
				'title'         => $a['job_title'],
				'employer'      => $a['employer'],
				'state'         => $a['status'],
				'state_label'   => $a['status_label'],
				'saved'         => sprintf( __( 'Saved %s', 'mmi-teach' ), wp_date( get_option( 'date_format' ), $a['saved'] ) ),
				'job_url'       => $a['job_url'],
				'apply_url'     => $a['apply_url'],
				'checklist_url' => add_query_arg( 'job', $a['job'], Pages::url( 'checklist' ) ),
				'left'          => $left ? sprintf( _n( '%d step left on your checklist', '%d steps left on your checklist', $left, 'mmi-teach' ), $left ) : __( 'Nothing missing when you saved it', 'mmi-teach' ),
				'job_open'      => $a['job_open'] ? '1' : '',
				'closed_note'   => $a['job_open'] ? '' : __( 'This job has closed on the employer’s board.', 'mmi-teach' ),
				'finished'      => in_array( $a['status'], array( 'finished', 'interview', 'offer', 'hired' ), true ) ? '1' : '',
			);
		}
		return $rows;
	}

	private static function personas(): array {
		$active = Profile::persona();
		$rows   = array();
		foreach ( Profile::personas() as $key => $p ) {
			$rows[] = array(
				'_row'   => 'persona',
				'id'     => $key,
				'title'  => $p['name'],
				'blurb'  => $p['blurb'],
				'url'    => add_query_arg( 'mt_persona', $key, Pages::url( 'check' ) ),
				'active' => $active === $key ? '1' : '',
				'state'  => $active === $key ? 'active' : '',
			);
		}
		return $rows;
	}

	/** [{label,url}] => "<ul><li><a…>…</a></li></ul>" */
	public static function links_html( array $links ): string {
		if ( ! $links ) {
			return '';
		}
		return '<ul>' . implode(
			'',
			array_map( static fn( $l ) => '<li><a href="' . esc_url( $l['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $l['label'] ) . '</a></li>', $links )
		) . '</ul>';
	}
}
