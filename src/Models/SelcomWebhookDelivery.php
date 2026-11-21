<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored webhook delivery: the body with personal data redacted, the headers minus
 * Authorization and Digest, the verdict and the time received. `dedupe_key` is
 * "order_id|transid" and carries a unique index, so a second delivery cannot be stored twice.
 *
 * @property int $id
 * @property string|null $dedupe_key
 * @property string|null $order_id
 * @property string|null $transid
 * @property string $verdict
 * @property string|null $payment_status
 * @property string $body
 * @property array<string, mixed>|null $headers
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 */
class SelcomWebhookDelivery extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        $table = config('selcom.tables.webhook_deliveries');

        return is_string($table) && $table !== '' ? $table : 'selcom_webhook_deliveries';
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
            'headers' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
