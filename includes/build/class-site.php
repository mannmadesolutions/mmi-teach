<?php
namespace MMI_Teach\Build;

use MMI_Teach\Applications;
use MMI_Teach\Bricks\Actions;
use MMI_Teach\Classifier;
use MMI_Teach\Front;
use MMI_Teach\Pages;
use MMI_Teach\Profile;
use MMI_Teach\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Bricks site: design tokens, global classes, header, footer, job and 404 templates,
 * every page and the menu. Idempotent; run as `wp --user=1 mmi-teach build-site`.
 *
 * Styling lives in Bricks (palette, theme style, global classes in the "MMI Teach" category), so
 * it can be changed in the builder; pages edited in the builder are left alone on a rebuild.
 */
final class Site {

	/** Palette: key => hex. Each becomes a Bricks palette color defining --mt-<key>. */
	private const COLORS = array(
		'ink'         => '#16133a',
		'navy'        => '#1b1745',
		'navy-2'      => '#262062',
		'indigo'      => '#4f46e5',
		'indigo-dark' => '#3730a3',
		'lilac'       => '#b4bcfd',
		'indigo-soft' => '#eef0ff',
		'slate'       => '#555b6e',
		'line'        => '#e2e4ee',
		'mist'        => '#f6f6fb',
		'white'       => '#ffffff',
		'green'       => '#17703a',
		'green-soft'  => '#e6f5ea',
		'amber'       => '#8a4b00',
		'amber-soft'  => '#fff3dc',
		'blue'        => '#1d4ed8',
		'blue-soft'   => '#e8efff',
		'red'         => '#b42318',
		'red-soft'    => '#fdecea',
	);

	private const FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

	/** Bricks element ID of the jobs loop (filters and pagination point at it). */
	public const JOBS_LOOP = 'mtjobs';

	/** Bricks element ID of the jobs sort select (left out of the active-filter chips; it shows its own value). */
	public const JOBS_SORT = 'mtsort';

	private bool $force_all;
	private array $force;

	/** @param string[] $force Page slugs (or template names) to overwrite even if edited in the builder; ['all'] for every one. */
	public function __construct( array $force = array() ) {
		$this->force_all = in_array( 'all', $force, true );
		$this->force     = $force;
	}

	/* ====================================================================
	 * Step 1: settings (run in its own process: Bricks attaches its filter
	 * hooks at load time only when query filters were already enabled).
	 * ================================================================== */

	public function settings(): void {
		$gs                       = get_option( 'bricks_global_settings', array() );
		$gs                       = is_array( $gs ) ? $gs : array();
		$gs['postTypes']          = array( 'page' );
		$gs['enableQueryFilters'] = true;
		$gs['disableLazyLoad']    = true;
		$gs['saveFormSubmissions'] = false; // Our actions store what's needed; no copy of form data.
		// Bricks quotes a font stack as one family name, so the stack is set here instead of in the theme style.
		$css = ':root { --mt-font: ' . self::FONT . "; }\nbody, button, input, select, textarea { font-family: var(--mt-font); }";
		$gs['customCss'] = trim( preg_replace( '#/\* mmi-teach \*/.*?/\* /mmi-teach \*/#s', '', (string) ( $gs['customCss'] ?? '' ) ) . "\n/* mmi-teach */\n" . $css . "\n/* /mmi-teach */" );
		update_option( 'bricks_global_settings', $gs );

		$palette = array();
		foreach ( self::COLORS as $key => $hex ) {
			$palette[] = array(
				'id'   => 'mt' . str_replace( '-', '', $key ),
				'name' => ucwords( str_replace( '-', ' ', $key ) ),
				'raw'  => "var(--mt-$key)",
				'hex'  => $hex,
			);
		}
		$palettes = get_option( 'bricks_color_palette', array() );
		$palettes = array_values( array_filter( is_array( $palettes ) ? $palettes : array(), static fn( $p ) => 'mtpal' !== ( $p['id'] ?? '' ) ) );
		array_unshift(
			$palettes,
			array(
				'id'     => 'mtpal',
				'name'   => 'MannMade Teaching',
				'colors' => $palette,
			)
		);
		update_option( 'bricks_color_palette', $palettes );

		$styles       = get_option( 'bricks_theme_styles', array() );
		$styles       = is_array( $styles ) ? $styles : array();
		$styles['mt'] = array(
			'label'    => 'MannMade Teaching',
			'settings' => array(
				'conditions' => array(
					'conditions' => array(
						array(
							'id'   => 'mtcond',
							'main' => 'any',
						),
					),
				),
				'general'    => array( 'siteBackground' => array( 'color' => self::col( 'white' ) ) ),
				'typography' => array(
					'typographyBody'     => array(
						'color'       => self::col( 'ink' ),
						'font-size'   => '1.6rem',
						'line-height' => '1.6',
					),
					'typographyHeadings' => array(
						'color'          => self::col( 'ink' ),
						'font-weight'    => '700',
						'line-height'    => '1.2',
						'letter-spacing' => '-.01em',
					),
				),
				'links'      => array( 'typography' => array( 'color' => self::col( 'indigo' ) ) ),
			),
		);
		update_option( 'bricks_theme_styles', $styles );

		if ( class_exists( '\Bricks\Query_Filters' ) ) {
			// tables_check() creates the tables AND stamps their versions; Bricks reads URL filters
			// (?role=, ?sort=) only once the element table's version is 1.1. Bricks runs it on
			// admin_init for admins, but a logged-out admin-ajax call sets its 8-hour "checked"
			// transient without doing anything, so the stamp may never happen on its own.
			delete_transient( \Bricks\Query_Filters::DB_CHECK_TRANSIENT );
			\Bricks\Query_Filters::get_instance()->tables_check();
		}
		Kit::$log[] = 'settings: query filters on, palette, theme style, global settings';
	}

	/* ====================================================================
	 * Step 2: classes, templates, pages, menu.
	 * ================================================================== */

	public function build(): void {
		if ( ! \Bricks\Helpers::enabled_query_filters() ) {
			throw new \RuntimeException( 'Query filters are off. Run `wp --user=1 mmi-teach build-site --step=settings` first.' );
		}
		$this->classes();

		$ids = array();
		foreach ( Pages::PAGES as $slug => [ $title ] ) {
			$ids[ $slug ] = Kit::page( $slug, $title );
		}
		foreach ( self::GUIDES as $slug => $guide ) {
			$ids[ 'guides/' . $slug ] = Kit::page( 'guides/' . $slug, $guide[0], $ids['guides'] );
		}
		foreach ( Pages::PAGES as $slug => [ , $seo, $desc ] ) {
			update_post_meta( $ids[ $slug ], '_bricks_page_settings', array( 'documentTitle' => $seo, 'metaDescription' => $desc ) );
		}
		foreach ( self::GUIDES as $slug => [ $title, $intro ] ) {
			update_post_meta( $ids[ 'guides/' . $slug ], '_bricks_page_settings', array( 'documentTitle' => $title, 'metaDescription' => $intro ) );
		}

		$menu = $this->menu( $ids );

		$this->save( Kit::template( 'Header', 'header', array( array( 'id' => 'mthead', 'main' => 'any' ) ) ), $this->header( $menu ), 'header', 'header' );
		$this->save( Kit::template( 'Footer', 'footer', array( array( 'id' => 'mtfoot', 'main' => 'any' ) ) ), $this->footer(), 'footer', 'footer' );
		$this->save( Kit::template( 'Job', 'content', array( array( 'id' => 'mtjob', 'main' => 'postType', 'postType' => array( \MMI_Teach\Jobs::POST_TYPE ) ) ) ), $this->job_template(), 'content', 'job' );
		$this->save( Kit::template( 'Not found', 'error', array( array( 'id' => 'mt404', 'main' => 'error' ) ) ), $this->not_found(), 'content', '404' );

		$this->save( $ids['home'], $this->home(), 'content', 'home' );
		$this->save( $ids['jobs'], $this->jobs(), 'content', 'jobs' );
		$this->save( $ids['check'], $this->check(), 'content', 'check' );
		$this->save( $ids['checklist'], $this->checklist(), 'content', 'checklist' );
		$this->save( $ids['join'], $this->join(), 'content', 'join' );
		$this->save( $ids['sign-in'], $this->sign_in(), 'content', 'sign-in' );
		$this->save( $ids['reset-password'], $this->reset_password(), 'content', 'reset-password' );
		$this->save( $ids['my-applications'], $this->my_applications(), 'content', 'my-applications' );
		$this->save( $ids['account'], $this->account(), 'content', 'account' );
		$this->save( $ids['guides'], $this->guides_index(), 'content', 'guides' );
		foreach ( self::GUIDES as $slug => $guide ) {
			$this->save( $ids[ 'guides/' . $slug ], $this->guide( $slug ), 'content', $slug );
		}
		$this->save( $ids['privacy'], $this->privacy(), 'content', 'privacy' );

		$gs                        = get_option( 'bricks_global_settings', array() );
		$gs['reset_password_page'] = $ids['reset-password'];
		update_option( 'bricks_global_settings', $gs );
		update_option( 'wp_page_for_privacy_policy', $ids['privacy'] );

		Kit::$log[] = Kit::publish_classes() . ' global classes published';
		flush_rewrite_rules( false );
	}

	/**
	 * Makes the Bricks site the live site: front page, old pages retired (their URLs redirect).
	 * Separate from build() so pages can be checked before visitors see them.
	 */
	public function go_live(): void {
		$home = Pages::id( 'home' );
		if ( ! $home ) {
			throw new \RuntimeException( 'Build the site first.' );
		}
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home );
		foreach ( array( (int) get_option( 'mmi_teach_page_id' ), (int) get_option( 'mmi_teach_pathway_page_id' ), 2 ) as $old ) {
			if ( $old && $old !== $home && get_post( $old ) && 'page' === get_post_type( $old ) ) {
				wp_update_post( array( 'ID' => $old, 'post_status' => 'draft' ) );
			}
		}
		$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
		if ( $hello ) {
			wp_update_post( array( 'ID' => $hello->ID, 'post_status' => 'draft' ) );
		}
		update_option( Front::OPTION_LIVE, 1 );
		flush_rewrite_rules( false );
		Kit::$log[] = 'live: front page set, old dashboard and substitute pages drafted (their URLs redirect)';
	}

	private function save( int $post_id, array $tree, string $area, string $slug ): void {
		Kit::save( $post_id, $tree, $area, $this->force_all || in_array( $slug, $this->force, true ) );
	}

	/* ====================================================================
	 * Design system
	 * ================================================================== */

	private static function col( string $key ): array {
		return array(
			'raw' => "var(--mt-$key)",
			'hex' => self::COLORS[ $key ],
		);
	}

	private static function box( string $y, string $x = '' ): array {
		$x = '' === $x ? $y : $x;
		return array( 'top' => $y, 'bottom' => $y, 'left' => $x, 'right' => $x );
	}

	private static function radius( string $r ): array {
		return array( 'radius' => array( 'top' => $r, 'right' => $r, 'bottom' => $r, 'left' => $r ) );
	}

	private static function border( string $color, string $r = '12px', string $w = '1px' ): array {
		return array(
			'width'  => array( 'top' => $w, 'right' => $w, 'bottom' => $w, 'left' => $w ),
			'style'  => 'solid',
			'color'  => self::col( $color ),
			'radius' => array( 'top' => $r, 'right' => $r, 'bottom' => $r, 'left' => $r ),
		);
	}

	private function classes(): void {
		$z   = array( 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' );
		$c   = array( Kit::class, 'cls' );
		$t   = static fn( array $typo ) => array( '_typography' => $typo );
		$col = array( self::class, 'col' );

		// Layout.
		$c( 'mt-section', array( '_padding' => self::box( '4.5em', '1.6rem' ), '_padding:mobile_landscape' => self::box( '2.8em', '1.6rem' ), '_background' => array( 'color' => $col( 'white' ) ) ) );
		$c( 'mt-section--mist', array( '_background' => array( 'color' => $col( 'mist' ) ) ) );
		$c( 'mt-section--navy', array( '_background' => array( 'color' => $col( 'navy' ) ), '_typography' => array( 'color' => $col( 'white' ) ) ) );
		$c( 'mt-section--tight', array( '_padding' => self::box( '2.4em', '1.6rem' ), '_padding:mobile_landscape' => self::box( '1.8em', '1.6rem' ) ) );
		$c( 'mt-section--flush-top', array( '_padding' => array( 'top' => '0', 'bottom' => '4.5em', 'left' => '1.6rem', 'right' => '1.6rem' ) ) );
		$c( 'mt-inner', array( '_width' => '100%', '_widthMax' => '1160px', '_rowGap' => '1.4em' ) );
		$c( 'mt-inner--narrow', array( '_widthMax' => '820px' ) );
		$c( 'mt-stack', array( '_display' => 'flex', '_direction' => 'column', '_rowGap' => '.9em', '_alignItems' => 'flex-start', '_width' => '100%' ) );
		$c( 'mt-stack--tight', array( '_rowGap' => '.4em' ) );
		$c( 'mt-row', array( '_display' => 'flex', '_direction' => 'row', '_flexWrap' => 'wrap', '_columnGap' => '.8em', '_rowGap' => '.6em', '_alignItems' => 'center' ) );
		$c( 'mt-row--between', array( '_justifyContent' => 'space-between', '_width' => '100%' ) );
		foreach ( array( 2, 3 ) as $n ) {
			$c( "mt-grid-$n", array( '_display' => 'grid', '_gridTemplateColumns' => "repeat($n, minmax(0, 1fr))", '_gridGap' => '1.2em', '_width' => '100%', '_gridTemplateColumns:tablet_portrait' => 'repeat(' . min( $n, 2 ) . ', minmax(0, 1fr))', '_gridTemplateColumns:mobile_landscape' => 'minmax(0, 1fr)' ) );
		}
		$c( 'mt-split', array( '_display' => 'grid', '_gridTemplateColumns' => 'minmax(0, 7fr) minmax(0, 5fr)', '_gridGap' => '2.6em', '_alignItems' => 'start', '_width' => '100%', '_gridTemplateColumns:tablet_portrait' => 'minmax(0, 1fr)', '_gridGap:tablet_portrait' => '1.6em' ) );
		$c( 'mt-first-on-mobile', array( '_order:tablet_portrait' => '-1' ) );
		$c( 'mt-sticky', array( '_cssCustom' => '%root% { position: sticky; top: 1.2em; }' . "\n" . '@media (max-width: 991px) { %root% { position: static; } }' ) );

		// Type.
		$c( 'mt-eyebrow', array( '_margin' => $z ) + $t( array( 'font-size' => '.78em', 'font-weight' => '700', 'letter-spacing' => '.12em', 'text-transform' => 'uppercase', 'color' => $col( 'indigo' ) ) ) );
		$c( 'mt-eyebrow--light', $t( array( 'color' => $col( 'lilac' ) ) ) );
		$c( 'mt-h1', array( '_margin' => $z ) + $t( array( 'font-size' => 'clamp(2em, 4.6vw, 3.1em)', 'font-weight' => '800', 'line-height' => '1.1', 'letter-spacing' => '-.02em' ) ) );
		$c( 'mt-h1--light', $t( array( 'color' => $col( 'white' ) ) ) );
		$c( 'mt-h2', array( '_margin' => $z ) + $t( array( 'font-size' => 'clamp(1.45em, 2.8vw, 2em)', 'font-weight' => '750', 'line-height' => '1.2' ) ) );
		$c( 'mt-h2--light', $t( array( 'color' => $col( 'white' ) ) ) );
		$c( 'mt-h3', array( '_margin' => $z ) + $t( array( 'font-size' => '1.15em', 'font-weight' => '700', 'line-height' => '1.3' ) ) );
		$c( 'mt-lead', array( '_margin' => $z ) + $t( array( 'font-size' => '1.15em', 'line-height' => '1.55', 'color' => $col( 'slate' ) ) ) );
		$c( 'mt-lead--light', $t( array( 'color' => $col( 'lilac' ) ) ) );
		$c( 'mt-muted', array( '_margin' => $z ) + $t( array( 'color' => $col( 'slate' ) ) ) );
		$c( 'mt-small', array( '_margin' => $z ) + $t( array( 'font-size' => '.88em', 'color' => $col( 'slate' ) ) ) );
		$c( 'mt-small--light', $t( array( 'color' => $col( 'lilac' ) ) ) );
		$c( 'mt-strong', array( '_margin' => $z ) + $t( array( 'font-weight' => '700' ) ) );
		$c(
			'mt-prose',
			array(
				'_width'     => '100%',
				'_cssCustom' => "%root% p { margin: 0 0 .9em; }\n%root% > :last-child { margin-bottom: 0; }\n%root% ul, %root% ol { margin: 0 0 .9em; padding-left: 1.3em; }\n%root% li { margin-bottom: .35em; }\n%root% h2 { font-size: 1.35em; line-height: 1.25; margin: 1.6em 0 .5em; }\n%root% h3 { font-size: 1.08em; margin: 1.3em 0 .4em; }\n%root% a { text-decoration: underline; }\n%root% table { width: 100%; border-collapse: collapse; margin: 0 0 1.2em; font-size: .94em; }\n%root% th { text-align: left; font-size: .8em; text-transform: uppercase; letter-spacing: .05em; color: var(--mt-slate); border-bottom: 2px solid var(--mt-ink); padding: .6em .7em; }\n%root% td { border-bottom: 1px solid var(--mt-line); padding: .7em; vertical-align: top; }\n@media (max-width: 767px) { %root% thead { display: none; } %root% tr { display: block; padding: .7em 0; border-bottom: 1px solid var(--mt-line); } %root% td { display: block; border: 0; padding: .15em 0; } %root% td[data-label]::before { content: attr(data-label) ': '; font-weight: 700; color: var(--mt-slate); } }",
			)
		);

		// Buttons and links.
		$btn = array(
			'_padding'    => array( 'top' => '.8em', 'bottom' => '.8em', 'left' => '1.4em', 'right' => '1.4em' ),
			'_typography' => array( 'font-weight' => '700', 'font-size' => '.95em', 'color' => $col( 'white' ), 'text-align' => 'center', 'line-height' => '1.25' ),
			'_background' => array( 'color' => $col( 'indigo' ) ),
			'_background:hover' => array( 'color' => $col( 'indigo-dark' ) ),
			'_border'     => self::radius( '10px' ),
			'_cssCustom'  => "%root% { transition: background-color .15s; text-decoration: none; }\n%root%:focus-visible { outline: 3px solid var(--mt-lilac); outline-offset: 2px; }",
		);
		$c( 'mt-btn', $btn );
		$c( 'mt-btn--light', array( '_background' => array( 'color' => $col( 'white' ) ), '_background:hover' => array( 'color' => $col( 'indigo-soft' ) ), '_typography' => array( 'color' => $col( 'navy' ), 'font-weight' => '700', 'font-size' => '.95em' ) ) );
		$c( 'mt-btn--line', array( '_background' => array( 'color' => array( 'raw' => 'transparent' ) ), '_background:hover' => array( 'color' => $col( 'indigo-soft' ) ), '_typography' => array( 'color' => $col( 'indigo' ), 'font-weight' => '700', 'font-size' => '.95em' ), '_border' => self::border( 'indigo', '10px', '2px' ) ) );
		$c( 'mt-btn--ghost', array( '_background' => array( 'color' => array( 'raw' => 'transparent' ) ), '_background:hover' => array( 'color' => $col( 'navy-2' ) ), '_typography' => array( 'color' => $col( 'white' ), 'font-weight' => '700', 'font-size' => '.95em' ), '_border' => self::border( 'lilac', '10px', '2px' ) ) );
		$c( 'mt-btn--small', array( '_padding' => array( 'top' => '.5em', 'bottom' => '.5em', 'left' => '1em', 'right' => '1em' ), '_cssCustom' => '%root% { font-size: .88em; }' ) );
		$c( 'mt-link', $t( array( 'font-weight' => '700', 'color' => $col( 'indigo' ) ) ) + array( '_cssCustom' => "%root%::after { content: ' →'; }\n%root%:hover { color: var(--mt-indigo-dark); text-decoration: underline; }" ) );
		$c( 'mt-link--back', array( '_cssCustom' => "%root%::after { content: none; }\n%root%::before { content: '← '; }" ) );
		$c( 'mt-link--out', array( '_cssCustom' => "%root%::after { content: ' ↗'; }" ) );
		$c( 'mt-link--light', $t( array( 'color' => $col( 'lilac' ), 'font-weight' => '700' ) ) );

		// Cards, chips, badges.
		$c( 'mt-card', array( '_display' => 'flex', '_direction' => 'column', '_rowGap' => '.7em', '_alignItems' => 'flex-start', '_width' => '100%', '_background' => array( 'color' => $col( 'white' ) ), '_padding' => self::box( '1.4em', '1.4em' ), '_border' => self::border( 'line' ) ) );
		$c( 'mt-card--soft', array( '_background' => array( 'color' => $col( 'indigo-soft' ) ), '_border' => self::border( 'indigo-soft' ) ) );
		$c(
			'mt-steps',
			array(
				'_display'               => 'grid',
				'_gridTemplateColumns'   => 'repeat(3, minmax(0, 1fr))',
				'_gridGap'               => '1.1em',
				'_width'                 => '100%',
				'_gridTemplateColumns:tablet_portrait' => 'minmax(0, 1fr)',
				'_cssCustom'             => '%root% { counter-reset: mtstep; }',
			)
		);
		$c(
			'mt-step',
			array(
				'_display'    => 'grid',
				'_gridTemplateColumns' => '2.4em minmax(0, 1fr)',
				'_columnGap'  => '.9em',
				'_rowGap'     => '.4em',
				'_background' => array( 'color' => $col( 'navy-2' ) ),
				'_padding'    => self::box( '1.4em', '1.3em' ),
				'_border'     => self::border( 'indigo', '12px' ),
				'_typography' => array( 'color' => $col( 'white' ) ),
				'_cssCustom'  => "%root% { counter-increment: mtstep; }\n%root%::before { content: counter(mtstep); grid-row: span 2; font-size: 2em; font-weight: 800; line-height: 1; color: var(--mt-lilac); }\n%root% > * { grid-column: 2; }",
			)
		);
		$c( 'mt-step__title', array( '_margin' => $z ) + $t( array( 'font-size' => '1.12em', 'font-weight' => '700', 'color' => $col( 'white' ), 'line-height' => '1.3' ) ) );
		$c( 'mt-step__text', array( '_margin' => $z ) + $t( array( 'color' => $col( 'lilac' ), 'font-size' => '.95em' ) ) );
		$c( 'mt-chips', array( '_display' => 'flex', '_direction' => 'row', '_flexWrap' => 'wrap', '_columnGap' => '.4em', '_rowGap' => '.4em' ) );
		$c( 'mt-chip', array( '_margin' => $z, '_padding' => array( 'top' => '.2em', 'bottom' => '.2em', 'left' => '.7em', 'right' => '.7em' ), '_background' => array( 'color' => $col( 'mist' ) ), '_border' => self::border( 'line', '999px' ) ) + $t( array( 'font-size' => '.78em', 'font-weight' => '600', 'color' => $col( 'slate' ) ) ) + array( '_cssCustom' => '%root%:empty { display: none; }' ) );
		$c(
			'mt-badge',
			array(
				'_margin'     => $z,
				'_padding'    => array( 'top' => '.25em', 'bottom' => '.25em', 'left' => '.75em', 'right' => '.75em' ),
				'_border'     => self::radius( '999px' ),
				'_background' => array( 'color' => $col( 'mist' ) ),
				'_typography' => array( 'font-size' => '.8em', 'font-weight' => '700', 'color' => $col( 'ink' ) ),
				'_cssCustom'  => implode(
					"\n",
					array(
						'%root%:empty { display: none; }',
						'%root%[data-state="now"], %root%[data-state="have"], %root%[data-state="done"], %root%[data-state="held"], %root%[data-state="hired"], %root%[data-state="offer"] { background: var(--mt-green-soft); color: var(--mt-green); }',
						'%root%[data-state="check"], %root%[data-state="maybe"], %root%[data-state="interview"] { background: var(--mt-amber-soft); color: var(--mt-amber); }',
						'%root%[data-state="steps"], %root%[data-state="saved"], %root%[data-state="finished"], %root%[data-state="started"] { background: var(--mt-blue-soft); color: var(--mt-blue); }',
						'%root%[data-state="later"], %root%[data-state="need"], %root%[data-state="expired"] { background: var(--mt-red-soft); color: var(--mt-red); }',
						'%root%[data-state="todo"], %root%[data-state="na"], %root%[data-state="closed"] { background: var(--mt-mist); color: var(--mt-slate); }',
					)
				),
			)
		);

		// Job cards and the jobs page.
		$c( 'mt-job', array( '_display' => 'flex', '_direction' => 'column', '_rowGap' => '.45em', '_alignItems' => 'flex-start', '_width' => '100%', '_background' => array( 'color' => $col( 'white' ) ), '_padding' => self::box( '1.2em', '1.3em' ), '_border' => self::border( 'line' ), '_cssCustom' => "%root% { transition: border-color .15s, box-shadow .15s; }\n%root%:hover { border-color: var(--mt-lilac); box-shadow: 0 8px 24px rgba(22,19,58,.07); }" ) );
		$c( 'mt-job__title', array( '_margin' => $z ) + $t( array( 'font-size' => '1.1em', 'font-weight' => '700', 'line-height' => '1.3' ) ) + array( '_cssCustom' => "%root% a { color: var(--mt-ink); }\n%root% a:hover { color: var(--mt-indigo); }" ) );
		$c( 'mt-job__pay', array( '_margin' => $z ) + $t( array( 'font-weight' => '700', 'color' => $col( 'ink' ) ) ) );
		$c( 'mt-job__fit', array( '_display' => 'flex', '_direction' => 'row', '_flexWrap' => 'wrap', '_alignItems' => 'center', '_columnGap' => '.6em', '_rowGap' => '.3em', '_width' => '100%', '_padding' => array( 'top' => '.6em' ), '_border' => array( 'width' => array( 'top' => '1px' ), 'style' => 'solid', 'color' => $col( 'line' ) ) ) );
		$c( 'mt-jobs', array( '_display' => 'grid', '_gridTemplateColumns' => '17em minmax(0, 1fr)', '_gridGap' => '2em', '_alignItems' => 'start', '_width' => '100%', '_gridTemplateColumns:tablet_portrait' => 'minmax(0, 1fr)' ) );
		$c( 'mt-filters', array( '_display' => 'flex', '_direction' => 'column', '_rowGap' => '1.1em', '_width' => '100%', '_cssCustom' => "%root% .brxe-filter-checkbox li, %root% .brxe-filter-radio li { font-size: .92em; }\n%root% select, %root% input[type=search], %root% input[type=number] { width: 100%; border: 1px solid #9aa0b4; border-radius: 8px; padding: .45em .6em; background: #fff; color: var(--mt-ink); }\n%root% .brx-option-text { color: var(--mt-ink); }" ) );
		$c( 'mt-filter-label', array( '_margin' => array( 'top' => '0', 'bottom' => '.3em', 'left' => '0', 'right' => '0' ) ) + $t( array( 'font-size' => '.8em', 'font-weight' => '700', 'letter-spacing' => '.06em', 'text-transform' => 'uppercase', 'color' => $col( 'slate' ) ) ) );
		$c( 'mt-sort', array( '_display' => 'flex', '_direction' => 'row', '_alignItems' => 'center', '_columnGap' => '.6em', '_cssCustom' => "%root% .mt-filter-label { margin: 0; }\n%root% select { border: 1px solid #9aa0b4; border-radius: 8px; padding: .45em .6em; background: #fff; color: var(--mt-ink); }" ) );
		$c( 'mt-results', array( '_display' => 'flex', '_direction' => 'column', '_rowGap' => '.9em', '_width' => '100%' ) );
		$c( 'mt-notice', array( '_display' => 'flex', '_direction' => 'row', '_flexWrap' => 'wrap', '_alignItems' => 'center', '_columnGap' => '1em', '_rowGap' => '.5em', '_width' => '100%', '_padding' => self::box( '.9em', '1.1em' ), '_background' => array( 'color' => $col( 'indigo-soft' ) ), '_border' => self::radius( '10px' ) ) );
		$c( 'mt-notice--warn', array( '_background' => array( 'color' => $col( 'amber-soft' ) ), '_typography' => array( 'color' => $col( 'amber' ) ) ) );
		$c( 'mt-notice--stop', array( '_background' => array( 'color' => $col( 'red-soft' ) ), '_typography' => array( 'color' => $col( 'red' ) ) ) );

		// What this job needs.
		$c(
			'mt-need',
			array(
				'_display'   => 'grid',
				'_gridTemplateColumns' => '8.5em minmax(0, 1fr)',
				'_columnGap' => '1em',
				'_rowGap'    => '.2em',
				'_width'     => '100%',
				'_padding'   => array( 'top' => '.8em', 'bottom' => '.8em' ),
				'_border'    => array( 'width' => array( 'bottom' => '1px' ), 'style' => 'solid', 'color' => $col( 'line' ) ),
				'_gridTemplateColumns:mobile_landscape' => 'minmax(0, 1fr)',
				'_cssCustom' => '%root% > .mt-badge { justify-self: start; align-self: start; }',
			)
		);

		// Checklist.
		$c( 'mt-progress', array( '_width' => '100%', '_height' => '.7em', '_background' => array( 'color' => $col( 'line' ) ), '_border' => self::radius( '999px' ), '_overflow' => 'hidden' ) );
		$c( 'mt-progress__bar', array( '_height' => '100%', '_background' => array( 'color' => $col( 'green' ) ), '_border' => self::radius( '999px' ) ) );
		$c( 'mt-group', array( '_display' => 'flex', '_direction' => 'column', '_rowGap' => '.7em', '_width' => '100%' ) );
		$c(
			'mt-task',
			array(
				'_width'      => '100%',
				'_background' => array( 'color' => $col( 'white' ) ),
				'_border'     => self::border( 'line' ),
				'_overflow'   => 'hidden',
				'_cssCustom'  => implode(
					"\n",
					array(
						'%root% { border-left-width: 5px; }',
						'%root%[data-state="done"], %root%[data-state="held"] { border-left-color: var(--mt-green); }',
						'%root%[data-state="na"] { border-left-color: var(--mt-line); opacity: .8; }',
						'%root%[data-state="todo"] { border-left-color: var(--mt-indigo); }',
						'%root%[data-state="maybe"] { border-left-color: var(--mt-amber); }',
						'%root%[data-state="expired"] { border-left-color: var(--mt-red); }',
						'%root% > summary { list-style: none; cursor: pointer; }',
						'%root% > summary::-webkit-details-marker { display: none; }',
						'%root% > summary:focus-visible { outline: 3px solid var(--mt-lilac); outline-offset: -3px; }',
						'%root%[open] > summary .mt-task__chev { transform: rotate(180deg); }',
					)
				),
			)
		);
		$c( 'mt-task__summary', array( '_display' => 'grid', '_gridTemplateColumns' => '8em minmax(0, 1fr) 1.5em', '_columnGap' => '1em', '_rowGap' => '.25em', '_alignItems' => 'start', '_padding' => self::box( '1em', '1.1em' ), '_gridTemplateColumns:mobile_landscape' => 'minmax(0, 1fr) 1.5em', '_cssCustom' => "@media (max-width: 767px) { %root% > .mt-badge { grid-column: 1 / -1; justify-self: start; } }" ) );
		$c( 'mt-task__chev', array( '_cssCustom' => "%root% { transition: transform .15s; color: var(--mt-slate); font-size: 1.1em; line-height: 1; }" ) );
		$c( 'mt-task__body', array( '_display' => 'flex', '_direction' => 'column', '_rowGap' => '.9em', '_alignItems' => 'flex-start', '_padding' => array( 'top' => '0', 'bottom' => '1.2em', 'left' => '1.1em', 'right' => '1.1em' ) ) );
		$c( 'mt-facts', array( '_display' => 'grid', '_gridTemplateColumns' => 'repeat(3, minmax(0, 1fr))', '_gridGap' => '.8em', '_width' => '100%', '_gridTemplateColumns:mobile_landscape' => 'minmax(0, 1fr)', '_cssCustom' => "%root% > * { margin: 0; }\n%root% strong { display: block; font-size: .78em; letter-spacing: .05em; text-transform: uppercase; color: var(--mt-slate); }" ) );
		$c( 'mt-callout', array( '_margin' => $z, '_width' => '100%', '_padding' => self::box( '.7em', '.9em' ), '_border' => self::radius( '8px' ) ) + $t( array( 'font-size' => '.92em', 'font-weight' => '600' ) ) + array( '_cssCustom' => '%root%:empty { display: none; }' ) );
		$c( 'mt-callout--stop', array( '_background' => array( 'color' => $col( 'red-soft' ) ), '_typography' => array( 'color' => $col( 'red' ), 'font-size' => '.92em', 'font-weight' => '600' ) ) );
		$c( 'mt-callout--warn', array( '_background' => array( 'color' => $col( 'amber-soft' ) ), '_typography' => array( 'color' => $col( 'amber' ), 'font-size' => '.92em', 'font-weight' => '600' ) ) );

		// Forms.
		$c(
			'mt-form',
			array(
				'_width'     => '100%',
				'_cssCustom' => implode(
					"\n",
					array(
						'%root% input:not([type=checkbox]):not([type=radio]), %root% select, %root% textarea { width: 100%; background: #fff; color: var(--mt-ink); border: 1px solid #9aa0b4; border-radius: 8px; padding: .55em .75em; line-height: 1.4; height: auto; font-size: 1em; }',
						'%root% input:focus-visible, %root% select:focus-visible { outline: 3px solid var(--mt-lilac); outline-offset: 1px; }',
						'%root% .form-group { padding-bottom: 1.1em; }',
						'%root% label, %root% .label { font-weight: 700; color: var(--mt-ink); font-size: .98em; text-transform: none; letter-spacing: normal; }',
						'%root% label small, %root% .label small { display: block; font-weight: 400; color: var(--mt-slate); font-size: .88em; margin-top: .15em; text-transform: none; }',
						'%root% label small a, %root% .label small a { color: var(--mt-indigo); text-decoration: underline; }',
						'%root% .options-wrapper { display: flex; flex-direction: column; gap: .35em; margin-top: .4em; }',
						'%root% .options-wrapper li { display: flex; gap: .55em; align-items: flex-start; }',
						'%root% .options-wrapper label { font-weight: 400; }',
						'%root% .options-wrapper input { margin-top: .3em; width: 1.05em; height: 1.05em; accent-color: var(--mt-indigo); }',
						'%root% button.bricks-button { background: var(--mt-indigo); color: #fff; font-weight: 700; border-radius: 10px; padding: .8em 1.5em; font-size: .95em; }',
						'%root% button.bricks-button:hover { background: var(--mt-indigo-dark); }',
						'%root% .message.error { background: var(--mt-red-soft); color: var(--mt-red); }',
						'%root% .message.success { background: var(--mt-green-soft); color: var(--mt-green); }',
					)
				),
			)
		);
		$c( 'mt-form--grid', array( '_cssCustom' => "@media (min-width: 768px) { %root% .options-wrapper { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1em; } }" ) );
		$c( 'mt-form--compact', array( '_cssCustom' => "%root% { padding: .9em; background: var(--mt-mist); border-radius: 10px; }\n%root% .options-wrapper { flex-direction: row; flex-wrap: wrap; column-gap: 1.2em; }\n%root% .form-group { padding-bottom: .7em; }" ) );

		// Disclosure (native <details>).
		$c( 'mt-details', array( '_width' => '100%', '_cssCustom' => "%root% > summary { cursor: pointer; font-weight: 700; color: var(--mt-indigo); }\n%root% > summary:focus-visible { outline: 3px solid var(--mt-lilac); outline-offset: 2px; }\n%root%[open] > summary { margin-bottom: .8em; }" ) );

		// Site chrome.
		$c( 'mt-demo-bar', array( '_padding' => self::box( '.45em', '1.6rem' ), '_background' => array( 'color' => $col( 'ink' ) ) ) + $t( array( 'color' => $col( 'lilac' ), 'font-size' => '.8em', 'text-align' => 'center' ) ) );
		$c( 'mt-persona-bar', array( '_padding' => self::box( '.6em', '1.6rem' ), '_background' => array( 'color' => $col( 'amber-soft' ) ), '_display' => 'flex', '_direction' => 'row', '_flexWrap' => 'wrap', '_justifyContent' => 'center', '_columnGap' => '1em', '_rowGap' => '.3em' ) + $t( array( 'color' => $col( 'amber' ), 'font-size' => '.9em', 'font-weight' => '600' ) ) );
		$c( 'mt-header', array( '_padding' => self::box( '.8em', '1.6rem' ), '_background' => array( 'color' => $col( 'white' ) ), '_cssCustom' => '%root% { border-bottom: 1px solid var(--mt-line); }' ) );
		$c( 'mt-header__row', array( '_display' => 'flex', '_direction' => 'row', '_alignItems' => 'center', '_justifyContent' => 'space-between', '_columnGap' => '1.2em', '_width' => '100%', '_widthMax' => '1160px' ) );
		$c( 'mt-brand', $t( array( 'font-weight' => '800', 'font-size' => '1.1em', 'color' => $col( 'ink' ), 'letter-spacing' => '-.01em' ) ) + array( '_cssCustom' => "%root% { text-decoration: none; }\n%root% span { color: var(--mt-indigo); }" ) );
		$c( 'mt-nav', array( '_cssCustom' => "%root% .bricks-nav-menu { gap: 1.5em; }\n%root% .bricks-nav-menu > li > a { color: var(--mt-ink); font-weight: 600; font-size: .95em; }\n%root% .bricks-nav-menu > li > a:hover, %root% .bricks-nav-menu > li.current-menu-item > a { color: var(--mt-indigo); }\n%root% .bricks-mobile-menu-wrapper::before { background-color: var(--mt-navy); }\n%root% .bricks-mobile-menu > li > a { color: #fff; font-size: 1.1em; }\n%root% .bricks-mobile-menu-toggle { color: var(--mt-ink); }" ) );
		$c( 'mt-header__actions', array( '_display' => 'flex', '_direction' => 'row', '_alignItems' => 'center', '_columnGap' => '1em', '_display:mobile_landscape' => 'none' ) );
		$c( 'mt-footer', array( '_padding' => array( 'top' => '3em', 'bottom' => '2em', 'left' => '1.6rem', 'right' => '1.6rem' ), '_background' => array( 'color' => $col( 'navy' ) ) ) + $t( array( 'color' => $col( 'lilac' ), 'font-size' => '.92em' ) ) );
		$c( 'mt-brand--light', array( '_typography' => array( 'color' => $col( 'white' ), 'font-weight' => '800', 'font-size' => '1.1em' ), '_cssCustom' => '%root% span { color: var(--mt-lilac); }' ) );
		$c( 'mt-footer__cols', array( '_display' => 'grid', '_gridTemplateColumns' => 'minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr)', '_gridGap' => '2em', '_width' => '100%', '_gridTemplateColumns:mobile_landscape' => 'minmax(0, 1fr)' ) );
		$c( 'mt-footer__links', array( '_cssCustom' => "%root% a { color: var(--mt-white); display: block; padding: .15em 0; }\n%root% a:hover { color: var(--mt-lilac); }\n%root% ul { list-style: none; margin: 0; padding: 0; }" ) );
		$c( 'mt-footer__title', array( '_margin' => array( 'top' => '0', 'bottom' => '.5em', 'left' => '0', 'right' => '0' ) ) + $t( array( 'color' => $col( 'white' ), 'font-size' => '.82em', 'font-weight' => '700', 'letter-spacing' => '.08em', 'text-transform' => 'uppercase' ) ) );
	}

	/* ====================================================================
	 * Element shorthands
	 * ================================================================== */

	private static function h( string $text, string $tag = 'h2', string ...$classes ): array {
		return Kit::el( 'heading', array( 'text' => $text, 'tag' => $tag ), array(), $classes ?: array( 'h1' === $tag ? 'mt-h1' : ( 'h2' === $tag ? 'mt-h2' : 'mt-h3' ) ) );
	}

	private static function p( string $text, string ...$classes ): array {
		return Kit::el( 'text-basic', array( 'text' => $text, 'tag' => 'p' ), array(), $classes );
	}

	private static function span( string $text, string ...$classes ): array {
		return Kit::el( 'text-basic', array( 'text' => $text, 'tag' => 'span' ), array(), $classes );
	}

	private static function rich( string $html, string ...$classes ): array {
		return Kit::el( 'text', array( 'text' => $html ), array(), $classes ?: array( 'mt-prose' ) );
	}

	/** A link: $url may be a path, a full URL or a dynamic tag. */
	private static function link( string $text, string $url, array $classes = array( 'mt-link' ), bool $new_tab = false ): array {
		$settings = array( 'text' => $text, 'link' => self::url( $url, $new_tab ) );
		return Kit::el( 'text-link', $settings, array(), $classes );
	}

	private static function btn( string $text, string $url, array $classes = array( 'mt-btn' ), bool $new_tab = false ): array {
		return Kit::el( 'button', array( 'text' => $text, 'tag' => 'a', 'link' => self::url( $url, $new_tab ) ), array(), $classes );
	}

	private static function url( string $url, bool $new_tab = false ): array {
		$link = str_starts_with( $url, '{' )
			? array( 'type' => 'meta', 'useDynamicData' => $url )
			: array( 'type' => 'external', 'url' => str_starts_with( $url, '/' ) ? home_url( $url ) : $url );
		if ( $new_tab ) {
			$link['newTab'] = true;
			$link['rel']    = 'noopener';
		}
		return $link;
	}

	private static function div( array $kids, string ...$classes ): array {
		return Kit::el( 'div', array(), $kids, $classes );
	}

	private static function sec( array $kids, array $section = array(), array $inner = array(), array $settings = array() ): array {
		return Kit::el( 'section', $settings, array( Kit::el( 'container', array(), $kids, array_merge( array( 'mt-inner' ), $inner ) ) ), array_merge( array( 'mt-section' ), $section ) );
	}

	/** Visibility: show when every [tag, compare] holds (compare: empty, empty_not, or a value for ==). */
	private static function when( array $settings, array ...$rules ): array {
		$set = array();
		foreach ( $rules as $i => [ $tag, $cmp ] ) {
			$cond = array(
				'id'           => 'mtc' . $i . substr( md5( $tag . $cmp ), 0, 3 ),
				'key'          => 'dynamic_data',
				'dynamic_data' => $tag,
			);
			if ( in_array( $cmp, array( 'empty', 'empty_not' ), true ) ) {
				$cond['compare'] = $cmp;
			} else {
				$cond['compare'] = '==';
				$cond['value']   = $cmp;
			}
			$set[] = $cond;
		}
		$settings['_conditions'] = array( $set );
		return $settings;
	}

	/** Wraps elements in a div shown only when the rules hold. */
	private static function only( array $kids, array $rules, string ...$classes ): array {
		return Kit::el( 'div', self::when( array(), ...$rules ), $kids, $classes );
	}

	private static function attr( array $settings, string $name, string $value ): array {
		$settings['_attributes']   = $settings['_attributes'] ?? array();
		$settings['_attributes'][] = array(
			'id'    => 'mta' . substr( md5( $name . $value ), 0, 4 ),
			'name'  => $name,
			'value' => $value,
		);
		return $settings;
	}

	private static function badge( string $text, string $state ): array {
		return Kit::el( 'text-basic', self::attr( array( 'text' => $text, 'tag' => 'span' ), 'data-state', $state ), array(), array( 'mt-badge' ) );
	}

	/** Native disclosure: <details><summary>…</summary>…</details>. */
	private static function details( array $summary, array $body, array $classes = array( 'mt-details' ), array $settings = array() ): array {
		return Kit::el(
			'div',
			array_merge( array( 'tag' => 'custom', 'customTag' => 'details' ), $settings ),
			array_merge( array( Kit::el( 'div', array( 'tag' => 'custom', 'customTag' => 'summary' ), $summary ) ), $body ),
			$classes
		);
	}

	/** Form field: id, type, label, plus extras. */
	private static function field( string $key, string $type, string $label, array $extra = array() ): array {
		return array_merge(
			array(
				'id'    => Actions::F[ $key ] ?? $key,
				'type'  => $type,
				'label' => $label,
				'width' => 100,
			),
			$extra
		);
	}

	private static function options( array $map ): string {
		return implode( "\n", array_map( static fn( $k, $v ) => $k . ':' . $v, array_keys( $map ), $map ) );
	}

	private static function form( array $fields, array $settings, array $classes = array( 'mt-form' ) ): array {
		return Kit::el(
			'form',
			array_merge(
				array(
					'fields'           => $fields,
					'showLabels'       => true,
					'submitButtonText' => 'Save',
					'successMessage'   => 'Saved.',
				),
				$settings
			),
			array(),
			$classes
		);
	}

	/**
	 * Sort select for the jobs loop. Empty = the loop's own order (newest first); the pay options
	 * sort by _mt_pay_day, and Front::jobs_order() keeps jobs without posted pay in the list, last.
	 */
	private static function jobs_sort(): array {
		$pay = static fn( string $id, string $label, string $order ) => array(
			'id'            => $id,
			'optionLabel'   => $label,
			'optionSource'  => 'meta_value_num',
			'optionMetaKey' => \MMI_Teach\Front::PAY_KEY,
			'optionOrder'   => $order,
		);
		return Kit::el(
			'filter-select',
			array(
				'_id'            => self::JOBS_SORT,
				'filterQueryId'  => self::JOBS_LOOP,
				'filterAction'   => 'sort',
				'filterNiceName' => 'sort',
				'placeholder'    => 'Most recent',
				'sortOptions'    => array( $pay( 'mtpayhi', 'Highest pay', 'DESC' ), $pay( 'mtpaylo', 'Lowest pay', 'ASC' ) ),
				'_attributes'    => array(
					array(
						'id'    => 'mtsortlbl',
						'name'  => 'aria-label',
						'value' => 'Sort jobs',
					),
				),
			)
		);
	}

	/** One job as a card (inside a post loop of jobs). */
	private static function job_card(): array {
		return Kit::el(
			'block',
			array( 'tag' => 'article' ),
			array(
				self::div( array( self::span( '{mt_job_role}', 'mt-chip' ), self::span( '{mt_job_type}', 'mt-chip' ), self::span( '{mt_job_county}', 'mt-chip' ) ), 'mt-chips' ),
				Kit::el( 'heading', array( 'text' => '{post_title}', 'tag' => 'h3', 'link' => self::url( '{post_url}' ) ), array(), array( 'mt-job__title' ) ),
				self::p( '{mt_job_employer}', 'mt-muted' ),
				self::p( '{mt_job_school}', 'mt-small' ),
				self::div( array( self::span( '{mt_job_pay}', 'mt-job__pay' ), self::span( '{mt_job_posted}', 'mt-small' ), self::span( '{mt_job_closes}', 'mt-small' ) ), 'mt-row' ),
				self::p( 'Asks for: {mt_job_credential}', 'mt-small' ),
				self::only(
					array( self::badge( '{mt_job_fit}', '{mt_job_fit_state}' ), self::span( '{mt_job_fit_summary}', 'mt-small' ) ),
					array( array( '{mt_has_profile}', 'empty_not' ) ),
					'mt-job__fit'
				),
			),
			array( 'mt-job' )
		);
	}

	private static function jobs_query( int $per_page, array $extra = array() ): array {
		return array_merge(
			array(
				'objectType'      => 'post',
				'post_type'       => array( \MMI_Teach\Jobs::POST_TYPE ),
				'posts_per_page'  => $per_page,
				'orderby'         => 'date',
				'order'           => 'DESC',
				'meta_query'      => array(
					array(
						'id'      => 'mtopen',
						'key'     => '_mt_open',
						'value'   => '1',
						'compare' => '=',
					),
				),
				'no_results_text' => 'No open jobs match. Try fewer filters.',
			),
			$extra
		);
	}

	/* ====================================================================
	 * Menu, header, footer, templates
	 * ================================================================== */

	private function menu( array $ids ): int {
		$name = 'MannMade Teaching';
		$menu = wp_get_nav_menu_object( $name );
		$id   = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $name );
		// Every item in the menu, including invalid ones wp_get_nav_menu_items() hides (e.g. pointing at a missing page).
		foreach ( get_objects_in_term( $id, 'nav_menu' ) as $item_id ) {
			wp_delete_post( (int) $item_id, true );
		}
		$items = array(
			'jobs'      => 'Jobs',
			'check'     => 'What can I apply for?',
			'checklist' => 'My checklist',
			'guides'    => 'Guides',
		);
		$pos = 0;
		foreach ( $items as $slug => $label ) {
			wp_update_nav_menu_item(
				$id,
				0,
				array(
					'menu-item-title'     => $label,
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $ids[ $slug ],
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-position'  => ++$pos,
				)
			);
		}
		return $id;
	}

	private function header( int $menu ): array {
		return array(
			Kit::el( 'div', array(), array( self::span( 'Demo site · Applications are saved here and not sent to employers · Requirements are guidance, not legal advice' ) ), array( 'mt-demo-bar' ) ),
			Kit::el(
				'div',
				self::when( array(), array( '{mt_persona}', 'empty_not' ) ),
				array(
					self::span( 'You’re seeing the site as {mt_persona_name}, a sample person.' ),
					self::link( 'Use my own answers', '/?mt_persona=off', array( 'mt-link' ) ),
				),
				array( 'mt-persona-bar' )
			),
			Kit::el(
				'section',
				array( 'tag' => 'div' ),
				array(
					Kit::el(
						'container',
						array(),
						array(
							Kit::el( 'text-link', array( 'text' => 'MannMade <span>Teaching</span>', 'link' => self::url( '/' ) ), array(), array( 'mt-brand' ) ),
							Kit::el(
								'nav-menu',
								array(
									'menu'       => (string) $menu,
									'mobileMenu' => 'tablet_portrait',
									'ariaLabel'  => 'Main',
								),
								array(),
								array( 'mt-nav' )
							),
							self::div(
								array(
									Kit::el( 'text-link', self::when( array( 'text' => 'My applications', 'link' => self::url( '/my-applications/' ) ), array( '{mt_logged_in}', 'empty_not' ) ), array(), array( 'mt-link', 'mt-link--back' ) ),
									Kit::el( 'text-link', self::when( array( 'text' => 'Account', 'link' => self::url( '/account/' ) ), array( '{mt_logged_in}', 'empty_not' ) ), array(), array( 'mt-muted' ) ),
									Kit::el( 'button', self::when( array( 'text' => 'Sign in', 'tag' => 'a', 'link' => self::url( '/sign-in/' ) ), array( '{mt_logged_in}', 'empty' ) ), array(), array( 'mt-btn', 'mt-btn--line', 'mt-btn--small' ) ),
								),
								'mt-header__actions'
							),
						),
						array( 'mt-header__row' )
					),
				),
				array( 'mt-header' )
			),
		);
	}

	private function footer(): array {
		return array(
			Kit::el(
				'section',
				array( 'tag' => 'footer' ),
				array(
					Kit::el(
						'container',
						array(),
						array(
							self::div(
								array(
									self::div(
										array(
											Kit::el( 'text-link', array( 'text' => 'MannMade <span>Teaching</span>', 'link' => self::url( '/' ) ), array(), array( 'mt-brand', 'mt-brand--light' ) ),
											self::p( 'Open school jobs in Milwaukee, Waukesha and Racine counties, collected every three hours from WECAN, Milwaukee Public Schools, the Archdiocese of Milwaukee and six other public job boards. Requirements come from Wisconsin DPI, DHS and state law, each with its source and the date we checked it.', 'mt-small', 'mt-small--light' ),
											self::p( 'A demo by MannMade Solutions. Applications you save here stay here: finish each one on the employer’s own site. Guidance, not legal advice.', 'mt-small', 'mt-small--light' ),
										),
										'mt-stack'
									),
									self::div(
										array(
											self::p( 'Find work', 'mt-footer__title' ),
											self::rich( '<ul><li><a href="/jobs/">All jobs</a></li><li><a href="/jobs/?role=substitute-teacher">Substitute jobs</a></li><li><a href="/jobs/?role=aide-paraprofessional">Aide jobs</a></li><li><a href="/check/">What can I apply for?</a></li></ul>', 'mt-footer__links' ),
										)
									),
									self::div(
										array(
											self::p( 'Learn', 'mt-footer__title' ),
											self::rich( '<ul><li><a href="/guides/substitute-teaching/">Become a substitute</a></li><li><a href="/guides/aide-jobs/">Aide jobs</a></li><li><a href="/guides/teaching-license/">Licenses and permits</a></li><li><a href="/guides/wisconsin-requirements/">Every requirement</a></li><li><a href="/privacy/">Privacy</a></li></ul>', 'mt-footer__links' ),
										)
									),
								),
								'mt-footer__cols'
							),
						),
						array( 'mt-inner' )
					),
				),
				array( 'mt-footer' )
			),
		);
	}

	private function not_found(): array {
		return array(
			self::sec(
				array(
					self::h( 'That page isn’t here', 'h1' ),
					self::p( 'The job may have closed, or the link changed.', 'mt-lead' ),
					self::div( array( self::btn( 'See open jobs', '/jobs/' ), self::btn( 'What can I apply for?', '/check/', array( 'mt-btn', 'mt-btn--line' ) ) ), 'mt-row' ),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	private function job_template(): array {
		return array(
			self::sec(
				array(
					self::link( 'All jobs', '/jobs/', array( 'mt-link', 'mt-link--back' ) ),
					self::div( array( self::span( '{mt_job_role}', 'mt-chip' ), self::span( '{mt_job_type}', 'mt-chip' ), self::span( '{mt_job_county}', 'mt-chip' ) ), 'mt-chips' ),
					Kit::el( 'post-title', array( 'tag' => 'h1' ), array(), array( 'mt-h1' ) ),
					self::p( '{mt_job_employer}', 'mt-lead' ),
					self::p( '{mt_job_school}', 'mt-muted' ),
					self::div( array( self::span( '{mt_job_pay}', 'mt-job__pay' ), self::span( '{mt_job_posted}', 'mt-muted' ), self::span( '{mt_job_closes}', 'mt-muted' ), self::span( '{mt_job_start}', 'mt-muted' ) ), 'mt-row' ),
					self::div(
						array(
							self::span( 'Listed on {mt_job_source}', 'mt-small' ),
							self::link( 'View the original posting', '{mt_job_url}', array( 'mt-link', 'mt-link--out' ), true ),
							Kit::el( 'text', array( 'text' => '{mt_job_also_html}', 'tag' => 'span' ), array(), array( 'mt-small' ) ),
						),
						'mt-row'
					),
					self::only( array( self::span( 'This job closed on {mt_job_closed_on}. It stays here so saved applications still link to it.' ) ), array( array( '{mt_job_open}', 'empty' ) ), 'mt-notice', 'mt-notice--warn' ),
				),
				array( 'mt-section--mist', 'mt-section--tight' )
			),
			self::sec(
				array(
					self::div(
						array(
							self::div(
								array(
									self::h( 'What this job needs' ),
									self::only( array( self::p( 'Based on your answers: {mt_profile_summary}.', 'mt-small' ), self::link( 'Change', '/check/#answers' ) ), array( array( '{mt_has_profile}', 'empty_not' ) ), 'mt-row' ),
									Kit::el(
										'block',
										array(
											'hasLoop' => true,
											'query'   => array( 'objectType' => 'mt_needs' ),
										),
										array(
											self::badge( '{mt_row_state_label}', '{mt_row_state}' ),
											self::div( array( self::p( '{mt_row_title}', 'mt-strong' ), self::p( '{mt_row_note}', 'mt-small' ), self::p( '{mt_row_detail}', 'mt-small' ) ), 'mt-stack', 'mt-stack--tight' ),
										),
										array( 'mt-need' )
									),
									self::p( 'Plus the onboarding every school job has: a background check, TB screening, required trainings and hiring paperwork.', 'mt-muted' ),
									self::link( 'See your checklist for this job', '{mt_job_checklist_url}' ),
									self::only( array( self::p( '{mt_job_agency}', 'mt-muted' ), self::p( '{mt_job_agency_steps}', 'mt-small' ) ), array( array( '{mt_job_agency}', 'empty_not' ) ), 'mt-card', 'mt-card--soft' ),
									self::h( 'About this job' ),
									Kit::el( 'post-content', array(), array(), array( 'mt-prose' ) ),
									self::link( 'Read the full posting on {mt_job_source}', '{mt_job_url}', array( 'mt-link', 'mt-link--out' ), true ),
								),
								'mt-stack'
							),
							self::div(
								array(
									self::div(
										array(
											self::only(
												array(
													self::h( 'Do you qualify?', 'h3' ),
													self::p( 'Answer six questions (no sign-up) and every job shows whether you qualify, and the quickest route if you don’t yet.', 'mt-muted' ),
													self::btn( 'Check what I qualify for', '/check/' ),
												),
												array( array( '{mt_has_profile}', 'empty' ) ),
												'mt-stack'
											),
											self::only(
												array(
													self::p( 'Your fit', 'mt-eyebrow' ),
													self::badge( '{mt_job_fit}', '{mt_job_fit_state}' ),
													self::p( '{mt_job_fit_summary}', 'mt-strong' ),
													self::rich( '{mt_job_fit_reasons_html}' ),
												),
												array( array( '{mt_has_profile}', 'empty_not' ) ),
												'mt-stack'
											),
										),
										'mt-card'
									),
									Kit::el(
										'div',
										array( '_cssId' => 'apply' ),
										array(
											self::h( 'Apply', 'h3' ),
											self::p( 'Demo: your application is saved here, then you finish it on the employer’s own site. Nothing is sent to the employer.', 'mt-small' ),
											self::only( array( self::p( 'Applications for this job are closed.', 'mt-muted' ) ), array( array( '{mt_job_open}', 'empty' ) ) ),
											self::only(
												array(
													self::p( 'Saved {mt_job_applied_on} · {mt_job_applied}', 'mt-strong' ),
													self::p( 'Next: finish your application on the employer’s site. Use the same details you saved here.', 'mt-muted' ),
													self::btn( 'Continue to the employer’s application', '{mt_job_apply_url}', array( 'mt-btn' ), true ),
													self::link( 'My applications', '/my-applications/' ),
												),
												array( array( '{mt_job_applied}', 'empty_not' ) ),
												'mt-stack'
											),
											self::only(
												array(
													self::form(
														array(
															self::field( 'avail', 'checkbox', 'When can you work?', array( 'options' => self::options( Applications::AVAILABILITY ), 'valueLabelOptions' => true ) ),
															self::field( 'start', 'radio', 'When could you start?', array( 'options' => self::options( Applications::START ), 'valueLabelOptions' => true, 'value' => 'now' ) ),
															self::field( 'agree', 'checkbox', '', array( 'options' => 'yes:I understand this demo saves my application here and doesn’t send it to the employer; I’ll finish it on their site.', 'valueLabelOptions' => true, 'required' => true ) ),
														),
														array(
															'actions'          => array( 'mt_apply' ),
															'submitButtonText' => 'Save my application',
														)
													),
												),
												array( array( '{mt_job_open}', 'empty_not' ), array( '{mt_job_applied}', 'empty' ), array( '{mt_is_applicant}', 'empty_not' ) )
											),
											self::only(
												array(
													self::btn( 'Create an account to apply', '{mt_job_join_url}' ),
													self::link( 'I have an account: sign in', '{mt_job_signin_url}' ),
													self::link( 'Or go straight to the employer’s application', '{mt_job_apply_url}', array( 'mt-link', 'mt-link--out' ), true ),
												),
												array( array( '{mt_job_open}', 'empty_not' ), array( '{mt_logged_in}', 'empty' ) )
											),
											self::only(
												array( self::p( 'You’re signed in as staff. Applicant accounts can save applications.', 'mt-small' ) ),
												array( array( '{mt_logged_in}', 'empty_not' ), array( '{mt_is_applicant}', 'empty' ) )
											),
										),
										array( 'mt-card', 'mt-stack' )
									),
								),
								'mt-stack',
								'mt-sticky',
								'mt-first-on-mobile'
							),
						),
						'mt-split'
					),
				)
			),
		);
	}

	/* ====================================================================
	 * Pages
	 * ================================================================== */

	private function home(): array {
		$roles = array(
			array( 'Teacher', 'A Wisconsin teaching license in the subject. Private schools may accept a bachelor’s degree instead.', 'teacher', '{mt_open_count}' ),
			array( 'Substitute teacher', 'DPI’s substitute permit: an associate degree plus a short course. About 7–10 weeks and $175–$215 from today.', 'substitute-teacher', '{mt_sub_count}' ),
			array( 'Aide / paraprofessional', 'A high-school diploma. Special-education and Title I jobs add one more step.', 'aide-paraprofessional', '{mt_aide_count}' ),
		);
		$role_cards = array();
		foreach ( $roles as [ $title, $text, $slug ] ) {
			$role_cards[] = Kit::el(
				'div',
				array(),
				array(
					self::h( $title, 'h3' ),
					self::p( $text, 'mt-muted' ),
					self::link( 'See ' . strtolower( $title ) . ' jobs', '/jobs/?role=' . $slug ),
				),
				array( 'mt-card' )
			);
		}
		return array(
			self::sec(
				array(
					self::p( 'Milwaukee · Waukesha · Racine counties', 'mt-eyebrow', 'mt-eyebrow--light' ),
					self::h( 'Find a school job, and see exactly what it takes to start', 'h1', 'mt-h1', 'mt-h1--light' ),
					self::p( '{mt_open_count} open teacher, substitute and aide jobs, a two-minute check of what you qualify for, and one checklist of Wisconsin’s requirements. Free, and no account needed to look.', 'mt-lead', 'mt-lead--light' ),
					self::div( array( self::btn( 'Check what I qualify for', '/check/', array( 'mt-btn', 'mt-btn--light' ) ), self::btn( 'Browse {mt_open_count} open jobs', '/jobs/', array( 'mt-btn', 'mt-btn--ghost' ) ) ), 'mt-row' ),
					self::p( '{mt_updated} from WECAN, Milwaukee Public Schools and six other public job boards.', 'mt-small', 'mt-small--light' ),
				),
				array( 'mt-section--navy' ),
				array( 'mt-inner--narrow' )
			),
			self::sec(
				array(
					self::h( 'Three steps, no surprises' ),
					self::div(
						array(
							self::div( array( self::p( 'Answer six questions', 'mt-step__title' ), self::p( 'Your education, any license you hold, and a few yes-or-no questions. No sign-up.', 'mt-step__text' ) ), 'mt-step' ),
							self::div( array( self::p( 'See jobs you can apply to now', 'mt-step__title' ), self::p( 'Every job shows whether you qualify. If you don’t yet, you see the quickest route: the steps, the weeks and the cost.', 'mt-step__text' ) ), 'mt-step' ),
							self::div( array( self::p( 'Follow one checklist', 'mt-step__title' ), self::p( 'Background check, TB screening, trainings and paperwork in one list, each with the law behind it, so nothing turns up after you apply.', 'mt-step__text' ) ), 'mt-step' ),
						),
						'mt-steps'
					),
				),
				array( 'mt-section--mist' )
			),
			self::sec(
				array(
					self::h( 'Pick your starting point' ),
					self::div( $role_cards, 'mt-grid-3' ),
				)
			),
			self::sec(
				array(
					self::h( 'Try it as someone else' ),
					self::p( 'Testing the site? See it through the eyes of one of these people. Your own answers stay as they are, and you can switch back at the top of any page.', 'mt-muted' ),
					self::div(
						array(
							Kit::el(
								'block',
								array(
									'hasLoop' => true,
									'query'   => array( 'objectType' => 'mt_personas' ),
								),
								array(
									Kit::el( 'heading', array( 'text' => '{mt_row_title}', 'tag' => 'h3', 'link' => self::url( '{mt_row_url}' ) ), array(), array( 'mt-job__title' ) ),
									self::p( '{mt_row_blurb}', 'mt-small' ),
									self::link( 'See the site as this person', '{mt_row_url}' ),
								),
								array( 'mt-card' )
							),
						),
						'mt-grid-3'
					),
				),
				array( 'mt-section--mist' )
			),
			self::sec(
				array(
					self::div( array( self::h( 'Newest jobs' ), self::link( 'All {mt_open_count} jobs', '/jobs/' ) ), 'mt-row', 'mt-row--between' ),
					self::div(
						array(
							Kit::el(
								'block',
								array(
									'hasLoop' => true,
									'query'   => self::jobs_query( 6 ),
								),
								self::job_card()['kids'],
								array( 'mt-job' )
							),
						),
						'mt-grid-3'
					),
				)
			),
		);
	}

	private function jobs(): array {
		$q      = self::JOBS_LOOP;
		$label  = static fn( $t ) => self::p( $t, 'mt-filter-label' );
		$tax    = static fn( string $el, string $tax, string $param, array $extra = array() ) => Kit::el(
			$el,
			array_merge(
				array(
					'filterQueryId'  => $q,
					'filterSource'   => 'taxonomy',
					'filterTaxonomy' => $tax,
					'filterNiceName' => $param,
					'filterHideEmpty' => true,
				),
				$extra
			)
		);
		$filters = self::div(
			array(
				self::div( array( $label( 'Search' ), Kit::el( 'filter-search', array( 'filterQueryId' => $q, 'filterNiceName' => 'q', 'placeholder' => 'Title, school, subject…', 'filterInputDebounce' => 400, 'filterMinChars' => 2 ) ) ) ),
				self::div( array( $label( 'Role' ), $tax( 'filter-radio', 'mt_role', 'role', array( 'filterLabelAll' => 'All roles' ) ) ) ),
				self::div( array( $label( 'Job type' ), $tax( 'filter-checkbox', 'mt_job_type', 'type' ) ) ),
				self::div( array( $label( 'County' ), $tax( 'filter-checkbox', 'mt_county', 'county' ) ) ),
				self::div( array( $label( 'Subject' ), $tax( 'filter-select', 'mt_subject', 'subject', array( 'filterLabelAll' => 'Any subject' ) ) ) ),
				self::div( array( $label( 'Grade level' ), $tax( 'filter-select', 'mt_grade', 'grade', array( 'filterLabelAll' => 'Any grade' ) ) ) ),
				self::div( array( $label( 'Minimum degree' ), $tax( 'filter-checkbox', 'mt_degree', 'degree' ) ) ),
				self::div( array( $label( 'Employer' ), $tax( 'filter-select', 'mt_employer', 'employer', array( 'filterLabelAll' => 'Any employer' ) ) ) ),
				self::div(
					array(
						$label( 'Posted pay per day' ),
						Kit::el(
							'filter-range',
							array(
								'filterQueryId'    => $q,
								'filterSource'     => 'customField',
								'customFieldKey'   => '_mt_pay_day',
								'fieldCompareType' => 'NUMERIC',
								'filterNiceName'   => 'pay',
								'displayMode'      => 'input',
								'labelMin'         => 'Min $',
								'labelMax'         => 'Max $',
							)
						),
						self::p( 'Only jobs that post their pay; hourly and yearly pay converted to a day.', 'mt-small' ),
					)
				),
			),
			'mt-filters'
		);
		return array(
			self::sec(
				array(
					self::h( 'Open school jobs', 'h1' ),
					self::p( '{mt_open_count} teacher, substitute and aide jobs in Milwaukee, Waukesha and Racine counties. {mt_updated}.', 'mt-lead' ),
					self::only(
						array( self::span( 'Answer six questions to see which of these you qualify for.' ), self::btn( 'Check what I qualify for', '/check/', array( 'mt-btn', 'mt-btn--small' ) ) ),
						array( array( '{mt_has_profile}', 'empty' ) ),
						'mt-notice'
					),
					self::only(
						array( self::span( 'Showing only jobs you can apply to now.' ), self::link( 'Show all jobs', '/jobs/?fit=all' ) ),
						array( array( '{mt_fit_on}', 'empty_not' ) ),
						'mt-notice'
					),
					self::only(
						array( self::span( 'You can apply to {mt_apply_now_count} of these now.' ), self::link( 'Show only those', '/jobs/?fit=now' ) ),
						array( array( '{mt_has_profile}', 'empty_not' ), array( '{mt_fit_on}', 'empty' ) ),
						'mt-notice'
					),
				),
				array( 'mt-section--mist', 'mt-section--tight' )
			),
			self::sec(
				array(
					self::div(
						array(
							$filters,
							self::div(
								array(
									self::div(
										array(
											self::div(
												array(
													Kit::el( 'query-results-summary', array( 'queryId' => $q, 'statsFormat' => '%start%–%end% of %total% jobs', 'oneResultText' => '1 job', 'noResultsText' => 'No jobs' ), array(), array( 'mt-muted' ) ),
													Kit::el( 'filter-active-filters', array( 'filterQueryId' => $q, 'excludeIds' => self::JOBS_SORT ) ),
												),
												'mt-row'
											),
											self::div( array( self::span( 'Sort', 'mt-filter-label' ), self::jobs_sort() ), 'mt-sort' ),
										),
										'mt-row',
										'mt-row--between'
									),
									Kit::el(
										'block',
										array(
											'_id'     => $q,
											'hasLoop' => true,
											'query'   => self::jobs_query( 20 ),
										),
										self::job_card()['kids'],
										array( 'mt-job' )
									),
									Kit::el( 'pagination', array( 'queryId' => $q ) ),
								),
								'mt-results'
							),
						),
						'mt-jobs'
					),
				),
				array( 'mt-section--tight' )
			),
		);
	}

	private function check(): array {
		$areas = Profile::areas();
		$yes   = array( 'yes' => 'Yes', 'no' => 'No' );
		$form  = self::form(
			array(
				self::field( 'edu', 'radio', 'What’s the highest level of education you’ve finished?', array( 'options' => self::options( Profile::EDU ), 'valueLabelOptions' => true, 'value' => '{mt_profile_edu}', 'required' => true ) ),
				self::field( 'cred', 'radio', 'Which license or permit do you hold now?<small>Hold more than one? Pick your teaching license. Not sure? <a href="#find-license">Find yours in DPI’s records</a> or <a href="/guides/teaching-license/">compare them</a>.</small>', array( 'options' => self::options( Profile::CRED ), 'valueLabelOptions' => true, 'value' => '{mt_profile_cred}', 'required' => true ) ),
				self::field( 'areas', 'checkbox', 'If you hold a teaching license, which subjects does it cover?<small>Current, expired or from another state. Skip this if you don’t have one.</small>', array( 'options' => self::options( $areas ), 'valueLabelOptions' => true, 'value' => '{mt_profile_areas}' ) ),
				self::field( 'epp', 'radio', 'Are you enrolled in a Wisconsin teacher-preparation program?', array( 'options' => self::options( $yes ), 'valueLabelOptions' => true, 'value' => '{mt_profile_epp}', 'required' => true ) ),
				self::field( 'age', 'radio', 'How old are you?', array( 'options' => self::options( Profile::AGES ), 'valueLabelOptions' => true, 'value' => '{mt_profile_age}', 'required' => true ) ),
				self::field( 'outside', 'radio', 'In the last 20 years, have you lived, worked or studied outside Wisconsin?<small>DPI then needs your fingerprints (about $40).</small>', array( 'options' => self::options( $yes ), 'valueLabelOptions' => true, 'value' => '{mt_profile_outside_wi}', 'required' => true ) ),
				self::field( 'roles', 'checkbox', 'Which jobs interest you?', array( 'options' => self::options( Classifier::ROLES ), 'valueLabelOptions' => true, 'value' => '{mt_profile_roles}' ) ),
			),
			array(
				'actions'          => array( 'mt_profile' ),
				'submitButtonText' => 'Show my options',
			),
			array( 'mt-form', 'mt-form--grid' )
		);
		return array(
			self::sec(
				array(
					self::h( 'What can I apply for?', 'h1' ),
					self::p( 'Six questions, about two minutes. No sign-up: your answers stay in this browser until you create an account.', 'mt-lead' ),
				),
				array( 'mt-section--mist', 'mt-section--tight' ),
				array( 'mt-inner--narrow' )
			),
			Kit::el(
				'section',
				self::when( array( '_cssId' => 'your-options' ), array( '{mt_has_profile}', 'empty_not' ) ),
				array(
					Kit::el(
						'container',
						array(),
						array(
							self::h( 'Your options' ),
							self::div( array( self::p( 'Based on: {mt_profile_summary}.', 'mt-muted' ), self::link( 'Change my answers', '#answers' ) ), 'mt-row' ),
							Kit::el(
								'block',
								array(
									'hasLoop' => true,
									'query'   => array( 'objectType' => 'mt_paths' ),
								),
								array(
									self::div( array( self::h( '{mt_row_title}', 'h3' ), self::badge( '{mt_row_state_label}', '{mt_row_state}' ) ), 'mt-row' ),
									self::p( '{mt_row_summary}', 'mt-strong' ),
									self::rich( '{mt_row_reasons_html}' ),
									self::rich( '{mt_row_steps_html}' ),
									self::div( array( self::btn( '{mt_row_count_label}', '{mt_row_jobs_url}', array( 'mt-btn', 'mt-btn--small' ) ), self::link( 'My checklist', '/checklist/' ) ), 'mt-row' ),
								),
								array( 'mt-card' )
							),
						),
						array( 'mt-inner', 'mt-inner--narrow' )
					),
				),
				array( 'mt-section' )
			),
			$this->find_license(),
			Kit::el(
				'section',
				array( '_cssId' => 'answers' ),
				array(
					Kit::el(
						'container',
						array(),
						array(
							self::only( array( self::h( 'Your answers' ) ), array( array( '{mt_has_profile}', 'empty_not' ) ) ),
							$form,
							self::p( 'We never ask about criminal history, health, immigration status or ID numbers. Employers handle those themselves, by law and in person.', 'mt-small' ),
						),
						array( 'mt-inner', 'mt-inner--narrow' )
					),
				),
				array( 'mt-section' )
			),
		);
	}

	/** "Find my license": DPI's public lookup, read for the visitor (Dpi_Lookup). */
	private function find_license(): array {
		$search = self::form(
			array(
				self::field( 'last', 'text', 'Last name', array( 'width' => 50, 'autocomplete' => 'family-name' ) ),
				self::field( 'first', 'text', 'First name (optional)', array( 'width' => 50, 'autocomplete' => 'given-name' ) ),
				self::field( 'file', 'text', 'Or your DPI file number<small>Also called the entity number. It’s on DPI’s emails and in your ELO account.</small>', array( 'width' => 100, 'inputmode' => 'numeric' ) ),
			),
			array(
				'actions'          => array( 'mt_dpi_search' ),
				'submitButtonText' => 'Find my license',
				'successMessage'   => 'Found.',
			),
			array( 'mt-form' )
		);
		$matches = Kit::el(
			'block',
			array(
				'hasLoop' => true,
				'query'   => array( 'objectType' => 'mt_dpi_matches' ),
			),
			array(
				self::span( '{mt_row_title}', 'mt-strong' ),
				self::div( array( self::link( 'View on DPI', '{mt_row_dpi_url}', array( 'mt-link' ), true ), self::btn( 'This is me', '{mt_row_pick_url}', array( 'mt-btn', 'mt-btn--small' ) ) ), 'mt-row' ),
			),
			array( 'mt-card', 'mt-row', 'mt-row--between' )
		);
		$licenses = Kit::el(
			'block',
			array(
				'hasLoop' => true,
				'query'   => array( 'objectType' => 'mt_dpi_rows' ),
			),
			array(
				self::div( array( self::p( '{mt_row_title}', 'mt-strong' ), self::badge( '{mt_row_state_label}', '{mt_row_state}' ) ), 'mt-row', 'mt-row--between' ),
				self::p( '{mt_row_detail}', 'mt-small' ),
				self::p( '{mt_row_status}', 'mt-small' ),
			),
			array( 'mt-card' )
		);
		return Kit::el(
			'section',
			array( '_cssId' => 'find-license' ),
			array(
				Kit::el(
					'container',
					array(),
					array(
						self::h( 'Find your license in DPI’s records' ),
						self::p( 'Optional. We search DPI’s public license lookup for you, explain what you hold, and fill in your license answers. Your name is used only for this search and isn’t stored.', 'mt-muted' ),
						self::only( array( self::span( '{mt_dpi_error}' ) ), array( array( '{mt_dpi_error}', 'empty_not' ) ), 'mt-notice', 'mt-notice--warn' ),
						self::only( array( $search ), array( array( '{mt_dpi_state}', 'empty' ) ) ),
						self::only(
							array(
								self::p( '{mt_dpi_match_note} DPI lists names only, so pick yours, or open each on DPI’s site to check.', 'mt-strong' ),
								self::div( array( $matches ), 'mt-stack', 'mt-stack--tight' ),
								self::link( 'None of these: search again', '{mt_dpi_forget_url}' ),
							),
							array( array( '{mt_dpi_state}', 'matches' ) ),
							'mt-stack'
						),
						self::only(
							array(
								self::p( '{mt_dpi_summary}', 'mt-strong' ),
								self::div( array( $licenses ), 'mt-stack' ),
								self::only( array( self::p( '{mt_dpi_more}', 'mt-small' ) ), array( array( '{mt_dpi_more}', 'empty_not' ) ) ),
								self::only( array( self::rich( '{mt_dpi_notes_html}' ) ), array( array( '{mt_dpi_notes_html}', 'empty_not' ) ) ),
								self::only( array( self::span( 'Your license answers below are filled in from this record. Answer the rest, then Show my options.' ) ), array( array( '{mt_dpi_prefill}', 'empty_not' ) ), 'mt-notice' ),
								self::div( array( self::link( 'See it on DPI’s site', '{mt_dpi_url}', array( 'mt-link' ), true ), self::link( 'Not me: search again', '{mt_dpi_forget_url}' ) ), 'mt-row' ),
							),
							array( array( '{mt_dpi_state}', 'record' ) ),
							'mt-stack'
						),
					),
					array( 'mt-inner', 'mt-inner--narrow' )
				),
			),
			array( 'mt-section', 'mt-section--tight' )
		);
	}

	private function checklist(): array {
		$state_form = function ( bool $expiry ): array {
			$fields = array(
				self::field( 'item', 'hidden', '', array( 'value' => '{mt_row_id}' ) ),
				self::field( 'state', 'radio', 'Status', array( 'options' => "done:Done\nna:Not needed for me\ntodo:Still to do", 'valueLabelOptions' => true, 'value' => '{mt_row_state}', 'required' => true ) ),
				self::field( 'date', 'datepicker', 'Date done', array( 'value' => '{mt_row_date}', 'width' => $expiry ? 50 : 100 ) ),
			);
			if ( $expiry ) {
				$fields[] = self::field( 'expiry', 'datepicker', 'Valid until', array( 'value' => '{mt_row_expiry}', 'width' => 50 ) );
			}
			$fields[] = self::field( 'where', 'select', 'Where you did it', array( 'options' => self::options( array( '' => 'Choose (optional)' ) + Rules::where_options() ), 'valueLabelOptions' => true, 'value' => '{mt_row_where_done}' ) );
			return self::form( $fields, array( 'actions' => array( 'mt_task' ), 'submitButtonText' => 'Save' ), array( 'mt-form', 'mt-form--compact' ) );
		};
		$task = Kit::el(
			'div',
			self::attr( array( 'tag' => 'custom', 'customTag' => 'details', 'hasLoop' => true, 'query' => array( 'objectType' => 'mt_tasks' ) ), 'data-state', '{mt_row_state}' ),
			array(
				Kit::el(
					'div',
					array( 'tag' => 'custom', 'customTag' => 'summary' ),
					array(
						self::badge( '{mt_row_state_label}', '{mt_row_state}' ),
						self::div( array( self::p( '{mt_row_title}', 'mt-strong' ), self::p( '{mt_row_blocks_label} · {mt_row_time} · {mt_row_cost}', 'mt-small' ), self::p( '{mt_row_when}', 'mt-small' ) ), 'mt-stack', 'mt-stack--tight' ),
						self::span( '▾', 'mt-task__chev' ),
					),
					array( 'mt-task__summary' )
				),
				self::div(
					array(
						self::p( '{mt_row_why}', 'mt-muted' ),
						self::p( '{mt_row_never}', 'mt-callout', 'mt-callout--stop' ),
						self::p( '{mt_row_conflict}', 'mt-callout', 'mt-callout--warn' ),
						self::rich( '{mt_row_how_html}' ),
						self::div(
							array(
								self::rich( '<strong>Cost</strong>{mt_row_cost} · {mt_row_payer}' ),
								self::rich( '<strong>Keep as proof</strong>{mt_row_proof}' ),
								self::rich( '<strong>How long it lasts</strong>{mt_row_valid}' ),
							),
							'mt-facts'
						),
						self::rich( '{mt_row_where_html}' ),
						self::rich( '{mt_row_source_html}', 'mt-small' ),
						self::only( array( $state_form( false ) ), array( array( '{mt_row_editable}', 'empty_not' ), array( '{mt_row_has_expiry}', 'empty' ) ) ),
						self::only( array( $state_form( true ) ), array( array( '{mt_row_editable}', 'empty_not' ), array( '{mt_row_has_expiry}', 'empty_not' ) ) ),
					),
					'mt-task__body'
				),
			),
			array( 'mt-task' )
		);
		return array(
			self::sec(
				array(
					self::only( array( self::h( 'My checklist', 'h1' ) ), array( array( '{mt_checklist_job}', 'empty' ) ) ),
					self::only(
						array(
							self::p( 'Checklist for this job', 'mt-eyebrow' ),
							self::h( '{mt_checklist_job}', 'h1' ),
							self::div( array( self::link( 'Back to the job', '{mt_checklist_job_url}', array( 'mt-link', 'mt-link--back' ) ), self::link( 'My full checklist', '/checklist/' ) ), 'mt-row' ),
						),
						array( array( '{mt_checklist_job}', 'empty_not' ) ),
						'mt-stack'
					),
					self::p( 'Everything between you and your first day, in one list. Each item says why it’s required, what it costs and who pays, and whether it holds up applying or only your first day.', 'mt-lead' ),
					self::only(
						array( self::span( 'This is the general list for every role. Answer six questions to fit it to you.' ), self::btn( 'Check what I qualify for', '/check/', array( 'mt-btn', 'mt-btn--small' ) ) ),
						array( array( '{mt_has_profile}', 'empty' ) ),
						'mt-notice'
					),
					self::only(
						array(
							self::p( '{mt_progress}', 'mt-strong' ),
							Kit::el( 'div', array(), array( Kit::el( 'div', self::attr( array(), 'style', 'width: {mt_progress_pct}%' ), array(), array( 'mt-progress__bar' ) ) ), array( 'mt-progress' ) ),
							self::p( 'Based on: {mt_profile_summary}. Ticks are saved in this browser until you create an account.', 'mt-small' ),
						),
						array( array( '{mt_has_profile}', 'empty_not' ) ),
						'mt-stack'
					),
				),
				array( 'mt-section--mist', 'mt-section--tight' ),
				array( 'mt-inner--narrow' )
			),
			self::sec(
				array(
					Kit::el(
						'block',
						array(
							'hasLoop' => true,
							'query'   => array( 'objectType' => 'mt_task_groups' ),
						),
						array(
							self::div( array( self::h( '{mt_row_title}' ), self::span( '{mt_row_count}', 'mt-small' ) ), 'mt-row', 'mt-row--between' ),
							$task,
						),
						array( 'mt-group' )
					),
					self::p( 'Never upload or email ID documents, your Social Security number, bank details or medical records to anyone who asks by email. Show ID in person, and enter the rest only in an employer’s own secure system.', 'mt-callout', 'mt-callout--stop' ),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	private function join(): array {
		return array(
			self::sec(
				array(
					self::h( 'Create an account', 'h1' ),
					self::p( 'You need an account only to save applications. Your answers and checklist come with you.', 'mt-lead' ),
					self::only( array( self::p( 'You’re signed in.' ), self::link( 'My applications', '/my-applications/' ) ), array( array( '{mt_logged_in}', 'empty_not' ) ), 'mt-notice' ),
					self::only(
						array(
							self::form(
								array(
									self::field( 'mtfnam', 'text', 'First name', array( 'required' => true, 'autocomplete' => 'given-name' ) ),
									self::field( 'mtemai', 'email', 'Email', array( 'required' => true, 'autocomplete' => 'email' ) ),
									self::field( 'mtpass', 'password', 'Password<small>At least 8 characters.</small>', array( 'required' => true, 'autocomplete' => 'new-password' ) ),
									self::field( 'to', 'hidden', '', array( 'value' => '{mt_redirect_to}' ) ),
									self::field( 'job', 'hidden', '', array( 'value' => '{mt_job_param}' ) ),
									self::field( 'mthpot', 'text', 'Leave this empty', array( 'isHoneypot' => true ) ),
								),
								array(
									'actions'                       => array( 'registration', 'mt_redirect' ),
									'registrationEmail'             => 'mtemai',
									'registrationPassword'          => 'mtpass',
									'registrationFirstName'         => 'mtfnam',
									'registrationRole'              => \MMI_Teach\Accounts::ROLE,
									'registrationAutoLogin'         => true,
									'registrationPasswordMinLength' => 8,
									'submitButtonText'              => 'Create my account',
									'successMessage'                => 'Account created.',
								)
							),
							self::div( array( self::span( 'Already have an account?', 'mt-muted' ), self::link( 'Sign in', '/sign-in/' ) ), 'mt-row' ),
							self::p( 'We store your name, email, your answers, your checklist ticks and the applications you save. Nothing is shared with employers. You can delete it all from your account page.', 'mt-small' ),
						),
						array( array( '{mt_logged_in}', 'empty' ) ),
						'mt-stack'
					),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	private function sign_in(): array {
		return array(
			self::sec(
				array(
					self::h( 'Sign in', 'h1' ),
					self::only( array( self::p( 'You’re signed in.' ), self::link( 'My applications', '/my-applications/' ) ), array( array( '{mt_logged_in}', 'empty_not' ) ), 'mt-notice' ),
					self::only(
						array(
							self::form(
								array(
									self::field( 'mtlnam', 'email', 'Email', array( 'required' => true, 'autocomplete' => 'email' ) ),
									self::field( 'mtlpwd', 'password', 'Password', array( 'required' => true, 'autocomplete' => 'current-password' ) ),
									self::field( 'mtlrem', 'checkbox', '', array( 'options' => 'yes:Keep me signed in', 'valueLabelOptions' => true ) ),
									self::field( 'to', 'hidden', '', array( 'value' => '{mt_redirect_to}' ) ),
									self::field( 'job', 'hidden', '', array( 'value' => '{mt_job_param}' ) ),
								),
								array(
									'actions'          => array( 'login', 'mt_redirect' ),
									'loginName'        => 'mtlnam',
									'loginPassword'    => 'mtlpwd',
									'loginRemember'    => 'mtlrem',
									'submitButtonText' => 'Sign in',
									'successMessage'   => 'Signed in.',
								)
							),
							self::div( array( self::link( 'Forgot your password?', '/reset-password/' ), self::link( 'New here? Create an account', '/join/' ) ), 'mt-row' ),
						),
						array( array( '{mt_logged_in}', 'empty' ) ),
						'mt-stack'
					),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	private function reset_password(): array {
		return array(
			self::sec(
				array(
					self::h( 'Reset your password', 'h1' ),
					self::only(
						array(
							self::p( 'Enter your email and we’ll send a link to set a new password.', 'mt-lead' ),
							self::form(
								array( self::field( 'mtlost', 'email', 'Email', array( 'required' => true, 'autocomplete' => 'email' ) ) ),
								array(
									'actions'                   => array( 'lost-password' ),
									'lostPasswordEmailUsername' => 'mtlost',
									'submitButtonText'          => 'Send the link',
									'successMessage'            => 'If that email has an account, a link is on its way. Check your inbox and spam folder.',
								)
							),
						),
						array( array( '{url_parameter:key}', 'empty' ) ),
						'mt-stack'
					),
					self::only(
						array(
							self::form(
								array( self::field( 'mtnewp', 'password', 'New password<small>At least 8 characters.</small>', array( 'required' => true, 'autocomplete' => 'new-password' ) ) ),
								array(
									'actions'          => array( 'reset-password' ),
									'resetPasswordNew' => 'mtnewp',
									'submitButtonText' => 'Set my password',
									'successMessage'   => 'Password changed. You can sign in now.',
								)
							),
							self::link( 'Sign in', '/sign-in/' ),
						),
						array( array( '{url_parameter:key}', 'empty_not' ) ),
						'mt-stack'
					),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	private function my_applications(): array {
		$status_options = array();
		foreach ( Applications::APPLICANT_STATUSES as $s ) {
			$status_options[ $s ] = Applications::STATUSES[ $s ];
		}
		return array(
			self::sec(
				array(
					self::h( 'My applications', 'h1' ),
					self::p( 'Saved here, finished on each employer’s own site. Update the status as you hear back.', 'mt-lead' ),
				),
				array( 'mt-section--mist', 'mt-section--tight' ),
				array( 'mt-inner--narrow' )
			),
			self::sec(
				array(
					self::only(
						array( self::span( 'Sign in to see your applications.' ), self::btn( 'Sign in', '/sign-in/?redirect_to=' . rawurlencode( home_url( '/my-applications/' ) ), array( 'mt-btn', 'mt-btn--small' ) ), self::link( 'Create an account', '/join/' ) ),
						array( array( '{mt_logged_in}', 'empty' ) ),
						'mt-notice'
					),
					Kit::el(
						'block',
						self::when(
							array(
								'hasLoop' => true,
								'query'   => array(
									'objectType'      => 'mt_my_apps',
									'no_results_text' => 'No applications yet. Find a job you qualify for, then choose Apply on its page.',
								),
							),
							array( '{mt_logged_in}', 'empty_not' )
						),
						array(
							self::div( array( self::h( '{mt_row_title}', 'h3' ), self::badge( '{mt_row_state_label}', '{mt_row_state}' ) ), 'mt-row', 'mt-row--between' ),
							self::p( '{mt_row_employer}', 'mt-muted' ),
							self::p( '{mt_row_saved} · {mt_row_left}', 'mt-small' ),
							self::p( '{mt_row_closed_note}', 'mt-callout', 'mt-callout--warn' ),
							self::div(
								array(
									Kit::el( 'button', self::when( array( 'text' => 'Continue to the employer’s application', 'tag' => 'a', 'link' => self::url( '{mt_row_apply_url}', true ) ), array( '{mt_row_job_open}', 'empty_not' ), array( '{mt_row_finished}', 'empty' ) ), array(), array( 'mt-btn', 'mt-btn--small' ) ),
									self::link( 'View job', '{mt_row_job_url}' ),
									self::link( 'Checklist for this job', '{mt_row_checklist_url}' ),
								),
								'mt-row'
							),
							self::details(
								array( self::span( 'Update status' ) ),
								array(
									self::form(
										array(
											self::field( 'app', 'hidden', '', array( 'value' => '{mt_row_id}' ) ),
											self::field( 'status', 'select', 'Where it stands', array( 'options' => self::options( $status_options ), 'valueLabelOptions' => true, 'value' => '{mt_row_state}' ) ),
										),
										array( 'actions' => array( 'mt_app_status' ), 'submitButtonText' => 'Update' ),
										array( 'mt-form', 'mt-form--compact' )
									),
								)
							),
						),
						array( 'mt-card' )
					),
					self::div( array( self::btn( 'Find more jobs', '/jobs/' ), self::link( 'My checklist', '/checklist/' ) ), 'mt-row' ),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	private function account(): array {
		return array(
			self::sec(
				array(
					self::h( 'My account', 'h1' ),
					self::only(
						array( self::span( 'Sign in to manage your account.' ), self::btn( 'Sign in', '/sign-in/', array( 'mt-btn', 'mt-btn--small' ) ) ),
						array( array( '{mt_logged_in}', 'empty' ) ),
						'mt-notice'
					),
					self::only(
						array(
							self::p( 'Hi {mt_user_name}.', 'mt-lead' ),
							self::div(
								array(
									self::h( 'Your answers', 'h3' ),
									self::p( '{mt_profile_summary}', 'mt-muted' ),
									self::link( 'Change my answers', '/check/#answers' ),
								),
								'mt-card'
							),
							self::div(
								array(
									self::h( 'Your data', 'h3' ),
									self::p( 'We keep your name, email, answers, checklist ticks and saved applications. Nothing is sent to employers.', 'mt-muted' ),
									self::link( 'Privacy notice', '/privacy/' ),
									self::btn( 'Sign out', '{mt_logout_url}', array( 'mt-btn', 'mt-btn--line', 'mt-btn--small' ) ),
								),
								'mt-card'
							),
							self::details(
								array( self::span( 'Delete my account' ) ),
								array(
									self::p( 'This deletes your account, answers, checklist and every saved application, right away. It can’t be undone.', 'mt-muted' ),
									self::form(
										array(
											self::field( 'confirm', 'checkbox', '', array( 'options' => 'yes:Delete my account and everything stored about me', 'valueLabelOptions' => true, 'required' => true ) ),
											self::field( 'password', 'password', 'Your password', array( 'required' => true, 'autocomplete' => 'current-password' ) ),
										),
										array( 'actions' => array( 'mt_delete_me' ), 'submitButtonText' => 'Delete everything' )
									),
								),
								array( 'mt-details', 'mt-card' )
							),
						),
						array( array( '{mt_is_applicant}', 'empty_not' ) ),
						'mt-stack'
					),
					self::only(
						array( self::p( 'You’re signed in as staff. Applicant tools appear for applicant accounts.', 'mt-muted' ) ),
						array( array( '{mt_logged_in}', 'empty_not' ), array( '{mt_is_applicant}', 'empty' ) )
					),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	/* ====================================================================
	 * Guides and privacy (static text from the sourced data files)
	 * ================================================================== */

	/** slug => [title, intro] */
	private const GUIDES = array(
		'substitute-teaching'    => array( 'Become a substitute teacher', 'Wisconsin’s substitute permit, who qualifies, what it costs and how long it takes.' ),
		'aide-jobs'              => array( 'Aide and paraprofessional jobs', 'The quickest way into a school: what aide jobs ask for, including special education and Title I.' ),
		'teaching-license'       => array( 'Licenses and permits', 'Which Wisconsin license or permit fits you, what it lets you do, and how to get it.' ),
		'wisconsin-requirements' => array( 'Every requirement at a glance', 'All the checks, trainings and paperwork Wisconsin schools need, with the law, cost and source for each.' ),
	);

	private function guides_index(): array {
		$cards = array();
		foreach ( self::GUIDES as $slug => [ $title, $intro ] ) {
			$cards[] = self::div( array( self::h( $title, 'h3' ), self::p( $intro, 'mt-muted' ), self::link( 'Read', '/guides/' . $slug . '/' ) ), 'mt-card' );
		}
		return array(
			self::sec( array( self::h( 'Guides', 'h1' ), self::p( 'Plain-language guides to working in Wisconsin schools, built from DPI, DHS and state law. Each fact carries its source and the date we checked it.', 'mt-lead' ) ), array( 'mt-section--mist', 'mt-section--tight' ) ),
			self::sec( array( self::div( $cards, 'mt-grid-2' ) ) ),
		);
	}

	private function guide( string $slug ): array {
		[ $title, $intro ] = self::GUIDES[ $slug ];
		return array(
			self::sec(
				array( self::link( 'All guides', '/guides/', array( 'mt-link', 'mt-link--back' ) ), self::h( $title, 'h1' ), self::p( $intro, 'mt-lead' ) ),
				array( 'mt-section--mist', 'mt-section--tight' ),
				array( 'mt-inner--narrow' )
			),
			self::sec(
				array(
					self::rich( $this->guide_html( $slug ) ),
					self::div( array( self::btn( 'Check what I qualify for', '/check/' ), self::btn( 'See open jobs', '/jobs/', array( 'mt-btn', 'mt-btn--line' ) ) ), 'mt-row' ),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}

	/** One requirement as a short paragraph block, from rules.json. */
	private static function item_html( string $id ): string {
		$i = Rules::item( $id );
		if ( ! $i ) {
			return '';
		}
		$src = '<a href="' . esc_url( $i['source'] ) . '">' . esc_html( (string) wp_parse_url( $i['source'], PHP_URL_HOST ) ) . '</a>';
		return '<h3>' . esc_html( $i['title'] ) . '</h3><p>' . esc_html( $i['why'] ) . '</p><p><strong>Time:</strong> ' . esc_html( $i['time']['label'] ) . '. <strong>Cost:</strong> ' . esc_html( $i['cost']['label'] ) . '.' . ( ! empty( $i['valid']['note'] ) ? ' <strong>Lasts:</strong> ' . esc_html( ( ! empty( $i['valid']['years'] ) ? $i['valid']['years'] . ' years. ' : '' ) . $i['valid']['note'] ) . '.' : '' ) . '</p>'
			. ( ! empty( $i['how'] ) ? '<ol>' . implode( '', array_map( static fn( $s ) => '<li>' . esc_html( $s ) . '</li>', $i['how'] ) ) . '</ol>' : '' )
			. ( ! empty( $i['conflict'] ) ? '<p><em>Confirm with the employer: ' . esc_html( $i['conflict'] ) . '</em></p>' : '' )
			. '<p><small>Source: ' . $src . ', checked ' . esc_html( $i['verified_on'] ) . '.</small></p>';
	}

	private function guide_html( string $slug ): string {
		switch ( $slug ) {
			case 'substitute-teaching':
				return '<p>Wisconsin public schools need a DPI license or permit for every substitute teacher. Most people start with the <strong>Three-Year Short-Term Substitute Teacher Permit</strong>.</p>'
					. '<h2>Who qualifies</h2><ul><li><strong>Associate degree or higher</strong>, plus a DPI-approved substitute training course (a bachelor’s doesn’t exempt you from the course).</li><li><strong>Teacher-preparation students</strong> age 20 or older at junior standing, with 15 hours of classroom observation. Your program confirms it on form PI-1975; no course needed.</li><li><strong>Former or out-of-state teachers</strong> go straight to the five-year substitute license, no course needed.</li><li><strong>Permit expired?</strong> Apply again with the same application and the same proof; no new course.</li></ul>'
					. '<p>No associate degree yet? <a href="/guides/aide-jobs/">Aide jobs</a> need only a high-school diploma, and Wisconsin technical colleges offer an associate degree in Foundations of Teacher Education.</p>'
					. '<h2>Steps, time and cost</h2>' . self::item_html( 'sub_training' ) . self::item_html( 'fingerprints' ) . self::item_html( 'sub_permit' ) . self::item_html( 'long_term_license' )
					. '<h2>Things that catch people out</h2><ul><li><strong>Some employers ask for more than DPI.</strong> Milwaukee Public Schools and Racine Unified building subs need a bachelor’s degree.</li><li><strong>45 days.</strong> A permit covers up to 45 consecutive days in one assignment. Longer needs a license for the assignment.</li><li><strong>Apply to employers while DPI processes your permit.</strong> Their applications, interviews and onboarding don’t wait on it; only your first day does.</li><li><strong>Agencies.</strong> Many districts fill daily sub jobs through EDUStaff or Kelly Education: one application covers every district the agency serves, but agency subs aren’t in the Wisconsin Retirement System.</li></ul>';
			case 'aide-jobs':
				return '<p>Aide and paraprofessional jobs are the quickest way to work in a Wisconsin school: most need a <strong>high-school diploma or GED/HSED</strong> and no DPI license.</p>'
					. '<h2>Two extra rules for some jobs</h2>' . self::item_html( 'sepa_license' ) . self::item_html( 'title1_para' )
					. '<h2>Then the usual onboarding</h2><p>Every school hire goes through a background check, TB screening, required trainings and hiring paperwork. <a href="/checklist/">Your checklist</a> lists them with time and cost.</p>'
					. '<h2>From aide to teacher</h2><p>Many aides go on to substitute teaching once they finish an associate degree, and to a teaching license with a bachelor’s. Districts sometimes help pay for it; ask during the interview.</p>';
			case 'teaching-license':
				// Fees the person pays, summed; tuition is never totalled in.
				$row  = static function ( string $have, string $get, string $lets, array $ids, string $extra = '' ): string {
					$cost = array( 0, 0 );
					foreach ( $ids as $id ) {
						$c        = Rules::item( $id )['cost'] ?? array();
						$cost[0] += (int) ( $c['min'] ?? 0 );
						$cost[1] += (int) ( $c['max'] ?? 0 );
					}
					return '<tr><td><strong>' . esc_html( $have ) . '</strong></td><td data-label="Get">' . $get . '</td><td data-label="Lets you">' . esc_html( $lets ) . '</td><td data-label="Cost">' . esc_html( Rules::cost_label( $cost[0], $cost[1] ) . $extra ) . '</td></tr>';
				};
				$rows = $row( 'A high-school diploma', 'Nothing for most aide jobs. Special-education aides: the <a href="#sepa_license">Program Aide license</a>', 'Work as an aide or paraprofessional', array() )
					. $row( 'An associate degree, or you’re a teacher-prep student aged 20+', 'Substitute training, then the <a href="#sub_permit">substitute permit</a>', 'Substitute in any subject and grade, up to 45 days in one assignment', array( 'sub_training', 'sub_permit' ) )
					. $row( 'A substitute permit that expired', 'The <a href="#sub_permit">same permit again</a>, with the same proof; no new course', 'The same as before, for 3 more years', array( 'sub_permit' ) )
					. $row( 'A bachelor’s degree, no license', 'A <a href="#teaching_license">teacher-prep program</a>; meanwhile the substitute permit, or a <a href="#stipulations">License with Stipulations</a> if a district asks for one', 'Teach your subjects once licensed. Private Choice schools accept the degree alone', array( 'teaching_license' ), ' plus tuition' )
					. $row( 'A Wisconsin teaching license that expired', 'A <a href="#license_reissue">reissued provisional license</a>. Only want to sub? The <a href="#long_term_license">five-year substitute license</a>', 'Teach your old subjects and substitute in any', array( 'license_reissue' ) )
					. $row( 'A teaching license from another state', 'The <a href="#oos_license">out-of-state application</a>. Only want to sub? The <a href="#long_term_license">five-year substitute license</a>', 'Teach the subjects DPI matches to yours', array( 'oos_license' ) )
					. $row( 'A current Wisconsin teaching license', 'Nothing more', 'Teach its subjects and grades, and substitute in any subject', array() );
				$anchor = static fn( string $id ): string => '<div id="' . esc_attr( $id ) . '">' . self::item_html( $id ) . '</div>';
				return '<p>Every Wisconsin public-school teacher and substitute needs a license or permit from DPI. Find the row that describes you: it names the one credential to get next.</p>'
					. '<p><strong>Not sure what you hold?</strong> <a href="https://dpi.wi.gov/licensing/license-lookup" target="_blank" rel="noopener">DPI’s license lookup</a> lists every license under your name, its subjects and when it expires.</p>'
					. '<table><thead><tr><th>You have</th><th>Get</th><th>Lets you</th><th>Cost</th></tr></thead><tbody>' . $rows . '</tbody></table>'
					. '<p>Every application goes through <a href="https://elo.wieducatorlicensing.org/" target="_blank" rel="noopener">Educator Licensing Online (ELO)</a> and is paid by credit card. Apply to employers at the same time: only your first day waits on DPI. If you’ve lived, worked or studied outside Wisconsin, DPI also needs <a href="#fingerprints">your fingerprints</a>.</p>'
					. '<h2>Substitute teaching</h2>' . $anchor( 'sub_permit' ) . $anchor( 'long_term_license' )
					. '<h2>Teaching</h2>' . $anchor( 'teaching_license' ) . $anchor( 'stipulations' ) . '<p>Private schools don’t need a DPI license at all; schools in the Parental Choice Program accept a bachelor’s degree instead.</p>'
					. '<h2>Coming back or moving here</h2>' . $anchor( 'license_reissue' ) . $anchor( 'oos_license' ) . $anchor( 'fingerprints' )
					. '<h2>Aides</h2>' . $anchor( 'sepa_license' );
			case 'wisconsin-requirements':
				$rows = '';
				foreach ( Rules::items() as $id => $i ) {
					if ( 'step' !== $i['kind'] && ! in_array( $id, array( 'sub_permit', 'fingerprints', 'sepa_license', 'title1_para' ), true ) ) {
						continue;
					}
					$rows .= '<tr><td><strong>' . esc_html( $i['title'] ) . '</strong></td><td data-label="Why">' . esc_html( $i['why'] ) . '</td><td data-label="Time">' . esc_html( $i['time']['label'] ) . '</td><td data-label="Cost">' . esc_html( $i['cost']['label'] ) . '</td><td data-label="Source"><a href="' . esc_url( $i['source'] ) . '">' . esc_html( (string) wp_parse_url( $i['source'], PHP_URL_HOST ) ) . '</a></td></tr>';
				}
				return '<p>These apply to teachers, substitutes and aides alike unless noted. Requirements marked “confirm with the employer” are ones where sources disagree.</p><table><thead><tr><th>Requirement</th><th>Why</th><th>Time</th><th>Cost</th><th>Source</th></tr></thead><tbody>' . $rows . '</tbody></table>'
					. '<h2>What employers can’t ask</h2><p>Under Wis. Stat. 111.335, employers can’t ask about arrests on an application, and can use a conviction only when it relates substantially to the job (schools may refuse anyone with an unpardoned felony). This site asks nothing about criminal history.</p>';
		}
		return '';
	}

	private function privacy(): array {
		$updated = wp_date( 'F j, Y' );
		return array(
			self::sec(
				array( self::h( 'Privacy notice', 'h1' ), self::p( 'What this demo stores about you, and what it never asks for. Last updated ' . $updated . '.', 'mt-lead' ) ),
				array( 'mt-section--mist', 'mt-section--tight' ),
				array( 'mt-inner--narrow' )
			),
			self::sec(
				array(
					self::rich(
						'<h2>Without an account</h2><p>Your answers to the six questions and your checklist ticks are kept in cookies in your own browser for up to 180 days. They aren’t sent anywhere else, and clearing your cookies removes them.</p>'
						. '<h2>Finding your license</h2><p>If you use “Find your license in DPI’s records”, the name or file number you type is sent to DPI’s public license lookup for that search only. We don’t store or log it. The names DPI lists are kept in a cookie in your browser for 30 minutes so you can pick yours, and the record you pick (your file number and licenses, without your name) for one day. To limit lookups, we count them against a scrambled form of your IP address for 15 minutes.</p><h2>With an account</h2><p>We store your first name, email address, password (encrypted), your answers, your checklist ticks with their dates, and the applications you save: which job, its status, your availability and start date. We email you when you save an application, and the site owner receives a short notice.</p>'
						. '<h2>What we never ask for</h2><ul><li>Social Security numbers, ID or immigration documents (Form I-9)</li><li>Bank or tax details</li><li>Medical or TB results</li><li>Arrests, convictions or any criminal history</li><li>Uploaded files of any kind</li></ul><p>Employers collect those themselves, in their own secure systems or in person.</p>'
						. '<h2>Employers</h2><p>This is a demo. Applications you save here are not sent to any employer; you finish each one on the employer’s own site.</p>'
						. '<h2>Deleting your data</h2><p>Signed in, go to <a href="/account/">My account</a> and choose Delete my account: everything above is removed at once. Questions: use the contact details on <a href="https://mannmade.us/">mannmade.us</a>.</p>'
					),
				),
				array(),
				array( 'mt-inner--narrow' )
			),
		);
	}
}
