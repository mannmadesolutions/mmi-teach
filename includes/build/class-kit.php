<?php
namespace MMI_Teach\Build;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for writing Bricks data from code: element trees, global classes, pages and
 * templates. Same approach as the MannMade site kit, carried here so this public plugin
 * stands alone.
 *
 * Every write is re-read to prove it landed (Bricks silently drops _bricks_page_content_2
 * writes made without builder access, so run with `wp --user=1`). A page or template whose
 * Bricks data was changed since this kit last wrote it is skipped unless forced, so edits made
 * in the builder survive a rebuild.
 */
final class Kit {

	public const CATEGORY = 'mmi-teach';

	/** name => [id, name, settings, category] */
	private static array $classes = array();

	/** Report lines for the CLI. */
	public static array $log = array();

	/** Registers a global class; returns its ID. */
	public static function cls( string $name, array $settings ): string {
		$id = 'mt' . substr( md5( 'class-' . $name ), 0, 6 );
		if ( isset( $settings['_cssCustom'] ) ) {
			// Bricks prints a global class's custom CSS verbatim: use the class selector, not %root%.
			$settings['_cssCustom'] = str_replace( '%root%', '.' . $name, $settings['_cssCustom'] );
		}
		self::$classes[ $name ] = array(
			'id'       => $id,
			'name'     => $name,
			'settings' => $settings,
			'category' => self::CATEGORY,
		);
		return $id;
	}

	/** @return string[] class IDs */
	public static function c( string ...$names ): array {
		$ids = array();
		foreach ( $names as $n ) {
			if ( ! isset( self::$classes[ $n ] ) ) {
				throw new \RuntimeException( "Unknown global class {$n}" );
			}
			$ids[] = self::$classes[ $n ]['id'];
		}
		return $ids;
	}

	/**
	 * A nested element. Put '_id' in $settings to fix the element's ID (needed where other
	 * elements point at it: query loops for filters and pagination).
	 */
	public static function el( string $name, array $settings = array(), array $kids = array(), array $classes = array() ): array {
		if ( $classes ) {
			$settings['_cssGlobalClasses'] = self::c( ...$classes );
		}
		return array(
			'name'     => $name,
			'settings' => $settings,
			'kids'     => $kids,
		);
	}

	/** Nested tree => Bricks' flat element list, IDs stable across builds. */
	public static function flat( array $tree, string $seed, $parent = 0, array &$out = array(), string $path = '' ): array {
		foreach ( $tree as $i => $node ) {
			$p        = $path . '/' . $i;
			$id       = self::node_id( $node, $seed . $p );
			$settings = $node['settings'];
			unset( $settings['_id'] );
			$at    = count( $out );
			$out[] = array(
				'id'       => $id,
				'name'     => $node['name'],
				'parent'   => $parent,
				'children' => array(),
				'settings' => $settings,
			);
			$kids = array();
			foreach ( $node['kids'] as $j => $kid ) {
				$kids[] = self::node_id( $kid, $seed . $p . '/' . $j );
			}
			$out[ $at ]['children'] = $kids;
			self::flat( $node['kids'], $seed, $id, $out, $p );
		}
		return $out;
	}

	private static function node_id( array $node, string $seed ): string {
		return (string) ( $node['settings']['_id'] ?? 'm' . substr( md5( $seed ), 0, 5 ) );
	}

	/**
	 * Writes a page's or template's Bricks data, unless it was edited in the builder since the
	 * last build (pass $force to overwrite). Returns 'written', 'same' or 'skipped'.
	 */
	public static function save( int $post_id, array $tree, string $area = 'content', bool $force = false ): string {
		$key  = array(
			'content' => '_bricks_page_content_2',
			'header'  => '_bricks_page_header_2',
			'footer'  => '_bricks_page_footer_2',
		)[ $area ];
		$flat = self::flat( $tree, $post_id . $area );
		$now  = get_post_meta( $post_id, $key, true );
		$was  = (string) get_post_meta( $post_id, '_mt_built_' . $area, true );
		if ( is_array( $now ) && $now && ! $force ) {
			if ( '' === $was || self::hash( $now ) !== $was ) {
				self::$log[] = sprintf( 'skipped %d %s: changed in the builder (use --force=%s)', $post_id, $area, get_post_field( 'post_name', $post_id ) );
				return 'skipped';
			}
			if ( self::hash( $flat ) === $was ) {
				return 'same';
			}
		}
		update_post_meta( $post_id, $key, wp_slash( $flat ) );
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );
		$back = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $back ) || count( $back ) !== count( $flat ) ) {
			throw new \RuntimeException( "Bricks data for {$post_id} ({$area}) didn't save. Run with --user=1." );
		}
		update_post_meta( $post_id, '_mt_built_' . $area, self::hash( $back ) );
		self::$log[] = sprintf( 'wrote %d %s: %d elements', $post_id, $area, count( $back ) );
		return 'written';
	}

	private static function hash( array $elements ): string {
		return md5( (string) wp_json_encode( $elements ) );
	}

	/** Creates or updates a page by its recorded ID or slug; returns its ID. */
	public static function page( string $slug, string $title, int $parent = 0, string $status = 'publish' ): int {
		$id   = \MMI_Teach\Pages::id( $slug );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'trash' === $post->post_status ) {
			$found = get_page_by_path( $parent ? get_post_field( 'post_name', $parent ) . '/' . basename( $slug ) : $slug );
			$post  = $found && 'trash' !== $found->post_status ? $found : null;
		}
		$data = array(
			'post_type'   => 'page',
			'post_title'  => $title,
			'post_name'   => basename( $slug ),
			'post_status' => $status,
			'post_parent' => $parent,
		);
		if ( $post ) {
			$data['ID'] = $post->ID;
			// Keep a title someone changed in WordPress.
			unset( $data['post_title'] );
			$id = (int) wp_update_post( $data );
		} else {
			$id = (int) wp_insert_post( $data );
		}
		if ( ! $id ) {
			throw new \RuntimeException( "Page {$slug} couldn't be saved." );
		}
		\MMI_Teach\Pages::remember( $slug, $id );
		return $id;
	}

	/** Creates or finds a Bricks template by title; sets its type and conditions. */
	public static function template( string $title, string $type, array $conditions ): int {
		$found = get_posts(
			array(
				'post_type'   => 'bricks_template',
				'title'       => $title,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		$id    = (int) ( $found[0] ?? wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		) );
		update_post_meta( $id, '_bricks_template_type', $type );
		$settings                       = get_post_meta( $id, '_bricks_template_settings', true );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['templateConditions'] = $conditions;
		update_post_meta( $id, '_bricks_template_settings', $settings );
		return $id;
	}

	/**
	 * Publishes the registered classes into Bricks, merged by name: classes this plugin didn't
	 * make are left alone, ours are replaced, and they sit in their own category.
	 */
	public static function publish_classes(): int {
		$all  = get_option( 'bricks_global_classes', array() );
		$all  = is_array( $all ) ? $all : array();
		$mine = array_keys( self::$classes );
		$all  = array_values( array_filter( $all, static fn( $c ) => ! in_array( $c['name'] ?? '', $mine, true ) ) );
		$all  = array_merge( $all, array_values( self::$classes ) );
		update_option( 'bricks_global_classes', $all );
		update_option( 'bricks_global_classes_timestamp', time() );

		$cats = get_option( 'bricks_global_classes_categories', array() );
		$cats = is_array( $cats ) ? $cats : array();
		if ( ! in_array( self::CATEGORY, array_column( $cats, 'id' ), true ) ) {
			$cats[] = array(
				'id'   => self::CATEGORY,
				'name' => 'MMI Teach',
			);
			update_option( 'bricks_global_classes_categories', $cats );
		}
		return count( self::$classes );
	}
}
