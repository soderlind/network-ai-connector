<?php
/**
 * Run with a bootstrapped multisite via WP-CLI. Never calls a real AI provider.
 */

namespace Soderlind\Network_AI_Connector\Tests;

use Soderlind\Network_AI_Connector\Admin;
use Soderlind\Network_AI_Connector\Network_Authentication;
use Soderlind\Network_AI_Connector\Plugin;
use Soderlind\Network_AI_Connector\Provider_Settings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Contracts\CachesDataInterface;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

if ( ! defined( 'ABSPATH' ) || ! is_multisite() || '1' !== getenv( 'NAC_TEST_ALLOW_DATABASE_WRITES' ) ) {
	throw new \RuntimeException( 'Run via WP-CLI on a test multisite with NAC_TEST_ALLOW_DATABASE_WRITES=1.' );
}

if ( class_exists( Plugin::class, false ) ) {
	throw new \RuntimeException( 'Run with --skip-plugins --skip-themes so tests cannot change an active plugin instance.' );
}

$prior_bootstraps = $GLOBALS['wp_filter']['plugins_loaded']->callbacks[10] ?? array();
require_once dirname( __DIR__ ) . '/network-ai-connector.php';
$bootstraps = array_diff_key( $GLOBALS['wp_filter']['plugins_loaded']->callbacks[10], $prior_bootstraps );
require_once dirname( __DIR__ ) . '/includes/class-network-authentication.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';

final class Directory extends AbstractApiBasedModelMetadataDirectory {
	public int $calls = 0;

	protected function sendListModelsRequest(): array {
		++$this->calls;
		$key = $this->getRequestAuthentication()->getApiKey();
		if ( ! str_starts_with( $key, 'valid-' ) ) {
			throw new \RuntimeException( 'Synthetic provider rejected the credential.' );
		}
		return array();
	}
}

final class Provider implements ProviderInterface {
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( 'nac-test', 'Synthetic provider', ProviderTypeEnum::cloud(), null, RequestAuthenticationMethod::apiKey() );
	}

	public static function model( string $modelId, ?ModelConfig $modelConfig = null ): ModelInterface {
		throw new \LogicException( 'No model is required for these tests.' );
	}

	public static function availability(): ProviderAvailabilityInterface {
		static $availability;
		return $availability ??= new ListModelsApiBasedProviderAvailability( self::modelMetadataDirectory() );
	}

	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		static $directory;
		return $directory ??= new Directory();
	}
}

final class Redirect extends \RuntimeException {}
final class Died extends \RuntimeException {}

final class AzureAuthentication extends \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication {
	public function authenticateRequest( Request $request ): Request {
		return $request->withHeader( 'api-key', $this->getApiKey() );
	}
}

final class ConfiguredDirectory extends AbstractApiBasedModelMetadataDirectory {
	public int $invalidations_before_failure = 0;

	public function __construct( private string $id ) {}

	public function invalidateCaches(): void {
		if ( $this->invalidations_before_failure > 0 && 0 === --$this->invalidations_before_failure ) {
			throw new \Error( 'SYNTHETIC_SECRET_MARKER: cache invalidation failed.' );
		}
		parent::invalidateCaches();
	}

	protected function sendListModelsRequest(): array {
		$request = $this->getRequestAuthentication()->authenticateRequest( new Request( HttpMethodEnum::get(), 'https://example.invalid/models' ) );
		if ( 'azure-ai-foundry' === $this->id ) {
			if ( 'valid-azure-key' !== $request->getHeaderAsString( 'api-key' ) || 'https://azure.example.invalid/models' !== get_option( 'connectors_ai_azure_ai_foundry_endpoint' ) ) {
				throw new \RuntimeException( 'Synthetic Azure configuration failed.' );
			}
		} elseif ( 'http://ollama.example.invalid:11434' !== getenv( 'OLLAMA_HOST' ) ) {
			throw new \RuntimeException( 'Synthetic Ollama configuration failed.' );
		}
		return array();
	}
}

abstract class ConfiguredProvider implements ProviderInterface {
	protected const ID = '';

	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( static::ID, static::ID, ProviderTypeEnum::cloud(), null, RequestAuthenticationMethod::apiKey() );
	}

	public static function model( string $modelId, ?ModelConfig $modelConfig = null ): ModelInterface {
		throw new \LogicException( 'No model is required for these tests.' );
	}

	public static function availability(): ProviderAvailabilityInterface {
		static $availability = array();
		return $availability[ static::ID ] ??= new ListModelsApiBasedProviderAvailability( static::modelMetadataDirectory() );
	}

	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		static $directories = array();
		return $directories[ static::ID ] ??= new ConfiguredDirectory( static::ID );
	}
}

final class AzureProvider extends ConfiguredProvider {
	protected const ID = 'azure-ai-foundry';
}

final class OllamaProvider extends ConfiguredProvider {
	protected const ID = 'ollama';
}

final class UnmanagedProvider extends ConfiguredProvider {
	protected const ID = 'nac-unmanaged';

	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( static::ID, 'Synthetic no-auth provider', ProviderTypeEnum::cloud() );
	}
}

final class CacheOrderDirectory extends AbstractApiBasedModelMetadataDirectory {
	public function invalidateCaches(): void {
		$GLOBALS['nac_cache_order'][] = 'clear directory';
		parent::invalidateCaches();
	}

	protected function sendListModelsRequest(): array {
		return array();
	}
}

final class CacheOrderAvailability extends ListModelsApiBasedProviderAvailability implements CachesDataInterface {
	public function invalidateCaches(): void {
		$GLOBALS['nac_cache_order'][] = 'clear availability';
		if ( $GLOBALS['nac_fail_availability_cache'] ) {
			throw new \Error( 'SYNTHETIC_SECRET_MARKER: availability cache failed.' );
		}
	}
}

final class CacheOrderProvider extends ConfiguredProvider {
	protected const ID = 'nac-cache-order';

	public static function availability(): ProviderAvailabilityInterface {
		$GLOBALS['nac_cache_order'][] = 'get availability';
		if ( $GLOBALS['nac_fail_availability_factory'] ) {
			throw new \Error( 'SYNTHETIC_SECRET_MARKER: availability factory failed.' );
		}
		static $availability;
		return $availability ??= new CacheOrderAvailability( self::modelMetadataDirectory() );
	}

	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		$GLOBALS['nac_cache_order'][] = 'get directory';
		static $directory;
		return $directory ??= new CacheOrderDirectory();
	}
}

$GLOBALS['nac_test_assertions'] = 0;
function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		throw new \RuntimeException( 'FAILED: ' . $message );
	}
	++$GLOBALS['nac_test_assertions'];
	echo 'PASS: ' . $message . "\n";
}

global $wpdb, $current_site, $current_screen;
$original_network = $current_site;
$original_screen  = $current_screen ?? null;
$original_user    = get_current_user_id();
$original_admins  = get_super_admins();
$original_get     = $_GET;
$original_post    = $_POST;
$original_request = $_REQUEST;
$original_method  = $_SERVER['REQUEST_METHOD'] ?? null;
$original_env     = getenv( 'NAC_TEST_API_KEY' );
$original_ollama_env = getenv( 'OLLAMA_HOST' );
$deployment_ollama_host = 'http://deployment.example.invalid:11434';
$prefix           = 'nac_test_' . wp_generate_password( 12, false );
$site_option      = $prefix . '_local';
$other_option     = $prefix . '_other';
$extra_option     = $prefix . '_extra';
$sentinel_id      = 'nac-test-azure-status';
$sentinel_option  = $prefix . '_status';
$rest_alias       = $prefix . '_alias';
$networks         = array( wp_rand( 1000000000, 1500000000 ), wp_rand( 1500000001, 2000000000 ) );
$network_id       = $networks[0];
$enforced         = true;
$deny_admin       = false;
$deny_plugins     = false;
$activations      = array();
$plugin_suffix    = strtolower( wp_generate_password( 10, false ) );
$activatable_file = 'nac-test-activatable-' . $plugin_suffix . '/plugin.php';
$installable_file = 'nac-test-installable-' . $plugin_suffix . '/plugin.php';
$installable_zip  = get_temp_dir() . 'nac-test-installable-' . $plugin_suffix . '.zip';
$redirected_provider = '';
$filters          = array();

if ( defined( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID' ) || defined( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION' ) ) {
	throw new \RuntimeException( 'The Azure compatibility connector must not be loaded during this isolated test.' );
}

foreach ( $networks as $id ) {
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE site_id = %d", $id ) ) ) {
		throw new \RuntimeException( 'Synthetic network ID collision; rerun the suite.' );
	}
}

$filter = static function ( string $name, callable $callback, int $priority = 10, int $args = 1 ) use ( &$filters ): void {
	add_filter( $name, $callback, $priority, $args );
	$filters[] = array( $name, $callback, $priority );
};

try {
	$filter(
		'get_site',
		static function ( \WP_Site $site ) use ( &$network_id ): \WP_Site {
			$site          = clone $site;
			$site->site_id = (string) $network_id;
			return $site;
		}
	);
	$filter(
		'pre_site_option_active_sitewide_plugins',
		static function ( mixed $value, string $option, int $id ) use ( $networks, &$enforced ): mixed {
			if ( in_array( $id, $networks, true ) ) {
				return $enforced ? array(
					plugin_basename( \Soderlind\Network_AI_Connector\PLUGIN_FILE ) => 1,
					'ai-provider-for-azure-ai-foundry/ai-provider-for-azure-ai-foundry.php' => 1,
					'ai-provider-for-ollama/plugin.php' => 1,
				) : array();
			}
			return $value;
		},
		10,
		3
	);
	$filter( 'wp_die_handler', static fn(): \Closure => static function ( mixed $message ): never { throw new Died( wp_strip_all_tags( (string) $message ) ); }, PHP_INT_MAX );
	$filter( 'pre_site_option_site_admins', static fn(): array => $original_admins );
	$filter(
		'wp_redirect',
		static function ( string $location ) use ( &$redirected_provider ): never {
			parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
			$redirected_provider = $query['nac_provider'] ?? '';
			throw new Redirect( $query['nac_status'] ?? 'missing_status' );
		},
		-PHP_INT_MAX
	);
	$filter(
		'map_meta_cap',
		static function ( array $caps, string $cap ) use ( &$deny_admin, &$deny_plugins ): array {
			if ( $deny_plugins && in_array( $cap, array( 'manage_network_plugins', 'activate_plugin', 'activate_plugins' ), true ) ) {
				return array( 'do_not_allow' );
			}
			return $deny_admin && 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps;
		},
		10,
		2
	);
	$filter(
		'pre_update_site_option_active_sitewide_plugins',
		static fn( mixed $value, mixed $old_value, string $option, int $id ): mixed => in_array( $id, $networks, true ) ? $old_value : $value,
		10,
		4
	);
	$filter(
		'activate_plugin',
		static function ( string $file, bool $network_wide ) use ( &$activations ): void {
			$activations[] = array( $file, $network_wide );
		},
		10,
		2
	);
	$filter(
		'plugins_api',
		static function ( mixed $result, string $action, object $args ) use ( $installable_file, $installable_zip ): mixed {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}
			if ( dirname( $installable_file ) === $args->slug ) {
				return (object) array( 'download_link' => $installable_zip, 'language_packs' => array() );
			}
			if ( str_starts_with( $args->slug, 'nac-test-' ) ) {
				return new \WP_Error( 'plugins_api_failed', 'Synthetic directory failure.' );
			}
			throw new \RuntimeException( 'Tests must not query the plugin directory.' );
		},
		10,
		3
	);
	$plugin_header = "<?php\n/**\n * Plugin Name: Network AI Connector test plugin\n */\n";
	wp_mkdir_p( WP_PLUGIN_DIR . '/' . dirname( $activatable_file ) );
	file_put_contents( WP_PLUGIN_DIR . '/' . $activatable_file, $plugin_header );
	$zip = new \ZipArchive();
	$zip->open( $installable_zip, \ZipArchive::CREATE );
	$zip->addFromString( $installable_file, $plugin_header );
	$zip->close();
	wp_clean_plugins_cache( false );
	$super = get_user_by( 'login', get_super_admins()[0] );
	wp_set_current_user( $super->ID );
	$current_site     = clone $original_network;
	$current_site->id = $network_id;
	set_current_screen( 'settings_page_network-ai-connector-network' );
	$_SERVER['REQUEST_METHOD'] = 'POST';

	add_option( $site_option, 'valid-site-key' );
	add_option( $other_option, 'other-site-key' );
	add_option( $extra_option, 'native-provider-setting' );
	add_option( $sentinel_option, 'ready' );
	putenv( 'NAC_TEST_API_KEY=valid-environment-key' );
	putenv( 'OLLAMA_HOST=' . $deployment_ollama_host );
	define( 'NAC_TEST_API_KEY', 'valid-constant-key' );
	$ai_registry = AiClient::defaultRegistry();
	$ai_registry->registerProvider( Provider::class );

	$connector_registry = \WP_Connector_Registry::get_instance();
	$connector          = array(
		'name'           => 'Synthetic provider',
		'description'    => 'A test-only connector.',
		'logo_url'       => 'https://example.invalid/synthetic-logo.svg',
		'type'           => 'ai_provider',
		'authentication' => array(
			'method'        => 'api_key',
			'setting_name'  => $site_option,
			'env_var_name'  => 'NAC_TEST_API_KEY',
			'constant_name' => 'NAC_TEST_API_KEY',
		),
		'plugin'         => array( 'is_active' => '__return_true' ),
	);
	$connector_registry->register( 'nac-test', $connector );
	$connector_registry->register(
		'nac-inactive',
		array(
			'name'           => 'Inactive provider',
			'type'           => 'ai_provider',
			'authentication' => array( 'method' => 'api_key', 'setting_name' => $prefix . '_inactive' ),
		)
	);
	$sentinel_connector = array(
		'name'           => 'Internal compatibility status',
		'type'           => 'ai_provider',
		'authentication' => array( 'method' => 'api_key', 'setting_name' => $sentinel_option ),
	);
	foreach (
		array(
			'nac-activatable'   => $activatable_file,
			'nac-installable'   => $installable_file,
			'nac-uninstallable' => 'nac-test-missing-' . $plugin_suffix . '/plugin.php',
		) as $plugin_connector_id => $plugin_connector_file
	) {
		$connector_registry->register(
			$plugin_connector_id,
			array(
				'name'           => 'Plugin provider ' . $plugin_connector_id,
				'type'           => 'ai_provider',
				'authentication' => array( 'method' => 'api_key', 'setting_name' => $prefix . '_' . str_replace( '-', '_', $plugin_connector_id ) ),
				'plugin'         => array( 'file' => $plugin_connector_file ),
			)
		);
	}
	$connector_registry->register( $sentinel_id, $sentinel_connector );
	$connector_registry->register( 'nac-keyless', array( 'name' => 'Keyless', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );
	$connector_registry->register( 'nac-other', array( 'name' => 'Other', 'type' => 'service', 'authentication' => array( 'method' => 'api_key', 'setting_name' => $other_option ) ) );
	register_setting( 'connectors', $site_option, array( 'type' => 'string', 'default' => '', 'show_in_rest' => array( 'name' => $rest_alias ) ) );
	register_setting( 'connectors', $other_option, array( 'type' => 'string', 'default' => '', 'show_in_rest' => true ) );
	register_setting( 'connectors', $sentinel_option, array( 'type' => 'string', 'default' => '', 'show_in_rest' => true ) );

	expect( 1 === count( $bootstraps ), 'Main plugin registers exactly one deferred bootstrap' );
	$bootstrap = reset( $bootstraps );
	$bootstrap['function']();
	$plugin = null;
	foreach ( $GLOBALS['wp_filter']['init']->callbacks[19] as $callback ) {
		if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Plugin ) {
			$plugin = $callback['function'][0];
		}
	}
	expect( $plugin instanceof Plugin, 'Network activation bootstraps enforcement before core key initialization' );
	expect( 'http://localhost:11434' === getenv( 'OLLAMA_HOST' ), 'Enforcement initially overrides the deployment Ollama host' );
	$enforced = false;
	$invalid_network_settings = static fn(): string => 'invalid network settings';
	$filter( 'pre_site_option_' . Plugin::CONFIG_OPTION, $invalid_network_settings );
	try {
		$plugin->apply_provider_configuration();
	} finally {
		remove_filter( 'pre_site_option_' . Plugin::CONFIG_OPTION, $invalid_network_settings );
	}
	expect( $deployment_ollama_host === getenv( 'OLLAMA_HOST' ), 'Leaving enforcement restores the deployment Ollama host even if network settings are invalid' );
	putenv( 'OLLAMA_HOST' );
	$enforced = true;
	$plugin->apply_provider_configuration();
	$enforced = false;
	$plugin->apply_provider_configuration();
	expect( false === getenv( 'OLLAMA_HOST' ), 'Leaving enforcement unsets an Ollama host that was originally absent' );
	putenv( 'OLLAMA_HOST=' );
	$enforced = true;
	$plugin->apply_provider_configuration();
	$enforced = false;
	$plugin->apply_provider_configuration();
	expect( '' === getenv( 'OLLAMA_HOST' ), 'Leaving enforcement preserves an originally empty Ollama host' );
	putenv( 'OLLAMA_HOST=' . $deployment_ollama_host );
	$enforced = true;
	$plugin->apply_provider_configuration();
	expect( isset( $plugin->connectors()[ $sentinel_id ] ), 'A normal API-key connector is not excluded without identifying metadata' );
	define( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID', $sentinel_id );
	define( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION', $sentinel_option );
	$connector_registry->unregister( $sentinel_id );
	$different_option = $sentinel_connector;
	$different_option['authentication']['setting_name'] = $other_option;
	$connector_registry->register( $sentinel_id, $different_option );
	expect( isset( $plugin->connectors()[ $sentinel_id ] ), 'A connector sharing the status ID but not its option remains managed' );
	$connector_registry->unregister( $sentinel_id );
	$connector_registry->register( $sentinel_id, $sentinel_connector );
	$plugin->enforce();
	$admin = null;
	foreach ( $GLOBALS['wp_filter']['network_admin_edit_network_ai_connector']->callbacks[10] as $callback ) {
		if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Admin ) {
			$admin = $callback['function'][0];
		}
	}
	expect( $admin instanceof Admin, 'Network administration registers its save handler through the main bootstrap' );
	try {
		do_action( 'activate_' . plugin_basename( \Soderlind\Network_AI_Connector\PLUGIN_FILE ), false );
		expect( false, 'Site-only activation must be rejected' );
	} catch ( Died $exception ) {
		expect( str_contains( $exception->getMessage(), 'network activation' ), 'Site-only activation fails explicitly' );
	}
	do_action( 'activate_' . plugin_basename( \Soderlind\Network_AI_Connector\PLUGIN_FILE ), true );
	expect( true, 'Network-wide activation passes its prerequisites' );
	expect( isset( $plugin->connectors()['nac-test'] ), 'Discovers a third-party standard API-key connector' );
	expect( ! isset( $plugin->connectors()['nac-keyless'] ) && ! isset( $plugin->connectors()['nac-other'] ), 'Excludes keyless and non-AI connectors from enforcement' );
	expect( ! isset( $plugin->connectors()[ $sentinel_id ] ), 'Excludes the identified internal Azure status connector' );
	expect( 'ready' === get_option( $sentinel_option ), 'The internal status option remains readable by its provider' );
	update_option( $sentinel_option, 'updated' );
	expect( 'updated' === get_option( $sentinel_option ), 'The internal status option remains writable by its provider' );
	expect( '' === get_option( $site_option ), 'Site option reads never expose either site or network credentials' );
	expect( 'other-site-key' === get_option( $other_option ), 'Non-AI credential reads are unchanged' );

	$authentication = $ai_registry->getProviderRequestAuthentication( 'nac-test' );
	expect( $authentication instanceof Network_Authentication, 'Replaces deployment authentication in the AI registry' );
	$request = new Request( HttpMethodEnum::get(), 'https://example.invalid/models' );
	try {
		$authentication->authenticateRequest( $request );
		expect( false, 'Missing network key must refuse authentication' );
	} catch ( \RuntimeException $exception ) {
		expect( str_contains( $exception->getMessage(), 'no network credential' ), 'Missing network key refuses authentication despite site, constant, and environment keys' );
	}

	update_network_option( $network_id, Plugin::OPTION, array( 'nac-test' => 'valid-network-one' ) );
	expect( 'valid-network-one' === $authentication->getApiKey(), 'Network key overrides environment and constant keys' );
	_wp_connectors_pass_default_keys_to_ai_client();
	expect( $ai_registry->getProviderRequestAuthentication( 'nac-test' ) instanceof Network_Authentication, 'Core credential initialization cannot replace network authentication' );
	$group_one = $plugin->cache_group( 'wp_ai_client' );
	expect( ! str_contains( $group_one, 'valid-network-one' ), 'Cache namespace never includes plaintext credentials' );

	$network_id       = $networks[1];
	$current_site->id = $network_id;
	update_network_option( $network_id, Plugin::OPTION, array( 'nac-test' => 'valid-network-two' ) );
	do_action( 'switch_blog', get_current_blog_id(), get_current_blog_id(), 'switch' );
	expect( 'valid-network-two' === $authentication->getApiKey(), 'An existing authentication object follows the new network after a blog switch' );
	expect( $group_one !== $plugin->cache_group( 'wp_ai_client' ), 'Model caches are isolated between networks' );
	expect( array( 'nac-test' => 'valid-network-one' ) === Plugin::keys( $networks[0] ), 'Changing the second network leaves the first untouched' );
	$network_id       = $networks[0];
	$current_site->id = $network_id;

	update_option( $site_option, 'attempted-site-replacement' );
	$stored_site = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $site_option ) );
	expect( 'valid-site-key' === $stored_site, 'Suppressed site writes preserve the actual existing credential' );
	$allowed = $plugin->remove_site_options( array( 'connectors' => array( $site_option, $other_option, $sentinel_option ) ) );
	expect( array( $other_option, $sentinel_option ) === $allowed['connectors'], 'Site option forms retain non-managed fields and the internal status option' );
	$data = $plugin->site_page_data( array( 'connectors' => array( 'nac-test' => array(), 'nac-keyless' => array(), 'nac-other' => array() ) ) );
	expect( ! isset( $data['connectors']['nac-test'] ) && isset( $data['connectors']['nac-keyless'], $data['connectors']['nac-other'] ), 'Site UI removes managed controls and preserves other connectors' );
	set_current_screen( 'options-connectors' );
	ob_start();
	$admin->site_notice();
	$notice = ob_get_clean();
	expect( str_contains( $notice, 'managed by your network administrator' ) && str_contains( $notice, 'inline' ) && str_contains( $notice, 'id="network-ai-connector-notice"' ), 'Site notice has a stable mount target and opts out of legacy relocation' );
	expect( ! str_contains( $notice, 'nac-hide-site-empty-state' ), 'Network administrators retain the core site empty state' );
	$deny_admin = true;
	ob_start();
	$admin->site_notice();
	$site_admin_notice = ob_get_clean();
	$deny_admin        = false;
	expect( str_contains( $site_admin_notice, 'nac-hide-site-empty-state' ), 'Site administrators hide the core site empty state' );
	$admin->site_assets( 'options-connectors.php' );
	expect( wp_script_is( 'network-ai-connector-site-notice', 'enqueued' ), 'Site Connectors page enqueues the notice route integration' );
	expect( wp_style_is( 'network-ai-connector-site-notice', 'enqueued' ), 'Site Connectors page enqueues the notice layout styles' );
	set_current_screen( 'settings_page_network-ai-connector-network' );

	$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/settings' ) );
	expect( 200 === $response->get_status() && '' === $response->get_data()[ $rest_alias ], 'Actual REST settings reads hide credentials, including aliased settings' );
	$status_write = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
	$status_write->set_param( $sentinel_option, 'synced' );
	$status_response = rest_do_request( $status_write );
	expect( 200 === $status_response->get_status() && 'synced' === get_option( $sentinel_option ), 'The internal status option is not blocked by network REST enforcement' );
	$rest_write = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
	$rest_write->set_param( $rest_alias, 'attempted-rest-replacement' );
	$rest_write->set_param( $other_option, 'must-not-change' );
	$response = rest_do_request( $rest_write );
	expect( 403 === $response->get_status() && 'network_ai_connector_managed' === $response->get_data()['code'], 'Actual REST credential writes fail explicitly before any mixed update' );
	expect( 'other-site-key' === get_option( $other_option ), 'Rejected mixed REST updates are atomic' );
	expect( array( 'nac-test' => 'valid-network-one' ) === Plugin::keys(), 'REST cannot change network credentials' );

	expect( true === $plugin->validate_key( 'nac-test', 'valid-replacement' ), 'Valid replacement credentials pass provider validation' );
	expect( $ai_registry->getProviderRequestAuthentication( 'nac-test' ) instanceof Network_Authentication, 'Validation restores network authentication' );
	expect( is_wp_error( $plugin->validate_key( 'nac-test', 'invalid-replacement' ) ), 'Invalid replacement cannot reuse cached validation success' );
	expect( 'valid-network-one' === $authentication->getApiKey(), 'Failed validation retains the working network credential' );
	expect( is_wp_error( $plugin->validate_key( 'nac-inactive', 'valid-key' ) ), 'Inactive providers fail validation explicitly' );

	$submit = static function ( array $values, bool $valid_nonce = true, string $provider = 'nac-test' ) use ( $admin, &$network_id ): string {
		$_POST    = array_merge( array( 'provider' => $provider, '_wpnonce' => $valid_nonce ? wp_create_nonce( 'network_ai_connector_' . $network_id . '_' . $provider ) : 'invalid' ), $values );
		$_REQUEST = $_POST;
		try {
			$admin->save();
		} catch ( Redirect $redirect ) {
			return $redirect->getMessage();
		}
		throw new \RuntimeException( 'Expected a redirect.' );
	};
	$saved_key = 'valid-saved-secret-7391';
	expect( 'saved' === $submit( array( 'operation' => 'save', 'api_key' => $saved_key ) ), 'Authorized nonced form validates and persists a network key' );
	expect( $saved_key === Plugin::keys()['nac-test'], 'Successful save is persistent in network-option storage' );
	expect( 'validation_failed' === $submit( array( 'operation' => 'save', 'api_key' => 'invalid-key' ) ), 'Invalid form submission reports validation failure' );
	expect( 'nac-test' === $redirected_provider, 'Failed submissions retain the provider identity for inline recovery' );
	expect( $saved_key === Plugin::keys()['nac-test'], 'Failed form validation preserves the saved key' );
	expect( 'invalid_key' === $submit( array( 'operation' => 'save', 'api_key' => '' ) ), 'Blank replacement is an error, not implicit removal' );
	expect( 'confirm_remove' === $submit( array( 'operation' => 'remove' ) ), 'Removal requires explicit confirmation' );

	ob_start();
	try {
		$submit( array( 'operation' => 'save', 'api_key' => 'valid-forged-key' ), false );
		expect( false, 'Invalid nonce must be rejected' );
	} catch ( Died $exception ) {
		ob_end_clean();
		expect( $saved_key === Plugin::keys()['nac-test'], 'Invalid nonce is rejected without changing stored credentials' );
	}
	$deny_admin = true;
	try {
		$submit( array( 'operation' => 'save', 'api_key' => 'valid-forbidden-key' ) );
		expect( false, 'Missing network capability must be rejected' );
	} catch ( Died $exception ) {
		expect( $saved_key === Plugin::keys()['nac-test'], 'Missing network capability is rejected without changes' );
	} finally {
		$deny_admin = false;
	}

	ob_start();
	$admin->render();
	$html = ob_get_clean();
	expect( str_contains( $html, 'class="nac-connector-list"' ) && str_contains( $html, '<summary' ) && str_contains( $html, '>Edit<' ), 'Network UI renders a site-like expandable connector list' );
	expect( str_contains( $html, 'aria-controls="nac-settings-nac-test"' ) && str_contains( $html, 'id="nac-settings-nac-test"' ), 'Connector disclosure controls identify their own settings' );
	expect( str_contains( $html, 'nac-connector__cancel-label' ) && str_contains( $html, 'nac-connector__close-label' ), 'Enhanced cancellation and the native disclosure fallback are both labeled' );
	expect( str_contains( $html, 'Synthetic provider' ) && str_contains( $html, 'https://example.invalid/synthetic-logo.svg' ) && str_contains( $html, 'Replacement API key' ), 'Network UI renders provider information, registered logos, and replacement controls' );
	$logo_src = static function ( string $markup, string $id ): string {
		$pattern = '/<(?:details|div) id="nac-' . preg_quote( $id, '/' ) . '"[^>]*>\s*<(?:summary|div)[^>]*>\s*<img class="nac-connector__logo" src="([^"]*)" width="40" height="40" alt="">/';
		return preg_match( $pattern, $markup, $matches ) ? html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	};
	foreach ( array( 'anthropic', 'google', 'openai' ) as $id ) {
		$asset = 'assets/logos/' . $id . '.svg';
		expect( plugins_url( $asset, \Soderlind\Network_AI_Connector\PLUGIN_FILE ) === $logo_src( $html, $id ) && is_readable( dirname( __DIR__ ) . '/' . $asset ), "The {$id} connector uses its bundled core logo" );
	}
	$default_asset = 'assets/logos/default.svg';
	expect( plugins_url( $default_asset, \Soderlind\Network_AI_Connector\PLUGIN_FILE ) === $logo_src( $html, 'nac-inactive' ) && is_readable( dirname( __DIR__ ) . '/' . $default_asset ), 'Unknown providers use the core fallback logo' );
	expect( 'https://example.invalid/synthetic-logo.svg' === $logo_src( $html, 'nac-test' ), 'Third-party registered logos remain unchanged' );

	$original_openai = $connector_registry->unregister( 'openai' );
	$override_openai = $original_openai;
	$override_openai['logo_url'] = 'https://example.invalid/openai-override.svg';
	$connector_registry->register( 'openai', $override_openai );
	try {
		ob_start();
		$admin->render();
		$override_html = ob_get_clean();
		expect( 'https://example.invalid/openai-override.svg' === $logo_src( $override_html, 'openai' ), 'A registered logo overrides a bundled core logo' );
	} finally {
		$connector_registry->unregister( 'openai' );
		$connector_registry->register( 'openai', $original_openai );
	}
	$card = static function ( string $markup, string $id ): string {
		return preg_match( '/<(details|div) id="nac-' . preg_quote( $id, '/' ) . '".*?<\/\1>\s*<\/li>/s', $markup, $matches ) ? $matches[0] : '';
	};
	$openai_card = $card( $html, 'openai' );
	expect( ! str_contains( $openai_card, '<details' ) && str_contains( $openai_card, 'name="operation" value="activate"' ) && str_contains( $openai_card, '>Activate<' ), 'An installed but inactive provider plugin offers network activation like the site page' );
	expect( ! str_contains( $openai_card, 'name="api_key"' ), 'An inactive provider does not render credential fields' );
	$anthropic_card = $card( $html, 'anthropic' );
	expect( str_contains( $anthropic_card, 'name="operation" value="install"' ) && str_contains( $anthropic_card, '>Install<' ), 'A missing provider plugin offers installation like the site page' );
	expect( str_contains( $card( $html, 'nac-inactive' ), '>Not available<' ) && ! str_contains( $card( $html, 'nac-inactive' ), '<form' ), 'A provider without plugin metadata is not available and has no action' );
	expect( ! str_contains( $html, 'Manage provider plugins' ), 'The plugin-management fallback link is replaced by inline actions' );

	$filter( 'file_mod_allowed', '__return_false' );
	ob_start();
	$admin->render();
	$no_file_mods = ob_get_clean();
	remove_filter( 'file_mod_allowed', '__return_false' );
	$anthropic_card = $card( $no_file_mods, 'anthropic' );
	expect( ! str_contains( $anthropic_card, '<form' ) && str_contains( $anthropic_card, 'https://wordpress.org/plugins/ai-provider-for-anthropic/' ) && str_contains( $anthropic_card, '>Learn more' ), 'Without install permission a missing plugin links to its directory page' );

	$deny_plugins = true;
	ob_start();
	$admin->render();
	$no_plugin_caps = ob_get_clean();
	$deny_plugins = false;
	expect( ! str_contains( $card( $no_plugin_caps, 'openai' ), '<form' ) && str_contains( $card( $no_plugin_caps, 'openai' ), 'https://wordpress.org/plugins/ai-provider-for-openai/' ), 'Without network plugin permission an inactive plugin links to its directory page' );

	expect( 'activated' === $submit( array( 'operation' => 'activate' ), true, 'nac-activatable' ), 'Activation reports success and reopens the provider' );
	expect( array( array( $activatable_file, true ) ) === $activations, 'Activation network-activates exactly the connector plugin' );
	expect( 'installed' === $submit( array( 'operation' => 'install' ), true, 'nac-installable' ), 'Installation reports success and reopens the provider' );
	expect( 0 === validate_plugin( $installable_file ) && array( $installable_file, true ) === end( $activations ), 'Installation installs and network-activates the connector plugin' );
	expect( 'install_failed' === $submit( array( 'operation' => 'install' ), true, 'nac-uninstallable' ), 'A failed directory lookup reports installation failure' );
	foreach (
		array(
			'Installing an installed plugin is rejected'        => array( 'install', 'nac-activatable' ),
			'Activating a loaded provider is rejected'          => array( 'activate', 'nac-test' ),
			'Activating a plugin that is not installed is rejected' => array( 'activate', 'nac-uninstallable' ),
		) as $message => list( $operation, $provider )
	) {
		try {
			$submit( array( 'operation' => $operation ), true, $provider );
			expect( false, $message );
		} catch ( Died $exception ) {
			expect( true, $message );
		}
	}
	$deny_plugins = true;
	try {
		$submit( array( 'operation' => 'activate' ), true, 'nac-activatable' );
		expect( false, 'Activation without network plugin permission is rejected' );
	} catch ( Died $exception ) {
		expect( 1 === count( array_filter( $activations, static fn( array $activation ): bool => $activatable_file === $activation[0] ) ), 'Activation without network plugin permission is rejected' );
	} finally {
		$deny_plugins = false;
	}
	$filter( 'file_mod_allowed', '__return_false' );
	try {
		$submit( array( 'operation' => 'install' ), true, 'nac-uninstallable' );
		expect( false, 'Installation is rejected when file modifications are disallowed' );
	} catch ( Died $exception ) {
		expect( true, 'Installation is rejected when file modifications are disallowed' );
	} finally {
		remove_filter( 'file_mod_allowed', '__return_false' );
	}
	expect( ! str_contains( $html, 'Internal compatibility status' ), 'The internal compatibility connector is not rendered as a provider' );
	expect( str_contains( $html, 'Confirm removal' ) && str_contains( $html, 'name="operation" value="remove"' ), 'The expanded workflow preserves explicit removal confirmation' );
	expect( ! str_contains( $html, $saved_key ) && ! str_contains( $html, '7391' ) && ! str_contains( $html, 'valid-site-key' ), 'Rendered network forms never include stored credential values or suffixes' );
	$_GET = array( 'nac_status' => 'validation_failed', 'nac_provider' => 'nac-test' );
	ob_start();
	$admin->render();
	$failure_html = ob_get_clean();
	expect( str_contains( $failure_html, 'id="nac-nac-test" class="nac-connector" open' ), 'A failed save reopens the corresponding connector settings' );
	expect( ! str_contains( $failure_html, $saved_key ) && ! str_contains( $failure_html, '7391' ), 'The reopened form never redisplays a stored credential or suffix' );
	$_GET = array( 'nac_status' => 'validation_in_progress', 'nac_provider' => 'nac-test' );
	ob_start();
	$admin->render();
	$nested_html = ob_get_clean();
	expect( str_contains( $nested_html, 'already being validated' ) && str_contains( $nested_html, 'id="nac-nac-test" class="nac-connector" open' ), 'A rejected nested save shows an explicit notice and reopens its connector' );
	$_GET = array( 'nac_status' => 'validation_failed', 'nac_provider' => 'not-a-connector' );
	ob_start();
	$admin->render();
	$unknown_html = ob_get_clean();
	expect( ! str_contains( $unknown_html, 'class="nac-connector" open' ), 'An unknown provider cannot open another connector' );
	$_GET = array();

	$filter(
		'network_ai_connector_provider_settings',
		static function ( array $definitions ) use ( $extra_option ): array {
			$definitions['azure-ai-foundry']['key_class'] = AzureAuthentication::class;
			$definitions['ollama']['fields']['note'] = array(
				'label'  => 'Synthetic extra setting',
				'type'   => 'text',
				'option' => $extra_option,
			);
			return $definitions;
		}
	);
	$ai_registry->registerProvider( AzureProvider::class );
	$ai_registry->registerProvider( OllamaProvider::class );
	$ai_registry->registerProvider( UnmanagedProvider::class );
	$plugin->enforce();
	expect( isset( $plugin->connectors()['azure-ai-foundry'], $plugin->connectors()['ollama'] ), 'Providers missing from the connector registry are discovered through AI Client metadata' );
	expect( Provider_Settings::enabled( 'ollama' ) && '' === $ai_registry->getProviderRequestAuthentication( 'ollama' )->getApiKey(), 'An unconfigured keyless connection works with an empty key, like the site Connectors page' );
	expect( 'http://localhost:11434' === getenv( 'OLLAMA_HOST' ), 'An unconfigured keyless connection uses the provider default host' );
	$ollama_configuration = array( 'host' => 'http://ollama.example.invalid:11434', 'note' => 'network-provider-setting' );
	expect( 'saved' === $submit( array( 'operation' => 'save', 'api_key' => '', 'configuration' => $ollama_configuration ), true, 'ollama' ), 'A validated local Ollama connection can be enabled without an API key' );
	expect( Provider_Settings::enabled( 'ollama' ) && '' === $ai_registry->getProviderRequestAuthentication( 'ollama' )->getApiKey(), 'Enabled keyless connections authenticate with an empty key rather than falling back to site credentials' );
	expect( 'http://ollama.example.invalid:11434' === getenv( 'OLLAMA_HOST' ) && array( 'host' => 'http://ollama.example.invalid:11434' ) === get_option( 'ai_provider_for_ollama_settings' ), 'The network Ollama host overrides both the environment and site settings' );
	expect( 'network-provider-setting' === get_option( $extra_option ), 'Extensions can map arbitrary provider fields without modifying the network plugin' );
	update_option( $extra_option, 'forged-site-setting' );
	expect( 'native-provider-setting' === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $extra_option ) ), 'Site updates cannot overwrite the preserved native custom provider setting' );
	$ollama_settings_request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
	$ollama_settings_request->set_param( 'ai_provider_for_ollama_settings', array( 'host' => 'http://forged.invalid' ) );
	expect( is_wp_error( $plugin->block_site_updates( null, rest_get_server(), $ollama_settings_request ) ), 'Custom provider settings cannot be written through site REST settings' );
	$azure_detect_request = new \WP_REST_Request( 'POST', '/azure-ai-foundry/v1/detect' );
	expect( is_wp_error( $plugin->block_site_updates( null, rest_get_server(), $azure_detect_request ) ), 'The native Azure detection endpoint is blocked so it cannot reuse a network key with a site-selected endpoint' );
	expect( 'invalid_configuration' === $submit( array( 'operation' => 'save', 'configuration' => array( 'host' => 'file:///etc/passwd' ) ), true, 'ollama' ), 'Non-HTTP provider endpoints are rejected before validation' );
	expect( $ollama_configuration['host'] === Provider_Settings::configuration( 'ollama' )['host'], 'Invalid configuration retains the previous network host' );
	expect( 'validation_failed' === $submit( array( 'operation' => 'save', 'configuration' => array( 'host' => 'http://wrong.example.invalid' ) ), true, 'ollama' ), 'An unreachable candidate host fails keyless provider validation' );
	expect( $ollama_configuration['host'] === getenv( 'OLLAMA_HOST' ), 'Failed keyless validation restores the previous runtime endpoint' );
	$azure_configuration = array( 'endpoint' => 'https://azure.example.invalid/models', 'model_name' => 'synthetic-deployment', 'capabilities' => array( 'text_generation' ) );
	expect( 'saved' === $submit( array( 'operation' => 'save', 'api_key' => 'valid-azure-key', 'configuration' => $azure_configuration ), true, 'azure-ai-foundry' ), 'Azure validates the submitted endpoint and key using its provider-specific api-key header' );
	$azure_auth = $ai_registry->getProviderRequestAuthentication( 'azure-ai-foundry' );
	$azure_request = $azure_auth->authenticateRequest( new Request( HttpMethodEnum::get(), 'https://example.invalid/models' ) );
	expect( 'valid-azure-key' === $azure_request->getHeaderAsString( 'api-key' ) && null === $azure_request->getHeaderAsString( 'Authorization' ), 'Normal network Azure requests preserve api-key authentication instead of Bearer authentication' );
	expect( 'invalid_configuration' === $submit( array( 'operation' => 'save', 'api_key' => 'valid-azure-key', 'configuration' => array_merge( $azure_configuration, array( 'capabilities' => array( array( 'forged' ) ) ) ) ), true, 'azure-ai-foundry' ), 'Malformed nested configuration is explicitly rejected' );
	expect( 'validation_failed' === $submit( array( 'operation' => 'save', 'api_key' => 'invalid-azure-key', 'configuration' => $azure_configuration ), true, 'azure-ai-foundry' ), 'Failed Azure validation does not replace the connection' );
	expect( 'valid-azure-key' === Plugin::keys()['azure-ai-foundry'] && $azure_configuration['endpoint'] === get_option( 'connectors_ai_azure_ai_foundry_endpoint' ), 'A failed replacement restores the saved Azure key and endpoint' );
	$network_id = $networks[1];
	$current_site->id = $network_id;
	$plugin->enforce();
	expect( ! isset( get_network_option( $network_id, Plugin::CONFIG_OPTION, array() )['ollama'] ) && 'http://localhost:11434' === getenv( 'OLLAMA_HOST' ), 'Switching networks does not reuse another network keyless settings or endpoint' );
	$network_id = $networks[0];
	$current_site->id = $network_id;
	$plugin->enforce();
	expect( 'http://ollama.example.invalid:11434' === getenv( 'OLLAMA_HOST' ), 'Returning to the first network restores its Ollama endpoint' );

	$runtime_events = array();
	$runtime_mode = '';
	$nested = array();
	$nested_state = array();
	$diagnostics = array();
	$other_plugin = new Plugin();
	$runtime_filter = static function ( array $definitions ) use ( &$runtime_events, &$runtime_mode, &$nested, &$nested_state, $plugin, $other_plugin ): array {
		$definitions['azure-ai-foundry']['apply'] = static function ( array $configuration, bool $is_enforced ) use ( &$runtime_events, &$runtime_mode, &$nested, &$nested_state, $plugin, $other_plugin ): void {
			$runtime_events[] = array(
				'provider' => 'azure',
				'network' => Plugin::network_id(),
				'configuration' => $configuration,
				'enforced' => $is_enforced,
				'ollama_host' => getenv( 'OLLAMA_HOST' ),
			);
			$is_candidate = array_key_exists( 'api_key', $configuration );
			$reenter = ( $is_candidate && 'reenter' === $runtime_mode ) || ( ! $is_candidate && $is_enforced && 'reenter_restoration' === $runtime_mode );
			if ( $reenter && ! isset( $nested['attempted'] ) ) {
				$nested['attempted'] = true;
				$nested_state['before'] = array(
					$plugin->cache_group( 'wp_ai_client' ),
					$plugin->configuration( 'azure-ai-foundry' ),
					getenv( 'OLLAMA_HOST' ),
				);
				$nested['same'] = $plugin->validate_key( 'azure-ai-foundry', 'valid-azure-key', $configuration );
				$nested['other'] = $plugin->validate_key( 'nac-test', 'valid-nested-key' );
				$nested['instance'] = $other_plugin->validate_key( 'nac-test', 'valid-nested-key' );
				$nested_state['after'] = array(
					$plugin->cache_group( 'wp_ai_client' ),
					$plugin->configuration( 'azure-ai-foundry' ),
					getenv( 'OLLAMA_HOST' ),
				);
			}
			if ( $is_candidate && 'candidate_error' === $runtime_mode ) {
				throw new \Error( 'SYNTHETIC_SECRET_MARKER: candidate callback failed.' );
			}
			if ( ! $is_candidate && $is_enforced && 'restoration_error' === $runtime_mode ) {
				throw new \TypeError( 'SYNTHETIC_SECRET_MARKER: restoration callback failed.' );
			}
			if ( 'switch_error' === $runtime_mode ) {
				throw new \Error( 'SYNTHETIC_SECRET_MARKER: network switch callback failed.' );
			}
		};
		$definitions['ollama']['apply'] = static function ( array $configuration, bool $is_enforced ) use ( &$runtime_events, &$runtime_mode ): void {
			$runtime_events[] = array(
				'provider' => 'ollama',
				'network' => Plugin::network_id(),
				'configuration' => $configuration,
				'enforced' => $is_enforced,
				'ollama_host' => getenv( 'OLLAMA_HOST' ),
			);
			if ( $is_enforced && 'override' === $runtime_mode ) {
				putenv( 'OLLAMA_HOST=http://extension.example.invalid:11434' );
			}
		};
		return $definitions;
	};
	$filter( 'network_ai_connector_provider_settings', $runtime_filter, 20 );
	$filter(
		'wp_trigger_error_always_run',
		static function ( string $function, string $message ) use ( &$diagnostics ): void {
			if ( str_contains( $function, 'Network_AI_Connector' ) ) {
				$diagnostics[] = $message;
			}
		},
		10,
		2
	);

	$plugin->apply_provider_configuration();
	expect( array( 'azure', 'ollama' ) === array_column( $runtime_events, 'provider' ), 'Provider integration callbacks run once in their existing order' );
	expect( $ollama_configuration['host'] === $runtime_events[0]['ollama_host'] && $ollama_configuration['host'] === $runtime_events[1]['ollama_host'], 'The built-in Ollama override runs before every extension callback' );
	$runtime_events = array();
	$enforced = false;
	$plugin->apply_provider_configuration();
	expect( false === $runtime_events[0]['enforced'] && false === $runtime_events[1]['enforced'] && $deployment_ollama_host === $runtime_events[0]['ollama_host'], 'Callbacks receive the unenforced state after the deployment host is restored' );
	$enforced = true;
	$plugin->apply_provider_configuration();
	$runtime_mode = 'override';
	$plugin->apply_provider_configuration();
	expect( 'http://extension.example.invalid:11434' === getenv( 'OLLAMA_HOST' ), 'An Ollama extension callback retains its existing last-write precedence' );
	$runtime_mode = '';
	$plugin->apply_provider_configuration();
	$remove_ollama_integration = static function ( array $definitions ): array {
		unset( $definitions['ollama'] );
		return $definitions;
	};
	$filter( 'network_ai_connector_provider_settings', $remove_ollama_integration, PHP_INT_MAX );
	$plugin->apply_provider_configuration();
	expect( $ollama_configuration['host'] === getenv( 'OLLAMA_HOST' ), 'The built-in Ollama runtime applies even when an extension removes its settings definition' );
	remove_filter( 'network_ai_connector_provider_settings', $remove_ollama_integration, PHP_INT_MAX );

	$invalid_callback = static function ( array $definitions ): array {
		$definitions['azure-ai-foundry']['apply'] = 'nac_nonexistent_callback';
		return $definitions;
	};
	$filter( 'network_ai_connector_provider_settings', $invalid_callback, 30 );
	$runtime_events = array();
	try {
		$plugin->apply_provider_configuration();
		$callback_failure = null;
	} catch ( \Throwable $exception ) {
		$callback_failure = $exception;
	}
	expect( $callback_failure instanceof \RuntimeException && str_contains( $callback_failure->getMessage(), 'azure-ai-foundry' ) && array( 'ollama' ) === array_column( $runtime_events, 'provider' ), 'A non-callable integration fails explicitly without preventing later callbacks' );
	remove_filter( 'network_ai_connector_provider_settings', $invalid_callback, 30 );

	$runtime_events = array();
	$network_id = $networks[1];
	$current_site->id = $network_id;
	do_action( 'switch_blog', get_current_blog_id(), get_current_blog_id(), 'switch' );
	expect( array( 'azure', 'ollama' ) === array_column( $runtime_events, 'provider' ) && 'http://localhost:11434' === $runtime_events[0]['ollama_host'] && array() === $runtime_events[1]['configuration'], 'Blog switching applies callbacks with the new network runtime configuration' );
	$runtime_events = array();
	$network_id = $networks[0];
	$current_site->id = $network_id;
	do_action( 'switch_blog', get_current_blog_id(), get_current_blog_id(), 'switch' );
	expect( $ollama_configuration['host'] === $runtime_events[0]['ollama_host'] && $ollama_configuration['host'] === $runtime_events[1]['configuration']['host'], 'Blog switching restores callbacks for the original network' );

	$runtime_events = array();
	$invalid_candidate = array( 'host' => 'http://wrong.example.invalid', 'note' => 'candidate-setting' );
	expect( is_wp_error( $plugin->validate_key( 'ollama', '', $invalid_candidate ) ), 'A custom integration observes a failed candidate validation' );
	expect( 'http://wrong.example.invalid' === $runtime_events[0]['ollama_host'] && $invalid_candidate['host'] === $runtime_events[1]['configuration']['host'], 'Callbacks receive the candidate configuration during validation' );
	expect( $ollama_configuration['host'] === $runtime_events[2]['ollama_host'] && $ollama_configuration['host'] === $runtime_events[3]['configuration']['host'], 'Callbacks receive the saved configuration after failed validation' );

	$runtime_mode = 'reenter';
	$runtime_events = array();
	$before_group = $plugin->cache_group( 'wp_ai_client' );
	$outer_result = $plugin->validate_key( 'azure-ai-foundry', 'valid-azure-key', $azure_configuration );
	expect( is_wp_error( $nested['same'] ?? null ) && 'validation_in_progress' === $nested['same']->get_error_code(), 'Nested validation of the same provider is explicitly rejected' );
	expect( is_wp_error( $nested['other'] ?? null ) && 'validation_in_progress' === $nested['other']->get_error_code(), 'Nested validation of another provider is explicitly rejected' );
	expect( is_wp_error( $nested['instance'] ?? null ) && 'validation_in_progress' === $nested['instance']->get_error_code(), 'Nested validation through another plugin instance is explicitly rejected' );
	expect( $nested_state['before'] === $nested_state['after'] && true === $outer_result, 'Rejected nested validation leaves the outer candidate and cache scope intact' );
	expect( $before_group === $plugin->cache_group( 'wp_ai_client' ) && $ai_registry->getProviderRequestAuthentication( 'azure-ai-foundry' ) instanceof Network_Authentication, 'Successful outer validation restores the cache scope and network authentication' );

	$runtime_mode = 'reenter_restoration';
	$nested = array();
	$nested_state = array();
	$outer_result = $plugin->validate_key( 'azure-ai-foundry', 'valid-azure-key', $azure_configuration );
	expect( is_wp_error( $nested['same'] ?? null ) && is_wp_error( $nested['other'] ?? null ) && is_wp_error( $nested['instance'] ?? null ) && $nested_state['before'] === $nested_state['after'] && true === $outer_result, 'Nested validation remains blocked throughout runtime restoration' );

	$runtime_mode = 'candidate_error';
	$runtime_events = array();
	try {
		$candidate_failure = $plugin->validate_key( 'azure-ai-foundry', 'valid-azure-key', $azure_configuration );
	} catch ( \Throwable $exception ) {
		$candidate_failure = $exception;
	}
	expect( is_wp_error( $candidate_failure ) && 'validation_failed' === $candidate_failure->get_error_code() && ! str_contains( $candidate_failure->get_error_message(), 'SYNTHETIC_SECRET_MARKER' ), 'A callback Error produces a sanitized validation failure' );
	expect( array( 'azure', 'ollama', 'azure', 'ollama' ) === array_column( $runtime_events, 'provider' ), 'A failing callback does not prevent other callbacks from applying and restoring' );
	expect( $before_group === $plugin->cache_group( 'wp_ai_client' ) && $ollama_configuration['host'] === getenv( 'OLLAMA_HOST' ) && $ai_registry->getProviderRequestAuthentication( 'azure-ai-foundry' ) instanceof Network_Authentication, 'Callback Error cleanup restores host, cache scope, and network authentication' );

	$runtime_mode = 'restoration_error';
	$runtime_events = array();
	$keys_before = Plugin::keys();
	$configurations_before = get_network_option( $network_id, Plugin::CONFIG_OPTION );
	$candidate_azure = array_merge( $azure_configuration, array( 'model_name' => 'candidate-deployment' ) );
	try {
		$restoration_status = $submit( array( 'operation' => 'save', 'api_key' => 'valid-azure-key', 'configuration' => $candidate_azure ), true, 'azure-ai-foundry' );
	} catch ( \Throwable $exception ) {
		$restoration_status = $exception;
	}
	expect( 'validation_failed' === $restoration_status, 'A callback TypeError during restoration fails the save explicitly' );
	expect( $keys_before === Plugin::keys() && $configurations_before === get_network_option( $network_id, Plugin::CONFIG_OPTION ), 'A failed restoration preserves the previous network credentials and configuration' );
	expect( array( 'azure', 'ollama', 'azure', 'ollama' ) === array_column( $runtime_events, 'provider' ) && $ai_registry->getProviderRequestAuthentication( 'azure-ai-foundry' ) instanceof Network_Authentication, 'Restoration continues to other callbacks and restores network authentication after a TypeError' );

	$runtime_mode = '';
	$azure_directory = AzureProvider::modelMetadataDirectory();
	$azure_directory->invalidations_before_failure = 2;
	$cache_failure = $plugin->validate_key( 'azure-ai-foundry', 'valid-azure-key', $azure_configuration );
	expect( is_wp_error( $cache_failure ) && 'validation_failed' === $cache_failure->get_error_code(), 'A cache invalidation failure during cleanup fails validation explicitly' );
	expect( $before_group === $plugin->cache_group( 'wp_ai_client' ) && $ai_registry->getProviderRequestAuthentication( 'azure-ai-foundry' ) instanceof Network_Authentication, 'A cache cleanup failure does not prevent runtime and authentication restoration' );
	expect( true === $plugin->validate_key( 'azure-ai-foundry', 'valid-azure-key', $azure_configuration ), 'Validation can run again after runtime and cleanup failures' );

	$runtime_mode = 'switch_error';
	$runtime_events = array();
	$network_id = $networks[1];
	$current_site->id = $network_id;
	try {
		$plugin->enforce();
		$switch_failure = null;
	} catch ( \Throwable $exception ) {
		$switch_failure = $exception;
	}
	expect( $switch_failure instanceof \RuntimeException && ! str_contains( $switch_failure->getMessage(), 'SYNTHETIC_SECRET_MARKER' ), 'A runtime callback failure during a network switch surfaces without its sensitive exception' );
	expect( array( 'azure', 'ollama' ) === array_column( $runtime_events, 'provider' ) && 'http://localhost:11434' === getenv( 'OLLAMA_HOST' ), 'A network switch attempts remaining runtime callbacks after one fails' );
	$runtime_mode = '';
	$network_id = $networks[0];
	$current_site->id = $network_id;
	$plugin->enforce();
	expect( $ollama_configuration['host'] === getenv( 'OLLAMA_HOST' ), 'The original network runtime can be reapplied after a failed switch' );
	expect( count( $diagnostics ) >= 2 && ! str_contains( implode( ' ', $diagnostics ), 'SYNTHETIC_SECRET_MARKER' ), 'Validation diagnostics never expose provider exception details' );
	remove_filter( 'network_ai_connector_provider_settings', $runtime_filter, 20 );

	ob_start();
	$admin->render();
	$provider_html = ob_get_clean();
	expect( str_contains( $provider_html, 'configuration[endpoint]' ) && str_contains( $provider_html, 'configuration[host]' ) && str_contains( $provider_html, 'configuration[capabilities][]' ), 'Network forms render provider-specific settings from the shared integration schema' );
	expect( ! str_contains( $provider_html, 'valid-azure-key' ), 'Custom provider credentials are never redisplayed' );
	expect( str_contains( $provider_html, 'not network-managed: Synthetic no-auth provider' ), 'Providers without compatible authentication are visibly reported as unmanaged rather than silently claimed as enforced' );
	$site_only_activation = static function ( mixed $plugins ): mixed {
		if ( is_array( $plugins ) ) {
			unset( $plugins['ai-provider-for-azure-ai-foundry/ai-provider-for-azure-ai-foundry.php'] );
		}
		return $plugins;
	};
	$filter( 'pre_site_option_active_sitewide_plugins', $site_only_activation, 20 );
	ob_start();
	$admin->render();
	$site_only_html = ob_get_clean();
	expect( str_contains( $card( $site_only_html, 'azure-ai-foundry' ), '>Activate<' ) && ! str_contains( $card( $site_only_html, 'azure-ai-foundry' ), 'name="api_key"' ), 'A provider loaded only on the main site must be network-activated before network setup' );
	expect( 'provider_inactive' === $submit( array( 'operation' => 'save', 'api_key' => 'valid-azure-key', 'configuration' => $azure_configuration ), true, 'azure-ai-foundry' ), 'A forged settings submission cannot bypass the network activation requirement' );
	remove_filter( 'pre_site_option_active_sitewide_plugins', $site_only_activation, 20 );
	$ai_registry->setProviderRequestAuthentication( 'nac-test', new AzureAuthentication( 'unused-native-key' ) );
	$plugin->enforce();
	$custom_request = $ai_registry->getProviderRequestAuthentication( 'nac-test' )->authenticateRequest( new Request( HttpMethodEnum::get(), 'https://example.invalid/models' ) );
	expect( $saved_key === $custom_request->getHeaderAsString( 'api-key' ), 'A standard provider native authentication subclass is preserved without a named integration' );
	wp_register_script_module( 'azure-ai-foundry/connectors', 'https://example.invalid/module.js' );
	wp_enqueue_script_module( 'azure-ai-foundry/connectors' );
	expect( in_array( 'azure-ai-foundry/connectors', wp_script_modules()->get_queue(), true ), 'The custom site connector module is initially queued' );
	$plugin->remove_managed_modules();
	expect( ! in_array( 'azure-ai-foundry/connectors', wp_script_modules()->get_queue(), true ), 'Managed custom site connector modules are dequeued' );
	expect( 'saved' === $submit( array( 'operation' => 'save', 'api_key' => 'valid-ollama-key', 'configuration' => $ollama_configuration ), true, 'ollama' ) && 'valid-ollama-key' === Plugin::keys()['ollama'], 'A keyless provider can store an optional key' );
	expect( 'key_conflict' === $submit( array( 'operation' => 'save', 'api_key' => 'other-key', 'clear_key' => '1', 'configuration' => $ollama_configuration ), true, 'ollama' ) && 'valid-ollama-key' === Plugin::keys()['ollama'], 'Entering a key and removing it at once is rejected without changing the key' );
	expect( 'saved' === $submit( array( 'operation' => 'save', 'clear_key' => '1', 'configuration' => $ollama_configuration ), true, 'ollama' ) && '' === Plugin::keys()['ollama'] && Provider_Settings::enabled( 'ollama' ), 'An optional key can be removed without removing the connection' );
	expect( 'reset' === $submit( array( 'operation' => 'remove', 'confirm_remove' => '1' ), true, 'ollama' ) && ! isset( Provider_Settings::configuration( 'ollama' )['host'] ) && Provider_Settings::enabled( 'ollama' ), 'Resetting a keyless connection removes network settings and returns to provider defaults' );

	$admin->menu();
	$admin->network_assets( get_plugin_page_hookname( Admin::PAGE, 'settings.php' ) );
	expect( wp_style_is( 'network-ai-connector-admin', 'enqueued' ), 'The network Connectors screen enqueues its scoped interface styles' );
	expect( wp_script_is( 'network-ai-connector-admin', 'enqueued' ), 'The network Connectors screen enqueues its accessible disclosure enhancement' );
	expect( 'removed' === $submit( array( 'operation' => 'remove', 'confirm_remove' => '1' ) ), 'Confirmed removal deletes the selected network credential' );
	expect( ! isset( Plugin::keys()['nac-test'] ) && 'valid-network-two' === Plugin::keys( $networks[1] )['nac-test'], 'Removal affects only the selected network' );

	$GLOBALS['nac_cache_order'] = array();
	$GLOBALS['nac_fail_availability_cache'] = false;
	$GLOBALS['nac_fail_availability_factory'] = false;
	$ai_registry->registerProvider( CacheOrderProvider::class );
	CacheOrderProvider::availability();
	$GLOBALS['nac_cache_order'] = array();
	$plugin->clear_provider_cache( 'nac-cache-order' );
	$expected_cache_order = array( 'get availability', 'get directory', 'clear availability', 'clear directory' );
	expect( $expected_cache_order === $GLOBALS['nac_cache_order'], 'Both provider cache objects are obtained before either cache is invalidated' );
	$GLOBALS['nac_fail_availability_cache'] = true;
	$GLOBALS['nac_cache_order'] = array();
	try {
		$plugin->clear_provider_cache( 'nac-cache-order' );
		$cache_exception = null;
	} catch ( \Throwable $exception ) {
		$cache_exception = $exception;
	}
	expect( $expected_cache_order === $GLOBALS['nac_cache_order'] && $cache_exception instanceof \RuntimeException && ! str_contains( $cache_exception->getMessage(), 'SYNTHETIC_SECRET_MARKER' ), 'A cache failure is sanitized and does not prevent remaining cache invalidations' );
	$GLOBALS['nac_fail_availability_cache'] = false;
	$GLOBALS['nac_fail_availability_factory'] = true;
	$GLOBALS['nac_cache_order'] = array();
	try {
		$plugin->clear_provider_cache( 'nac-cache-order' );
		$factory_exception = null;
	} catch ( \Throwable $exception ) {
		$factory_exception = $exception;
	}
	expect( array( 'get availability', 'get directory', 'clear directory' ) === $GLOBALS['nac_cache_order'] && $factory_exception instanceof \RuntimeException && ! str_contains( $factory_exception->getMessage(), 'SYNTHETIC_SECRET_MARKER' ), 'A cache factory failure is sanitized and still permits other cache invalidation' );
	unset( $GLOBALS['nac_cache_order'], $GLOBALS['nac_fail_availability_cache'], $GLOBALS['nac_fail_availability_factory'] );

	$enforced = false;
	$plugin->apply_provider_configuration();
	expect( 'native-provider-setting' === get_option( $extra_option ), 'Leaving an enforced network restores native provider fields' );
	expect( 'valid-site-key' === get_option( $site_option ), 'An unenforced network regains its original site credential' );
	expect( 'valid-environment-key' === $authentication->getApiKey(), 'An unenforced network retains ordinary environment precedence' );
	putenv( 'NAC_TEST_API_KEY' );
	expect( 'valid-constant-key' === $authentication->getApiKey(), 'An unenforced network retains ordinary constant precedence' );
	expect( 'wp_ai_client' === $plugin->cache_group( 'wp_ai_client' ), 'Unenforced networks retain the core cache namespace' );
	$enforced = true;
	update_network_option( $networks[0], Plugin::OPTION, array( 'nac-test' => 'valid-uninstall-key' ) );
	$filter( 'networks_pre_query', static fn(): array => $networks );
	define( 'WP_UNINSTALL_PLUGIN', 'network-ai-connector/network-ai-connector.php' );
	require dirname( __DIR__ ) . '/uninstall.php';
	expect( false === get_network_option( $networks[0], Plugin::OPTION ) && false === get_network_option( $networks[1], Plugin::OPTION ) && false === get_network_option( $networks[0], Plugin::CONFIG_OPTION ), 'Uninstall removes plugin-owned credentials and configuration across networks' );
	$stored_site = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $site_option ) );
	expect( 'valid-site-key' === $stored_site && 'other-site-key' === get_option( $other_option ), 'Uninstall preserves site credentials and unrelated settings' );
	echo "\n{$GLOBALS['nac_test_assertions']} assertions passed.\n";
} finally {
	foreach ( $filters as list( $name, $callback, $priority ) ) {
		remove_filter( $name, $callback, $priority );
	}
	foreach ( $networks as $id ) {
		delete_network_option( $id, Plugin::OPTION );
		delete_network_option( $id, Plugin::CONFIG_OPTION );
	}
	delete_option( $site_option );
	delete_option( $other_option );
	delete_option( $extra_option );
	delete_option( $sentinel_option );
	foreach ( array( $activatable_file, $installable_file ) as $temporary_plugin ) {
		$temporary_dir = WP_PLUGIN_DIR . '/' . dirname( $temporary_plugin );
		if ( str_contains( $temporary_dir, '/nac-test-' ) && is_dir( $temporary_dir ) ) {
			array_map( 'unlink', glob( $temporary_dir . '/*' ) ?: array() );
			rmdir( $temporary_dir );
		}
	}
	if ( file_exists( $installable_zip ) ) {
		unlink( $installable_zip );
	}
	wp_clean_plugins_cache( false );
	$current_site   = $original_network;
	$current_screen = $original_screen;
	wp_set_current_user( $original_user );
	$_GET     = $original_get;
	$_POST    = $original_post;
	$_REQUEST = $original_request;
	if ( null === $original_method ) {
		unset( $_SERVER['REQUEST_METHOD'] );
	} else {
		$_SERVER['REQUEST_METHOD'] = $original_method;
	}
	if ( false === $original_env ) {
		putenv( 'NAC_TEST_API_KEY' );
	} else {
		putenv( 'NAC_TEST_API_KEY=' . $original_env );
	}
	if ( false === $original_ollama_env ) {
		putenv( 'OLLAMA_HOST' );
	} else {
		putenv( 'OLLAMA_HOST=' . $original_ollama_env );
	}
}
