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
	 * Settings-API page slug for the General Settings tab.
	 */
	const GENERAL_PAGE_SLUG = 'gco-stock-sync-settings-general';

	/**
	 * Get the Settings-API page slug for a given supplier's own tab.
	 *
	 * @param string $supplier_key Supplier key.
	 * @return string
	 */
	public static function get_supplier_page_slug( $supplier_key ) {
		return 'gco-stock-sync-settings-supplier-' . $supplier_key;
	}

	/**
	 * Initialize settings registration and hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_gco_stock_sync_manual_run', array( $this, 'ajax_manual_run' ) );
		add_action( 'wp_ajax_gco_stock_sync_test_connection', array( $this, 'ajax_test_connection' ) );
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
			self::GENERAL_PAGE_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Enable Stock Sync', 'gco-stock-sync' ),
			array( $this, 'render_enabled_field' ),
			self::GENERAL_PAGE_SLUG,
			'gco_stock_sync_global_section'
		);

		add_settings_field(
			'cron_mode',
			__( 'Schedule Method', 'gco-stock-sync' ),
			array( $this, 'render_cron_mode_field' ),
			self::GENERAL_PAGE_SLUG,
			'gco_stock_sync_global_section'
		);

		add_settings_field(
			'sync_interval',
			__( 'Sync Interval (WP-Cron)', 'gco-stock-sync' ),
			array( $this, 'render_interval_field' ),
			self::GENERAL_PAGE_SLUG,
			'gco_stock_sync_global_section'
		);

		add_settings_field(
			'delete_data_uninstall',
			__( 'Delete Data on Uninstall', 'gco-stock-sync' ),
			array( $this, 'render_delete_uninstall_field' ),
			self::GENERAL_PAGE_SLUG,
			'gco_stock_sync_global_section'
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
				self::get_supplier_page_slug( $key )
			);

			foreach ( $supplier->get_settings_fields() as $field_id => $field_spec ) {
				add_settings_field(
					$opt_name . '_' . $field_id,
					esc_html( $field_spec['label'] ),
					array( $this, 'render_supplier_field' ),
					self::get_supplier_page_slug( $key ),
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

		// Cron schedule method: wp_cron (default) or system_cron
		$cron_mode = isset( $input['cron_mode'] ) ? sanitize_key( $input['cron_mode'] ) : 'wp_cron';
		if ( ! in_array( $cron_mode, array( 'wp_cron', 'system_cron' ), true ) ) {
			$cron_mode = 'wp_cron';
		}
		$output['cron_mode'] = $cron_mode;

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

		// Generic fallback: sanitize any other field the supplier declares via
		// get_settings_fields() by its declared type, so connectors beyond
		// Highland (whose feed_url/min_rows keys are already handled above)
		// can persist settings without each needing its own bespoke branch
		// here. Fields already set above are left untouched, and any key the
		// supplier hasn't declared stays dropped, same as before this loop.
		$suppliers = GCO_Stock_Sync_Plugin::get_instance()->get_suppliers();
		$supplier  = isset( $suppliers[ $supplier_key ] ) ? $suppliers[ $supplier_key ] : null;

		if ( $supplier ) {
			foreach ( $supplier->get_settings_fields() as $field_id => $field_spec ) {
				if ( array_key_exists( $field_id, $output ) || ! isset( $input[ $field_id ] ) ) {
					continue;
				}

				$type                = isset( $field_spec['type'] ) ? $field_spec['type'] : 'text';
				$output[ $field_id ] = $this->sanitize_field_by_type( $input[ $field_id ], $type );
			}
		}

		return $output;
	}

	/**
	 * Sanitize a single settings-field value according to its declared type.
	 *
	 * @param mixed  $value Raw input value.
	 * @param string $type  Declared field type.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_field_by_type( $value, $type ) {
		switch ( $type ) {
			case 'checkbox':
				return ! empty( $value );

			case 'number':
				return absint( $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'password':
				return trim( (string) $value );

			case 'url':
				return esc_url_raw( trim( (string) $value ), array( 'https' ) );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
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
	 * Render 'cron_mode' radio selection.
	 */
	public function render_cron_mode_field() {
		$settings = get_option( self::OPTION_KEY, array() );
		$current  = isset( $settings['cron_mode'] ) ? $settings['cron_mode'] : 'wp_cron';
		?>
		<fieldset class="gco-ss-cron-mode-fieldset">
			<label style="display: block; margin-bottom: 8px;">
				<input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cron_mode]" value="wp_cron" <?php checked( $current, 'wp_cron' ); ?> class="gco-ss-cron-mode-radio" />
				<strong><?php esc_html_e( 'Built-in WP-Cron (Default)', 'gco-stock-sync' ); ?></strong> —
				<?php esc_html_e( 'Runs automatically in the background on visitor traffic according to the interval below.', 'gco-stock-sync' ); ?>
			</label>
			<label style="display: block; margin-bottom: 4px;">
				<input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cron_mode]" value="system_cron" <?php checked( $current, 'system_cron' ); ?> class="gco-ss-cron-mode-radio" />
				<strong><?php esc_html_e( 'Server System Cron / WP-CLI (Recommended for high reliability)', 'gco-stock-sync' ); ?></strong> —
				<?php esc_html_e( 'Disables traffic-triggered cron; syncs are run by your server crontab or task scheduler on exact times.', 'gco-stock-sync' ); ?>
			</label>
		</fieldset>
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

			case 'textarea':
				?>
				<textarea name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" rows="8" class="large-text code" style="width: 100%; max-width: 600px; font-family: monospace;"><?php echo esc_textarea( $val ); ?></textarea>
				<?php if ( ! empty( $spec['description'] ) ) : ?>
					<p class="description"><?php echo esc_html( $spec['description'] ); ?></p>
				<?php endif; ?>
				<?php
				break;

			case 'text':
			case 'url':
			default:
				?>
				<input type="text" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $val ); ?>" class="regular-text code" style="width: 100%; max-width: 600px;" />
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
	 * Render the General Settings tab: overview panel, global toggles, cron guide.
	 */
	public function render_general_tab() {
		$this->render_cron_warning();
		$this->render_status_panel();
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( self::SETTINGS_GROUP );
			do_settings_sections( self::GENERAL_PAGE_SLUG );
			submit_button( __( 'Save General Settings', 'gco-stock-sync' ) );
			?>
		</form>
		<?php
		$this->render_cron_guide();
	}

	/**
	 * Render one supplier's own settings tab: its fields, Test Connection,
	 * and its own Save button — independent of every other supplier's form,
	 * so saving one connector's settings never touches another's.
	 *
	 * @param string $supplier_key Supplier key, e.g. 'ladds_infac'.
	 */
	public function render_supplier_tab( $supplier_key ) {
		$suppliers = GCO_Stock_Sync_Plugin::get_instance()->get_suppliers();

		if ( ! isset( $suppliers[ $supplier_key ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Unknown supplier.', 'gco-stock-sync' ) . '</p></div>';
			return;
		}

		$supplier = $suppliers[ $supplier_key ];

		// Optional, duck-typed: any supplier can surface its own status
		// notices (e.g. Browning's "needs reauthentication" warning) without
		// this shared page needing to know anything supplier-specific.
		// method_exists() is false for suppliers that don't implement it
		// (Highland, Ladds), making this a no-op for them.
		if ( method_exists( $supplier, 'get_status_notices' ) ) {
			foreach ( (array) $supplier->get_status_notices() as $notice ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
			}
		}
		?>
		<div class="gco-ss-section">
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::get_supplier_page_slug( $supplier_key ) );
				$this->render_test_connection_button( $supplier_key );
				submit_button(
					sprintf(
						/* translators: %s: supplier name */
						__( 'Save %s Settings', 'gco-stock-sync' ),
						$supplier->get_label()
					)
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the single Test Connection control for a supplier's tab. The JS
	 * side serializes every field currently on the page (whether saved yet or
	 * not) and posts it to ajax_test_connection(), which fetches with those
	 * values applied as a temporary override — nothing is written to the DB.
	 *
	 * @param string $supplier_key Supplier key.
	 */
	private function render_test_connection_button( $supplier_key ) {
		?>
		<div class="gco-ss-test-conn-wrapper">
			<button type="button" class="button button-secondary gco-ss-test-connection-btn" data-supplier="<?php echo esc_attr( $supplier_key ); ?>">
				<span class="dashicons dashicons-rest-api" style="vertical-align: middle; margin-top: -2px;"></span>
				<?php esc_html_e( 'Test Connection', 'gco-stock-sync' ); ?>
			</button>
			<span class="spinner gco-ss-test-spinner" style="float: none; margin: 0;"></span>
			<span class="gco-ss-test-result"></span>
		</div>
		<?php
	}

	/**
	 * Render the cron pattern reference guide and server cron setup instructions.
	 */
	public function render_cron_guide() {
		$site_url  = site_url( 'wp-cron.php?doing_wp_cron' );
		$abspath   = rtrim( ABSPATH, '/\\' );
		$cli_cmd   = "0 * * * * wp gco-stock-sync run --path=\"{$abspath}\" >/dev/null 2>&1";
		$curl_cmd  = "0 * * * * curl -s -L \"{$site_url}\" >/dev/null 2>&1";
		?>
		<div class="gco-ss-guide-card">
			<h2>
				<span class="dashicons dashicons-calendar-alt"></span>
				<?php esc_html_e( 'Cron Pattern Reference & Server Setup Guide', 'gco-stock-sync' ); ?>
			</h2>

			<p class="description">
				<?php esc_html_e( 'When using "Server System Cron / WP-CLI", your web server handles scheduled runs with exact timing independently of website visitors. Use the pattern reference and copyable commands below.', 'gco-stock-sync' ); ?>
			</p>

			<div class="gco-ss-guide-columns">
				<div class="gco-ss-guide-col">
					<h3><?php esc_html_e( 'Standard Cron Pattern Syntax (5 Fields)', 'gco-stock-sync' ); ?></h3>
					<pre class="gco-ss-code-block"><code>* * * * *
│ │ │ │ │
│ │ │ │ └─── <?php esc_html_e( 'Day of week (0 - 6, Sunday = 0)', 'gco-stock-sync' ); ?>

│ │ │ └───── <?php esc_html_e( 'Month of year (1 - 12)', 'gco-stock-sync' ); ?>

│ │ └─────── <?php esc_html_e( 'Day of month (1 - 31)', 'gco-stock-sync' ); ?>

│ └───────── <?php esc_html_e( 'Hour of day (0 - 23)', 'gco-stock-sync' ); ?>

└─────────── <?php esc_html_e( 'Minute of hour (0 - 59)', 'gco-stock-sync' ); ?></code></pre>

					<table class="widefat striped gco-ss-pattern-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Schedule', 'gco-stock-sync' ); ?></th>
								<th><?php esc_html_e( 'Cron Expression', 'gco-stock-sync' ); ?></th>
								<th><?php esc_html_e( 'Description', 'gco-stock-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Every 15 mins', 'gco-stock-sync' ); ?></strong></td>
								<td><code>*/15 * * * *</code></td>
								<td><?php esc_html_e( 'Runs at :00, :15, :30, :45 past every hour', 'gco-stock-sync' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Every 30 mins', 'gco-stock-sync' ); ?></strong></td>
								<td><code>*/30 * * * *</code></td>
								<td><?php esc_html_e( 'Runs at :00 and :30 past every hour', 'gco-stock-sync' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Every 1 hour', 'gco-stock-sync' ); ?></strong></td>
								<td><code>0 * * * *</code></td>
								<td><?php esc_html_e( 'Runs at minute 0 of every hour (Standard)', 'gco-stock-sync' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Every 2 hours', 'gco-stock-sync' ); ?></strong></td>
								<td><code>0 */2 * * *</code></td>
								<td><?php esc_html_e( 'Runs every 2nd hour at minute 0 (e.g. 02:00, 04:00)', 'gco-stock-sync' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Twice daily', 'gco-stock-sync' ); ?></strong></td>
								<td><code>0 0,12 * * *</code></td>
								<td><?php esc_html_e( 'Runs at 12:00 AM and 12:00 PM daily', 'gco-stock-sync' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Once daily (Midnight)', 'gco-stock-sync' ); ?></strong></td>
								<td><code>0 0 * * *</code></td>
								<td><?php esc_html_e( 'Runs once a day at 00:00 midnight', 'gco-stock-sync' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="gco-ss-guide-col">
					<h3><?php esc_html_e( 'Recommended Server Crontab Commands', 'gco-stock-sync' ); ?></h3>
					<p><?php esc_html_e( 'Add one of the following lines to your server crontab (via cPanel Cron Jobs or ssh <code>crontab -e</code>):', 'gco-stock-sync' ); ?></p>

					<div class="gco-ss-cmd-box">
						<div class="gco-ss-cmd-title">
							<strong><?php esc_html_e( 'Option A: WP-CLI (Fastest & Most Reliable)', 'gco-stock-sync' ); ?></strong>
							<button type="button" class="button button-small gco-ss-copy-btn" data-copy="<?php echo esc_attr( $cli_cmd ); ?>">
								<?php esc_html_e( 'Copy', 'gco-stock-sync' ); ?>
							</button>
						</div>
						<code><?php echo esc_html( $cli_cmd ); ?></code>
					</div>

					<div class="gco-ss-cmd-box" style="margin-top: 15px;">
						<div class="gco-ss-cmd-title">
							<strong><?php esc_html_e( 'Option B: cURL / Wget (HTTP Trigger)', 'gco-stock-sync' ); ?></strong>
							<button type="button" class="button button-small gco-ss-copy-btn" data-copy="<?php echo esc_attr( $curl_cmd ); ?>">
								<?php esc_html_e( 'Copy', 'gco-stock-sync' ); ?>
							</button>
						</div>
						<code><?php echo esc_html( $curl_cmd ); ?></code>
					</div>

					<div class="gco-ss-tip-box" style="margin-top: 20px;">
						<span class="dashicons dashicons-info" style="color: #2271b1; margin-right: 6px;"></span>
						<em><?php esc_html_e( 'Tip: If using Server Cron, set DISABLE_WP_CRON to true in wp-config.php to prevent visitors from triggering duplicate internal cron tasks.', 'gco-stock-sync' ); ?></em>
					</div>
				</div>
			</div>
		</div>
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

	/**
	 * Handle AJAX request to test connection to a supplier feed.
	 */
	public function ajax_test_connection() {
		// 1. Capability check
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. You must be able to manage WooCommerce.', 'gco-stock-sync' ) ), 403 );
		}

		// 2. Nonce verification
		check_ajax_referer( 'gco_stock_sync_test_connection', 'nonce' );

		$supplier_key = isset( $_POST['supplier_key'] ) ? sanitize_key( $_POST['supplier_key'] ) : '';

		$suppliers = GCO_Stock_Sync_Plugin::get_instance()->get_suppliers();
		if ( empty( $supplier_key ) || ! isset( $suppliers[ $supplier_key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown or invalid supplier specified.', 'gco-stock-sync' ) ) );
		}

		$supplier = $suppliers[ $supplier_key ];

		// The tab's JS serializes every declared field currently on the page
		// (whether saved yet or not) into a flat field_id => raw value map, so
		// Test Connection works the same way for every supplier's tab without
		// each needing its own bespoke handling here (the old code only ever
		// understood Highland's 'feed_url' field).
		$raw_overrides = isset( $_POST['overrides'] ) ? wp_unslash( $_POST['overrides'] ) : array();
		$overrides     = array();

		if ( is_array( $raw_overrides ) ) {
			$fields = $supplier->get_settings_fields();

			foreach ( $raw_overrides as $field_id => $raw_value ) {
				$field_id = sanitize_key( $field_id );
				if ( ! isset( $fields[ $field_id ] ) ) {
					continue; // Only declared fields can be overridden.
				}

				$type                  = isset( $fields[ $field_id ]['type'] ) ? $fields[ $field_id ]['type'] : 'text';
				$overrides[ $field_id ] = $this->sanitize_field_by_type( $raw_value, $type );
			}
		}

		if ( ! empty( $overrides ) ) {
			// Temporary option filter — never persisted, only affects this one
			// fetch(). WordPress only fires 'option_{name}' when the option
			// row already exists in the DB; for a supplier that has never
			// been saved yet (e.g. testing Ladds before its first Save),
			// get_option() short-circuits via 'default_option_{name}'
			// instead — so both must be hooked or the override silently
			// never applies on a brand-new, never-saved supplier.
			$merge_overrides = function( $opts ) use ( $overrides ) {
				if ( ! is_array( $opts ) ) {
					$opts = array();
				}
				return array_merge( $opts, $overrides );
			};

			add_filter( "option_gco_stock_sync_supplier_{$supplier_key}", $merge_overrides );
			add_filter( "default_option_gco_stock_sync_supplier_{$supplier_key}", $merge_overrides );
		}

		try {
			// Safety: test connection ONLY fetches the feed.
			// It NEVER calls matcher, NEVER calls set_stock_status(), and NEVER logs a run to the database.
			$fetch = $supplier->fetch();

			if ( ! $fetch->ok ) {
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Connection failed: %s', 'gco-stock-sync' ),
						$fetch->error_message
					),
				) );
			}

			$msg = sprintf(
				/* translators: 1: raw rows, 2: parsed items */
				__( 'Connection successful! Feed responded with %1$d raw rows (%2$d parsed stock items).', 'gco-stock-sync' ),
				$fetch->raw_row_count,
				count( $fetch->items )
			);

			wp_send_json_success( array(
				'message'      => $msg,
				'raw_rows'     => $fetch->raw_row_count,
				'parsed_items' => count( $fetch->items ),
			) );

		} catch ( Throwable $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: exception message */
					__( 'Connection test error: %s', 'gco-stock-sync' ),
					$e->getMessage()
				),
			) );
		}
	}
}
