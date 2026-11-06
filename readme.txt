=== Network AI Connector ===
Contributors: PerS
Tags: multisite, ai, connectors, network, api
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage AI provider connections for every site on your WordPress multisite network.

== Description ==

Network AI Connector gives network administrators a single Connectors page for managing supported AI provider connections across a multisite network.

**Before activating:** Network enforcement starts immediately. Managed providers without a saved network connection become unavailable, even if a site or the server already has a working key. Existing site settings are kept, but are not used for managed providers while the plugin is active.

* Manage network connections from Network Admin > Settings > Connectors.
* Install or network-activate available provider plugins from the Connectors page.
* Validate connections before saving. Saved keys are not displayed on the network page.
* Use compatible API-key providers, including integrations for Azure AI Foundry and Fueled's AI Provider for Ollama.
* Keep settings separate between networks in a multi-network installation.

Providers without compatible request authentication are shown as not network-managed. This plugin does not enforce or disable those providers. Additional provider-specific settings or custom endpoints may need an integration or separate review.

== Installation ==

1. Make sure you have WordPress multisite, WordPress 7.1 or later, and PHP 8.2 or later.
2. Install Network AI Connector in Network Admin, or upload its folder to `wp-content/plugins`.
3. Network-activate the plugin. Network enforcement starts immediately.
4. Go to Network Admin > Settings > Connectors.
5. For your provider, select Install or Activate if offered. Both actions activate the provider plugin across the network.
6. Select Set up, enter the requested connection details, and select Save.

The WordPress server must be able to reach the provider. Validation may contact the provider and can count toward its usage limits or charges.

== Frequently Asked Questions ==

= Does this work on a single WordPress site? =

No. The plugin requires WordPress multisite and must be network-activated.

= What happens to existing site keys and settings? =

They are retained, but managed providers use the network connection instead of site or hosting-provided credentials. If the plugin is deactivated, ordinary site and hosting configuration becomes available again on subsequent requests.

= How do I change or remove a connection? =

Open the provider with Edit and save the changes. Saved keys are not displayed. Leaving the key field blank does not remove a stored key, although some providers require a key when saving.

To remove the connection, select Confirm removal and then Remove network connection. The managed provider becomes unavailable until it is configured again; it does not fall back to a site key.

= Why do I see Install, Activate, Learn more, or Not available? =

Install means the provider plugin is missing; Activate means it is installed but not network-active. Both actions network-activate the plugin. Learn more appears when the necessary plugin-management action is unavailable, such as when you lack permissions or file modifications are disabled.

Not available can mean the provider lacks usable plugin information, or its network-active plugin has not registered the provider. Check the provider plugin and its requirements.

= Does Ollama need an API key? =

An API key is optional if your Ollama server does not require one, but you must save and validate the host to enable the connection. The default `http://localhost:11434` refers to the WordPress server, not your browser's computer. Ollama must be running separately and reachable from WordPress.

To switch an authenticated Ollama connection to a keyless one, remove the connection and set it up again without a key.

= How are keys stored? =

Keys are stored in WordPress network options. The plugin does not add application-level encryption. Protect network administrator accounts, database access, and backups, and use HTTPS where possible.

= What happens when I delete the plugin? =

Uninstalling deletes the plugin's network keys and settings from all networks in the installation. Existing site settings are not deleted.

== Changelog ==

= 0.1.2 =

* Fix the layout of site Connectors notices, including the plugin-installation permission message.
* Hide the "No connectors yet" prompt for site administrators when only network-managed AI providers are present.

= 0.1.1 =

* Refactor provider runtime configuration.
* Add network-wide AI provider connectors.

= 0.1.0 =

* Initial version.

== Credits ==

The bundled provider and fallback icons are derived from the WordPress 7.1 core Connectors page (Copyright 2011-2026 WordPress contributors; GPL-2.0-or-later).
