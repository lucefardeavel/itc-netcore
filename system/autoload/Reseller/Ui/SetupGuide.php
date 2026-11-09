<?php

namespace Reseller\Ui;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * V1.0.1: the "Get started" card of the administrator dashboard after a fresh install. Each step opens the page where
 * it is done and is ticked from the stored settings and records, so the card needs no state of its own and leaves the
 * dashboard once every required step is done. Presentation only: it reads, never writes.
 */
final class SetupGuide
{
    /** Installer value of CompanyName (install/fresh/nuxr-fresh-install.sh): still the product, not the operator. */
    private const STOCK_COMPANY = ['', 'ITC NetCore', 'PHPNuxBill'];

    /**
     * @param array<string,bool> $done step key => done
     * @return list<array{key:string,label:string,hint:string,route:string,done:bool,optional:bool}> empty once every
     *         required step is done
     */
    public static function build(array $done): array
    {
        $steps = [
            ['company', 'Company name and phone', 'Shown on the portal, the receipts and the cards.', 'settings/app&open=General', false],
            ['logo', 'Company logo', 'Printed on the cards and shown on the sign-in pages.', 'settings/app&open=General', false],
            ['network', 'Router or RADIUS device', 'The device that gives the internet to the subscribers.', 'routers/list', false],
            ['plans', 'Plans and speeds','The packages you sell (speed, period and price).', 'services/hotspot', false],
            ['resale', 'Prices for resellers', 'Only if resellers sell your plans.', 'pricing/plans', true],
        ];
        $out = [];
        $open = false;
        foreach ($steps as [$key, $label, $hint, $route, $optional]) {
            $isDone = !empty($done[$key]);
            $open = $open || (!$isDone && !$optional);
            $out[] = ['key' => $key, 'label' => $label, 'hint' => $hint, 'route' => $route, 'done' => $isDone, 'optional' => $optional];
        }
        return $open ? $out : [];
    }

    /** @return array<string,bool> what this installation already has */
    public static function facts(array $config, string $uploadPath): array
    {
        $count = static function (string $table, ?string $connection = null): int {
            try {
                return (int) ($connection === null ? \ORM::for_table($table) : \ORM::for_table($table, $connection))->count();
            } catch (\Throwable $e) {
                return 0;
            }
        };
        $nas = !empty($config['radius_enable']) ? $count('nas', 'radius') : 0;
        return [
            'company' => !in_array(trim((string) ($config['CompanyName'] ?? '')), self::STOCK_COMPANY, true) && trim((string) ($config['phone'] ?? '')) !== '',
            'logo' => is_file($uploadPath . DIRECTORY_SEPARATOR . 'logo.png'),
            'network' => $count('tbl_routers') > 0 || $nas > 0,
            'plans' => $count('tbl_plans') > 0,
            'resale' => $count('tbl_reseller_plan_base_costs') > 0,
        ];
    }
}
