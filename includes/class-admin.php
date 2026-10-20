<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Teaching Vacancies: who can see the dashboard, source health, a manual refresh,
 * and placing employers the county matcher couldn't place.
 */
final class Admin {

	private const SLUG = 'mmi-teach';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_mmi_teach_save', array( self::class, 'save' ) );
		add_action( 'admin_post_mmi_teach_refresh', array( self::class, 'refresh' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MMI_TEACH_FILE ), array( self::class, 'action_links' ) );
	}

	public static function menu(): void {
		add_options_page( __( 'Teaching Vacancies', 'mmi-teach' ), __( 'Teaching Vacancies', 'mmi-teach' ), Plugin::manage_cap(), self::SLUG, array( self::class, 'render' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'mmi-teach' ) . '</a>' );
		return $links;
	}

	/** County choices for an employer: the covered counties, or the two non-county answers. */
	private static function choices(): array {
		$choices = array( '' => __( '— Not placed —', 'mmi-teach' ) );
		foreach ( Plugin::COUNTIES as $code => $name ) {
			/* translators: %s: county name */
			$choices[ $code ] = sprintf( __( '%s County', 'mmi-teach' ), $name );
		}
		$choices['multi'] = __( 'Several counties — decide per school', 'mmi-teach' );
		$choices['out']   = __( 'Outside these counties', 'mmi-teach' );
		return $choices;
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::manage_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mmi-teach' ), 403 );
		}
		$settings  = Plugin::settings();
		$status    = get_option( Refresher::OPTION_STATUS, array() );
		$snapshot  = Snapshot::get();
		$unplaced  = Store::unmapped_employers();
		$overrides = $settings['employer_overrides'];
		$choices   = self::choices();
		$page_id   = (int) get_option( Dashboard::OPTION_PAGE );
		$queued    = (bool) wp_next_scheduled( Plugin::CRON_HOOK . '_now' );
		$notice    = isset( $_GET['mmi_teach'] ) ? sanitize_key( wp_unslash( $_GET['mmi_teach'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Teaching Vacancies', 'mmi-teach' ); ?></h1>
			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. The dashboard has been rebuilt.', 'mmi-teach' ); ?></p></div>
			<?php elseif ( 'queued' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Refresh queued. It runs in the background within a minute or two; reload this page to see the result.', 'mmi-teach' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: 1: number of vacancies, 2: dashboard link */
					esc_html__( '%1$s open teaching postings on the %2$s.', 'mmi-teach' ),
					esc_html( number_format_i18n( count( $snapshot['vacancies'] ) ) ),
					$page_id ? '<a href="' . esc_url( get_permalink( $page_id ) ) . '">' . esc_html__( 'dashboard', 'mmi-teach' ) . '</a>' : esc_html__( 'dashboard', 'mmi-teach' )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Sources', 'mmi-teach' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th><?php esc_html_e( 'Source', 'mmi-teach' ); ?></th>
					<th><?php esc_html_e( 'Postings listed', 'mmi-teach' ); ?></th>
					<th><?php esc_html_e( 'Last good read', 'mmi-teach' ); ?></th>
					<th><?php esc_html_e( 'Last error', 'mmi-teach' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( Refresher::sources() as $source ) : ?>
					<?php $s = $status['sources'][ $source->id() ] ?? array(); ?>
					<tr>
						<td><a href="<?php echo esc_url( $source->home_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $source->label() ); ?></a></td>
						<td><?php echo esc_html( isset( $s['listed'] ) ? number_format_i18n( $s['listed'] ) : '—' ); ?></td>
						<td><?php echo esc_html( ! empty( $s['last_ok'] ) ? get_date_from_gmt( $s['last_ok'], 'M j, g:i a' ) : __( 'never', 'mmi-teach' ) ); ?></td>
						<td><?php echo esc_html( $s['last_error'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The server reads every source every 3 hours. Detail pages (salary, licenses, contact) are read once per new or changed posting in the covered counties.', 'mmi-teach' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mmi_teach_refresh">
				<?php wp_nonce_field( 'mmi_teach_refresh' ); ?>
				<?php submit_button( $queued ? __( 'Refresh queued…', 'mmi-teach' ) : __( 'Refresh now', 'mmi-teach' ), 'secondary', 'submit', false, $queued ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>

			<h2><?php esc_html_e( 'Substitute page facts to re-check', 'mmi-teach' ); ?></h2>
			<?php $stale = Pathway::stale_entries(); ?>
			<?php if ( $stale ) : ?>
				<p class="description">
					<?php
					/* translators: %d: number of days */
					echo esc_html( sprintf( __( 'These facts on the "Become a substitute" page were last checked more than %d days ago. Check each source, update data/pathway.json and its verified_on date.', 'mmi-teach' ), Pathway::STALE_DAYS ) );
					?>
				</p>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th><?php esc_html_e( 'Fact', 'mmi-teach' ); ?></th><th><?php esc_html_e( 'Last checked', 'mmi-teach' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $stale as $label => $date ) : ?>
						<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( $date ?: __( 'never', 'mmi-teach' ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>
					<?php
					/* translators: %d: number of days */
					echo esc_html( sprintf( __( 'Every fact was checked within the last %d days.', 'mmi-teach' ), Pathway::STALE_DAYS ) );
					?>
					<?php if ( Pathway::url() ) : ?>
						<a href="<?php echo esc_url( Pathway::url() ); ?>"><?php esc_html_e( 'View the page', 'mmi-teach' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mmi_teach_save">
				<?php wp_nonce_field( 'mmi_teach_save' ); ?>

				<h2><?php esc_html_e( 'Who can see the dashboard', 'mmi-teach' ); ?></h2>
				<fieldset>
					<label><input type="radio" name="visibility" value="login" <?php checked( $settings['visibility'], 'login' ); ?>> <?php esc_html_e( 'Signed-in users only (hidden from search engines)', 'mmi-teach' ); ?></label><br>
					<label><input type="radio" name="visibility" value="public" <?php checked( $settings['visibility'], 'public' ); ?>> <?php esc_html_e( 'Everyone', 'mmi-teach' ); ?></label>
				</fieldset>

				<h2><?php esc_html_e( 'Employers not placed in a county', 'mmi-teach' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These employers have open postings, but their names match no district or school in the NCES directories, so their postings are left off the dashboard. Pick a county for any that belong in it. Most are outside the area and can stay as they are.', 'mmi-teach' ); ?></p>
				<?php if ( $unplaced ) : ?>
					<table class="widefat striped" style="max-width:900px">
						<thead><tr>
							<th><?php esc_html_e( 'Employer', 'mmi-teach' ); ?></th>
							<th><?php esc_html_e( 'Open postings', 'mmi-teach' ); ?></th>
							<th><?php esc_html_e( 'Example school field', 'mmi-teach' ); ?></th>
							<th><?php esc_html_e( 'County', 'mmi-teach' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $unplaced as $row ) : ?>
							<?php $key = Places::normalize( $row['employer'] ); ?>
							<tr>
								<td><?php echo esc_html( $row['employer'] ); ?></td>
								<td><?php echo esc_html( $row['postings'] ); ?></td>
								<td><?php echo esc_html( $row['sample_location'] ); ?></td>
								<td>
									<select name="place[<?php echo esc_attr( $key ); ?>]">
										<?php foreach ( $choices as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $overrides[ $key ] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'Every employer with open postings is placed.', 'mmi-teach' ); ?></p>
				<?php endif; ?>

				<?php if ( $overrides ) : ?>
					<h2><?php esc_html_e( 'Your placements', 'mmi-teach' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Employers you placed by hand. Choose "Not placed" to undo one.', 'mmi-teach' ); ?></p>
					<table class="widefat striped" style="max-width:900px">
						<tbody>
						<?php foreach ( $overrides as $key => $code ) : ?>
							<tr>
								<td><code><?php echo esc_html( $key ); ?></code></td>
								<td>
									<select name="place[<?php echo esc_attr( $key ); ?>]">
										<?php foreach ( $choices as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $code, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php submit_button( __( 'Save', 'mmi-teach' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save(): void {
		if ( ! current_user_can( Plugin::manage_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mmi-teach' ), 403 );
		}
		check_admin_referer( 'mmi_teach_save' );

		$visibility = sanitize_key( wp_unslash( $_POST['visibility'] ?? 'login' ) );
		$overrides  = Plugin::settings()['employer_overrides'];
		$valid      = array_keys( self::choices() );
		$posted     = isset( $_POST['place'] ) && is_array( $_POST['place'] ) ? wp_unslash( $_POST['place'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key/value is checked below.
		foreach ( $posted as $key => $value ) {
			$key   = Places::normalize( (string) $key ); // Keys are already normalized; this also strips anything else.
			$value = (string) $value;
			if ( '' === $key || ! in_array( $value, $valid, true ) ) {
				continue;
			}
			if ( '' === $value ) {
				unset( $overrides[ $key ] );
			} else {
				$overrides[ $key ] = $value;
			}
		}

		Plugin::update_settings(
			array(
				'visibility'         => in_array( $visibility, array( 'login', 'public' ), true ) ? $visibility : 'login',
				'employer_overrides' => $overrides,
			)
		);
		Places::reset();
		Store::reclassify_open();
		Snapshot::rebuild();

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&mmi_teach=saved' ) );
		exit;
	}

	/** Queues one background refresh (never more than one pending). */
	public static function refresh(): void {
		if ( ! current_user_can( Plugin::manage_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mmi-teach' ), 403 );
		}
		check_admin_referer( 'mmi_teach_refresh' );
		if ( ! wp_next_scheduled( Plugin::CRON_HOOK . '_now' ) ) {
			wp_schedule_single_event( time(), Plugin::CRON_HOOK . '_now' );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&mmi_teach=queued' ) );
		exit;
	}
}
