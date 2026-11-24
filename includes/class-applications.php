<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applications saved on the site (mt_application, private, author = applicant).
 *
 * The site keeps the application and the applicant finishes it on the employer's own site:
 * nothing is sent to employers. Applicants see only their own (My applications is a PHP-scoped
 * query, never a Bricks post loop); staff see all of them in wp-admin.
 */
final class Applications {

	public const POST_TYPE = 'mt_application';

	public const STATUSES = array(
		'started'   => 'Started',
		'saved'     => 'Saved here',
		'finished'  => 'Finished on the employer’s site',
		'interview' => 'Interview',
		'offer'     => 'Offer',
		'hired'     => 'Hired',
		'closed'    => 'Closed',
	);

	/** Statuses the applicant can set themselves. */
	public const APPLICANT_STATUSES = array( 'saved', 'finished', 'interview', 'offer', 'hired', 'closed' );

	/** Availability and start choices on the apply form (no free text anywhere). */
	public const AVAILABILITY = array(
		'full_time' => 'Full time',
		'part_time' => 'Part time',
		'on_call'   => 'Day-to-day, on call',
	);
	public const START = array(
		'now'      => 'Right away',
		'2_weeks'  => 'Within 2 weeks',
		'semester' => 'Next semester',
	);

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_type' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( self::class, 'sortable' ) );
		add_action( 'pre_get_posts', array( self::class, 'admin_sort' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( self::class, 'meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'save_meta_box' ), 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	public static function register_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Applications', 'mmi-teach' ),
				'labels'              => array(
					'singular_name' => __( 'Application', 'mmi-teach' ),
					'edit_item'     => __( 'Application', 'mmi-teach' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Jobs::POST_TYPE,
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	/**
	 * Saves (or re-saves) the current user's application for a job.
	 *
	 * @return int|\WP_Error Application ID.
	 */
	public static function save( int $user_id, int $job_id, array $availability, string $start ) {
		$job = Jobs::job( $job_id );
		if ( ! $job || ! $job['open'] ) {
			return new \WP_Error( 'mt_job', __( 'This job has closed.', 'mmi-teach' ) );
		}
		$existing = self::for_job( $user_id, $job_id );
		$fit      = Eligibility::for_job( $job, Profile::current(), Profile::tasks() );
		$title    = get_the_title( $job_id );
		$id       = $existing ?: wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_author' => $user_id,
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$meta = array(
			'_mt_job_id'       => $job_id,
			'_mt_job_snapshot' => array(
				'title'     => $title,
				'employer'  => (string) get_post_meta( $job_id, '_mt_employer', true ),
				'apply_url' => (string) get_post_meta( $job_id, '_mt_apply_url', true ),
			),
			'_mt_employer'     => (string) get_post_meta( $job_id, '_mt_employer', true ),
			'_mt_readiness'    => $fit['gaps'],
			'_mt_left'         => count( $fit['gaps'] ),
			'_mt_fit'          => $fit['status'],
			'_mt_availability' => array_values( array_intersect( $availability, array_keys( self::AVAILABILITY ) ) ),
			'_mt_start'        => isset( self::START[ $start ] ) ? $start : '',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		if ( ! $existing || in_array( self::status( $id ), array( '', 'started' ), true ) ) {
			self::set_status( $id, 'saved', 'applicant' );
		}
		return (int) $id;
	}

	/** Records that someone began applying (joined or signed in from a job's apply button). */
	public static function start( int $user_id, int $job_id ): int {
		$job = Jobs::job( $job_id );
		if ( ! $job || ! $job['open'] || self::for_job( $user_id, $job_id ) ) {
			return 0;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_author' => $user_id,
				'post_title'  => get_the_title( $job_id ),
			)
		);
		if ( ! $id ) {
			return 0;
		}
		update_post_meta( $id, '_mt_job_id', $job_id );
		update_post_meta( $id, '_mt_employer', (string) get_post_meta( $job_id, '_mt_employer', true ) );
		update_post_meta(
			$id,
			'_mt_job_snapshot',
			array(
				'title'     => get_the_title( $job_id ),
				'employer'  => (string) get_post_meta( $job_id, '_mt_employer', true ),
				'apply_url' => (string) get_post_meta( $job_id, '_mt_apply_url', true ),
			)
		);
		self::set_status( $id, 'started', 'applicant' );
		return (int) $id;
	}

	public static function set_status( int $id, string $status, string $by ): bool {
		if ( ! isset( self::STATUSES[ $status ] ) || self::POST_TYPE !== get_post_type( $id ) ) {
			return false;
		}
		update_post_meta( $id, '_mt_status', $status );
		$history   = get_post_meta( $id, '_mt_history', true );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array( gmdate( 'c' ), $status, $by );
		update_post_meta( $id, '_mt_history', array_slice( $history, -30 ) );
		return true;
	}

	public static function status( int $id ): string {
		return (string) get_post_meta( $id, '_mt_status', true );
	}

	/** One application as display data, or null. */
	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$snap   = (array) get_post_meta( $id, '_mt_job_snapshot', true );
		$job_id = (int) get_post_meta( $id, '_mt_job_id', true );
		$status = self::status( $id );
		$open   = '1' === (string) get_post_meta( $job_id, '_mt_open', true );
		return array(
			'id'           => $id,
			'user'         => (int) $post->post_author,
			'job'          => $job_id,
			'job_title'    => (string) ( $snap['title'] ?? $post->post_title ),
			'employer'     => (string) ( $snap['employer'] ?? '' ),
			'apply_url'    => (string) ( $snap['apply_url'] ?? '' ),
			'job_url'      => $job_id ? (string) get_permalink( $job_id ) : '',
			'job_open'     => $open,
			'status'       => $status,
			'status_label' => self::STATUSES[ $status ] ?? '',
			'readiness'    => array_values( (array) get_post_meta( $id, '_mt_readiness', true ) ),
			'fit'          => (string) get_post_meta( $id, '_mt_fit', true ),
			'saved'        => get_post_time( 'U', true, $post ),
		);
	}

	/** The user's application for a job, or 0. */
	public static function for_job( int $user_id, int $job_id ): int {
		if ( ! $user_id || ! $job_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'author'         => $user_id,
				'meta_key'       => '_mt_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $job_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) ( $ids[0] ?? 0 );
	}

	/** @return int[] newest first */
	public static function ids_for_user( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'private',
					'author'         => $user_id,
					'fields'         => 'ids',
					'posts_per_page' => 200,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);
	}

	/* ---------- wp-admin: staff list ---------- */

	public static function columns( array $cols ): array {
		return array(
			'cb'           => $cols['cb'] ?? '',
			'mt_id'        => __( 'ID', 'mmi-teach' ),
			'title'        => __( 'Job', 'mmi-teach' ),
			'mt_employer'  => __( 'Employer', 'mmi-teach' ),
			'mt_applicant' => __( 'Applicant', 'mmi-teach' ),
			'mt_status'    => __( 'Status', 'mmi-teach' ),
			'mt_fit'       => __( 'Fit when saved', 'mmi-teach' ),
			'mt_left'      => __( 'Steps left', 'mmi-teach' ),
			'date'         => __( 'Saved', 'mmi-teach' ),
		);
	}

	public static function column( string $col, int $id ): void {
		$app = self::get( $id );
		if ( ! $app ) {
			return;
		}
		switch ( $col ) {
			case 'mt_id':
				echo (int) $id;
				break;
			case 'mt_employer':
				echo esc_html( $app['employer'] );
				break;
			case 'mt_applicant':
				$u = get_userdata( $app['user'] );
				echo $u ? esc_html( $u->display_name . ' <' . $u->user_email . '>' ) : '—';
				break;
			case 'mt_status':
				echo esc_html( $app['status_label'] );
				break;
			case 'mt_fit':
				echo esc_html( Eligibility::LABELS[ $app['fit'] ] ?? '' );
				break;
			case 'mt_left':
				echo (int) count( $app['readiness'] );
				break;
		}
	}

	public static function sortable( array $cols ): array {
		return $cols + array(
			'mt_id'        => 'ID',
			'title'        => 'title',
			'mt_employer'  => 'mt_employer',
			'mt_applicant' => 'author',
			'mt_status'    => 'mt_status',
			'mt_fit'       => 'mt_fit',
			'mt_left'      => 'mt_left',
		);
	}

	public static function admin_sort( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || self::POST_TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		$by = (string) $q->get( 'orderby' );
		if ( 'mt_status' === $by || 'mt_fit' === $by ) {
			$q->set( 'meta_key', '_' . $by );
			$q->set( 'orderby', 'meta_value' );
		} elseif ( 'mt_employer' === $by ) {
			$q->set( 'meta_key', '_mt_employer' );
			$q->set( 'orderby', 'meta_value' );
		} elseif ( 'mt_left' === $by ) {
			$q->set( 'meta_key', '_mt_left' );
			$q->set( 'orderby', 'meta_value_num' );
		}
	}

	public static function meta_box(): void {
		add_meta_box( 'mt_application', __( 'Application', 'mmi-teach' ), array( self::class, 'render_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function render_box( \WP_Post $post ): void {
		$app = self::get( $post->ID );
		$u   = get_userdata( $app['user'] );
		wp_nonce_field( 'mt_app_status', 'mt_app_nonce' );
		echo '<p><strong>' . esc_html__( 'Applicant', 'mmi-teach' ) . ':</strong> ' . esc_html( $u ? $u->display_name . ' <' . $u->user_email . '>' : '—' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Job', 'mmi-teach' ) . ':</strong> <a href="' . esc_url( $app['job_url'] ) . '">' . esc_html( $app['job_title'] . ' — ' . $app['employer'] ) . '</a>' . ( $app['job_open'] ? '' : ' ' . esc_html__( '(closed)', 'mmi-teach' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Employer’s application', 'mmi-teach' ) . ':</strong> <a href="' . esc_url( $app['apply_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $app['apply_url'] ) . '</a></p>';
		$left = array_map( static fn( $id ) => Rules::item( $id )['title'] ?? $id, $app['readiness'] );
		echo '<p><strong>' . esc_html__( 'Missing when saved', 'mmi-teach' ) . ':</strong> ' . esc_html( $left ? implode( '; ', $left ) : __( 'nothing', 'mmi-teach' ) ) . '</p>';
		$avail = array_map( static fn( $k ) => self::AVAILABILITY[ $k ] ?? $k, (array) get_post_meta( $post->ID, '_mt_availability', true ) );
		echo '<p><strong>' . esc_html__( 'Availability', 'mmi-teach' ) . ':</strong> ' . esc_html( implode( ', ', $avail ) ?: '—' ) . ' · <strong>' . esc_html__( 'Can start', 'mmi-teach' ) . ':</strong> ' . esc_html( self::START[ (string) get_post_meta( $post->ID, '_mt_start', true ) ] ?? '—' ) . '</p>';
		echo '<p><label for="mt_status"><strong>' . esc_html__( 'Status', 'mmi-teach' ) . '</strong></label><br><select name="mt_status" id="mt_status">';
		foreach ( self::STATUSES as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $app['status'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html__( 'Demo: applications are kept here only; employers receive nothing from this site.', 'mmi-teach' ) . '</p>';
		$history = (array) get_post_meta( $post->ID, '_mt_history', true );
		if ( $history ) {
			echo '<p><strong>' . esc_html__( 'History', 'mmi-teach' ) . '</strong></p><ul>';
			foreach ( array_reverse( $history ) as $h ) {
				echo '<li>' . esc_html( wp_date( 'M j, Y g:i a', (int) strtotime( (string) $h[0] ) ) . ' — ' . ( self::STATUSES[ $h[1] ] ?? $h[1] ) . ' (' . $h[2] . ')' ) . '</li>';
			}
			echo '</ul>';
		}
	}

	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['mt_app_nonce'], $_POST['mt_status'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mt_app_nonce'] ) ), 'mt_app_status' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_POST['mt_status'] ) );
		if ( $status !== self::status( $post_id ) ) {
			self::set_status( $post_id, $status, 'staff' );
		}
	}

	/* ---------- privacy tools (Tools → Export / Erase Personal Data) ---------- */

	public static function exporters( array $exporters ): array {
		$exporters['mmi-teach'] = array(
			'exporter_friendly_name' => __( 'School job applications', 'mmi-teach' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers['mmi-teach'] = array(
			'eraser_friendly_name' => __( 'School job applications', 'mmi-teach' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	public static function export( string $email ): array {
		$user  = get_user_by( 'email', $email );
		$items = array();
		if ( $user ) {
			foreach ( self::ids_for_user( $user->ID ) as $id ) {
				$app     = self::get( $id );
				$items[] = array(
					'group_id'    => 'mt-applications',
					'group_label' => __( 'Applications', 'mmi-teach' ),
					'item_id'     => 'mt-application-' . $id,
					'data'        => array(
						array( 'name' => __( 'Job', 'mmi-teach' ), 'value' => $app['job_title'] . ' — ' . $app['employer'] ),
						array( 'name' => __( 'Status', 'mmi-teach' ), 'value' => $app['status_label'] ),
						array( 'name' => __( 'Saved', 'mmi-teach' ), 'value' => wp_date( 'Y-m-d', $app['saved'] ) ),
					),
				);
			}
			$profile = get_user_meta( $user->ID, Profile::META, true );
			if ( is_array( $profile ) && $profile ) {
				$items[] = array(
					'group_id'    => 'mt-profile',
					'group_label' => __( 'Eligibility answers', 'mmi-teach' ),
					'item_id'     => 'mt-profile',
					'data'        => array( array( 'name' => __( 'Answers', 'mmi-teach' ), 'value' => wp_json_encode( $profile ) ) ),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			foreach ( self::ids_for_user( $user->ID ) as $id ) {
				$removed = (bool) wp_delete_post( $id, true ) || $removed;
			}
			$removed = delete_user_meta( $user->ID, Profile::META ) || $removed;
			$removed = delete_user_meta( $user->ID, Profile::META_TASKS ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
