<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Unknown parcel: HTTP 404.
 */
class ShipmentNotFoundException extends ShipmentException
{
    public static function withId(int $shipmentId): self
    {
        return new self(sprintf('Parcel %d not found.', $shipmentId), parameters: ['id' => $shipmentId]);
    }
}
