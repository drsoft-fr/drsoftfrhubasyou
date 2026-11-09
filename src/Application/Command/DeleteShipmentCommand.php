<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Application\Command;

use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentId;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Deletes a parcel (wrong content): its external reference becomes free again.
 * The e-mail already sent and the order state are not undone.
 */
final class DeleteShipmentCommand
{
    public readonly ShipmentId $shipmentId;

    public function __construct(int $shipmentId)
    {
        $this->shipmentId = new ShipmentId($shipmentId);
    }
}
