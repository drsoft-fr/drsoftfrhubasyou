<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\CommandHandler;

use DrSoftFr\Module\HubAsYou\Application\Command\SendDeliveredMailCommand;
use DrSoftFr\Module\HubAsYou\Application\Service\ShipmentEffects;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ShipmentJournal;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

#[AsCommandHandler]
final class SendDeliveredMailHandler
{
    public function __construct(
        private readonly ShipmentEffects $effects,
        private readonly OrderLock $lock,
        private readonly ShipmentJournal $journal,
    ) {
    }

    /**
     * With the order locked, as for a parcel: no second e-mail from a parcel saved at the same time.
     */
    public function handle(SendDeliveredMailCommand $command): void
    {
        $this->lock->synchronized($command->orderId, function () use ($command): void {
            $this->effects->sendDeliveredMailNow($command->orderId);
            $this->journal->deliveredMailSent($command->orderId);
        });
    }
}
