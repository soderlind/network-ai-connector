# Changelog

## 1.0.1 - 2026-11-24

- Fix detection of network-managed AI providers by WordPress, the AI plugin, and llms.md.
- Allow server-side connector option reads of network credentials; site REST settings remain redacted.

## 1.0.0 - 2026-11-23

- Production ready.

## 0.1.3 - 2026-11-06

- Ollama works network-wide with its default host and no key, matching the site Connectors page.
- Add "Remove saved key and connect without one" for Ollama.
- Replace Ollama's "Remove network connection" with "Reset to default".
- Simplify the README and link the latest release ZIP.

## 0.1.2 - 2026-11-06

- Fix the layout of site Connectors notices, including the plugin-installation permission message.
- Hide the "No connectors yet" prompt for site administrators when only network-managed AI providers are present.

## 0.1.1 - 2026-11-06

- Refactor provider runtime configuration.
- Add network-wide AI provider connectors.
