<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Events;

use Illuminate\Database\Eloquent\Model;
use SelcomDevelopers\Selcom\Status;

/**
 * A result contradicted a final state (a FAILED after a SUCCESS, or the reverse). The final
 * state was kept; the record is flagged `needs_review` for a person to reconcile.
 */
final class ConflictingResultFlagged
{
    public function __construct(
        public readonly Model $record,
        public readonly Status $incoming,
        public readonly string $reason,
    ) {
    }
}
