<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Testing;

use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\Transport\TransportRequest;

/**
 * A request the fake transport received, for assertions. Secrets are masked in `headers`.
 */
final class SentRequest
{
    /**
     * @param array<string, mixed> $params the decoded query string (GET, DELETE) or JSON body (POST)
     * @param array<string, mixed> $headers Authorization and Digest masked
     */
    public function __construct(
        public readonly Operation $operation,
        public readonly string $method,
        public readonly string $url,
        public readonly array $params,
        public readonly array $headers,
        public readonly TransportRequest $request,
    ) {
    }

    public function param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }

    public static function from(TransportRequest $request): self
    {
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        $operation = null;
        foreach (Operation::cases() as $candidate) {
            if ($candidate->method() === $request->method && $candidate->path() === $path) {
                $operation = $candidate;
                break;
            }
        }
        if ($operation === null) {
            throw new \LogicException('The request ' . $request->method . ' ' . $path . ' matches no Selcom operation.');
        }

        $params = [];
        if ($request->body !== null) {
            $decoded = json_decode($request->body, true);
            $params = is_array($decoded) ? $decoded : [];
        } else {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $params);
        }

        /** @var array<string, mixed> $params */
        return new self($operation, $request->method, $request->url, $params, $request->redactedHeaders(), $request);
    }
}
