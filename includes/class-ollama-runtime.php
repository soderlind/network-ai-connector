<?php

namespace Soderlind\Network_AI_Connector;

defined( 'ABSPATH' ) || exit;

final class Ollama_Runtime {
	private ?string $original_host = null;
	private bool $host_changed     = false;

	public function apply( array $configuration, bool $is_enforced ): void {
		if ( $is_enforced ) {
			if ( ! $this->host_changed ) {
				$host                = getenv( 'OLLAMA_HOST' );
				$this->original_host = false === $host ? null : $host;
				$this->host_changed  = true;
			}
			$host = $configuration['host'] ?? 'http://localhost:11434';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The Ollama SDK reads only this environment variable.
			if ( ! putenv( 'OLLAMA_HOST=' . $host ) ) {
				throw new \RuntimeException( 'Could not apply the network Ollama host.' );
			}
		} elseif ( $this->host_changed ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the deployment value when leaving an enforced network.
			if ( ! putenv( null === $this->original_host ? 'OLLAMA_HOST' : 'OLLAMA_HOST=' . $this->original_host ) ) {
				throw new \RuntimeException( 'Could not restore the deployment Ollama host.' );
			}
			$this->host_changed = false;
		}
	}
}
