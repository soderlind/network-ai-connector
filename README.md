# Network AI Connector

A network-only WordPress multisite plugin providing **Network Admin → Settings → Connectors** for WordPress AI Client providers, including Azure AI Foundry and local or authenticated Ollama.

Requires WordPress 7.1+ and PHP 8.2+ (individual provider plugins may require a newer PHP version). Network-activate this plugin; provider plugins can then be installed and network-activated from the Connectors page. **Activation immediately enforces managed connections: unconfigured providers become unavailable, even when site configuration or deployment-provided keys exist. Existing site configuration is not imported or deleted.**

Open a provider with **Set up** or **Edit**, enter its key and any additional settings, and select **Save**. Candidates are validated before saving; validation failures retain the previous connection and reopen the form. **Cancel** clears unsaved input. Removal requires explicit confirmation. No stored key, including its suffix, is displayed.

- **Azure AI Foundry:** configure the endpoint, deployment names, capabilities, and API key. The provider's native `api-key` header is preserved.
- **Ollama (Fueled):** configure the host URL and optionally a key for authenticated servers. An empty key is allowed, but the host must validate before the network connection is enabled. `localhost` means the WordPress server, not the administrator's browser.
- Existing standard API-key connections continue to work without migration.

The built-in provider and fallback icons are derived from the WordPress 7.1 core Connectors page (Copyright 2011-2026 WordPress contributors; GPL-2.0-or-later). As on the site page, a connector's registered logo takes precedence.

## Policy and boundaries

- Connections are isolated to the site's network, including when code switches blogs.
- Network configuration overrides mapped site options and authentication. The Ollama host environment override is request-scoped and restored when leaving a managed network.
- Site Connectors screens show a managed-connections notice instead of managed AI controls. Non-AI connectors retain their existing behavior. Custom provider settings pages may still render; writes to mapped options are suppressed.
- Site settings REST reads return empty managed fields; writes are rejected. Neither network option is registered in REST.
- Standard API-key providers are discovered dynamically through both the Connectors and AI Client registries. Additional fields need a provider integration; the provider standard does not expose a universal settings schema.
- Azure's internal compatibility marker remains site-managed and hidden, while its real credentials and settings are network-managed.
- Local/keyless connections implemented using the SDK's API-key authentication (such as Ollama) are supported. Providers with no compatible request-authentication mechanism are explicitly reported as unmanaged: the SDK does not expose a universal disable hook for those providers. They must not be assumed to be network-enforced.
- A provider plugin must be active on a site to use its network key. Like the site Connectors page, the network page offers **Install** for missing provider plugins and **Activate** for inactive ones; both network-activate the plugin. Users who cannot install or network-activate plugins (or when file modifications are disabled) see a **Learn more** link to the plugin directory instead.
- Keys use ordinary WordPress network-option storage, **not application-level encryption**. Protect database access, backups, and administrator accounts; use HTTPS in production.

Deactivation retains network settings and restores ordinary site/deployment configuration on subsequent requests. Uninstall deletes this plugin's network credentials and configuration from all networks, preserving site settings.

## Additional provider integrations

Register `network_ai_connector_provider_settings` before `init` (preferably at plugin load). Return a map keyed by AI Client provider ID. Each entry declares `name`, plugin basename `plugin`, `key_option`, optional `key_optional`, and `fields`. Each field declares `label`, `type` (`text`, `url`, or `choices`), site `option`, optional `required`, `default`, and `path` for an array-valued option; choices also declare `choices`.

Optional `key_class` must extend the SDK's `ApiKeyRequestAuthentication` and accept the key in its constructor. The plugin also preserves an existing provider authentication subclass when one is already bound. `script_module` identifies a custom site connector module to dequeue. `blocked_routes` lists exact REST route paths that must be disabled while managed (Azure's native detection endpoint is blocked because it accepts a site-selected destination and can resolve a saved key). Optional `apply` is called with candidate/current configuration and an enforcement boolean; it must apply and restore any provider-specific runtime overrides on validation and blog switches. Optional `resolve_key` returns the provider's native key when leaving a managed network.

The included integrations are in [Provider_Settings](includes/class-provider-settings.php). This extension contract belongs to this plugin; it is not part of the upstream provider standard. Do not infer arbitrary settings from names or expose unrelated plugin options. Provider-specific custom REST endpoints require their own authorization review before being treated as read-only.

See the [design](docs/design.md) and [glossary](GLOSSARY.md).

## Verification

Run PHP syntax checks and the integration suite against a **disposable multisite**:

```sh
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
phpcs --standard=WordPress-Extra --extensions=php --ignore=tests .
NAC_TEST_ALLOW_DATABASE_WRITES=1 wp --skip-plugins --skip-themes eval-file tests/integration.php
```

For Local sites use your Local-aware WP-CLI wrapper. The integration suite uses synthetic network identities, temporary namespaced site options, and synthetic credentials in isolated network-option rows. It cleans these up and never sends requests to real AI providers. It temporarily creates and removes test plugins in the plugins directory, installing one from a local zip without contacting WordPress.org, and blocks network-activation writes. It does not activate the plugin or create sites, networks, or users. Do not run it against production.
