<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * Writes downloaded Origin bytes into the uploads directory atomically.
 *
 * The body is written to a temporary file in the destination directory and then
 * `rename()`d into place, so a concurrent request never observes a half-written
 * file and the web server can serve subsequent requests directly. The caller is
 * responsible for containing the target path to the uploads basedir (see
 * {@see UploadsScope}); this class only performs the atomic write.
 */
final class FileWriter {

	/**
	 * Write $bytes to $target atomically, creating intermediate directories.
	 *
	 * @return bool True on success, false if the directory or file could not be written.
	 */
	public function write( string $target, string $bytes ): bool {
		$directory = dirname( $target );

		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$temp = $this->temporaryFileIn( $directory );

		if ( null === $temp ) {
			return false;
		}

		if ( false === file_put_contents( $temp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->cleanUp( $temp );
			return false;
		}

		// Atomic swap into the final path.
		if ( ! rename( $temp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$this->cleanUp( $temp );
			return false;
		}

		return true;
	}

	/**
	 * Create a unique temporary file in $directory, returning its path or null.
	 */
	private function temporaryFileIn( string $directory ): ?string {
		$temp = tempnam( $directory, 'uploads-proxy-' );

		return false === $temp ? null : $temp;
	}

	/**
	 * Remove a leftover temporary file, ignoring failures.
	 */
	private function cleanUp( string $temp ): void {
		if ( is_file( $temp ) ) {
			wp_delete_file( $temp );
		}
	}
}
