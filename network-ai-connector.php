<?php
/**
 * Plugin Name: Network AI Connector
 * Description: Network-enforced AI provider credentials for WordPress multisite.
 * Version: 0.1.1
 * Requires at least: 7.1
 * Requires PHP: 8.2
 * Network: true
 * License: GPL-2.0-or-later
 * Text Domain: network-ai-connector
 */

namespace Soderlind\Network_AI_Connector;

defined( 'ABSPATH' ) || exit;

const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook(
	__FILE__,
	static function ( bool $network_wide ): void {
		if ( ! is_multisite() || ! $network_wide || ! function_exists( 'wp_get_connectors' ) ) {
			wp_die( esc_html__( 'Network AI Connector requires network activation on WordPress multisite 7.1 or later.', 'network-ai-connector' ) );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$active = get_network_option( get_current_network_id(), 'active_sitewide_plugins', array() );
		if ( ! is_multisite() || ! isset( $active[ plugin_basename( __FILE__ ) ] ) ) {
			return;
		}

		if ( ! function_exists( 'wp_get_connectors' ) || ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			add_action(
				'network_admin_notices',
				static function (): void {
					wp_admin_notice(
						__( 'Network AI Connector requires the WordPress Connectors API and AI Client. Network enforcement is not running.', 'network-ai-connector' ),
						array( 'type' => 'error' )
					);
				}
			);
			return;
		}

		require_once __DIR__ . '/includes/class-network-authentication.php';
		$plugin = new Plugin();
		$plugin->register();

		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-admin.php';
			( new Admin( $plugin ) )->register();
		}
	}
);
