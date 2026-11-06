# Network AI Connector

Shared language for AI provider connections governed by a WordPress multisite network.

## Language

**Network-enforced connection**:
A provider connection governed by a network and authoritative for all its sites, ahead of site configuration and deployment-provided credentials. Connections that need a key must be explicitly configured and validated; keyless connections use provider defaults until configured.
_Avoid_: Network default, fallback connection

**Candidate connection**:
A proposed network-enforced connection awaiting validation and persistence. It does not replace the saved connection until both succeed.

**Provider runtime configuration**:
The provider settings in effect for a network-enforced connection. During validation they may temporarily reflect a candidate connection instead of the saved connection.

**Site connection**:
An AI provider connection configured for one site, subordinate to a network-enforced connection while network enforcement is active.

**Network boundary**:
The set of sites governed by one network's connection policy, independent of other networks in the same installation.

**API-key provider**:
An AI provider whose standard connection requires an API key.

**Keyless provider**:
An AI provider connection that requires no API key, such as a local Ollama server. Like the site Connectors page, it is enabled with provider defaults until a network administrator saves other settings.

**Provider integration**:
The description of a provider's additional connection settings and authentication behavior needed to manage it network-wide.

**Deployment-provided key**:
An AI provider credential supplied by the hosting environment rather than by a site or network administrator.
