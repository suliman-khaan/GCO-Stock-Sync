<?php
/**
 * Admin Settings Page — handles plugin settings and manual sync trigger.
 *
 * Uses the standard WordPress Settings API for form rendering and validation.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Settings_Page
 */
class GCO_Stock_Sync_Settings_Page {

	/**
	 * Settings group name for Settings API.
	 */
	const SETTINGS_GROUP = 'gco_stock_sync_settings_group';

	/**
	 * Global option key.
	 */
	const OPTION_KEY = 'gco_stock_sync_settings';

	/**
	 * Initialize settings registration and hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_gco_stock_sync_manual_run', array( $this, 'ajax_manual_run' ) );
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings() {
		// 1. Global Settings
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_global_settings' ),
				'capability'        => 'manage_woocommerce',
			)
		);

		add_settings_section(
			'gco_stock_sync_global_section',
			__( 'General Settings', 'gco-stock-sync' ),
			array( $this, 'render_global_section_intro' ),
			'gco-stock-sync-settings'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Stock Sync', 'gco-stock-sync' ),
			array( $this, 'render_enabled_field' ),
			'gco-stock-sync-settings',
			'gco-stock-sync-global_section'
		);

		add_settings_field(
			'sync_interval',
			__( 'Sync Interval', 'gco-stock-sync' ),
			array( $this, 'render_interval_field' ),
			'gco-stock-sync-settings',
			'gco-stock-sync-global_section'
		);

		add_settings_field(
			'delete_data_uninstall',
			__( 'Delete Data on Uninstall', 'gco-stock-sync' ),
			array( $this, 'render_delete_uninstall_field' ),
			'gco-stock-sync-settings',
			'gco-stock-sync-global_section'
		);

		// 2. Per-Supplier Sections & Settings
		$suppliers = GCO_Stock_Sync_Plugin::get_instance()->get_suppliers();

		foreach ( $suppliers as $key => $supplier ) {
			$opt_name = 'gco_stock_sync_supplier_' . $key;

			register_setting(
				self::SETTINGS_GROUP,
				$opt_name,
				array(
					'type'              => 'array',
					'sanitize_callback' => function( $input ) use ( $key ) {
						return $this->sanitize_supplier_settings( $input, $key );
					},
					'capability'        => 'manage_woocommerce',
				)
			);

			$section_id = 'gco_stock_sync_supplier_section_' . $key;

			add_settings_section(
				$section_id,
				sprintf(
					/* translators: %s: supplier name */
					__( '%s Settings', 'gco-stock-sync' ),
					$supplier->get_label()
				),
				function() use ( $supplier ) {
					echo '<p class="description">' . esc_html(
						sprintf(
							/* translators: %s: supplier name */
							__( 'Configure the live feed connector for %s.', 'gco-stock-sync' ),
							$supplier->get_label()
						)
					) . '</p>';
				},
				'gco-stock-sync-settings'
			);

			foreach ( $supplier->get_settings_fields() as $field_id => $field_spec ) {
				add_settings_field(
					$opt_name . '_' . $field_id,
					esc_html( $field_spec['label'] ),
					array( $this, 'render_supplier_field' ),
					'gco-stock-sync-settings',
					$section_id,
					array(
						'supplier_key' => $key,
						'option_name'  => $opt_name,
						'field_id'     => $field_id,
						'field_spec'   => $field_spec,
					)
				);
			}
		}
	}

	/**
	 * Sanitize global plugin settings.
	 *
	 * @param array $input Raw input from POST.
	 * @return array Sanitized settings.
	 */
	public function sanitize_global_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$output = array();

		// Enable sync globally
		$output['enabled'] = ! empty( $input['enabled'] );

		// Sync interval: only 30, 60, 120 allowed. Fallback to 60.
		$interval = isset( $input['sync_interval'] ) ? absint( $input['sync_interval'] ) : 60;
		if ( ! in_array( $interval, array( 30, 60, 120 ), true ) ) {
			$interval = 60;
		}
		$output['sync_interval'] = $interval;

		// Delete data on uninstall
		$output['delete_data_uninstall'] = ! empty( $input['delete_data_uninstall'] );

		return $output;
	}

	/**
	 * Sanitize per-supplier settings with SSRF hardening.
	 *
	 * @param array  $input        Raw input.
	 * @param string $supplier_key Supplier key.
	 * @return array Sanitized array.
	 */
	public function sanitize_supplier_settings( $input, $supplier_key ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$output = array();

		// Enabled toggle
		if ( isset( $input['enabled'] ) ) {
			$output['enabled'] = ! empty( $input['enabled'] );
		}

		// Feed URL — strictly HTTPS required per SSRF protection rule
		if ( isset( $input['feed_url'] ) ) {
			$raw_url = trim( (string) $input['feed_url'] );
			// esc_url_raw with https only
			$clean_url = esc_url_raw( $raw_url, array( 'https' ) );

			// Check if URL starts with https://
			if ( ! empty( $raw_url ) && 0 !== stripos( $clean_url, 'https://' ) ) {
				add_settings_error(
					'gco_stock_sync_supplier_' . $supplier_key,
					'invalid_feed_url',
					__( 'Supplier Feed URL must be a valid, secure HTTPS URL.', 'gco-stock-sync' )
				);
				$clean_url = '';
			}

			$output['feed_url'] = $clean_url;
		}

		// Minimum rows threshold
		if ( isset( $input['min_rows'] ) ) {
			$output['min_rows'] = max( 1, absint( $input['min_rows'] ) );
		}

		return $output;
	}

	/**
	 * Section intro text.
	 */
	public function render_global_section_intro() {
		echo '<p class="description">' . esc_html__( 'Configure automatic stock synchronization and lifecycle behaviour.', 'gco-stock-sync' ) . '</p>';
	}

	/**
	 * Render 'enabled' checkbox.
	 */
	public function render_enabled_field() {
		$settings = get_option( self::OPTION_KEY, array() );
		$enabled  = ! empty( $settings['enabled'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $enabled, true ); ?> />
			<?php esc_html_e( 'Enable automatic background sync on the schedule specified below.', 'gco-stock-sync' ); ?>
		</label>
		<?php
	}

	/**
	 * Render 'sync_interval' select dropdown.
	 */
	public function render_interval_field() {
		$settings = get_option( self::OPTION_KEY, array() );
		$current  = isset( $settings['sync_interval'] ) ? absint( $settings['sync_interval'] ) : 60;
		?>
		<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_interval]" id="gco_ss_sync_interval">
			<option value="30" <?php selected( $current, 30 ); ?>><?php esc_html_e( 'Every 30 minutes', 'gco-stock-sync' ); ?></option>
			<option value="60" <?php selected( $current, 60 ); ?>><?php esc_html_e( 'Every 60 minutes (Default)', 'gco-stock-sync' ); ?></option>
			<option value="120" <?php selected( $current, 120 ); ?>><?php esc_html_e( 'Every 2 hours', 'gco-stock-sync' ); ?></option>
		</select>
		<p class="description">
			<?php esc_html_e( 'How frequently WP-Cron should poll supplier feeds for stock updates.', 'gco-stock-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Render 'delete_data_uninstall' checkbox.
	 */
	public function render_delete_uninstall_field() {
		$settings = get_option( self::OPTION_KEY, array() );
		$checked  = ! empty( $settings['delete_data_uninstall'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_data_uninstall]" value="1" <?php checked( $checked, true ); ?> />
			<?php esc_html_e( 'Drop database tables, options, and product sync meta when the plugin is uninstalled.', 'gco-stock-sync' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Leave unchecked to preserve logs and product settings if you plan to reinstall.', 'gco-stock-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Render supplier field dynamically based on specification.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_supplier_field( $args ) {
		$option_name = $args['option_name'];
		$field_id    = $args['field_id'];
		$spec        = $args['field_spec'];

		$options = get_option( $option_name, array() );
		$val     = isset( $options[ $field_id ] ) ? $options[ $field_id ] : ( isset( $spec['default'] ) ? $spec['default'] : '' );

		$input_name = "{$option_name}[{$field_id}]";

		switch ( $spec['type'] ) {
			case 'checkbox':
				?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="1" <?php checked( ! empty( $val ), true ); ?> />
					<?php echo esc_html( isset( $spec['description'] ) ? $spec['description'] : '' ); ?>
				</label>
				<?php
				break;

			case 'number':
				?>
				<input type="number" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $val ); ?>" class="small-text" min="1" step="1" />
				<?php if ( ! empty( $spec['description'] ) ) : ?>
					<p class="description"><?php echo esc_html( $spec['description'] ); ?></p>
				<?php endif; ?>
				<?php
				break;

			case 'text':
			case 'url':
			default:
				?>
				<input type="text" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $val ); ?>" class="regular-text code" style="width: 100%; max-width: 600px;" />
				<?php if ( ! empty( $spec['description'] ) ) : ?>
					<p class="description"><?php echo esc_html( $spec['description'] ); ?></p>
				<?php endif; ?>
				<?php
				break;
		}
	}

	/**
	 * Render the status panel with last run details and "Run sync now" button.
	 */
	public function render_status_panel() {
		global $wpdb;

		$runs_table = $wpdb->prefix . 'gco_ss_runs';
		$last_run   = $wpdb->get_row( "SELECT * FROM {$runs_table} ORDER BY id DESC LIMIT 1" );

		$next_cron = wp_next_scheduled( 'gco_stock_sync_cron' );
		$settings  = get_option( self::OPTION_KEY, array() );
		$enabled   = ! empty( $settings['enabled'] );

		?>
		<div class="gco-ss-status-card">
			<h2>
				<span class="dashicons dashicons-dashboard"></span>
				<?php esc_html_e( 'Sync Overview & Status', 'gco-stock-sync' ); ?>
			</h2>

			<div class="gco-ss-metrics-grid">
				<div class="gco-ss-metric">
					<div class="gco-ss-metric-label"><?php esc_html_e( 'Sync Engine', 'gco-stock-sync' ); ?></div>
					<div class="gco-ss-metric-value">
						<?php if ( $enabled ) : ?>
							<span class="gco-ss-badge status-success"><?php esc_html_e( 'Active', 'gco-stock-sync' ); ?></span>
						<?php else : ?>
							<span class="gco-ss-badge status-skipped"><?php esc_html_e( 'Disabled', 'gco-stock-sync' ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="gco-ss-metric">
					<div class="gco-ss-metric-label"><?php esc_html_e( 'Last Run', 'gco-stock-sync' ); ?></div>
					<div class="gco-ss-metric-value">
						<?php
						if ( $last_run ) {
							echo esc_html( $last_run->started_at );
						} else {
							esc_html_e( 'Never run', 'gco-stock-sync' );
						}
						?>
					</div>
				</div>

				<div class="gco-ss-metric">
					<div class="gco-ss-metric-label"><?php esc_html_e( 'Last Result', 'gco-stock-sync' ); ?></div>
					<div class="gco-ss-metric-value">
						<?php if ( $last_run ) : ?>
							<span class="gco-ss-badge status-<?php echo esc_attr( sanitize_html_class( $last_run->status ) ); ?>">
								<?php echo esc_html( $last_run->status ); ?>
							</span>
						<?php else : ?>
							<span class="gco-ss-badge status-skipped"><?php esc_html_e( 'N/A', 'gco-stock-sync' ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="gco-ss-metric">
					<div class="gco-ss-metric-label"><?php esc_html_e( 'Next Scheduled', 'gco-stock-sync' ); ?></div>
					<div class="gco-ss-metric-value">
						<?php
						if ( ! $enabled ) {
							esc_html_e( 'Paused', 'gco-stock-sync' );
						} elseif ( $next_cron ) {
							$time_diff = human_time_diff( time(), $next_cron );
							printf(
								/* translators: %s: relative time */
								esc_html__( 'In %s', 'gco-stock-sync' ),
								esc_html( $time_diff )
							);
						} else {
							esc_html_e( 'Not scheduled', 'gco-stock-sync' );
						}
						?>
					</div>
				</div>
			</div>

			<div class="gco-ss-actions-row">
				<button type="button" class="button button-primary" id="gco-ss-run-sync-now">
					<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 4px;"></span>
					<?php esc_html_e( 'Run Sync Now', 'gco-stock-sync' ); ?>
				</button>
				<span class="spinner" id="gco-ss-sync-spinner"></span>
				<span id="gco-ss-sync-message"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render WP-Cron warning if DISABLE_WP_CRON is enabled.
	 */
	public function render_cron_warning() {
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			return;
		}

		$site_url = site_url( 'wp-cron.php?doing_wp_cron' );
		?>
		<div class="gco-ss-cron-warning">
			<p>
				<strong><?php esc_html_e( 'Notice:', 'gco-stock-sync' ); ?></strong>
				<?php esc_html_e( 'DISABLE_WP_CRON is enabled on this WordPress installation. Automatic background stock sync will only run if a real system cron job invokes wp-cron.php periodically.', 'gco-stock-sync' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Recommended server cron command (run every 15-30 mins):', 'gco-stock-sync' ); ?><br />
				<code>wget -q -O - <?php echo esc_url( $site_url ); ?> >/dev/null 2>&1</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the full settings page HTML.
	 */
	public function render() {
		$this->render_cron_warning();
		$this->render_status_panel();
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( self::SETTINGS_GROUP );
			do_settings_sections( 'gco-stock-sync-settings' );
			submit_button( __( 'Save Settings', 'gco-stock-sync' ) );
			?>
		</form>
		<?php
	}

	/**
	 * Handle the AJAX request to manually run a sync.
	 */
	public function ajax_manual_run() {
		// 1. Capability check
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. You must be able to manage WooCommerce.', 'gco-stock-sync' ) ), 403 );
		}

		// 2. Nonce verification
		check_ajax_referer( 'gco_stock_sync_manual_run', 'nonce' );

		try {
			$runner    = new GCO_Stock_Sync_Sync_Runner();
			$suppliers = GCO_Stock_Sync_Plugin::get_instance()->get_suppliers();

			$total_updated = 0;
			$total_rows    = 0;
			$statuses      = array();

			foreach ( $suppliers as $key => $supplier ) {
				$result = $runner->run( $key, $supplier );
				$statuses[] = $result->status;
				$total_updated += $result->products_updated;
				$total_rows    += $result->rows_fetched;
			}

			$msg = sprintf(
				/* translators: 1: rows fetched, 2: products updated */
				__( 'Sync completed! Processed %1$d feed rows, updated stock status on %2$d product(s).', 'gco-stock-sync' ),
				$total_rows,
				$total_updated
			);

			wp_send_json_success( array(
				'message'          => $msg,
				'rows_fetched'     => $total_rows,
				'products_updated' => $total_updated,
				'statuses'         => $statuses,
			) );

		} catch ( Throwable $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Sync failed: %s', 'gco-stock-sync' ),
					$e->getMessage()
				),
			) );
		}
	}
}
