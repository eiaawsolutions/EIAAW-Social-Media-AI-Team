<?php

namespace App\Support\Compliance;

/**
 * Maps a brand's primary business-location country (free text the operator
 * typed, e.g. "Malaysia", "MY", "singapore") to a canonical jurisdiction key
 * used by compliance_legal_rules ('MY', 'SG', …).
 *
 * Pure + static — no DB, no model state — so it is unit-testable without a
 * database (the suite runs DB-free) and reusable by Brand::primaryJurisdiction.
 *
 * v1 scope (per plan): resolve the PRIMARY location only. Multi-country union
 * is a documented fast-follow. Unknown / empty input falls back to the app's
 * default jurisdiction (Malaysia/APAC lens).
 */
final class JurisdictionResolver
{
    /** Default when a brand has no resolvable primary location. */
    public const DEFAULT = 'MY';

    /**
     * @param  array<int, array<string, mixed>>  $locations  business_locations rows
     */
    public static function fromBusinessLocations(array $locations): string
    {
        $primary = null;
        $first = null;

        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $country = trim((string) ($loc['country'] ?? ''));
            if ($country === '') {
                continue;
            }
            $first ??= $country;
            if (! empty($loc['is_primary'])) {
                $primary = $country;
                break;
            }
        }

        $country = $primary ?? $first;

        return $country === null ? self::DEFAULT : self::fromCountry($country);
    }

    /** Map a single country string to a jurisdiction key. */
    public static function fromCountry(?string $country): string
    {
        $needle = mb_strtolower(trim((string) $country));
        if ($needle === '') {
            return self::DEFAULT;
        }

        $map = [
            'MY' => ['malaysia', 'my', 'mys'],
            'SG' => ['singapore', 'sg', 'sgp'],
            'ID' => ['indonesia', 'id', 'idn'],
            'TH' => ['thailand', 'th', 'tha'],
            'PH' => ['philippines', 'ph', 'phl'],
            'VN' => ['vietnam', 'viet nam', 'vn', 'vnm'],
            'BN' => ['brunei', 'bn', 'brn'],
            'AU' => ['australia', 'au', 'aus'],
            'GB' => ['united kingdom', 'uk', 'gb', 'britain', 'england'],
            'US' => ['united states', 'usa', 'us', 'america'],
        ];

        foreach ($map as $key => $needles) {
            foreach ($needles as $alias) {
                if ($needle === $alias || str_contains($needle, $alias)) {
                    return $key;
                }
            }
        }

        return self::DEFAULT;
    }
}
