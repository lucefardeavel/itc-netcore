<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Authoritative core-schema contract: the exact canonical definitions of the legacy tables that
 * WP1 depends on (tbl_users, tbl_customers, tbl_plans), for every explicitly verified baseline
 * variant on every supported engine family.
 *
 * Data lives in variants.php, generated from the WP0-verified fixtures by
 * dev/tools/build-variant-registry.php and reviewed like any other source change.
 * There is no runtime override: an unrecognised core schema is NOT_READY.
 * Tables outside the core set (plugins, FreeRADIUS, deployment extensions) are not part of the
 * contract; they are reported but do not by themselves block migration (owner decision Q3).
 */
final class VariantRegistry
{
    public const CORE_TABLES = ['tbl_customers', 'tbl_plans', 'tbl_users'];

    /** @param array<string,array<string,array<string,array<string,mixed>>>> $variants engine => variant => table => canonical */
    private function __construct(private readonly array $variants)
    {
    }

    public static function load(string $file): self
    {
        $data = (static function (string $f) {
            return require $f;
        })($file);
        if (!is_array($data)) {
            throw new StopException(Outcome::USAGE, "variant registry {$file} must return an array");
        }
        return new self($data);
    }

    /** @return list<string> */
    public function variantIds(string $engineFamily): array
    {
        return array_keys($this->variants[$engineFamily] ?? []);
    }

    /** @return array<string,array<string,mixed>>|null */
    public function variant(string $engineFamily, string $id): ?array
    {
        return $this->variants[$engineFamily][$id] ?? null;
    }

    /**
     * @param array<string,array<string,mixed>|null> $core canonical core tables (project objects already subtracted)
     * @return array{id:?string,fingerprint:string,nearest:?string,diff:list<string>}
     */
    public function match(string $engineFamily, array $core): array
    {
        $fp = SchemaInspector::fingerprint($core);
        $nearest = null;
        $nearestDiff = null;
        foreach ($this->variants[$engineFamily] ?? [] as $id => $tables) {
            if (SchemaInspector::fingerprint($tables) === $fp) {
                return ['id' => $id, 'fingerprint' => $fp, 'nearest' => $id, 'diff' => []];
            }
            $diff = [];
            foreach (self::CORE_TABLES as $t) {
                $diff = array_merge($diff, SchemaInspector::diff($t, SchemaInspector::normalise($tables[$t] ?? null), SchemaInspector::normalise($core[$t] ?? null)));
            }
            if ($nearestDiff === null || count($diff) < count($nearestDiff)) {
                $nearest = $id;
                $nearestDiff = $diff;
            }
        }
        return ['id' => null, 'fingerprint' => $fp, 'nearest' => $nearest, 'diff' => $nearestDiff ?? ['no variants registered for engine ' . $engineFamily]];
    }
}
