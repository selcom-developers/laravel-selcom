<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Support;

use SelcomDevelopers\Selcom\Config;
use SelcomDevelopers\Selcom\Exception\ConfigurationException;

/**
 * Turns the published `selcom` config array into a selcom-php Config.
 *
 * The Laravel config uses `key` where selcom-php's array form uses `api_key`, and an unset
 * environment variable arrives as null or as an empty string: both mean "not set".
 */
final class ConfigFactory
{
    /**
     * @param array<string, mixed> $settings the `selcom` config array
     *
     * @throws ConfigurationException when something required is missing or invalid
     */
    public static function make(array $settings): Config
    {
        $array = [
            'environment' => self::text($settings['environment'] ?? null),
            'api_key' => self::text($settings['key'] ?? null),
            'secret' => self::text($settings['secret'] ?? null),
            'vendor' => self::text($settings['vendor'] ?? null),
            'pin' => self::text($settings['pin'] ?? null),
            'base_url' => self::text($settings['base_url'] ?? null),
            'timezone' => self::text($settings['timezone'] ?? null),
            'max_not_sent_retries' => self::integer($settings['max_not_sent_retries'] ?? 0, 'max_not_sent_retries'),
        ];

        $checkout = $settings['checkout'] ?? null;
        if (is_array($checkout)) {
            $key = self::text($checkout['key'] ?? null);
            $secret = self::text($checkout['secret'] ?? null);
            $vendor = self::text($checkout['vendor'] ?? null);
            if ($key !== null || $secret !== null) {
                // Half a pair is a mistake worth failing on, not a reason to fall back silently.
                $array['checkout'] = ['api_key' => $key, 'secret' => $secret, 'vendor' => $vendor];
            }
        }

        $timeouts = $settings['timeouts'] ?? null;
        if (is_array($timeouts)) {
            $array['timeouts'] = [];
            foreach (['query_connect', 'query_read', 'payment_connect', 'payment_read'] as $name) {
                if (isset($timeouts[$name])) {
                    $value = $timeouts[$name];
                    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                        throw new ConfigurationException('"selcom.timeouts.' . $name . '" must be a number of seconds.');
                    }
                    $array['timeouts'][$name] = (float) $value;
                }
            }
        }

        return Config::fromArray(array_filter($array, static fn (mixed $v): bool => $v !== null));
    }

    private static function text(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    private static function integer(mixed $value, string $name): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) $value;
        }

        throw new ConfigurationException('"selcom.' . $name . '" must be an integer.');
    }
}
