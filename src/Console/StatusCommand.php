<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Console;

use Illuminate\Console\Command;
use SelcomDevelopers\Selcom\Client;
use SelcomDevelopers\Selcom\Exception\SelcomException;
use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\UtilityQueryRequest;
use SelcomDevelopers\Selcom\Request\WalletCashinQueryRequest;
use SelcomDevelopers\Selcom\Result\Result;

/**
 * Ask the gateway about one order or one transaction. Read-only: it only calls the status queries.
 */
final class StatusCommand extends Command
{
    protected $signature = 'selcom:status
        {id : The order_id (type "order") or the transid (types "utility" and "cashin")}
        {--type=order : order, utility (utility payment) or cashin (wallet cash-in)}
        {--raw : Also print the raw response body, redacted}';

    protected $description = 'Query the status of a Selcom order or transaction (read-only)';

    public function handle(Client $client, Redactor $redactor): int
    {
        $id = $this->argument('id');
        $type = $this->option('type');
        if (!is_string($id) || trim($id) === '' || !is_string($type)) {
            $this->components->error('Give an id.');

            return self::INVALID;
        }

        try {
            $result = match ($type) {
                'order' => $client->orderStatus(new OrderStatusRequest($id)),
                'utility' => $client->utilityQuery(new UtilityQueryRequest($id)),
                'cashin' => $client->walletCashinQuery(new WalletCashinQueryRequest($id)),
                default => null,
            };
        } catch (TransportException $e) {
            $this->components->error('No answer from the gateway (' . $e->failure->value . '). '
                . ($e->isNotSent() ? 'The request never left; asking again is safe.' : 'The outcome of the request is unknown; ask again later.'));

            return self::FAILURE;
        } catch (SelcomException $e) {
            $this->components->error($e::class . ': ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($result === null) {
            $this->components->error('Unknown --type "' . $type . '". Use order, utility or cashin.');

            return self::INVALID;
        }

        $this->render($result, $redactor);

        return self::SUCCESS;
    }

    private function render(Result $result, Redactor $redactor): void
    {
        $this->table(['Field', 'Value'], [
            ['status', $result->status->value],
            ['final', $result->isFinal() ? 'yes' : 'no'],
            ['resultcode', $result->resultCode ?? '(none)'],
            ['result', $result->result ?? ''],
            ['message', $result->message ?? ''],
            ['reference', $result->reference ?? ''],
            ['transid', $result->transid ?? ''],
            ['order_id', $result->orderId ?? ''],
            ['http_status', (string) $result->httpStatus],
        ]);
        if ($this->option('raw') === true) {
            $this->line($redactor->redactJson($result->raw));
        }
    }
}
