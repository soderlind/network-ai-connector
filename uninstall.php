<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_multisite() ) {
	return;
}

$network_ai_connector_offset = 0;
do {
	$network_ai_connector_ids = get_networks(
		array(
			'fields' => 'ids',
			'number' => 100,
			'offset' => $network_ai_connector_offset,
		)
	);
	foreach ( $network_ai_connector_ids as $network_ai_connector_id ) {
		delete_network_option( (int) $network_ai_connector_id, 'network_ai_connector_keys' );
		delete_network_option( (int) $network_ai_connector_id, 'network_ai_connector_settings' );
	}
	$network_ai_connector_offset += 100;
	$network_ai_connector_count   = count( $network_ai_connector_ids );
} while ( 100 === $network_ai_connector_count );
