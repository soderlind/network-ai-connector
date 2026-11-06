<?php

namespace Soderlind\Network_AI_Connector;

use WordPress\AiClient\AiClient;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public const PAGE = 'network-ai-connector';

	private string $page_hook = '';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_edit_network_ai_connector', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'site_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'site_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'network_assets' ) );
	}

	public function site_assets( string $hook ): void {
		if ( 'options-connectors.php' === $hook && Plugin::is_enforced() && $this->plugin->connectors() ) {
			wp_enqueue_script( 'network-ai-connector-site-notice', plugins_url( 'assets/site-notice.js', PLUGIN_FILE ), array(), '0.1.0', true );
		}
	}

	public function network_assets( string $hook ): void {
		if ( '' === $this->page_hook || $hook !== $this->page_hook || ! is_network_admin() ) {
			return;
		}

		wp_enqueue_style( 'network-ai-connector-admin', plugins_url( 'assets/network-admin.css', PLUGIN_FILE ), array(), '0.1.0' );
		wp_enqueue_script( 'network-ai-connector-admin', plugins_url( 'assets/network-admin.js', PLUGIN_FILE ), array(), '0.1.0', true );
	}

	public function menu(): void {
		$hook            = add_submenu_page(
			'settings.php',
			__( 'Connectors', 'network-ai-connector' ),
			__( 'Connectors', 'network-ai-connector' ),
			'manage_network_options',
			self::PAGE,
			array( $this, 'render' )
		);
		$this->page_hook = is_string( $hook ) ? $hook : '';
	}

	private function authorize(): void {
		if ( ! is_multisite() || ! Plugin::is_enforced() || ! is_network_admin() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage network connectors.', 'network-ai-connector' ), '', array( 'response' => 403 ) );
		}
	}

	public function save(): void {
		$this->authorize();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'A POST request is required.', 'network-ai-connector' ), '', array( 'response' => 405 ) );
		}

		$id = isset( $_POST['provider'] ) && is_string( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : '';
		if ( ! isset( $this->plugin->connectors()[ $id ] ) ) {
			wp_die( esc_html__( 'Unknown network connector.', 'network-ai-connector' ), '', array( 'response' => 400 ) );
		}

		$network_id = get_current_network_id();
		check_admin_referer( 'network_ai_connector_' . $network_id . '_' . $id );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? wp_unslash( $_POST['operation'] ) : '';
		if ( 'install' === $operation || 'activate' === $operation ) {
			$this->change_plugin( $id, $operation );
		}
		$keys           = Plugin::keys( $network_id );
		$configurations = get_network_option( $network_id, Plugin::CONFIG_OPTION, array() );
		if ( ! is_array( $configurations ) ) {
			$this->redirect( 'storage_failed', $id );
		}
		$old_configurations = $configurations;
		$status             = 'saved';

		if ( 'remove' === $operation ) {
			if ( '1' !== ( $_POST['confirm_remove'] ?? '' ) ) {
				$this->redirect( 'confirm_remove', $id );
			}
			unset( $keys[ $id ] );
			unset( $configurations[ $id ] );
			$status = 'removed';
		} elseif ( 'save' === $operation ) {
			if ( 'active' !== $this->plugin_state( $id, $this->plugin->connectors()[ $id ] ) ) {
				$this->redirect( 'provider_inactive', $id );
			}
			$definition = Provider_Settings::definitions()[ $id ] ?? array();
			$key        = isset( $_POST['api_key'] ) && is_string( $_POST['api_key'] ) ? trim( wp_unslash( $_POST['api_key'] ) ) : '';
			if ( '' === $key && ! empty( $definition['fields'] ) ) {
				$key = $keys[ $id ] ?? '';
			}
			if ( ( '' === $key && empty( $definition['key_optional'] ) ) || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
				$this->redirect( 'invalid_key', $id );
			}
			$configuration = $this->submitted_configuration( $definition );
			if ( is_wp_error( $configuration ) ) {
				$this->redirect( 'invalid_configuration', $id );
			}
			$result = $this->plugin->validate_key( $id, $key, $configuration );
			if ( is_wp_error( $result ) ) {
				$this->redirect( $result->get_error_code(), $id );
			}
			$keys                  = Plugin::keys( $network_id );
			$keys[ $id ]           = $key;
			$configurations[ $id ] = array_merge( $configuration, array( 'enabled' => true ) );
		} else {
			wp_die( esc_html__( 'Unknown connector action.', 'network-ai-connector' ), '', array( 'response' => 400 ) );
		}

		if ( $old_configurations !== $configurations && ! update_network_option( $network_id, Plugin::CONFIG_OPTION, $configurations ) ) {
			$this->redirect( 'storage_failed', $id );
		}
		if ( Plugin::keys( $network_id ) !== $keys && ! update_network_option( $network_id, Plugin::OPTION, $keys ) ) {
			if ( $old_configurations !== $configurations && ! update_network_option( $network_id, Plugin::CONFIG_OPTION, $old_configurations ) ) {
				wp_trigger_error( __METHOD__, 'Failed to restore network provider settings after a credential storage failure.' );
			}
			$this->redirect( 'storage_failed', $id );
		}
		$this->plugin->apply_provider_configuration();
		$this->plugin->clear_provider_cache( $id );
		$this->redirect( $status, $id );
	}

	/** @return array<string, mixed>|\WP_Error */
	private function submitted_configuration( array $definition ): array|\WP_Error {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- save() has checked the provider/network nonce.
		$submitted     = isset( $_POST['configuration'] ) && is_array( $_POST['configuration'] ) ? wp_unslash( $_POST['configuration'] ) : array();
		$configuration = array();
		foreach ( $definition['fields'] ?? array() as $name => $field ) {
			$value = $submitted[ $name ] ?? ( 'choices' === $field['type'] ? array() : '' );
			if ( 'choices' === $field['type'] ) {
				if ( ! is_array( $value ) || count( array_filter( $value, 'is_string' ) ) !== count( $value ) || array_diff( $value, $field['choices'] ) ) {
					return new \WP_Error( 'invalid_configuration' );
				}
				$value = array_values( $value );
			} else {
				if ( ! is_string( $value ) ) {
					return new \WP_Error( 'invalid_configuration' );
				}
				$value = trim( $value );
				if ( 'url' === $field['type'] && '' !== $value ) {
					$parts = wp_parse_url( $value );
					if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
						return new \WP_Error( 'invalid_configuration' );
					}
					$value = esc_url_raw( $value, array( 'http', 'https' ) );
				} else {
					$value = sanitize_text_field( $value );
				}
			}
			if ( ! empty( $field['required'] ) && empty( $value ) ) {
				return new \WP_Error( 'invalid_configuration' );
			}
			$configuration[ $name ] = $value;
		}
		return $configuration;
	}

	private function redirect( string $status, string $provider ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'nac_status'   => $status,
					'nac_provider' => $provider,
				),
				network_admin_url( 'settings.php?page=' . self::PAGE )
			)
		);
		exit;
	}

	public function site_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'options-connectors' !== $screen->id || is_network_admin() || ! Plugin::is_enforced() || ! $this->plugin->connectors() ) {
			return;
		}

		$names = array_column( $this->plugin->connectors(), 'name' );
		wp_admin_notice(
			sprintf(
				/* translators: %s: Comma-separated AI provider names. */
				esc_html__( 'AI connections are managed by your network administrator: %s. Site credentials and settings are not used for managed providers. Connections must be enabled in Network Admin.', 'network-ai-connector' ),
				esc_html( implode( ', ', $names ) )
			),
			array(
				'id'                 => 'network-ai-connector-notice',
				'type'               => 'info',
				'additional_classes' => array( 'inline' ),
			)
		);
	}

	public function render(): void {
		$this->authorize();
		$keys       = Plugin::keys( get_current_network_id() );
		$connectors = $this->plugin->connectors();
		$registry   = AiClient::defaultRegistry();
		$status     = $this->requested_status();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only provider ID, checked against registered connectors.
		$provider = isset( $_GET['nac_provider'] ) && is_string( $_GET['nac_provider'] ) ? sanitize_key( wp_unslash( $_GET['nac_provider'] ) ) : '';
		$reopen   = in_array( $status, array( 'invalid_key', 'invalid_configuration', 'confirm_remove', 'provider_inactive', 'validation_failed', 'validation_in_progress', 'storage_failed', 'installed', 'activated' ), true ) && isset( $connectors[ $provider ] ) ? $provider : '';
		ksort( $connectors );
		?>
		<div class="nac-page">
			<header class="nac-page__header">
				<h1><?php esc_html_e( 'Connectors', 'network-ai-connector' ); ?></h1>
				<p><?php esc_html_e( 'Manage AI connections for every site in this network. Network configuration overrides site settings. Connections remain disabled until configured and validated here, including providers that do not require a key.', 'network-ai-connector' ); ?></p>
			</header>
			<div class="nac-page__content">
				<?php $this->notice( $status, isset( $connectors[ $provider ] ) ? $connectors[ $provider ]['name'] : '' ); ?>
				<?php
				$unmanaged = array();
				foreach ( $registry->getRegisteredProviderIds() as $registered_id ) {
					if ( ! isset( $connectors[ $registered_id ] ) ) {
						$class       = $registry->getProviderClassName( $registered_id );
						$unmanaged[] = $class::metadata()->getName();
					}
				}
				if ( $unmanaged ) {
					wp_admin_notice(
						sprintf(
							/* translators: %s: Provider names. */
							__( 'These providers do not expose compatible request authentication and are not network-managed: %s. They require a provider integration before network enforcement is possible.', 'network-ai-connector' ),
							implode( ', ', $unmanaged )
						),
						array( 'type' => 'warning' )
					);
				}
				?>
				<?php if ( ! $connectors ) : ?>
					<p><?php esc_html_e( 'No AI providers are registered. Check whether AI support is enabled and the provider plugins are available.', 'network-ai-connector' ); ?></p>
				<?php else : ?>
					<ul class="nac-connector-list">
						<?php foreach ( $connectors as $id => $connector ) : ?>
							<?php
							$has_key          = '' !== ( $keys[ $id ] ?? '' );
							$enabled          = Provider_Settings::enabled( $id );
							$definition       = Provider_Settings::definitions()[ $id ] ?? array();
							$configuration    = Provider_Settings::configuration( $id );
							$plugin_state     = $this->plugin_state( $id, $connector );
							$active           = 'active' === $plugin_state;
							$logo_name        = in_array( $id, array( 'anthropic', 'google', 'openai' ), true ) ? $id : 'default';
							$logo_url         = ! empty( $connector['logo_url'] ) ? $connector['logo_url'] : plugins_url( 'assets/logos/' . $logo_name . '.svg', PLUGIN_FILE );
							$connection_state = __( 'Not connected', 'network-ai-connector' );
							$badge            = '';
							$badge_type       = '';
							if ( $active && $enabled ) {
								try {
									if ( $registry->isProviderConfigured( $id ) ) {
										$connection_state = __( 'Connected', 'network-ai-connector' );
										$badge            = $connection_state;
										$badge_type       = 'connected';
									} else {
										$connection_state = __( 'Connection could not be verified', 'network-ai-connector' );
										$badge            = __( 'Unverified', 'network-ai-connector' );
										$badge_type       = 'unverified';
									}
								} catch ( \Exception $exception ) {
									$connection_state = __( 'Connection check failed', 'network-ai-connector' );
									$badge            = __( 'Check failed', 'network-ai-connector' );
									$badge_type       = 'unverified';
								}
							}
							?>
							<li>
								<?php if ( ! $active ) : ?>
									<?php
									$plugin_file = $this->plugin_file( $connector );
									$can_change  = '' !== $plugin_file && $this->can_activate( $plugin_file ) && ( 'inactive' === $plugin_state || ( 'not-installed' === $plugin_state && $this->can_install() ) );
									?>
									<div id="<?php echo esc_attr( 'nac-' . $id ); ?>" class="nac-connector nac-connector--plugin">
										<div class="nac-connector__summary">
											<img class="nac-connector__logo" src="<?php echo esc_url( $logo_url ); ?>" width="40" height="40" alt="">
											<div class="nac-connector__copy">
												<h2><?php echo esc_html( $connector['name'] ); ?></h2>
												<span class="nac-connector__description"><?php echo esc_html( $connector['description'] ); ?></span>
											</div>
											<?php if ( $can_change ) : ?>
												<?php
												$installing = 'not-installed' === $plugin_state;
												$aria_label = $installing
													/* translators: %s: Connector name. */
													? sprintf( __( 'Install plugin for %s', 'network-ai-connector' ), $connector['name'] )
													/* translators: %s: Connector name. */
													: sprintf( __( 'Network-activate plugin for %s', 'network-ai-connector' ), $connector['name'] );
												?>
												<form class="nac-connector__plugin-form" method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=network_ai_connector' ) ); ?>">
													<?php wp_nonce_field( 'network_ai_connector_' . get_current_network_id() . '_' . $id ); ?>
													<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>">
													<input type="hidden" name="operation" value="<?php echo esc_attr( $installing ? 'install' : 'activate' ); ?>">
													<button type="submit" class="nac-connector__action" data-busy-label="<?php echo esc_attr( $installing ? __( 'Installing…', 'network-ai-connector' ) : __( 'Activating…', 'network-ai-connector' ) ); ?>" aria-label="<?php echo esc_attr( $aria_label ); ?>"><?php echo $installing ? esc_html__( 'Install', 'network-ai-connector' ) : esc_html__( 'Activate', 'network-ai-connector' ); ?></button>
												</form>
											<?php elseif ( 'unavailable' !== $plugin_state ) : ?>
												<a class="nac-connector__link" href="<?php echo esc_url( 'https://wordpress.org/plugins/' . $this->plugin_slug( $plugin_file ) . '/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'network-ai-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'network-ai-connector' ); ?></span></a>
											<?php else : ?>
												<span class="nac-connector__badge"><?php esc_html_e( 'Not available', 'network-ai-connector' ); ?></span>
											<?php endif; ?>
										</div>
									</div>
								<?php else : ?>
								<details id="<?php echo esc_attr( 'nac-' . $id ); ?>" class="nac-connector"<?php echo $id === $reopen ? ' open' : ''; ?>>
									<summary class="nac-connector__summary" aria-controls="<?php echo esc_attr( 'nac-settings-' . $id ); ?>">
										<img class="nac-connector__logo" src="<?php echo esc_url( $logo_url ); ?>" width="40" height="40" alt="">
										<div class="nac-connector__copy">
											<h2><?php echo esc_html( $connector['name'] ); ?></h2>
											<span class="nac-connector__description"><?php echo esc_html( $connector['description'] ); ?></span>
										</div>
										<?php if ( $badge ) : ?>
											<span class="<?php echo esc_attr( 'nac-connector__badge nac-connector__badge--' . $badge_type ); ?>"><?php echo esc_html( $badge ); ?></span>
										<?php endif; ?>
										<span class="nac-connector__action nac-connector__action--closed"><?php echo $enabled ? esc_html__( 'Edit', 'network-ai-connector' ) : esc_html__( 'Set up', 'network-ai-connector' ); ?></span>
										<span class="nac-connector__action nac-connector__action--open">
											<span class="nac-connector__close-label"><?php esc_html_e( 'Close', 'network-ai-connector' ); ?></span>
											<span class="nac-connector__cancel-label"><?php esc_html_e( 'Cancel', 'network-ai-connector' ); ?></span>
										</span>
									</summary>
									<div id="<?php echo esc_attr( 'nac-settings-' . $id ); ?>" class="nac-connector__settings">
										<p><strong><?php echo esc_html( $connection_state ); ?></strong></p>
										<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=network_ai_connector' ) ); ?>">
											<?php wp_nonce_field( 'network_ai_connector_' . get_current_network_id() . '_' . $id ); ?>
											<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>">
											<?php foreach ( $definition['fields'] ?? array() as $name => $field ) : ?>
												<?php $value = $configuration[ $name ] ?? $field['default'] ?? ''; ?>
												<?php if ( 'choices' === $field['type'] ) : ?>
													<fieldset>
														<legend><?php echo esc_html( $field['label'] ); ?></legend>
														<?php foreach ( $field['choices'] as $choice ) : ?>
															<label><input type="checkbox" name="configuration[<?php echo esc_attr( $name ); ?>][]" value="<?php echo esc_attr( $choice ); ?>"<?php checked( is_array( $value ) && in_array( $choice, $value, true ) ); ?>> <?php echo esc_html( $choice ); ?></label>
														<?php endforeach; ?>
													</fieldset>
												<?php else : ?>
													<p>
														<label for="<?php echo esc_attr( 'nac-' . $id . '-' . $name ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
														<input class="regular-text" type="<?php echo 'url' === $field['type'] ? 'url' : 'text'; ?>" id="<?php echo esc_attr( 'nac-' . $id . '-' . $name ); ?>" name="configuration[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $value ); ?>"<?php echo ! empty( $field['required'] ) ? ' required' : ''; ?>>
													</p>
												<?php endif; ?>
											<?php endforeach; ?>
											<p>
												<label for="<?php echo esc_attr( 'nac-key-' . $id ); ?>"><?php echo $has_key ? esc_html__( 'Replacement API key', 'network-ai-connector' ) : esc_html__( 'API key', 'network-ai-connector' ); ?></label>
												<input type="password" class="regular-text" id="<?php echo esc_attr( 'nac-key-' . $id ); ?>" name="api_key" value="" autocomplete="new-password" spellcheck="false">
											</p>
											<?php if ( $has_key ) : ?>
												<p><?php esc_html_e( 'A network key is stored. It is never displayed. Leaving the field blank does not remove it.', 'network-ai-connector' ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $definition['key_optional'] ) ) : ?>
												<p><?php esc_html_e( 'The API key is optional for local servers. Saving validates and enables this connection for the network.', 'network-ai-connector' ); ?></p>
											<?php endif; ?>
											<p><?php esc_html_e( 'New and replacement keys are validated before they are saved.', 'network-ai-connector' ); ?></p>
											<?php if ( ! empty( $connector['authentication']['credentials_url'] ) ) : ?>
												<p><a href="<?php echo esc_url( $connector['authentication']['credentials_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'network-ai-connector' ); ?></a></p>
											<?php endif; ?>
											<p><button class="button button-primary" type="submit" name="operation" value="save"><?php esc_html_e( 'Save', 'network-ai-connector' ); ?></button></p>
											<?php if ( $enabled ) : ?>
												<p><label><input type="checkbox" name="confirm_remove" value="1"> <?php esc_html_e( 'Confirm removal: this provider will become unavailable across this network.', 'network-ai-connector' ); ?></label></p>
												<p><button class="button" type="submit" name="operation" value="remove" formnovalidate><?php esc_html_e( 'Remove network connection', 'network-ai-connector' ); ?></button></p>
											<?php endif; ?>
										</form>
									</div>
								</details>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Matches the plugin states used by the site Connectors page.
	 *
	 * @param string               $id        Connector ID.
	 * @param array<string, mixed> $connector Connector data.
	 * @return 'active'|'inactive'|'not-installed'|'unavailable'
	 */
	private function plugin_state( string $id, array $connector ): string {
		$file   = $this->plugin_file( $connector );
		$active = AiClient::defaultRegistry()->hasProvider( $id );
		if ( '' === $file ) {
			return $active ? 'active' : 'unavailable';
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( $active && is_plugin_active_for_network( $file ) ) {
			return 'active';
		}
		if ( 0 !== validate_plugin( $file ) ) {
			return 'not-installed';
		}
		// A network-active plugin that still registers no provider cannot be fixed from here.
		return is_plugin_active_for_network( $file ) ? 'unavailable' : 'inactive';
	}

	/**
	 * @param array<string, mixed> $connector Connector data.
	 */
	private function plugin_file( array $connector ): string {
		$file = $connector['plugin']['file'] ?? '';
		return is_string( $file ) && '' !== $file && 0 === validate_file( $file ) ? $file : '';
	}

	private function plugin_slug( string $file ): string {
		return str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
	}

	private function can_install(): bool {
		return current_user_can( 'install_plugins' ) && wp_is_file_mod_allowed( 'install_plugins' );
	}

	private function can_activate( string $file ): bool {
		return current_user_can( 'manage_network_plugins' ) && current_user_can( 'activate_plugin', $file );
	}

	private function change_plugin( string $id, string $operation ): never {
		$connector = $this->plugin->connectors()[ $id ];
		$file      = $this->plugin_file( $connector );
		$expected  = 'install' === $operation ? 'not-installed' : 'inactive';
		if ( $expected !== $this->plugin_state( $id, $connector ) ) {
			wp_die( esc_html__( 'This provider plugin cannot be changed from its current state.', 'network-ai-connector' ), '', array( 'response' => 409 ) );
		}
		if ( ! $this->can_activate( $file ) || ( 'install' === $operation && ! $this->can_install() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this provider plugin.', 'network-ai-connector' ), '', array( 'response' => 403 ) );
		}

		if ( 'install' === $operation && is_wp_error( $this->install_plugin( $file ) ) ) {
			$this->redirect( 'install_failed', $id );
		}
		if ( is_wp_error( activate_plugin( $file, '', true ) ) ) {
			$this->redirect( 'activate_failed', $id );
		}
		$this->redirect( 'install' === $operation ? 'installed' : 'activated', $id );
	}

	/**
	 * Installs a connector plugin from WordPress.org, like core's REST plugins endpoint.
	 */
	private function install_plugin( string $file ): true|\WP_Error {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		if ( 'direct' !== get_filesystem_method() ) {
			ob_start();
			$credentials = request_filesystem_credentials( self_admin_url() );
			ob_end_clean();
			if ( ! $credentials ) {
				return new \WP_Error( 'fs_unavailable', __( 'The filesystem is currently unavailable for installing plugins.', 'network-ai-connector' ) );
			}
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $this->plugin_slug( $file ),
				'fields' => array(
					'sections'       => false,
					'language_packs' => true,
				),
			)
		);
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		if ( empty( $api->download_link ) ) {
			return new \WP_Error( 'no_package', __( 'The plugin package could not be found.', 'network-ai-connector' ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( null === $result ) {
			return $wp_filesystem instanceof \WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors()
				? $wp_filesystem->errors
				: new \WP_Error( 'unable_to_connect_to_filesystem', __( 'Unable to connect to the filesystem.', 'network-ai-connector' ) );
		}
		// Only activate the plugin file the connector declared.
		if ( $file !== $upgrader->plugin_info() ) {
			return new \WP_Error( 'unexpected_plugin', __( 'The installed plugin does not match the connector.', 'network-ai-connector' ) );
		}

		$locales        = apply_filters( 'plugins_update_check_locales', array_values( get_available_languages() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$language_packs = array_filter(
			array_map( static fn( mixed $pack ): object => (object) $pack, (array) ( $api->language_packs ?? array() ) ),
			static fn( object $pack ): bool => in_array( $pack->language ?? '', $locales, true )
		);
		if ( $language_packs ) {
			( new \Language_Pack_Upgrader( $skin ) )->bulk_upgrade( $language_packs );
		}
		return true;
	}

	private function requested_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status code, matched against an allowlist.
		return isset( $_GET['nac_status'] ) && is_string( $_GET['nac_status'] ) ? sanitize_key( wp_unslash( $_GET['nac_status'] ) ) : '';
	}

	private function notice( string $status, string $name ): void {
		$messages = array(
			/* translators: %s: Connector name. */
			'installed'              => sprintf( __( 'Plugin for %s installed and network-activated successfully.', 'network-ai-connector' ), $name ),
			/* translators: %s: Connector name. */
			'activated'              => sprintf( __( 'Plugin for %s network-activated successfully.', 'network-ai-connector' ), $name ),
			/* translators: %s: Connector name. */
			'install_failed'         => sprintf( __( 'Failed to install plugin for %s.', 'network-ai-connector' ), $name ),
			/* translators: %s: Connector name. */
			'activate_failed'        => sprintf( __( 'Failed to network-activate plugin for %s.', 'network-ai-connector' ), $name ),
			'saved'                  => __( 'Network connection validated and saved.', 'network-ai-connector' ),
			'removed'                => __( 'Network connection removed. This provider is now unavailable across the network.', 'network-ai-connector' ),
			'invalid_key'            => __( 'Enter a non-empty API key without whitespace or control characters. The stored key has not changed.', 'network-ai-connector' ),
			'confirm_remove'         => __( 'Confirm removal before removing the network key.', 'network-ai-connector' ),
			'provider_inactive'      => __( 'Network-activate the provider plugin before validating its network connection.', 'network-ai-connector' ),
			'validation_failed'      => __( 'The connection could not be validated. Check the key, provider configuration, and connectivity. The stored key has not changed.', 'network-ai-connector' ),
			'validation_in_progress' => __( 'Another network connection is already being validated. The stored connection has not changed.', 'network-ai-connector' ),
			'storage_failed'         => __( 'The network key could not be saved. Please try again.', 'network-ai-connector' ),
			'invalid_configuration'  => __( 'Enter valid provider settings. URLs must use HTTP or HTTPS and must not contain credentials or fragments. The stored connection has not changed.', 'network-ai-connector' ),
		);
		if ( isset( $messages[ $status ] ) ) {
			wp_admin_notice( $messages[ $status ], array( 'type' => in_array( $status, array( 'saved', 'removed', 'installed', 'activated' ), true ) ? 'success' : 'error' ) );
		}
	}
}
