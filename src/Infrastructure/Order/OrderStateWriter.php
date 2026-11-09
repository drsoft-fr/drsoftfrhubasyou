<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Order;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Persistence\DatabaseError;
use Order;
use OrderHistory;
use PrestaShop\PrestaShop\Core\Context\EmployeeContext;
use RuntimeException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Changes the state of an order the way the back office does (history, payment,
 * invoice, e-mail of the state if it has one). The history names the employee when the
 * change comes from the back office (parcel edited, refund), as PrestaShop does; 0 from
 * the Admin API.
 */
class OrderStateWriter
{
    /**
     * @param EmployeeContext|null $employeeContext Employee of the request, given by the service
     *                                              container (none for the Admin API)
     * @param int $employeeId Employee given by a caller built outside the service container
     *                        (refund hook), used when there is no EmployeeContext
     */
    public function __construct(
        private readonly ?EmployeeContext $employeeContext = null,
        private readonly int $employeeId = 0,
    ) {
    }

    /**
     * @throws ShipmentException The history could not be saved (in the logs)
     * @throws RuntimeException The e-mail of the state was not sent
     */
    public function change(int $orderId, int $stateId): void
    {
        $order = new Order($orderId);

        if ((int) $order->getCurrentState() === $stateId) {
            return;
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_employee = $this->employeeContext
            ? (int) $this->employeeContext->getEmployee()?->getId()
            : $this->employeeId;
        $history->changeIdOrderState($stateId, $order, true);

        // What addWithemail() does, in two steps so that each failure says what it is.
        // PrestaShop has already changed the order itself: the line of the history is what is missing.
        DatabaseError::write(
            sprintf('The history of order #%d could not be saved for state %d.', $orderId, $stateId),
            static fn (): bool => (bool) $history->add(true)
        );
        Order::cleanHistoryCache();

        if (!$history->sendEmail($order)) {
            throw new RuntimeException(sprintf('Order #%d is in state %d, but the e-mail of this state was not sent.', $orderId, $stateId));
        }
    }
}
