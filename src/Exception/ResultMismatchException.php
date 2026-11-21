<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Exception;

use SelcomDevelopers\Selcom\Exception\SelcomException;

/**
 * A gateway result was offered for a record it does not belong to: the wrong operation for that
 * record (a payment answer for an order, a query for another kind of payout), or another ID.
 * Nothing was changed.
 */
final class ResultMismatchException extends \InvalidArgumentException implements SelcomException
{
}
