<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME) or the operator CLI; never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/** Minimal read-only query helper for the finance integrity report (SettlementReadModel::integrity; SELECT only). */
final class Reader
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $sql, array $params = []): array
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \LogicException('read-only');
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
