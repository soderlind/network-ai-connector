<?php

namespace Soderlind\Network_AI_Connector;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

defined( 'ABSPATH' ) || exit;

final class Provider_Settings {
	/** @var array<string, class-string<ApiKeyRequestAuthentication>> */
	private static array $authentication_classes = array();

	public static function remember_authentication( string $id, ?object $authentication ): void {
		if ( $authentication instanceof ApiKeyRequestAuthentication && ! $authentication instanceof Network_Authentication ) {
			self::$authentication_classes[ $id ] = get_class( $authentication );
		}
	}

	/** @return array<string, array> */
	public static function definitions(): array {
		return apply_filters(
			'network_ai_connector_provider_settings',
			array(
				'azure-ai-foundry' => array(
					'name'           => 'Azure AI Foundry',
					'plugin'         => 'ai-provider-for-azure-ai-foundry/ai-provider-for-azure-ai-foundry.php',
					'key_option'     => 'connectors_ai_azure_ai_foundry_api_key',
					'key_class'      => '\AzureAiFoundry\Http\AzureAiFoundryRequestAuthentication',
					'script_module'  => 'azure-ai-foundry/connectors',
					'blocked_routes' => array( '/azure-ai-foundry/v1/detect' ),
					'resolve_key'    => static fn(): string => \AzureAiFoundry\Settings\SettingsManager::instance()->get_real_api_key(),
					'fields'         => array(
						'endpoint'     => array(
							'label'    => __( 'Endpoint URL', 'network-ai-connector' ),
							'type'     => 'url',
							'required' => true,
							'option'   => 'connectors_ai_azure_ai_foundry_endpoint',
						),
						'model_name'   => array(
							'label'  => __( 'Deployment names (comma-separated)', 'network-ai-connector' ),
							'type'   => 'text',
							'option' => 'connectors_ai_azure_ai_foundry_model_name',
						),
						'capabilities' => array(
							'label'   => __( 'Capabilities', 'network-ai-connector' ),
							'type'    => 'choices',
							'option'  => 'connectors_ai_azure_ai_foundry_capabilities',
							'default' => array( 'text_generation', 'chat_history' ),
							'choices' => array(
								'text_generation',
								'chat_history',
								'image_generation',
								'embedding_generation',
								'text_to_speech_conversion',
							),
						),
					),
				),
				'ollama'           => array(
					'name'         => 'Ollama',
					'plugin'       => 'ai-provider-for-ollama/plugin.php',
					'key_option'   => 'connectors_ai_ollama_api_key',
					'key_optional' => true,
					'fields'       => array(
						'host' => array(
							'label'    => __( 'Host URL', 'network-ai-connector' ),
							'type'     => 'url',
							'required' => true,
							'default'  => 'http://localhost:11434',
							'option'   => 'ai_provider_for_ollama_settings',
							'path'     => 'host',
						),
					),
				),
			)
		);
	}

	/** @return array<string, mixed> */
	public static function configuration( string $id ): array {
		$configurations = get_network_option( Plugin::network_id(), Plugin::CONFIG_OPTION, array() );
		if ( ! is_array( $configurations ) || ( isset( $configurations[ $id ] ) && ! is_array( $configurations[ $id ] ) ) ) {
			throw new \UnexpectedValueException( 'Invalid network provider configuration.' );
		}
		return $configurations[ $id ] ?? array();
	}

	public static function enabled( string $id ): bool {
		return '' !== ( Plugin::keys()[ $id ] ?? '' ) || true === ( self::configuration( $id )['enabled'] ?? false );
	}

	public static function authentication( string $id, string $key ): ApiKeyRequestAuthentication {
		$class = self::definitions()[ $id ]['key_class'] ?? self::$authentication_classes[ $id ] ?? ApiKeyRequestAuthentication::class;
		if ( ! is_a( $class, ApiKeyRequestAuthentication::class, true ) ) {
			throw new \LogicException( 'The network provider authentication class must extend ApiKeyRequestAuthentication.' );
		}
		return new $class( $key );
	}
}
