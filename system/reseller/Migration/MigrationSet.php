<?php
namespace Reseller\Migration;

// CLI-only: this file must never execute through the web server (the web root is the repo root).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** The ordered, gap-free list of project migrations shipped in the repository. */
final class MigrationSet
{
    /** @param list<MigrationDefinition> $migrations */
    private function __construct(public readonly array $migrations)
    {
    }

    public static function fromDirectory(string $dir): self
    {
        $files = glob(rtrim($dir, '/') . '/R[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);
        $list = [];
        $expected = 1;
        $previous = null;
        foreach ($files as $f) {
            $m = MigrationDefinition::load($f);
            if ($m->sequence !== $expected) {
                throw new StopException(Outcome::USAGE, "migration sequence gap or disorder at {$m->id} (expected R" . sprintf('%04d', $expected) . ')');
            }
            if ($m->requires !== $previous) {
                throw new StopException(Outcome::USAGE, "migration {$m->id} must require " . ($previous ?? 'null'));
            }
            $list[] = $m;
            $previous = $m->id;
            $expected++;
        }
        if ($list === []) {
            throw new StopException(Outcome::USAGE, "no migrations found in {$dir}");
        }
        return new self($list);
    }

    public function get(string $id): ?MigrationDefinition
    {
        foreach ($this->migrations as $m) {
            if ($m->id === $id) {
                return $m;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn(MigrationDefinition $m): string => $m->id, $this->migrations);
    }
}
