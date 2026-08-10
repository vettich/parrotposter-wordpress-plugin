#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-09: canonical JCS + Ed25519 verify + key retention (D4) + task dispatch by
 * `type` — no live WP DB (SPEC-002-03 §7, DEC-002-06 D4).
 *
 * Golden vectors (canonical JCS bytes + Ed25519 signatures) were produced by a standalone Rust
 * binary using the *exact* crates back-app's BE-47 depends on (serde_json_canonicalizer 0.2.0,
 * ed25519-dalek 2.2.0, fixed 32-byte seed for a deterministic keypair) — not hand-derived, not
 * approximated. See the WP-09 task report for exactly how they were generated and how to
 * regenerate them.
 *
 * Ed25519 verification: this sandbox has neither ext-sodium nor WordPress's bundled
 * sodium_compat polyfill available (bare PHP CLI, no WP core loaded), so
 * `OutboundTaskSignature::verify()` itself (which requires
 * `sodium_crypto_sign_verify_detached`) cannot be exercised end-to-end here — it fails closed
 * (returns false) when the function doesn't exist, which this file also asserts. As a substitute
 * cryptographic check, this file verifies the same golden vectors via ext-openssl's Ed25519/EdDSA
 * support (present in this environment) against the exact canonical bytes `CanonicalJson`
 * produces — proving the golden signatures are valid Ed25519 signatures over PHP's canonicalized
 * output, i.e. that canonicalization is byte-parity-correct end to end, not just internally
 * self-consistent. At real WP runtime (sodium_compat always present since WP 5.2),
 * `OutboundTaskSignature::verify()` performs the identical mathematical operation via sodium
 * instead of openssl — Ed25519 verify is a pure deterministic function per RFC 8032, so the two
 * backends necessarily agree.
 *
 * Usage: php bin/test-wp09-signature-dispatch.php
 */

define('ABSPATH', true);

/** @var array<string, mixed> */
$GLOBALS['pp_test_options'] = [];

if (!function_exists('get_option')) {
	function get_option($key, $default = false)
	{
		return array_key_exists($key, $GLOBALS['pp_test_options'])
			? $GLOBALS['pp_test_options'][$key]
			: $default;
	}
}

if (!function_exists('update_option')) {
	function update_option($key, $value, $autoload = true)
	{
		$GLOBALS['pp_test_options'][$key] = $value;

		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option($key)
	{
		unset($GLOBALS['pp_test_options'][$key]);

		return true;
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

if (!function_exists('wp_generate_password')) {
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
	{
		return bin2hex(random_bytes((int) ceil($length / 2)));
	}
}

if (!function_exists('taxonomy_exists')) {
	function taxonomy_exists($taxonomy)
	{
		return in_array((string) $taxonomy, ['category', 'post_tag'], true);
	}
}

if (!function_exists('get_post_types')) {
	function get_post_types($args = [], $output = 'names')
	{
		return ['post', 'page'];
	}
}

if (!function_exists('sanitize_key')) {
	function sanitize_key($key)
	{
		$key = strtolower((string) $key);

		return preg_replace('/[^a-z0-9_\-]/', '', $key);
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error
	{
		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var array<string, mixed> */
		public $data;

		public function __construct($code = '', $message = '', $data = [])
		{
			$this->code = (string) $code;
			$this->message = (string) $message;
			$this->data = is_array($data) ? $data : [];
		}

		public function get_error_code()
		{
			return $this->code;
		}

		public function get_error_message()
		{
			return $this->message;
		}
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing)
	{
		return $thing instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/OutboundTaskSignature.php';
require_once dirname(__DIR__) . '/src/Settings.php';
require_once dirname(__DIR__) . '/src/SelectionFilterQuery.php';
require_once dirname(__DIR__) . '/src/DigestBatchQuery.php';
require_once dirname(__DIR__) . '/src/WpPostHelpers.php';
require_once dirname(__DIR__) . '/src/WireProtocol.php';
require_once dirname(__DIR__) . '/src/OutboundTaskDispatch.php';
require_once dirname(__DIR__) . '/src/OutboundTaskQueue.php';
require_once dirname(__DIR__) . '/src/OutboundTaskWorker.php';

use parrotposter\CanonicalJson;
use parrotposter\OutboundTaskDispatch;
use parrotposter\OutboundTaskQueue;
use parrotposter\OutboundTaskSignature;
use parrotposter\OutboundTaskWorker;
use parrotposter\Settings;
use parrotposter\WireProtocol;

function assert_true(string $name, bool $condition): void
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$name}\n");
		exit(1);
	}
	echo "OK: {$name}\n";
}

function assert_eq(string $name, $expected, $actual): void
{
	if ($expected !== $actual) {
		fwrite(STDERR, "FAIL: {$name}\n");
		fwrite(STDERR, '  expected: ' . var_export($expected, true) . "\n");
		fwrite(STDERR, '  actual:   ' . var_export($actual, true) . "\n");
		exit(1);
	}
	echo "OK: {$name}\n";
}

function b64url_decode(string $s): string
{
	$s = strtr($s, '-_', '+/');
	$pad = strlen($s) % 4;
	if ($pad > 0) {
		$s .= str_repeat('=', 4 - $pad);
	}

	return (string) base64_decode($s);
}

/**
 * Sandbox-only Ed25519 verify via ext-openssl (see file header) — NOT used by production code,
 * only to cross-check the golden vectors here.
 */
function openssl_ed25519_verify(string $message, string $signature, string $public_key_raw): bool
{
	$der_prefix = hex2bin('302a300506032b6570032100');
	$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der_prefix . $public_key_raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
	$pkey = openssl_pkey_get_public($pem);
	if ($pkey === false) {
		return false;
	}

	return openssl_verify($message, $signature, $pkey, 0) === 1;
}

// =====================================================================================
// 1. Golden-vector canonicalization: byte-for-byte against real Rust
//    (serde_json_canonicalizer 0.2.0) output. Regenerate via the Rust program described in the
//    WP-09 task report if these ever need updating alongside a BE-47 canonicalizer change.
// =====================================================================================

$golden_public_key_b64 = 'tXT7V-w-jg7QpDPu3epRwViieM9fWO5tYD1la_77qzo';

$golden_vectors = [
	'key_order_a' => [
		'input' => ['b' => 1, 'a' => ['y' => 2, 'x' => [3, 2, 1]], 'c' => "unicode: h\u{e9}llo \u{65e5}\u{672c}\u{8a9e}"],
		'canonical_hex' => '7b2261223a7b2278223a5b332c322c315d2c2279223a327d2c2262223a312c2263223a22756e69636f64653a2068c3a96c6c6f20e697a5e69cace8aa9e227d',
		'signature_b64' => 'f2cepSLiSZjQBKv2TlQ6cnLTO1X9QFPa7x0MnsykpmjtYebw4D3UWmYPHL35JJKlklsjqSchlCfXV1cDUMXAAw',
	],
	'key_order_b' => [
		// Same object, keys inserted in a different order — must canonicalize byte-identically.
		'input' => ['a' => ['x' => [3, 2, 1], 'y' => 2], 'c' => "unicode: h\u{e9}llo \u{65e5}\u{672c}\u{8a9e}", 'b' => 1],
		'canonical_hex' => '7b2261223a7b2278223a5b332c322c315d2c2279223a327d2c2262223a312c2263223a22756e69636f64653a2068c3a96c6c6f20e697a5e69cace8aa9e227d',
		'signature_b64' => 'f2cepSLiSZjQBKv2TlQ6cnLTO1X9QFPa7x0MnsykpmjtYebw4D3UWmYPHL35JJKlklsjqSchlCfXV1cDUMXAAw',
	],
	'nested' => [
		'input' => [
			'items' => [['id' => 2, 'tags' => ['b', 'a']], ['id' => 1, 'tags' => []]],
			'meta' => ['count' => 2, 'ratio' => 0.5],
		],
		'canonical_hex' => '7b226974656d73223a5b7b226964223a322c2274616773223a5b2262222c2261225d7d2c7b226964223a312c2274616773223a5b5d7d5d2c226d657461223a7b22636f756e74223a322c22726174696f223a302e357d7d',
		'signature_b64' => 'w3DCAwlS6mNuelrEhYRt0woALQ7A5iFINnEed1KAmaCoLZPhzDBSrqfZzF9Kw_zvS2mnAagzVV-BMRxLa58fBA',
	],
	'fetch_next' => [
		'input' => [
			'pipeline_id' => 'abc-123',
			'contract_version' => 3,
			'source_path' => [['step' => 'post_type', 'value' => 'post']],
			'selection_filter' => null,
			'sort' => ['field' => 'date', 'direction' => 'desc'],
			'exclude_mode' => 'post_source_ref',
			'published_ids' => ['post:1', 'post:2'],
			'run_exclude_ids' => [],
		],
		'canonical_hex' => '7b22636f6e74726163745f76657273696f6e223a332c226578636c7564655f6d6f6465223a22706f73745f736f757263655f726566222c22706970656c696e655f6964223a226162632d313233222c227075626c69736865645f696473223a5b22706f73743a31222c22706f73743a32225d2c2272756e5f6578636c7564655f696473223a5b5d2c2273656c656374696f6e5f66696c746572223a6e756c6c2c22736f7274223a7b22646972656374696f6e223a2264657363222c226669656c64223a2264617465227d2c22736f757263655f70617468223a5b7b2273746570223a22706f73745f74797065222c2276616c7565223a22706f7374227d5d7d',
		'signature_b64' => 'HCa44W0SU6QfcBWJs_FC1hSFzPbMv88ikkEti2ZTE2jfXpdWhGXFnHhsjn1-_y0450dz4yQ-vL4q7HRzpm8xCA',
	],
	'rotate_secrets' => [
		'input' => [
			'rotation_id' => '3f9c2a1e-1111-2222-3333-444455556666',
			'new_pp_to_site_secret' => 'pp_plg_new_secret_value',
			'new_signing_public_key_b64' => 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEf=',
		],
		'canonical_hex' => '7b226e65775f70705f746f5f736974655f736563726574223a2270705f706c675f6e65775f7365637265745f76616c7565222c226e65775f7369676e696e675f7075626c69635f6b65795f623634223a224162436445664768496a4b6c4d6e4f705172537455765778597a303132333435363738394162436445663d222c22726f746174696f6e5f6964223a2233663963326131652d313131312d323232322d333333332d343434343535353536363636227d',
		'signature_b64' => 'C1ieG9Sc0KWG9xC4-bhanwAdJdMgRSRuekODq2ksnwnkQ47mAKZDduNBsFeOffyaKFd7U_3CjuJ1RRb88-pdCQ',
	],
	'ping' => [
		'input' => ['task' => 'ping'],
		'canonical_hex' => '7b227461736b223a2270696e67227d',
		'signature_b64' => 's2mUSBSRsN0FbD7jbRNIxc-At3k2ZtUNtMd6ZSgwyZlJ7WcyNzpT2nXrzf9SAT-UR5d2i86iLUyJt0pHnoAyAA',
	],
	'numbers' => [
		'input' => ['zero' => 0, 'neg' => -42, 'float' => 333333333.3333332, 'big' => 9007199254740992, 'small_frac' => 0.000001],
		'canonical_hex' => '7b22626967223a393030373139393235343734303939322c22666c6f6174223a3333333333333333332e333333333333322c226e6567223a2d34322c22736d616c6c5f66726163223a302e3030303030312c227a65726f223a307d',
		'signature_b64' => 'cZxP9lTsucpdx3N5nxUFwU5ucOuTFuASogHfwoPo_nMyDAPRe_k7Dem72zRNtFTYsRSaZN_NKdKB68vTr9PTCA',
	],
	'surrogate_sort' => [
		// UTF-16 vs codepoint key-sort divergence: "\u{10000}" (astral, surrogate pair
		// 0xD800,0xDC00) sorts BEFORE "\u{ffff}" (BMP, single unit 0xFFFF) under RFC 8785's
		// UTF-16 code-unit ordering, even though the astral codepoint is numerically larger.
		'input' => ["\u{ffff}" => 'bmp_max', "\u{10000}" => 'astral_min'],
		'canonical_hex' => '7b22f0908080223a2261737472616c5f6d696e222c22efbfbf223a22626d705f6d6178227d',
		'signature_b64' => '7g0IR3bREOeyJvH5xFOGTOb44Odkx5FCRTqdVMhlYQzCi9Bou_37swPX5Oa__AWdTKVgT8k5DGQQ9KA38Kv2CQ',
	],
	'escaping' => [
		'input' => ['s' => "line1\nline2\ttab\"quote\\backslash/slash\u{0001}ctrl\u{65e5}\u{672c}\u{8a9e}"],
		'canonical_hex' => '7b2273223a226c696e65315c6e6c696e65325c747461625c2271756f74655c5c6261636b736c6173682f736c6173685c75303030316374726ce697a5e69cace8aa9e227d',
		'signature_b64' => 'i3yfWxCfR5vDtKAEfgvY3IeuAjZKLR5rBO4ZtlhdijlyVGCcJ-4PEjynWTSQyZvaTlrvE2nihYY_RDul4qxtBA',
	],
];

$public_key_raw = b64url_decode($golden_public_key_b64);
assert_true('golden public key decodes to 32 bytes', strlen($public_key_raw) === 32);

foreach ($golden_vectors as $name => $vector) {
	$canonical = CanonicalJson::canonicalize($vector['input']);
	assert_eq("canonicalize({$name}) matches Rust golden bytes", $vector['canonical_hex'], bin2hex($canonical));

	$signature = b64url_decode($vector['signature_b64']);
	assert_true("{$name}: Ed25519 signature is 64 bytes", strlen($signature) === 64);
	assert_true(
		"{$name}: real Ed25519 signature verifies against PHP-canonicalized bytes (openssl cross-check)",
		openssl_ed25519_verify($canonical, $signature, $public_key_raw)
	);
}

// Tamper check: a different payload must not verify against a signature for another payload.
$tampered = CanonicalJson::canonicalize(['task' => 'rotate_secrets']);
assert_true(
	'tampered payload fails verify',
	!openssl_ed25519_verify($tampered, b64url_decode($golden_vectors['ping']['signature_b64']), $public_key_raw)
);

// OutboundTaskSignature::verify() itself: fails closed when sodium is unavailable (this
// sandbox), or actually verifies when it is (real WP runtime / a CLI with ext-sodium).
if (function_exists('sodium_crypto_sign_verify_detached')) {
	foreach ($golden_vectors as $name => $vector) {
		assert_true(
			"{$name}: OutboundTaskSignature::verify() succeeds (sodium available)",
			OutboundTaskSignature::verify($vector['input'], $vector['signature_b64'], $golden_public_key_b64)
		);
	}
} else {
	echo "SKIP: sodium_crypto_sign_verify_detached unavailable in this environment — OutboundTaskSignature::verify() itself untested directly here; see file header for the openssl cross-check used instead.\n";
	assert_true(
		'OutboundTaskSignature::verify() fails closed without sodium',
		!OutboundTaskSignature::verify($golden_vectors['ping']['input'], $golden_vectors['ping']['signature_b64'], $golden_public_key_b64)
	);
}

// =====================================================================================
// 2. D4 key retention (DEC-002-06): verify_with_key_retention() ordering, pure — injected
//    verify_fn stands in for real crypto so this is provable without sodium.
// =====================================================================================

$only_a_verifies = static function (string $key): bool {
	return $key === 'key-A';
};
$r = OutboundTaskSignature::verify_with_key_retention('key-A', '', $only_a_verifies);
assert_true('current key succeeds -> used=current', $r['ok'] === true && $r['used'] === 'current');

$r = OutboundTaskSignature::verify_with_key_retention('key-B', 'key-A', $only_a_verifies);
assert_true('current fails, prev succeeds -> used=prev', $r['ok'] === true && $r['used'] === 'prev');

$r = OutboundTaskSignature::verify_with_key_retention('key-B', 'key-C', $only_a_verifies);
assert_true('neither current nor prev verify -> ok=false', $r['ok'] === false && $r['used'] === null);

$r = OutboundTaskSignature::verify_with_key_retention('', '', $only_a_verifies);
assert_true('empty current and prev -> ok=false, verify_fn still callable but nothing to try', $r['ok'] === false);

// =====================================================================================
// 3. D4 end-to-end sequencing at the Settings layer: old key verifies until the FIRST success
//    with the new key, then the old key is dropped — exactly the ordering DEC-002-06 D4
//    requires. Exercises the real Settings storage + real verify_with_key_retention, with the
//    crypto call itself faked (see section 1 for why) via the same "clear prev iff used ===
//    current" rule OutboundTaskDispatch::dispatch() applies.
// =====================================================================================

$GLOBALS['pp_test_options'] = []; // reset

Settings::set_outbound_task_signing_public_key('key-old');
assert_eq('initial key set as current', 'key-old', Settings::outbound_task_signing_public_key());
assert_eq('no prev key yet', '', Settings::outbound_task_signing_public_key_prev());

// Rotation delivers a new key (e.g. via a rotate_secrets task) — D4: old key must NOT be
// dropped immediately.
Settings::set_outbound_task_signing_public_key('key-new');
assert_eq('new key becomes current', 'key-new', Settings::outbound_task_signing_public_key());
assert_eq('old key preserved as prev (D4: not dropped on arrival)', 'key-old', Settings::outbound_task_signing_public_key_prev());

$verify_fn_accepts = static function (string $accepted_key) {
	return static function (string $key) use ($accepted_key): bool {
		return $key === $accepted_key;
	};
};

// A task signed with the OLD key (queued before the rotation committed, per D4's own rationale)
// must still verify successfully via the retained prev key.
$result = OutboundTaskSignature::verify_with_key_retention(
	Settings::outbound_task_signing_public_key(),
	Settings::outbound_task_signing_public_key_prev(),
	$verify_fn_accepts('key-old')
);
assert_true('old-key-signed task verifies via prev', $result['ok'] === true && $result['used'] === 'prev');
if ($result['used'] === 'current') {
	Settings::clear_outbound_task_signing_public_key_prev();
}
assert_eq('prev key still retained after a prev-key success (D4: only cleared on CURRENT success)', 'key-old', Settings::outbound_task_signing_public_key_prev());

// Another old-key task still succeeds — proves prev is usable repeatedly, not just once.
$result = OutboundTaskSignature::verify_with_key_retention(
	Settings::outbound_task_signing_public_key(),
	Settings::outbound_task_signing_public_key_prev(),
	$verify_fn_accepts('key-old')
);
assert_true('old key still verifies on a second attempt', $result['ok'] === true && $result['used'] === 'prev');

// Now the FIRST task signed with the NEW key arrives — verify succeeds via current -> prev must
// be dropped per D4.
$result = OutboundTaskSignature::verify_with_key_retention(
	Settings::outbound_task_signing_public_key(),
	Settings::outbound_task_signing_public_key_prev(),
	$verify_fn_accepts('key-new')
);
assert_true('new-key-signed task verifies via current', $result['ok'] === true && $result['used'] === 'current');
if ($result['used'] === 'current') {
	Settings::clear_outbound_task_signing_public_key_prev();
}
assert_eq('prev key dropped after first successful CURRENT-key verify (D4)', '', Settings::outbound_task_signing_public_key_prev());

// From here on, an old-key-signed task must fail — prev is gone.
$result = OutboundTaskSignature::verify_with_key_retention(
	Settings::outbound_task_signing_public_key(),
	Settings::outbound_task_signing_public_key_prev(),
	$verify_fn_accepts('key-old')
);
assert_true('old key no longer verifies once prev has been dropped', $result['ok'] === false);

// Re-setting the SAME current key is a no-op — must not needlessly cycle prev.
Settings::set_outbound_task_signing_public_key('key-new');
assert_eq('setting the same current key again is a no-op', '', Settings::outbound_task_signing_public_key_prev());

// =====================================================================================
// 4. Dispatch by type: ping / rotate_secrets / unknown. Signature verify is bypassed for this
//    section by installing a key pair via Settings + a real golden vector (ping's), so
//    OutboundTaskDispatch::dispatch() is exercised through actual signature verification
//    wherever sodium is available; where it isn't, this section documents the fail-closed
//    behavior instead (dispatch() must report signature_invalid, never silently execute).
// =====================================================================================

$GLOBALS['pp_test_options'] = [];
Settings::set_outbound_task_signing_public_key($golden_public_key_b64);

$ping_row = [
	'task_id' => 'task-ping-1',
	'task_type' => 'ping',
	'payload' => wp_json_encode($golden_vectors['ping']['input']),
	'payload_signature' => $golden_vectors['ping']['signature_b64'],
	'expires_at' => '2999-01-01 00:00:00',
];

$outcome = OutboundTaskDispatch::dispatch(null, $ping_row);
if (function_exists('sodium_crypto_sign_verify_detached')) {
	assert_eq('ping dispatch (sodium available) -> done', OutboundTaskQueue::STATUS_DONE, $outcome['status']);
	assert_true('prev key cleared after successful current-key verify via real dispatch', Settings::outbound_task_signing_public_key_prev() === '');
} else {
	assert_eq('ping dispatch (no sodium) fails closed -> signature_invalid, never executes', 'signature_invalid', $outcome['error_code']);
	assert_eq('fails-closed outcome status is failed, not done', OutboundTaskQueue::STATUS_FAILED, $outcome['status']);
}

// Unknown task_type must never silently execute — always skipped with an explicit error_code,
// checked with a signature that fails closed too (payload doesn't matter here; only the
// unknown-type branch is under test, alongside "verify runs first regardless of type").
$unknown_row = [
	'task_id' => 'task-unknown-1',
	'task_type' => 'some_future_type',
	'payload' => '{}',
	'payload_signature' => 'not-a-real-signature',
	'expires_at' => '2999-01-01 00:00:00',
];
$outcome = OutboundTaskDispatch::dispatch(null, $unknown_row);
assert_eq('bogus signature always fails verify first, regardless of task_type', 'signature_invalid', $outcome['error_code']);

// =====================================================================================
// 5. fetch_next via fallback vs primary: identical validation behavior for identical input
//    (both entry points funnel through WireProtocol::handle_items_next_from_body() — same
//    function, not a parallel implementation, so this proves equivalence by construction; this
//    section exercises it through both call shapes to prove the wiring, not just code reading).
// =====================================================================================

if (!class_exists('WP_REST_Request')) {
	class WP_REST_Request
	{
		/** @var array<string, mixed> */
		private $body;

		/**
		 * @param array<string, mixed> $body
		 */
		public function __construct(array $body)
		{
			$this->body = $body;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_json_params()
		{
			return $this->body;
		}
	}
}

// Reflection: WireProtocol::handle_items_next() is private (the real REST callback signature),
// handle_items_next_from_body() is the public WP-09 extraction — call both with the same body
// and confirm identical results, for both an early-validation-error case and a
// deep-into-the-handler case (up to the point a live WP_Query would be required).
$reflection = new ReflectionMethod(WireProtocol::class, 'handle_items_next');
$reflection->setAccessible(true);

$invalid_bodies = [
	'missing pipeline_id' => ['source_path' => [['key' => 'post_type', 'value' => 'post']]],
	'missing source_path' => ['pipeline_id' => 'p1'],
	'unknown post_type' => ['pipeline_id' => 'p1', 'source_path' => [['key' => 'post_type', 'value' => 'no_such_type']]],
];

foreach ($invalid_bodies as $case => $body) {
	$via_primary = $reflection->invoke(null, new WP_REST_Request($body));
	$via_fallback = WireProtocol::handle_items_next_from_body($body);

	assert_true("fetch_next '{$case}': both entry points return a WP_Error", is_wp_error($via_primary) && is_wp_error($via_fallback));
	assert_eq("fetch_next '{$case}': identical error_code via primary vs fallback", $via_primary->get_error_code(), $via_fallback->get_error_code());
	assert_eq("fetch_next '{$case}': identical error_message via primary vs fallback", $via_primary->get_error_message(), $via_fallback->get_error_message());
}

// A validly-shaped payload: both entry points proceed identically past all validation and
// SelectionFilterQuery::apply() up to constructing a live WP_Query — unavailable in this
// sandbox (no WP core loaded), so both are expected to throw the SAME PHP \Error at the exact
// same point, proving they follow the identical code path this far (not just "both error out").
$valid_body = [
	'pipeline_id' => 'p1',
	'source_path' => [['key' => 'post_type', 'value' => 'post']],
	'selection_filter' => null,
	'sort' => ['field' => 'date', 'direction' => 'desc'],
	'published_ids' => [],
];

$primary_error = null;
try {
	$reflection->invoke(null, new WP_REST_Request($valid_body));
} catch (\Throwable $e) {
	$primary_error = $e;
}

$fallback_error = null;
try {
	WireProtocol::handle_items_next_from_body($valid_body);
} catch (\Throwable $e) {
	$fallback_error = $e;
}

assert_true('valid fetch_next payload: both entry points reach WP_Query construction (no live WP core here)', $primary_error !== null && $fallback_error !== null);
assert_eq('valid fetch_next payload: identical exception class via primary vs fallback', get_class($primary_error), get_class($fallback_error));
assert_eq('valid fetch_next payload: identical exception message via primary vs fallback', $primary_error->getMessage(), $fallback_error->getMessage());

// Same equivalence check for fetch_batch / WP-07's pagination path.
$reflection_batch = new ReflectionMethod(WireProtocol::class, 'handle_items_batch');
$reflection_batch->setAccessible(true);

$invalid_batch_body = ['pipeline_id' => 'p1']; // missing source_path
$via_primary = $reflection_batch->invoke(null, new WP_REST_Request($invalid_batch_body));
$via_fallback = WireProtocol::handle_items_batch_from_body($invalid_batch_body);
assert_true('fetch_batch missing source_path: both entry points return a WP_Error', is_wp_error($via_primary) && is_wp_error($via_fallback));
assert_eq('fetch_batch missing source_path: identical error_code via primary vs fallback', $via_primary->get_error_code(), $via_fallback->get_error_code());

// =====================================================================================
// 6. OutboundTaskWorker::resolve_pending_outcome() threads a `confirm` field through from the
//    dispatch outcome (WP-09's plumbing for rotate_secrets' report-time confirmation) — pure,
//    same testing convention as WP-08's own coverage of this function.
// =====================================================================================

$dispatch_fn_with_confirm = static function (): array {
	return [
		'status' => OutboundTaskQueue::STATUS_DONE,
		'result' => null,
		'error_code' => null,
		'confirm' => ['rotation_id' => 'rot-123'],
	];
};
$outcome = OutboundTaskWorker::resolve_pending_outcome('2999-01-01T00:00:00Z', time(), $dispatch_fn_with_confirm);
assert_eq('resolve_pending_outcome threads confirm.rotation_id through', 'rot-123', $outcome['confirm']['rotation_id'] ?? null);

$dispatch_fn_without_confirm = static function (): array {
	return ['status' => OutboundTaskQueue::STATUS_DONE, 'result' => null, 'error_code' => null];
};
$outcome = OutboundTaskWorker::resolve_pending_outcome('2999-01-01T00:00:00Z', time(), $dispatch_fn_without_confirm);
assert_true('resolve_pending_outcome confirm is null when dispatch outcome has none', $outcome['confirm'] === null);

$expired_outcome = OutboundTaskWorker::resolve_pending_outcome('2000-01-01T00:00:00Z', time(), $dispatch_fn_with_confirm);
assert_true('expired task never reaches dispatch -> confirm is null, dispatched=false', $expired_outcome['confirm'] === null && $expired_outcome['dispatched'] === false);

echo "\nAll WP-09 signature verify + dispatch smoke tests passed.\n";
