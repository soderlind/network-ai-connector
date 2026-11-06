# Network-enforced AI connections

## Agreed scope

The plugin discovers API-key-capable AI providers through the WordPress Connectors and AI Client registries. A provider-settings integration maps custom fields and authentication; Azure AI Foundry and Fueled's Ollama ship with integrations. The network page follows core's compact connector list and inline Set up/Edit workflow. Server-rendered forms and native disclosure controls remain usable without JavaScript; the small enhancement resets unsaved input on Cancel.

Only users with `manage_network_options` can configure connections. The boundary is one network. Required-key connections without a key are disabled. Optional-key connections such as local Ollama must also be explicitly saved and validated before enablement. Non-AI connectors remain outside enforcement. Providers without compatible request authentication are reported as unmanaged rather than falsely advertised as protected; the SDK provides no universal disable hook for arbitrary provider implementations.

Azure's internal status marker remains excluded. Its real provider, endpoint, deployment names, capabilities, and custom `api-key` authentication are network-managed.

## Authority and data flow

1. Core and provider plugins register connectors at initialization.
2. Before providers initialize, the plugin overrides mapped options from network configuration. At initialization priorities 19 and PHP_INT_MAX it binds network authentication, including after providers' own authentication callbacks.
3. Authentication reads credentials from the current site's network at request time. This also protects model instances retained across a blog switch.
4. An unconfigured connection causes an explicit authentication exception before a managed authenticated request is sent. Required-key providers additionally require a nonempty key.
5. Cache groups include network, credentials, settings, and candidate validation scope. Candidate settings are applied temporarily and restored after validation, including failures.

Keys are stored in `network_ai_connector_keys`; enablement and fields live in `network_ai_connector_settings`. Neither option is registered in REST. Network administrators never see saved keys in rendered forms; managed site REST settings return empty values. Mapped PHP option reads supply network values so providers can consume them, including provider-specific credential resolvers.

Site settings REST writes naming managed credentials fail with an explicit 403 error, including mixed updates. Standard site option forms exclude managed fields; ordinary updates to these options are suppressed without deleting stored site credentials.

## Administration and failure behavior

Site Connectors pages retain non-managed entries and display a network-managed notice naming managed providers. Managed AI entries are removed from the client-side editable list.

Core hides legacy admin notices on this route. A small dependency-free script places the native notice inside the asynchronously rendered Connectors page, without duplicating core's application or changing other connectors.

Each network form has a network/provider-bound nonce, capability check, explicit operation, and registered-provider allowlist. New/replacement keys must validate through the AI Client before persistence. Errors reopen the affected provider, retain the previous key, do not redisplay submitted credentials, and do not echo provider exceptions that might contain secrets. Removal is explicit and requires confirmation. Like the site Connectors page, providers whose plugin is missing offer Install (from WordPress.org, through core's upgrader) and inactive providers offer Activate; both network-activate the connector's declared plugin file. Activate requires `manage_network_plugins`; Install also requires `install_plugins` and allowed file modifications. Without those capabilities a Learn more link to the plugin directory is shown; providers without plugin metadata are Not available.

Core may validate by listing models or making a minimal generation request, depending on the provider. The extensible settings schema in the README is a plugin-owned integration contract, not an upstream provider standard. Unmapped settings and custom provider endpoints cannot be assumed to be protected.

## Lifecycle

No automatic import, migration, or deletion of existing site credentials occurs. Existing network keys remain valid without migration. Deactivation restores native behavior on subsequent requests; uninstall deletes both plugin-owned network options.

## Verification contract

Verify precedence, no-key refusal, explicit keyless enablement, registry-only providers, Azure headers, Ollama hosts, candidate restoration, network isolation and blog switching, site REST and option write protection, nonce/capabilities, cache invalidation, and uninstall cleanup without affecting native configuration.
