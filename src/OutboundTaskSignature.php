<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Ed25519 verify of `PluginOutboundTask.payload_signature` (SPEC-002-03 §7.2, TASK-002-WP-09).
 *
 * Canonicalization: {@see CanonicalJson} (RFC 8785 JCS), the PHP port of back-app's
 * `pipeline_domain::signing::canonical_json_bytes` (TASK-002-BE-47) — byte-identical output
 * verified against real Rust-generated golden vectors (see WP-09's report).
 *
 * Key retention (DEC-002-06 D4): {@see Settings::outbound_task_signing_public_key()} /
 * {@see Settings::outbound_task_signing_public_key_prev()} hold the current and previous public
 * key. {@see verify_with_key_retention()} is the pure decision function for "try current, then
 * fall back to previous" — directly unit-tested with an injected verifier
 * (bin/test-wp09-signature-dispatch.php) so the retry/ordering logic is provable without a real
 * crypto backend. Clearing the previous key on the first successful current-key verify is the
 * *caller's* job (see {@see OutboundTaskDispatch::dispatch()}), not this class's — this class
 * only reports which key succeeded.
 */
class OutboundTaskSignature
{
	/**
	 * True Ed25519 verify: RFC 8785 JCS canonical bytes of `$payload`, base64url-decoded
	 * signature and public key, via `sodium_crypto_sign_verify_detached` — real ext-sodium, or
	 * WordPress's bundled `sodium_compat` polyfill (present since WP 5.2 regardless of the
	 * host's ext-sodium availability, so this is always callable at real WP runtime even though
	 * it can't be exercised in a bare PHP CLI without either).
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function verify(array $payload, string $signature_b64, string $public_key_b64): bool
	{
		if ($signature_b64 === '' || $public_key_b64 === '') {
			return false;
		}
		if (!function_exists('sodium_crypto_sign_verify_detached')) {
			// Should never happen at real WP runtime (sodium_compat is bundled since WP 5.2) —
			// fail closed rather than silently accept an unverifiable task.
			return false;
		}

		$signature = self::base64url_decode($signature_b64);
		$public_key = self::base64url_decode($public_key_b64);
		if ($signature === false || $public_key === false || strlen($signature) !== 64 || strlen($public_key) !== 32) {
			return false;
		}

		$canonical = CanonicalJson::canonicalize($payload);

		return sodium_crypto_sign_verify_detached($signature, $canonical, $public_key);
	}

	/**
	 * D4 (DEC-002-06): try the current key; on failure, retry the previous key. Pure — the
	 * actual crypto call is injected as `$verify_fn` so this ordering is testable without a real
	 * crypto backend.
	 *
	 * @param callable $verify_fn (string $public_key_b64): bool
	 * @return array{ok: bool, used: 'current'|'prev'|null}
	 */
	public static function verify_with_key_retention(
		string $current_key,
		string $prev_key,
		callable $verify_fn
	): array {
		if ($current_key !== '' && $verify_fn($current_key)) {
			return ['ok' => true, 'used' => 'current'];
		}
		if ($prev_key !== '' && $verify_fn($prev_key)) {
			return ['ok' => true, 'used' => 'prev'];
		}

		return ['ok' => false, 'used' => null];
	}

	/**
	 * @return string|false
	 */
	private static function base64url_decode(string $value)
	{
		$padded = strtr($value, '-_', '+/');
		$remainder = strlen($padded) % 4;
		if ($remainder > 0) {
			$padded .= str_repeat('=', 4 - $remainder);
		}

		return base64_decode($padded, true);
	}
}
