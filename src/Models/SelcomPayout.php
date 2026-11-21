<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payout: a utility payment or a wallet cash-in. Off unless you publish the migrations.
 *
 * Persist the row with state "submitting" BEFORE you call the gateway, with a unique transid.
 *
 * States: submitting, pending, ambiguous (not final), success (final), failed (final, only from the
 * gateway's direct answer to the payment, never from a status query alone), not_found (the gateway
 * has no record of the transid: the only state in which the same transid may be submitted again).
 *
 * @property int $id
 * @property string $transid
 * @property string $kind "utility" or "cashin"
 * @property int|null $amount whole TZS
 * @property string $state
 * @property string|null $last_status
 * @property string|null $last_resultcode
 * @property string|null $reference
 * @property string|null $receipt
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $finalized_at
 */
class SelcomPayout extends Model
{
    public const UTILITY = 'utility';
    public const CASHIN = 'cashin';

    public const SUBMITTING = 'submitting';
    public const PENDING = 'pending';
    public const AMBIGUOUS = 'ambiguous';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const NOT_FOUND = 'not_found';

    /** @var list<string> */
    public const FINAL_STATES = [self::SUCCESS, self::FAILED];

    /** States that selcom:reconcile queries. */
    public const RECONCILABLE_STATES = [self::SUBMITTING, self::PENDING, self::AMBIGUOUS];

    protected $guarded = ['id'];

    protected $attributes = [
        'state' => self::SUBMITTING,
        'needs_review' => false,
    ];

    public function getTable(): string
    {
        $table = config('selcom.tables.payouts');

        return is_string($table) && $table !== '' ? $table : 'selcom_payouts';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('selcom.database_connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'amount' => 'integer',
            'needs_review' => 'boolean',
            'meta' => 'array',
            'last_checked_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function isFinal(): bool
    {
        return in_array($this->state, self::FINAL_STATES, true);
    }
}
