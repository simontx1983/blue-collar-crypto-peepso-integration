<?php

namespace BCC\PeepSo\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract domain base for all page type models.
 */
abstract class AbstractPageType
{
    /**
     * Dashboard-visible health counter for contract-violation alerts.
     *
     * Stored as bucketed hourly counters keyed by `{PREFIX}:{YYYYMMDDHH}` (UTC).
     * Summing 24 consecutive buckets gives a true rolling-24h count that is
     * immune to TTL expiry "forgetting" on low-traffic systems — each bucket
     * is independent and the health getter reconstructs the window.
     *
     * Keys are versioned (`:v1`) so semantics can be rotated later without
     * colliding with stale readings. Group (`bcc_core`) is shared across the
     * plugin suite so every contract-violation counter lives in one namespace.
     *
     * Constants are public so ops tooling / the health endpoint can reference
     * the exact key shape without guessing at internals.
     */
    public const HEALTH_CACHE_GROUP      = 'bcc_core';
    public const HEALTH_COUNTER_PREFIX   = 'bcc:domain_contract_violation:v1';
    public const HEALTH_LAST_SEEN_KEY    = 'bcc:domain_contract_violation:v1:last_seen';
    /** Individual bucket TTL: 24h window + 1h slack for edge reads. */
    private const HEALTH_BUCKET_TTL      = 90000; // 25h
    /** last_seen survives longer so ops can distinguish "old blip" from "never". */
    private const HEALTH_LAST_SEEN_TTL   = 604800; // 7d
    /** Size of the rolling-count window in hours. */
    private const HEALTH_WINDOW_HOURS    = 24;

    /**
     * Cache-poisoning integrity caps.
     *
     * MAX_PER_BUCKET: upper bound of plausible contract violations per hour.
     * Prevents cache poisoning from inflating alerts while staying well
     * above any realistic traffic peak (a genuine bad-deploy incident would
     * plateau at this value and still page — the alert remains meaningful).
     * Do not lower without surveying historical peaks; do not raise without
     * confirming that MAX_COUNT_24H stays within a safe int range.
     *
     * MAX_COUNT_24H = MAX_PER_BUCKET * HEALTH_WINDOW_HOURS. Guards the
     * summed total against overflow on 32-bit systems and caps the worst-
     * case alert value so a fully-poisoned cache can't produce nonsense.
     */
    private const MAX_PER_BUCKET = 10000;
    private const MAX_COUNT_24H  = self::MAX_PER_BUCKET * self::HEALTH_WINDOW_HOURS;

    /**
     * In the fallback read path (no wp_cache_get_multiple), stop after this
     * many consecutive missing buckets to avoid paying 24 per-key RTTs on
     * a quiet system. Threshold is high enough that short gaps inside an
     * active window don't truncate valid data.
     */
    private const FALLBACK_MAX_MISS_STREAK = 8;

    /**
     * Machine-stable error codes for alerting systems. Keyed on regardless
     * of whether the human-readable log message later evolves.
     */
    public const CODE_MISSING_IS_VALID_FIELD = 'MISSING_IS_VALID_FIELD';
    public const CODE_NOT_LOADABLE_CLASS     = 'NOT_LOADABLE_CLASS';

    /** Upper bound for user-influenced strings that land in logs. */
    private const LOG_FIELD_MAX   = 128;
    private const LOG_DOMAIN_MAX  = 256;
    private const LOG_REASON_MAX  = 128;
    private const LOG_CALLER_MAX  = 256;

    /** Upper bound for the raw X-Request-ID header before sanitisation. */
    private const REQUEST_ID_RAW_MAX = 256;
    private const REQUEST_ID_MAX     = 64;

    /** Per-request correlation ID (memoised so multiple violations share one ID). */
    private static ?string $requestId = null;

    /**
     * Per-request dedup of already-logged violations. Key is "domain|field",
     * value is bool. Prevents log storms within a loop while preserving
     * cross-request volume — which IS the signal for a bad deploy.
     *
     * @var array<string, bool>
     */
    private static array $seenViolations = [];

    /**
     * Runtime contract check for a resolved domain class.
     *
     * On success returns `true` — callers can safely invoke static methods on
     * `$domain` without fear of fatal errors.
     *
     * On failure: logs a tagged error with full context (request correlation
     * ID, domain class, post, field, caller) and bumps a health counter that
     * the trust dashboard can surface for alerting — then returns `false` so
     * the caller can issue a clean rejection to the user.
     *
     * Deliberately fail-SOFT at runtime (no Error fatal) + fail-LOUD in ops
     * (tagged log + counter spike on bad deploys).
     *
     * @param mixed $domain The value returned from get_domain_for_post().
     */
    public static function assertContract($domain, int $post_id, string $field, string $caller): bool
    {
        // Defensive type/class guard — in our current design $domain is either
        // a class-string<AbstractPageType> or null, but if a future refactor
        // pipes something else through, this stops a fatal at the gate.
        //
        // Fast path first: `class_exists($domain, false)` skips autoload and
        // returns true only for already-declared classes (hot path in our
        // codebase — domain classes are loaded by Composer at boot). Only
        // fall back to the autoloading check if the class isn't resident yet.
        //
        // Both calls are wrapped so a broken autoloader (rare, but happens
        // in custom CI with partial PSR-4 maps) cannot morph the contract
        // check into an uncaught throw of a different failure class.
        try {
            $isLoadableClassString = is_string($domain)
                && (class_exists($domain, false) || class_exists($domain));
        } catch (\Throwable $e) {
            $isLoadableClassString = false;
        }

        if (!$isLoadableClassString) {
            self::logContractViolation(
                self::CODE_NOT_LOADABLE_CLASS,
                'domain is not a loadable class-string',
                is_scalar($domain) ? (string) $domain : gettype($domain),
                $post_id,
                $field,
                $caller
            );
            return false;
        }

        /** @var string $domain — narrowed by the try above. */
        if (!method_exists($domain, 'is_valid_field')) {
            self::logContractViolation(
                self::CODE_MISSING_IS_VALID_FIELD,
                'is_valid_field missing',
                $domain,
                $post_id,
                $field,
                $caller
            );
            return false;
        }
        return true;
    }

    /**
     * Health snapshot for the trust dashboard / ops tooling.
     *
     * - `count_24h`    — rolling 24-hour count, reconstructed by summing the
     *                    last 24 hourly buckets. Immune to single-key TTL
     *                    expiry under low traffic because each bucket has
     *                    its own lifetime and reads skip missing buckets.
     * - `rate_per_min` — `count_24h / 1440`, precomputed so alert rules can
     *                    threshold directly without re-doing the math.
     *                    Float; 0.0 when the counter is empty.
     * - `last_seen`    — unix timestamp of the most recent violation, or
     *                    `null` if no violation has been recorded within
     *                    the last 7 days (the TTL on the last_seen key).
     *
     * Uses `wp_cache_get_multiple` (when available) to batch all 24 bucket
     * reads into a single object-cache round-trip — keeps the endpoint cheap
     * on remote backends (Redis, Memcached) where per-key RTT dominates.
     *
     * Each bucket value is type-validated on read: only genuine ints or
     * digit-strings contribute to the sum. A poisoned cache entry cannot
     * inflate the count (it's silently treated as 0).
     *
     * @return array{count_24h: int, rate_per_min: float, last_seen: int|null}
     */
    public static function getContractViolationHealth(): array
    {
        $empty = ['count_24h' => 0, 'rate_per_min' => 0.0, 'last_seen' => null];
        if (!function_exists('wp_cache_get')) {
            return $empty;
        }

        $now = time();

        if (function_exists('wp_cache_get_multiple')) {
            // Single-RTT path: read all 24 buckets in one call.
            $keys = [];
            for ($i = 0; $i < self::HEALTH_WINDOW_HOURS; $i++) {
                $keys[] = self::bucketKey($now - ($i * 3600));
            }
            $raw    = wp_cache_get_multiple($keys, self::HEALTH_CACHE_GROUP);
            $values = is_array($raw) ? array_values($raw) : [];

            $total = 0;
            foreach ($values as $v) {
                $total += self::normaliseBucketValue($v);
            }
        } else {
            // Per-key fallback. Walk newest → oldest and break early ONLY
            // when the window is still empty AND we've seen a miss streak —
            // i.e. the cache has clearly not been populated in the recent
            // past. Once we see any hit, we always finish the full 24-scan
            // so a quiet tail after an earlier spike is never truncated.
            $total        = 0;
            $missStreak   = 0;
            for ($i = 0; $i < self::HEALTH_WINDOW_HOURS; $i++) {
                $v = wp_cache_get(self::bucketKey($now - ($i * 3600)), self::HEALTH_CACHE_GROUP);
                if ($v === false) {
                    $missStreak++;
                    if ($missStreak >= self::FALLBACK_MAX_MISS_STREAK && $total === 0) {
                        break;
                    }
                    continue;
                }
                $missStreak = 0;
                $total += self::normaliseBucketValue($v);
            }
        }

        // Overall-sum cap guards against multi-bucket poisoning and any
        // unforeseen overflow edge. Per-bucket caps already bound each
        // contribution; this is the belt on top of the suspenders.
        if ($total > self::MAX_COUNT_24H) {
            $total = self::MAX_COUNT_24H;
        }

        // last_seen: type-validate AND bound to the present. A poisoned
        // cache entry with a future timestamp would otherwise produce
        // nonsense "future events" on dashboards — clamp to null so the
        // output is always "real past time or no history".
        $lastSeenRaw = wp_cache_get(self::HEALTH_LAST_SEEN_KEY, self::HEALTH_CACHE_GROUP);
        $lastSeen    = null;
        if (is_int($lastSeenRaw)) {
            $lastSeen = $lastSeenRaw;
        } elseif (is_string($lastSeenRaw) && $lastSeenRaw !== '' && ctype_digit($lastSeenRaw)) {
            $lastSeen = (int) $lastSeenRaw;
        }
        if ($lastSeen !== null && $lastSeen > $now) {
            $lastSeen = null;
        }

        return [
            'count_24h'    => $total,
            'rate_per_min' => $total / 1440.0, // 24h * 60m = 1440 minutes
            'last_seen'    => $lastSeen,
        ];
    }

    /**
     * Validate + clamp a single raw bucket value read from the cache.
     *
     * Accepts:
     *   - plain int (primary case)
     *   - non-empty digit-only string (Memcached-style serialisation)
     *   - finite, non-negative float (some drop-ins coerce 42 → 42.0 — we
     *     tolerate that rather than silently dropping valid data)
     *
     * Anything else (object, array, signed numeric, INF, NAN, negative,
     * empty string) is treated as 0. Every accepted value is clamped to
     * MAX_PER_BUCKET so a single poisoned entry cannot inflate the sum.
     *
     * @param mixed $v
     */
    private static function normaliseBucketValue($v): int
    {
        if (is_int($v)) {
            return $v > self::MAX_PER_BUCKET ? self::MAX_PER_BUCKET : max(0, $v);
        }
        if (is_string($v) && $v !== '' && ctype_digit($v)) {
            $n = (int) $v;
            return $n > self::MAX_PER_BUCKET ? self::MAX_PER_BUCKET : $n;
        }
        if (is_float($v) && is_finite($v) && $v > 0) {
            $n = (int) $v;
            return $n > self::MAX_PER_BUCKET ? self::MAX_PER_BUCKET : $n;
        }
        return 0;
    }

    /**
     * Build the per-hour bucket cache key for a given unix timestamp.
     *
     * UTC-deterministic by construction: `gmdate('YmdH', $ts)` derives the
     * hour strictly from `$ts` and UTC, with no local-time-zone influence.
     * Multi-node fleets bucket identically even under modest clock drift —
     * a node whose clock is 5 minutes fast still lands in the same UTC hour
     * as its peers, and the summation window (24 buckets) absorbs any
     * off-by-one hour edge from a node that drifts across an hour boundary.
     */
    private static function bucketKey(int $timestamp): string
    {
        return self::HEALTH_COUNTER_PREFIX . ':' . gmdate('YmdH', $timestamp);
    }

    /**
     * Tagged error log + health counter increment. Centralised so every call
     * site emits the same log prefix, the same context shape, the same
     * machine-stable code, and the same counter — trivially grep-able and
     * dashboard-friendly.
     *
     * Per-request dedup (keyed by lowercased domain|field) prevents log
     * storms if a loop re-hits the same bad contract; inter-request volume
     * accumulates normally since the dedup state is request-scoped. Keys
     * are lowercased for dedup ONLY — the log still carries the original
     * (truncated) values so ops see exactly what came in.
     */
    private static function logContractViolation(
        string $code,
        string $reason,
        string $domainLabel,
        int $post_id,
        string $field,
        string $caller
    ): void {
        // Cap user-influenced strings before they hit logs or dedup keys.
        // Logs stay predictable; downstream parsers don't have to defend
        // against megabyte field names from adversarial input.
        $reason      = substr($reason, 0, self::LOG_REASON_MAX);
        $domainLabel = substr($domainLabel, 0, self::LOG_DOMAIN_MAX);
        $field       = substr($field, 0, self::LOG_FIELD_MAX);
        $caller      = substr($caller, 0, self::LOG_CALLER_MAX);

        // Normalise dedup key: PHP class names are case-insensitive, and
        // a slip like "Field" vs "field" from the caller shouldn't split
        // what is semantically the same violation within a request.
        $keySig = strtolower($domainLabel) . '|' . strtolower($field);
        if (isset(self::$seenViolations[$keySig])) {
            return;
        }
        self::$seenViolations[$keySig] = true;

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[BCC-DOMAIN-CONTRACT] ' . $reason, [
                'code'         => $code,
                'request_id'   => self::requestId(),
                'domain_class' => $domainLabel,
                'post_id'      => $post_id,
                'field'        => $field,
                'caller'       => $caller,
            ]);
        }

        // Atomic bucketed counter: each hour has its own key with its own
        // TTL, so the rolling-24h window is reconstructed by summation and
        // cannot "forget" history due to a single-key TTL expiry during a
        // low-traffic lull. wp_cache_add is a no-op if the key already
        // exists, eliminating the first-hit doubling race.
        if (function_exists('wp_cache_add') && function_exists('wp_cache_incr') && function_exists('wp_cache_set')) {
            $now       = time();
            $bucketKey = self::bucketKey($now);
            wp_cache_add($bucketKey, 0, self::HEALTH_CACHE_GROUP, self::HEALTH_BUCKET_TTL);
            wp_cache_incr($bucketKey, 1, self::HEALTH_CACHE_GROUP);

            // last_seen is a simple set (not a counter) and has its own
            // longer TTL so "we had a blip 3 days ago but are clean now"
            // is distinguishable from "nothing has happened in weeks".
            //
            // Monotonic-with-upper-bound write:
            //   - If a peer with a faster clock already recorded a later
            //     timestamp, we refuse to regress it (clock-skew safety).
            //   - BUT if the cached prev is in the future (cache poisoning
            //     or a rogue node with a badly-wrong clock), we ignore it
            //     and overwrite with `now`. This prevents a bad value from
            //     permanently latching last_seen into the future.
            $prev = wp_cache_get(self::HEALTH_LAST_SEEN_KEY, self::HEALTH_CACHE_GROUP);
            $prevInt = null;
            if (is_int($prev)) {
                $prevInt = $prev;
            } elseif (is_string($prev) && $prev !== '' && ctype_digit($prev)) {
                $prevInt = (int) $prev;
            }
            $trustedPrev = ($prevInt !== null && $prevInt <= $now) ? $prevInt : null;
            if ($trustedPrev === null || $now >= $trustedPrev) {
                wp_cache_set(self::HEALTH_LAST_SEEN_KEY, $now, self::HEALTH_CACHE_GROUP, self::HEALTH_LAST_SEEN_TTL);
            }
        }
    }

    /**
     * Return a stable correlation ID for the current request.
     *
     * Prefers an upstream-supplied X-Request-ID header (CDN / reverse proxy)
     * so ops can correlate the log entry with upstream traces. Falls back to
     * 8 hex chars from random_bytes; on the vanishingly-rare CSPRNG failure
     * falls back further to an mt_rand-prefixed label rather than throwing.
     *
     * The header is hard-bounded BEFORE sanitisation (raw max), sanitised
     * (charset-restricted), then re-checked (empty-after-sanitise or
     * too-long falls back to a generated ID). This prevents an attacker-
     * controlled header from producing an empty or pathological ID that
     * could enable log-line forgery or cross-request ID reuse.
     */
    private static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }

        $raw = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if (is_string($raw) && $raw !== '' && strlen($raw) <= self::REQUEST_ID_RAW_MAX) {
            $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', $raw);
            if (is_string($clean) && $clean !== '' && strlen($clean) <= self::REQUEST_ID_MAX) {
                return self::$requestId = $clean;
            }
        }

        try {
            return self::$requestId = bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            return self::$requestId = 'rnd-' . (string) mt_rand();
        }
    }


    /* ======================================================
       REQUIRED OVERRIDES
    ====================================================== */

    abstract public static function post_type(): string;

    /** @return array<int, string> */
    abstract public static function fields(): array;

    /** @return array<int, string> */
    public static function repeater_subfields(string $repeater): array
    {
        return [];
    }

    /* ======================================================
       VALIDATION
    ====================================================== */

    public static function is_valid_field(string $field): bool
    {
        return in_array($field, static::fields(), true);
    }

    public static function is_valid_subfield(string $repeater, string $sub): bool
    {
        return in_array($sub, static::repeater_subfields($repeater), true);
    }

    /* ======================================================
       ID RESOLUTION
    ====================================================== */

    public static function get_id_from_page(int $page_id): int
    {
        if (!$page_id) return 0;

        $meta_key = '_linked_' . static::post_type() . '_id';

        $linked = (int) get_post_meta($page_id, $meta_key, true);
        if ($linked && get_post($linked)) {
            return $linked;
        }

        $found = get_posts([
            'post_type'      => static::post_type(),
            'meta_key'       => '_peepso_page_id',
            'meta_value'     => (string) $page_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true
        ]);

        if (!empty($found)) {
            return (int) $found[0];
        }

        return 0;
    }

    public static function create_from_page(int $page_id): int
    {
        if (!$page_id) return 0;

        $page = get_post($page_id);
        if (!$page) return 0;

        $id = wp_insert_post([
            'post_type'   => static::post_type(),
            'post_title'  => $page->post_title,
            'post_status' => 'publish',
            'post_author' => (int) $page->post_author
        ]);

        if (!$id) {
            return 0;
        }

        update_post_meta($id, '_peepso_page_id', $page_id);
        update_post_meta($page_id, '_linked_' . static::post_type() . '_id', $id);

        update_post_meta($id, '_bcc_visibility', 'public');

        return (int) $id;
    }

    /* ======================================================
       DOMAIN CLASS RESOLVER
    ====================================================== */

    /** @var array<string, class-string<AbstractPageType>> Maps post type slug to domain class. */
    private static array $domain_map = [
        'validators' => \BCC\PeepSo\Domain\ValidatorPageType::class,
        'nft'        => \BCC\PeepSo\Domain\NftPageType::class,
        'builder'    => \BCC\PeepSo\Domain\BuilderPageType::class,
        'dao'        => \BCC\PeepSo\Domain\DaoPageType::class,
    ];

    /**
     * @return class-string<AbstractPageType>|null
     */
    public static function resolve(string $post_type): ?string
    {
        return self::$domain_map[$post_type] ?? null;
    }

    /**
     * @return class-string<AbstractPageType>|null
     */
    public static function get_domain_for_post(int $post_id): ?string
    {
        $type = get_post_type($post_id);
        if (!$type) return null;
        $class = self::resolve($type);
        return ($class && class_exists($class)) ? $class : null;
    }

    public static function create_from_page_by_type(int $page_id, string $post_type): int
    {
        $class = self::resolve($post_type);
        if (!$class || !class_exists($class)) {
            return 0;
        }
        return $class::create_from_page($page_id);
    }

}
