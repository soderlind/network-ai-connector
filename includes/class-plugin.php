<?php

namespace Soderlind\Network_AI_Connector;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Contracts\CachesDataInterface;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public const OPTION        = 'network_ai_connector_keys';
	public const CONFIG_OPTION = 'network_ai_connector_settings';

	/** @var array<string, bool> */
	private array $protected_options = array();

	private string $validation_scope = '';

	/** @var array<string, array> */
	private array $validation_configuration = array();

	private ?string $original_ollama_host = null;
	private bool $ollama_host_changed     = false;

	public function register(): void {
		require_once __DIR__ . '/class-provider-settings.php';
		$this->register_provider_options();
		add_action( 'init', array( $this, 'enforce' ), 19 );
		add_action( 'init', array( $this, 'enforce' ), PHP_INT_MAX );
		add_action( 'switch_blog', array( $this, 'enforce' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'block_site_updates' ), 10, 3 );
		add_filter( 'rest_pre_get_setting', array( $this, 'hide_setting' ), PHP_INT_MAX, 3 );
		add_filter( 'allowed_options', array( $this, 'remove_site_options' ), PHP_INT_MAX );
		add_filter( 'script_module_data_options-connectors-wp-admin', array( $this, 'site_page_data' ), PHP_INT_MAX );
		add_filter( 'script_module_data_connectors-wp-admin', array( $this, 'site_page_data' ), PHP_INT_MAX );
		add_filter( 'wp_ai_client_cache_group', array( $this, 'cache_group' ) );
		add_action( 'options-connectors-wp-admin_init', array( $this, 'remove_managed_modules' ), PHP_INT_MAX );
		add_action( 'connectors-wp-admin_init', array( $this, 'remove_managed_modules' ), PHP_INT_MAX );
	}

	public function remove_managed_modules(): void {
		if ( self::is_enforced() ) {
			foreach ( Provider_Settings::definitions() as $definition ) {
				if ( isset( $definition['script_module'] ) ) {
					wp_dequeue_script_module( $definition['script_module'] );
				}
			}
		}
	}

	public static function network_id(): int {
		$site = get_site( get_current_blog_id() );
		return $site ? (int) $site->site_id : get_current_network_id();
	}

	public static function is_enforced(): bool {
		$active = get_network_option( self::network_id(), 'active_sitewide_plugins', array() );
		return is_multisite() && isset( $active[ plugin_basename( PLUGIN_FILE ) ] );
	}

	/** @return array<string, string> */
	public static function keys( ?int $network_id = null ): array {
		$keys = get_network_option( $network_id ?? self::network_id(), self::OPTION, array() );
		if ( ! is_array( $keys ) ) {
			wp_trigger_error( __METHOD__, 'Network AI Connector credentials are not a valid map. Connections are disabled.' );
			return array();
		}

		if ( count( array_filter( $keys, 'is_string' ) ) !== count( $keys ) ) {
			wp_trigger_error( __METHOD__, 'Network AI Connector contains an invalid credential value. Connections are disabled.' );
			return array();
		}
		return $keys;
	}

	/** @return array<string, array> */
	public function connectors(): array {
		$connectors = array_filter(
			wp_get_connectors(),
			static fn( array $connector ): bool =>
				'ai_provider' === $connector['type']
				&& 'api_key' === $connector['authentication']['method']
				&& ! empty( $connector['authentication']['setting_name'] )
		);

		// Azure AI Foundry registers this internal status marker as an API-key connector.
		if ( defined( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID' ) && defined( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION' ) ) {
			$id     = constant( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID' );
			$option = constant( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION' );
			if ( is_string( $id ) && is_string( $option ) && isset( $connectors[ $id ] ) && $option === $connectors[ $id ]['authentication']['setting_name'] ) {
				unset( $connectors[ $id ] );
			}
		}

		$registry = AiClient::defaultRegistry();
		foreach ( $registry->getRegisteredProviderIds() as $id ) {
			$class    = $registry->getProviderClassName( $id );
			$metadata = $class::metadata();
			$method   = $metadata->getAuthenticationMethod();
			// The SDK has no universal enable/disable hook for providers without request authentication.
			if ( null === $method || ! $method->isApiKey() ) {
				unset( $connectors[ $id ] );
				continue;
			}
			if ( isset( $connectors[ $id ] ) ) {
				if ( empty( $connectors[ $id ]['plugin']['file'] ) ) {
					$connectors[ $id ]['plugin']['file'] = $this->provider_plugin_file( $class );
				}
				continue;
			}
			$connectors[ $id ] = array(
				'name'           => $metadata->getName(),
				'description'    => $metadata->getDescription() ?? '',
				'type'           => 'ai_provider',
				'plugin'         => array( 'file' => $this->provider_plugin_file( $class ) ),
				'authentication' => array(
					'method'          => 'api_key',
					'setting_name'    => 'connectors_ai_' . str_replace( '-', '_', $id ) . '_api_key',
					'credentials_url' => $metadata->getCredentialsUrl(),
				),
			);
			if ( $metadata->getLogoPath() ) {
				$connectors[ $id ]['logo_url'] = _wp_connectors_resolve_ai_provider_logo_url( $metadata->getLogoPath() );
			}
		}

		foreach ( Provider_Settings::definitions() as $id => $definition ) {
			$connectors[ $id ]                 ??= array(
				'name'           => $definition['name'],
				'description'    => '',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => $definition['key_option'],
				),
			);
			$connectors[ $id ]['plugin']['file'] = $definition['plugin'];
		}
		return $connectors;
	}

	private function provider_plugin_file( string $provider_class ): string {
		$source = ( new \ReflectionClass( $provider_class ) )->getFileName();
		$root   = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		if ( ! is_string( $source ) || ! str_starts_with( wp_normalize_path( $source ), $root ) ) {
			return '';
		}
		$relative = substr( wp_normalize_path( $source ), strlen( $root ) );
		$folder   = explode( '/', $relative )[0];
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$matches = array_filter(
			array_keys( get_plugins() ),
			static fn( string $file ): bool => $file === $relative || str_starts_with( $file, $folder . '/' )
		);
		return 1 === count( $matches ) ? reset( $matches ) : '';
	}

	public function register_provider_options(): void {
		foreach ( Provider_Settings::definitions() as $id => $definition ) {
			$options = array( $definition['key_option'] );
			foreach ( $definition['fields'] as $field ) {
				$options[] = $field['option'];
			}
			foreach ( array_unique( $options ) as $option ) {
				if ( isset( $this->protected_options[ $option ] ) ) {
					continue;
				}
				add_filter(
					"pre_option_{$option}",
					function ( mixed $value ) use ( $id, $definition, $option ): mixed {
						if ( ! self::is_enforced() ) {
							return $value;
						}
						$config = $this->configuration( $id );
						if ( $option === $definition['key_option'] ) {
							return $config['api_key'] ?? Plugin::keys()[ $id ] ?? '';
						}
						$result = null;
						foreach ( $definition['fields'] as $name => $field ) {
							if ( $option !== $field['option'] ) {
								continue;
							}
							$field_value = $config[ $name ] ?? $field['default'] ?? '';
							if ( isset( $field['path'] ) ) {
								$result[ $field['path'] ] = $field_value;
							} else {
								$result = $field_value;
							}
						}
						return $result;
					},
					PHP_INT_MAX
				);
				add_filter( "pre_update_option_{$option}", array( $this, 'preserve_site_option' ), PHP_INT_MAX, 2 );
				$this->protected_options[ $option ] = true;
			}
		}
		$this->apply_provider_configuration();
	}

	/** @return array<string, mixed> */
	public function configuration( string $id ): array {
		return $this->validation_configuration[ $id ] ?? Provider_Settings::configuration( $id );
	}

	public function apply_provider_configuration(): void {
		if ( self::is_enforced() ) {
			if ( ! $this->ollama_host_changed ) {
				$host                       = getenv( 'OLLAMA_HOST' );
				$this->original_ollama_host = false === $host ? null : $host;
				$this->ollama_host_changed  = true;
			}
			$host = $this->configuration( 'ollama' )['host'] ?? 'http://localhost:11434';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The Ollama SDK reads only this environment variable.
			putenv( 'OLLAMA_HOST=' . $host );
		} elseif ( $this->ollama_host_changed ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the deployment value when leaving an enforced network.
			putenv( null === $this->original_ollama_host ? 'OLLAMA_HOST' : 'OLLAMA_HOST=' . $this->original_ollama_host );
			$this->ollama_host_changed = false;
		}
		foreach ( Provider_Settings::definitions() as $id => $definition ) {
			if ( isset( $definition['apply'] ) ) {
				if ( ! is_callable( $definition['apply'] ) ) {
					throw new \LogicException( 'The network provider configuration callback must be callable.' );
				}
				call_user_func( $definition['apply'], $this->configuration( $id ), self::is_enforced() );
			}
		}
	}

	public function enforce(): void {
		$this->register_provider_options();
		$registry = AiClient::defaultRegistry();
		foreach ( $this->connectors() as $id => $connector ) {
			$option = $connector['authentication']['setting_name'];
			if ( ! isset( $this->protected_options[ $option ] ) ) {
				// Core and REST must never obtain a network secret through a site option.
				add_filter(
					"pre_option_{$option}",
					static fn( mixed $value ): mixed => self::is_enforced() ? '' : $value,
					PHP_INT_MAX
				);
				add_filter( "pre_update_option_{$option}", array( $this, 'preserve_site_option' ), PHP_INT_MAX, 2 );
				$this->protected_options[ $option ] = true;
			}

			if ( $registry->hasProvider( $id ) ) {
				Provider_Settings::remember_authentication( $id, $registry->getProviderRequestAuthentication( $id ) );
				$registry->setProviderRequestAuthentication( $id, new Network_Authentication( $id ) );
			}
		}
	}

	public function preserve_site_option( mixed $value, mixed $old_value ): mixed {
		return self::is_enforced() ? $old_value : $value;
	}

	public function hide_setting( mixed $value, string $name, array $args ): mixed {
		if ( self::is_enforced() && isset( $this->protected_options[ $args['option_name'] ?? $name ] ) ) {
			return '';
		}
		return $value;
	}

	public function block_site_updates( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( ! self::is_enforced() ) {
			return $result;
		}
		foreach ( Provider_Settings::definitions() as $definition ) {
			if ( in_array( untrailingslashit( $request->get_route() ), $definition['blocked_routes'] ?? array(), true ) ) {
				return new \WP_Error(
					'network_ai_connector_managed',
					__( 'AI provider configuration is managed in Network Admin.', 'network-ai-connector' ),
					array( 'status' => 403 )
				);
			}
		}
		if ( '/wp/v2/settings' !== untrailingslashit( $request->get_route() )
			|| ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $result;
		}

		$registered = get_registered_settings();
		$params     = $request->get_params();
		foreach ( array_keys( $this->protected_options ) as $option ) {
			$rest_name = $registered[ $option ]['show_in_rest']['name'] ?? $option;
			if ( array_key_exists( $option, $params ) || array_key_exists( $rest_name, $params ) ) {
				return new \WP_Error(
					'network_ai_connector_managed',
					__( 'AI provider credentials are managed by your network administrator.', 'network-ai-connector' ),
					array( 'status' => 403 )
				);
			}
		}

		return $result;
	}

	public function remove_site_options( array $groups ): array {
		if ( ! self::is_enforced() ) {
			return $groups;
		}
		foreach ( $groups as $group => $options ) {
			$groups[ $group ] = array_values( array_diff( $options, array_keys( $this->protected_options ) ) );
		}
		return $groups;
	}

	public function site_page_data( array $data ): array {
		if ( ! self::is_enforced() ) {
			return $data;
		}
		foreach ( array_keys( $this->connectors() ) as $id ) {
			unset( $data['connectors'][ $id ] );
		}
		return $data;
	}

	public function cache_group( string $group ): string {
		if ( ! self::is_enforced() ) {
			return $group;
		}
		// Core model-list caches otherwise outlive credential changes and validation attempts.
		$fingerprint = hash_hmac( 'sha256', (string) wp_json_encode( array( self::keys(), get_network_option( self::network_id(), self::CONFIG_OPTION, array() ) ) ) . $this->validation_scope, wp_salt( 'auth' ) );
		return $group . '_nac_' . self::network_id() . '_' . $fingerprint;
	}

	public function clear_provider_cache( string $id ): void {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( $id ) ) {
			return;
		}

		$class   = $registry->getProviderClassName( $id );
		$objects = array( $class::availability(), $class::modelMetadataDirectory() );
		foreach ( $objects as $object ) {
			if ( $object instanceof CachesDataInterface ) {
				$object->invalidateCaches();
			}
		}
	}

	public function validate_key( string $id, string $key, array $configuration = array() ): true|\WP_Error {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( $id ) ) {
			return new \WP_Error( 'provider_inactive', __( 'Network-activate this provider plugin before configuring its network connection.', 'network-ai-connector' ) );
		}

		$this->validation_scope                = $id . ':' . $key . ':' . wp_json_encode( $configuration );
		$this->validation_configuration[ $id ] = array_merge( $configuration, array( 'api_key' => $key ) );
		try {
			$this->apply_provider_configuration();
			$this->clear_provider_cache( $id );
			$registry->setProviderRequestAuthentication( $id, Provider_Settings::authentication( $id, $key ) );
			if ( ! $registry->isProviderConfigured( $id ) ) {
				return new \WP_Error( 'validation_failed', __( 'The provider could not validate this key. Check the key, provider configuration, and connectivity. The stored key has not changed.', 'network-ai-connector' ) );
			}
			return true;
		} catch ( \Exception $exception ) {
			// Provider exceptions can contain credentials or request headers.
			wp_trigger_error( __METHOD__, sprintf( 'Could not validate network connector "%s".', $id ) );
			return new \WP_Error( 'validation_failed', __( 'Connection validation failed. The stored key has not changed.', 'network-ai-connector' ) );
		} finally {
			$this->clear_provider_cache( $id );
			$this->validation_scope = '';
			unset( $this->validation_configuration[ $id ] );
			$this->apply_provider_configuration();
			$registry->setProviderRequestAuthentication( $id, new Network_Authentication( $id ) );
		}
	}
}
