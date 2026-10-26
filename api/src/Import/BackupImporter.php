<?php

declare(strict_types=1);

namespace DevisCrm\Api\Import;

use DevisCrm\Api\Auth\Clock;
use DevisCrm\Api\Clients\PdoClientStore;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Exceptions\IntegrityConflictException;
use DevisCrm\Api\Orders\PdoOrderStore;

/**
 * One-time move of an app backup into the empty server database.
 *   preview(): full validation and money report, no writes;
 *   import():  the same validation again (nothing from the preview is trusted), then every
 *              row, the order counter and the import marker in one transaction.
 * Either everything is committed or nothing is; there is no partial import and no retry.
 */
final class BackupImporter
{
    public function __construct(
        private PdoImportStore $import,
        private PdoClientStore $clients,
        private PdoOrderStore $orders,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $backup
     * @return array<string, mixed>
     */
    public function preview(array $backup): array
    {
        $plan = BackupAnalyzer::analyze($backup);
        $alreadyImported = $this->import->isImported();
        $empty = $this->import->isEmpty(false);

        return [
            'valid' => $plan['errorCount'] === 0,
            'canImport' => $plan['errorCount'] === 0 && $empty && !$alreadyImported,
            'database' => ['empty' => $empty, 'alreadyImported' => $alreadyImported],
            'counts' => $plan['counts'],
            'nextOrderNumber' => $plan['nextOrderNumber'],
            'moneyChanges' => $plan['moneyChanges'],
            'errorCount' => $plan['errorCount'],
            'errors' => $plan['errors'],
        ];
    }

    /**
     * @param array<string, mixed> $backup
     * @return array<string, mixed>
     */
    public function import(array $backup): array
    {
        $plan = BackupAnalyzer::analyze($backup);
        if ($plan['errorCount'] > 0) {
            throw new ApiException(400, 'import_invalid', 'The backup did not pass validation; see the import preview');
        }
        if ($this->import->isImported()) {
            throw self::alreadyImported();
        }

        $summary = [
            'importedAt' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'counts' => $plan['counts'],
            'nextOrderNumber' => $plan['nextOrderNumber'],
            'changedOrders' => count($plan['moneyChanges']),
        ];

        try {
            $this->orders->transaction(function () use ($plan, $summary): void {
                try {
                    $this->import->markImported($summary);
                } catch (IntegrityConflictException) {
                    throw self::alreadyImported();
                }
                if (!$this->import->isEmpty(true)) {
                    throw new ApiException(409, 'database_not_empty', 'The CRM database already contains data');
                }

                foreach ($plan['clients'] as $client) {
                    $this->clients->insert($client);
                }
                foreach ($plan['orders'] as $order) {
                    $this->orders->insertOrder($order);
                }
                $this->orders->insertItems($plan['orderItems']);
                foreach ($plan['payments'] as $payment) {
                    $this->orders->insertPayment($payment);
                }
                $this->import->setNextOrderNumber($plan['nextOrderNumber']);
            });
        } catch (IntegrityConflictException) {
            throw new ApiException(409, 'import_conflict', 'The backup conflicts with data in the database');
        }

        return $summary;
    }

    private static function alreadyImported(): ApiException
    {
        return new ApiException(409, 'import_already_done', 'A backup has already been imported');
    }
}
