<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Cli;

use WP_CLI;
use WP_CLI\Utils;

/**
 * The `wp uploads-proxy` WP-CLI command.
 *
 * A thin adapter: it parses WP-CLI arguments, delegates all logic to the
 * WordPress-free {@see CommandRunner}, and renders the result with WP-CLI's
 * native formatting affordances. Registration is guarded so the command is inert
 * in a web request (see {@see Command::register()}).
 */
final class Command {

	public function __construct( private readonly CommandRunner $runner ) {
	}

	/**
	 * Register the command, but only when WP-CLI is the runtime.
	 *
	 * Guarded with `defined( 'WP_CLI' ) && WP_CLI` so nothing is registered during
	 * a normal web request.
	 */
	public static function register( CommandRunner $runner ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'uploads-proxy', new self( $runner ) );
	}

	/**
	 * Report the proxy's effective configuration and counters.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output as a table, JSON, YAML, or CSV.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp uploads-proxy status
	 *     wp uploads-proxy status --format=json
	 *
	 * @subcommand status
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = $this->runner->status();

		$rows = [
			$this->row( 'active', $status['active'] ? 'yes' : 'no' ),
			$this->row( 'origin', '' === $status['origin'] ? '(not set)' : $status['origin'] ),
			$this->row( 'origin_source', $status['origin_source'] ),
			$this->row( 'mode', $status['mode'] ),
			$this->row( 'mode_source', $status['mode_source'] ),
			$this->row( 'downloaded', (string) $status['downloaded'] ),
			$this->row( 'negative_cache', (string) $status['negative_cache'] ),
		];

		$format = $assoc_args['format'] ?? 'table';

		Utils\format_items( $format, $rows, [ 'field', 'value' ] );
	}

	/**
	 * Clear the Negative cache and reset the counters.
	 *
	 * Removes every Negative-cache transient and resets the downloaded and
	 * Negative-cache counters to zero, giving CI a clean slate between runs. It
	 * never deletes downloaded media from the uploads directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp uploads-proxy clear-cache
	 *
	 * @subcommand clear-cache
	 */
	public function clear_cache(): void {
		$result = $this->runner->clearCache();

		WP_CLI::success(
			sprintf(
				/* translators: %d: number of Negative-cache entries cleared. */
				_n(
					'Cleared %d Negative-cache entry and reset the counters. Downloaded media was not touched.',
					'Cleared %d Negative-cache entries and reset the counters. Downloaded media was not touched.',
					$result['cleared'],
					'uploads-proxy'
				),
				$result['cleared']
			)
		);
	}

	/**
	 * Build a field/value row for WP-CLI's item formatter.
	 *
	 * @return array{field: string, value: string}
	 */
	private function row( string $field, string $value ): array {
		return [
			'field' => $field,
			'value' => $value,
		];
	}
}
