<?php
/**
 * Encryption and decryption helper functions.
 *
 * Provides encrypt/decrypt wrappers for storing sensitive data such as API keys
 * in wp_options. Uses libsodium (sodium_crypto_secretbox) with an AES-256-CBC
 * fallback via OpenSSL. Keys are derived from wp_salt() and are never stored.
 *
 * @package    WPClaw
 * @subpackage WPClaw/helpers
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypt a plaintext string using libsodium or AES-256-CBC fallback.
 *
 * Key derivation: SHA-256 of wp_salt('auth') → 32-byte binary key.
 * Primary path (sodium): 24-byte random nonce + sodium_crypto_secretbox ciphertext,
 * base64-encoded as a single blob (nonce prepended).
 * Fallback path (openssl): 16-byte random IV + AES-256-CBC ciphertext, base64-encoded
 * with a 'ssl:' prefix to distinguish from sodium output.
 * The derived key is zeroed from memory via sodium_memzero() after use.
 *
 * @since 1.0.0
 *
 * @param string $plaintext The value to encrypt.
 *
 * @return string Base64-encoded encrypted blob, or empty string on failure.
 */
function wp_claw_encrypt( string $plaintext ): string {
	if ( empty( $plaintext ) ) {
		return '';
	}

	// Derive a 32-byte key from the site's auth salt.
	$key = hash( 'sha256', wp_salt( 'auth' ), true );

	if ( function_exists( 'sodium_crypto_secretbox' ) ) {
		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			sodium_memzero( $key );

			return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( Exception $e ) {
			sodium_memzero( $key );
			wp_claw_log_error( 'Sodium encrypt failed, attempting OpenSSL fallback.', [ 'error' => $e->getMessage() ] );
		}
	} else {
		wp_claw_log_warning( 'libsodium unavailable — falling back to AES-256-CBC for encryption.' );
	}

	// OpenSSL AES-256-CBC fallback.
	if ( function_exists( 'openssl_encrypt' ) ) {
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		// Zero the key from memory (best-effort — not a sodium-guaranteed wipe).
		$key = str_repeat( "\0", strlen( $key ) );
		unset( $key );

		if ( false === $ciphertext ) {
			wp_claw_log_error( 'OpenSSL encrypt failed.' );
			return '';
		}

		return 'ssl:' . base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	wp_claw_log_error( 'Neither libsodium nor OpenSSL is available — cannot encrypt.' );
	return '';
}

/**
 * Decrypt a ciphertext blob produced by wp_claw_encrypt().
 *
 * Detects sodium vs. OpenSSL output by the 'ssl:' prefix.
 * Returns an empty string on any failure — never throws.
 * The derived key is zeroed from memory after use.
 *
 * @since 1.0.0
 *
 * @param string $ciphertext Base64-encoded encrypted blob as returned by wp_claw_encrypt().
 *
 * @return string Decrypted plaintext, or empty string on failure.
 */
function wp_claw_decrypt( string $ciphertext ): string {
	if ( empty( $ciphertext ) ) {
		return '';
	}

	// Derive the same 32-byte key.
	$key = hash( 'sha256', wp_salt( 'auth' ), true );

	// OpenSSL fallback path — blob was produced by the fallback branch.
	if ( str_starts_with( $ciphertext, 'ssl:' ) ) {
		$blob = base64_decode( substr( $ciphertext, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $blob || strlen( $blob ) < 17 ) {
			$key = str_repeat( "\0", strlen( $key ) );
			unset( $key );
			wp_claw_log_error( 'OpenSSL decrypt: invalid blob.' );
			return '';
		}

		$iv         = substr( $blob, 0, 16 );
		$encrypted  = substr( $blob, 16 );
		$plaintext  = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		$key = str_repeat( "\0", strlen( $key ) );
		unset( $key );

		if ( false === $plaintext ) {
			wp_claw_log_error( 'OpenSSL decrypt failed.' );
			return '';
		}

		return $plaintext;
	}

	// Sodium primary path.
	if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
		$key = str_repeat( "\0", strlen( $key ) );
		unset( $key );
		wp_claw_log_error( 'libsodium unavailable — cannot decrypt sodium-encrypted value.' );
		return '';
	}

	$blob = base64_decode( $ciphertext, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

	if ( false === $blob || strlen( $blob ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1 ) {
		sodium_memzero( $key );
		wp_claw_log_error( 'Sodium decrypt: invalid blob.' );
		return '';
	}

	$nonce     = substr( $blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$encrypted = substr( $blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

	try {
		$plaintext = sodium_crypto_secretbox_open( $encrypted, $nonce, $key );
		sodium_memzero( $key );

		if ( false === $plaintext ) {
			wp_claw_log_error( 'Sodium decrypt: authentication failed (tampered or wrong key).' );
			return '';
		}

		return $plaintext;
	} catch ( Exception $e ) {
		sodium_memzero( $key );
		wp_claw_log_error( 'Sodium decrypt threw exception.', [ 'error' => $e->getMessage() ] );
		return '';
	}
}
