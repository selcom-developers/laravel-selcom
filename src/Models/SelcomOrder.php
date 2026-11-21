<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A checkout order. Off unless you publish the migrations.
 *
 * States: pending (not final), success (final), failed (final, only after a cancel the gateway
 * confirmed), not_found (the gateway has no record; not final). Move between them only through
 * Services\FinalStateGuard, which compares-and-sets and never lets a later result change a final state.
 *
 * @property int $id
 * @property string $order_id
 * @property int|null $amount whole units
 * @property string|null $currency
 * @property string $state
 * @property string|null $last_status the last typed Status seen (SUCCESS, PENDING, ...)
 * @property string|null $last_resultcode
 * @property string|null $payment_status
 * @property string|null $reference
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $finalized_at
 */
class SelcomOrder extends Model
{
    public const PENDING = 'pending';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const NOT_FOUND = 'not_found';

    /** @var list<string> */
    public const FINAL_STATES = [self::SUCCESS, self::FAILED];

    /** States that selcom:reconcile queries. */
    public const RECONCILABLE_STATES = [self::PENDING, self::NOT_FOUND];

    protected $guarded = ['id'];

    protected $attributes = [
        'state' => self::PENDING,
        'needs_review' => false,
    ];

    public function getTable(): string
    {
        $table = config('selcom.tables.orders');

        return is_string($table) && $table !== '' ? $table : 'selcom_orders';
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
