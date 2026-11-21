<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use Illuminate\Database\Eloquent\Model;
use SelcomDevelopers\Selcom\Result\Result;

/**
 * selcom:reconcile queried the gateway about one in-progress record and applied the answer
 * through the final-state guard. `outcome` is one of the Guard outcomes ("applied", "recorded",
 * "flagged", "ignored").
 */
final class RecordReconciled
{
    public function __construct(
        public readonly Model $record,
        public readonly Result $result,
        public readonly string $outcome,
    ) {
    }
}
