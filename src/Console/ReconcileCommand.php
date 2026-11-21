<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use SelcomDevelopers\LaravelSelcom\Services\Reconciler;

/**
 * Query in-progress orders and payouts that have been quiet for N minutes and apply the answers
 * through the final-state guard. QUERY ONLY: it never resends a payment or any other write.
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'selcom:reconcile
        {--older-than= : Only records not touched for this many minutes (default from config, 3)}
        {--limit= : Most records per kind per run (default from config, 100)}
        {--type=all : all, orders or payouts}
        {--dry-run : List what would be queried, query nothing}';

    protected $description = 'Query in-progress Selcom orders and payouts and apply the answers (never resends a payment)';

    public function handle(Reconciler $reconciler, ConfigRepository $config): int
    {
        $type = $this->option('type');
        if (!in_array($type, ['all', 'orders', 'payouts'], true)) {
            $this->components->error('Unknown --type. Use all, orders or payouts.');

            return self::INVALID;
        }

        $older = $this->number('older-than', (int) $config->get('selcom.reconcile.older_than_minutes', 3));
        $limit = $this->number('limit', (int) $config->get('selcom.reconcile.limit', 100));
        if ($older < 0 || $limit < 1) {
            $this->components->error('--older-than must be 0 or more and --limit 1 or more.');

            return self::INVALID;
        }
        $dryRun = $this->option('dry-run') === true;

        $rows = [];
        if ($type === 'all' || $type === 'orders') {
            $rows = [...$rows, ...$reconciler->reconcileOrders($older, $limit, $dryRun)];
        }
        if ($type === 'all' || $type === 'payouts') {
            $rows = [...$rows, ...$reconciler->reconcilePayouts($older, $limit, $dryRun)];
        }

        if ($rows === []) {
            $this->components->info('Nothing in progress older than ' . $older . ' minutes.');

            return self::SUCCESS;
        }

        $this->table(['Type', 'Id', 'Status', 'Outcome', 'Error'], array_map(
            static fn (array $r): array => [$r['type'], $r['id'], $r['status'] ?? '', $r['outcome'], $r['error'] ?? ''],
            $rows,
        ));

        $errors = count(array_filter($rows, static fn (array $r): bool => $r['outcome'] === 'error'));
        if ($errors > 0) {
            $this->components->warn($errors . ' record(s) got no answer and were left as they were; the next run asks again.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function number(string $option, int $default): int
    {
        $value = $this->option($option);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : $default;
    }
}
