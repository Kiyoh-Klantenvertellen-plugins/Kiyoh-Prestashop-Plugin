<?php
/**
 * Retries temporary failed review invitations (cron / PrestaShop cronjobs).
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/KiyohConfigManager.php';
require_once dirname(__FILE__) . '/KiyohInvitationSupport.php';
require_once dirname(__FILE__) . '/KiyohInvitationManager.php';

class KiyohInvitationRetryRunner
{
    /**
     * @return array{checked: int, retried: int, sent: int, failed: int, skipped: int}
     */
    public static function retryDueFailedInvitations(): array
    {
        $summary = [
            'checked' => 0,
            'retried' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if (!KiyohConfigManager::isEnabled()) {
            return $summary;
        }

        try {
            $manager = new KiyohInvitationManager();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Kiyoh invitation retry skipped: ' . $e->getMessage(),
                2,
                null,
                'KiyohInvitationRetryRunner',
                null,
                true
            );

            return $summary;
        }

        $rows = self::fetchDueRows();
        foreach ($rows as $row) {
            $summary['checked']++;
            $orderId = (int) ($row['id_order'] ?? 0);
            $invitationId = (int) ($row['id_invitation'] ?? 0);
            if ($orderId <= 0 || $invitationId <= 0) {
                $summary['skipped']++;
                continue;
            }

            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                self::markPermanent($invitationId, 'Order not found');
                $summary['skipped']++;
                continue;
            }

            if ($manager->hasSuccessfulInvitation($order)) {
                $summary['skipped']++;
                continue;
            }

            $summary['retried']++;
            $result = $manager->retryFailedInvitation($invitationId, $order);
            if (!empty($result['sent'])) {
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchDueRows(): array
    {
        $now = date('Y-m-d H:i:s');
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('kiyoh_invitations');
        $sql->where('status = "failed"');
        $sql->where('failure_class = "temporary"');
        $sql->where('retry_attempts < ' . (int) KiyohInvitationSupport::MAX_RETRY_ATTEMPTS);
        $sql->where('(next_retry_at IS NULL OR next_retry_at <= "' . pSQL($now) . '")');
        $sql->orderBy('next_retry_at ASC');
        $sql->limit(25);

        $rows = Db::getInstance()->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param int $invitationId
     * @param string $reason
     * @return void
     */
    private static function markPermanent(int $invitationId, string $reason): void
    {
        Db::getInstance()->update(
            'kiyoh_invitations',
            [
                'failure_class' => pSQL('permanent'),
                'response_data' => pSQL(json_encode(['error' => $reason])),
            ],
            'id_invitation = ' . (int) $invitationId
        );
    }
}