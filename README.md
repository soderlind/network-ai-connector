# Network AI Connector

Manage AI provider connections for your WordPress multisite network in one place: **Network Admin → Settings → Connectors**. Supported connections use the network configuration instead of individual sites' keys or keys supplied by the hosting environment.

> **Before you activate:** Network enforcement starts immediately. A managed provider without a saved network connection becomes unavailable, even if a site or your server already has a working key. Existing site settings for managed providers are kept, but they are not used while network enforcement is active. Have your provider credentials ready before enabling the plugin.

## What it does

- Provides a network Connectors page that works like the site Connectors page.
- Lets authorized network administrators install or network-activate available provider plugins from that page.
- Validates new or replacement connections before saving them. Saved keys are never displayed on the network page.
- Supports standard API-key providers and includes integrations for Azure AI Foundry and Fueled's AI Provider for Ollama.
- Keeps each network's connections separate in installations with multiple networks.

## What you need

- A WordPress multisite installation running WordPress 7.1 or later and PHP 8.2 or later.
- A network administrator account. Installing or activating provider plugins also requires the appropriate plugin-management permissions.
- A compatible AI provider plugin, active for the sites that use it, and any credentials or connection details it requires. A provider plugin may have its own additional requirements.
- A WordPress server that can reach the provider. Validation may contact the provider and can count toward its usage limits or charges.

## Get started

1. Install Network AI Connector from a plugin ZIP in Network Admin, or place its folder in `wp-content/plugins`.
2. **Network Activate** the plugin.
3. Go to **Network Admin → Settings → Connectors**.
4. Find your provider. If offered, choose **Install** or **Activate**; both actions activate the provider plugin across the network.
5. Select **Set up**, enter any required API key and connection details, and select **Save**.

The connection is checked before it is saved. If validation fails, the previously saved connection is kept; a provider with no saved network connection remains unavailable.

### Azure AI Foundry

Install and network-activate **AI Provider for Azure AI Foundry**. Enter the endpoint URL, deployment names, capabilities, and API key on the network Connectors page. The provider's `api-key` request header is preserved.

### Ollama

Install and network-activate **AI Provider for Ollama** by Fueled. Enter the host URL and save the connection. An API key is optional for an Ollama server that does not require one, but the host must validate before the connection is enabled.

The default host is `http://localhost:11434`. Here, `localhost` means the **WordPress server**, not the computer running your browser. Ollama must be running separately and reachable from the WordPress server.

### Other providers

The plugin discovers compatible API-key providers through the WordPress Connectors and AI Client registries. A provider with additional settings may need a specific integration before those settings can be managed centrally.

Providers listed as **not network-managed** do not expose compatible request authentication. This plugin does not enforce or disable those providers; do not assume their connections are protected by network settings. Custom provider settings pages or endpoints also need their own review.

## Change or remove a connection

Open the provider with **Edit** and save the changes. To replace a key, enter the new key; the saved key and its suffix are never displayed. Leaving the key field blank does not remove a stored key, although some providers require a key when saving.

To delete a connection, select **Confirm removal** and then **Remove network connection**. This removes its network settings and makes the managed provider unavailable until it is configured again; it does not fall back to a site key. To switch an authenticated Ollama connection to a keyless one, remove the connection and set it up again without a key.

## Troubleshooting

**Why do I see Install or Activate?**

The provider plugin is missing or is installed but not network-active. Use the offered action to install or network-activate it. If you see **Learn more** instead, check your plugin-management permissions and whether your host allows file modifications.

**Why is a provider marked Not available?**

The plugin may not have enough information to install or activate its provider, or a network-active provider plugin may not have registered its provider. Check that the correct provider plugin is installed and compatible with your WordPress and PHP versions.

**Why did saving fail?**

Check the key, required fields, endpoint or host URL, and whether the WordPress server can reach the provider. URLs must use HTTP or HTTPS and cannot contain credentials or fragments. A failed validation leaves the previously saved connection unchanged.

**Where did the site-level AI controls go?**

Managed AI controls are removed from the site Connectors page, which shows a network-management notice instead. Non-AI connectors and providers that are not network-managed retain their own behavior.

## Security and removal

Network keys are stored in ordinary WordPress network options; the plugin does **not** add application-level encryption. Restrict access to network administration, the database, and backups, and use HTTPS where possible.

Deactivating the plugin keeps its network settings. On subsequent requests, sites return to their ordinary site or hosting-provided configuration. Uninstalling the plugin deletes its network keys and settings **from all networks** in the installation, but leaves site settings intact.

## Developer reference

The `network_ai_connector_provider_settings` filter is this plugin's integration contract; it is not part of the upstream provider standard. Register it before `init`, preferably at plugin load, and return a map keyed by AI Client provider ID.

- Each definition declares `name`, plugin basename `plugin`, `key_option`, and `fields`; `key_optional` is optional.
- Each field declares `label`, `type` (`text`, `url`, or `choices`), and site `option`. Optional field properties are `required`, `default`, and `path` for an array-valued option; a `choices` field also declares `choices`.
- Optional `key_class` must extend the SDK's `ApiKeyRequestAuthentication` and accept the key in its constructor. An existing provider authentication subclass is also preserved when already bound.
- Optional `script_module` names a custom site connector module to dequeue; `blocked_routes` lists exact REST paths to block while managed. Azure's detection endpoint is blocked because it accepts a site-selected destination and can resolve a saved key.
- Optional `resolve_key` returns the provider's native key when leaving a managed network.
- Optional `apply` receives the candidate or current configuration and an enforcement boolean. It must apply and restore its runtime overrides on validation and blog switches, including when enforcement is false.

Built-in runtime overrides run first, followed by `apply` callbacks in definition order, so an extension can still override a built-in value. A candidate configuration can contain an `api_key`; callbacks must not log or disclose it. Nested validation is rejected with `validation_in_progress`, including during restoration.

If an `apply` callback throws, the plugin attempts the remaining callbacks and reports a sanitized failure. During validation it also attempts to restore the saved runtime configuration, cache scope, and network authentication; a failure prevents the candidate from being saved. Outside validation, a runtime failure throws an exception. An integration that also throws while restoring its own state may leave that state unrestored.

The built-in integrations are in [Provider_Settings](includes/class-provider-settings.php). The provider standard does not expose a universal settings schema or disable hook: do not infer arbitrary settings from names, and review custom REST endpoints before treating them as protected.

See the [design](docs/design.md) and [glossary](GLOSSARY.md) for more detail.

### Release ZIP

Publishing a GitHub release builds and attaches `network-ai-connector.zip`. To build one manually, run **Manually Build release zip** from GitHub Actions with an existing tag. The workflow uploads the ZIP to that tag's release, creating the release if needed. Both workflows replace any existing asset with the same name.

The ZIP contains a top-level `network-ai-connector` directory. Development files are excluded according to [`.distignore`](.distignore).

### Testing

Run PHP syntax checks and the integration suite against a **disposable multisite**, not production:

```sh
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
phpcs --standard=WordPress-Extra --extensions=php --ignore=tests .
NAC_TEST_ALLOW_DATABASE_WRITES=1 wp --skip-plugins --skip-themes eval-file tests/integration.php
```

For Local sites, use a Local-aware WP-CLI wrapper. The suite uses synthetic network identities, temporary namespaced site options, and synthetic credentials in isolated network-option rows. It cleans these up and never sends requests to real AI providers. It temporarily creates and removes test plugins, installs one from a local ZIP without contacting WordPress.org, and blocks network-activation writes. It does not activate the plugin or create sites, networks, or users.

## License and credits

GPL-2.0-or-later. The bundled provider and fallback icons are derived from the WordPress 7.1 core Connectors page (Copyright 2011-2026 WordPress contributors; GPL-2.0-or-later). A connector's registered logo takes precedence over the bundled icon.
