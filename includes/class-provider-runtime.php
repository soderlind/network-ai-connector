<?php

namespace Soderlind\Network_AI_Connector;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Contracts\CachesDataInterface;

defined( 'ABSPATH' ) || exit;

final class Provider_Runtime {
	/** @var array<string, array> */
	private array $built_in_integrations;

	// Provider authentication and runtime overrides are shared across plugin instances.
	private static bool $validating = false;

	private string $validation_scope = '';

	/** @var array<string, array> */
	private array $validation_configuration = array();

	public function __construct() {
		$this->built_in_integrations = Provider_Settings::built_in_runtime_integrations();
	}

	/** @return array<string, mixed> */
	public function configuration( string $id ): array {
		return $this->validation_configuration[ $id ] ?? Provider_Settings::configuration( $id );
	}

	public function apply(): void {
		$failed = array();
		$this->apply_integrations( $this->built_in_integrations, $failed, true );
		try {
			$integrations = Provider_Settings::definitions();
		} catch ( \Throwable $exception ) {
			$integrations = array();
			$failed[]     = 'provider integrations';
		}
		$this->apply_integrations( $integrations, $failed );
		if ( $failed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception contains only sanitized provider identifiers, not HTML.
			throw new \RuntimeException( 'Could not apply network provider runtime configuration: ' . implode( ', ', $failed ) . '.' );
		}
	}

	private function apply_integrations( array $integrations, array &$failed, bool $built_in = false ): void {
		foreach ( $integrations as $id => $integration ) {
			if ( ! isset( $integration['apply'] ) ) {
				continue;
			}
			try {
				if ( ! is_callable( $integration['apply'] ) ) {
					throw new \LogicException( 'The network provider configuration callback must be callable.' );
				}
				if ( $built_in && ! empty( $integration['restore_without_configuration'] ) ) {
					$is_enforced = Plugin::is_enforced();
					// Native restoration must not depend on an unenforced network's settings.
					$configuration = $is_enforced ? $this->configuration( $id ) : array();
				} else {
					$configuration = $this->configuration( $id );
					$is_enforced   = Plugin::is_enforced();
				}
				call_user_func( $integration['apply'], $configuration, $is_enforced );
			} catch ( \Throwable $exception ) {
				$failed[] = sanitize_key( (string) $id );
			}
		}
	}

	public function cache_group( string $group ): string {
		if ( ! Plugin::is_enforced() ) {
			return $group;
		}
		// Core model-list caches otherwise outlive credential changes and validation attempts.
		$fingerprint = hash_hmac( 'sha256', (string) wp_json_encode( array( Plugin::keys(), get_network_option( Plugin::network_id(), Plugin::CONFIG_OPTION, array() ) ) ) . $this->validation_scope, wp_salt( 'auth' ) );
		return $group . '_nac_' . Plugin::network_id() . '_' . $fingerprint;
	}

	public function clear_provider_cache( string $id ): void {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( $id ) ) {
			return;
		}

		$class   = $registry->getProviderClassName( $id );
		$objects = array();
		$failed  = false;
		foreach ( array( 'availability', 'modelMetadataDirectory' ) as $method ) {
			try {
				$objects[] = $class::$method();
			} catch ( \Throwable $exception ) {
				$failed = true;
			}
		}
		foreach ( $objects as $object ) {
			try {
				if ( $object instanceof CachesDataInterface ) {
					$object->invalidateCaches();
				}
			} catch ( \Throwable $exception ) {
				$failed = true;
			}
		}
		if ( $failed ) {
			throw new \RuntimeException( 'Could not clear the network provider cache.' );
		}
	}

	public function validate( string $id, string $key, array $configuration = array() ): true|\WP_Error {
		if ( self::$validating ) {
			return new \WP_Error( 'validation_in_progress', __( 'Another network connection is already being validated. The stored connection has not changed.', 'network-ai-connector' ) );
		}

		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( $id ) ) {
			return new \WP_Error( 'provider_inactive', __( 'Network-activate this provider plugin before configuring its network connection.', 'network-ai-connector' ) );
		}

		self::$validating = true;
		$failed           = false;
		$result           = true;
		try {
			$this->validation_scope                = $id . ':' . $key . ':' . wp_json_encode( $configuration );
			$this->validation_configuration[ $id ] = array_merge( $configuration, array( 'api_key' => $key ) );
			$this->apply();
			$this->clear_provider_cache( $id );
			$registry->setProviderRequestAuthentication( $id, Provider_Settings::authentication( $id, $key ) );
			if ( ! $registry->isProviderConfigured( $id ) ) {
				$result = new \WP_Error( 'validation_failed', __( 'The provider could not validate this key. Check the key, provider configuration, and connectivity. The stored key has not changed.', 'network-ai-connector' ) );
			}
		} catch ( \Throwable $exception ) {
			$failed = true;
		} finally {
			try {
				$this->clear_provider_cache( $id );
			} catch ( \Throwable $exception ) {
				$failed = true;
			}
			$this->validation_scope = '';
			unset( $this->validation_configuration[ $id ] );
			try {
				$this->apply();
			} catch ( \Throwable $exception ) {
				$failed = true;
			}
			try {
				$registry->setProviderRequestAuthentication( $id, new Network_Authentication( $id ) );
			} catch ( \Throwable $exception ) {
				$failed = true;
			}
			self::$validating = false;
		}

		if ( $failed ) {
			// Provider exceptions can contain credentials or request headers.
			wp_trigger_error( __METHOD__, sprintf( 'Could not validate network connector "%s".', $id ) );
			return new \WP_Error( 'validation_failed', __( 'Connection validation failed. The stored key has not changed.', 'network-ai-connector' ) );
		}
		return $result;
	}
}
