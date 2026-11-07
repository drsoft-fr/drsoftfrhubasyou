<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * Error of the module. Its message is for the logs and the back office: the API never
 * sends it, and answers from its own table instead (ApiPlatform\ShipmentErrorMessages).
 *
 * Used as it is, this class is a technical failure: the API answers a generic 500.
 */
class ShipmentException extends RuntimeException
{
    /**
     * @param array<string, int|array<string>> $parameters Values for the message of the API table
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly array $parameters = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}
