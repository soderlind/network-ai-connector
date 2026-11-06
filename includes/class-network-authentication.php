<?php

namespace Soderlind\Network_AI_Connector;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

defined( 'ABSPATH' ) || exit;

final class Network_Authentication extends ApiKeyRequestAuthentication {
	private string $provider_id;

	public function __construct( string $provider_id ) {
		parent::__construct( '' );
		$this->provider_id = $provider_id;
	}

	public function getApiKey(): string {
		if ( ! Plugin::is_enforced() ) {
			$definition = Provider_Settings::definitions()[ $this->provider_id ] ?? array();
			if ( isset( $definition['resolve_key'] ) ) {
				if ( ! is_callable( $definition['resolve_key'] ) ) {
					throw new \LogicException( 'The native provider credential resolver must be callable.' );
				}
				return call_user_func( $definition['resolve_key'] );
			}
			$auth   = wp_get_connector( $this->provider_id )['authentication'] ?? array();
			$source = _wp_connectors_get_api_key_source( $auth['setting_name'] ?? '', $auth['env_var_name'] ?? '', $auth['constant_name'] ?? '' );
			if ( 'env' === $source ) {
				return (string) getenv( $auth['env_var_name'] );
			}
			if ( 'constant' === $source ) {
				return (string) constant( $auth['constant_name'] );
			}
			return (string) get_option( $auth['setting_name'] ?? '', '' );
		}

		$key = Plugin::keys()[ $this->provider_id ] ?? '';
		if ( ! Provider_Settings::enabled( $this->provider_id ) ) {
			throw new \RuntimeException( 'This AI provider has no network credential. Contact your network administrator.' );
		}
		if ( '' === $key && empty( Provider_Settings::definitions()[ $this->provider_id ]['key_optional'] ) ) {
			throw new \RuntimeException( 'This AI provider requires a network credential.' );
		}
		return $key;
	}

	public function authenticateRequest( Request $request ): Request {
		$key = $this->getApiKey();
		return Provider_Settings::authentication( $this->provider_id, $key )->authenticateRequest( $request );
	}

	public function toArray(): array {
		return array( self::KEY_API_KEY => $this->getApiKey() );
	}
}
