<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Logging;

use Psr\Log\LoggerInterface;
use SelcomDevelopers\Selcom\Exception\TransportException;
use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Transport\Transport;
use SelcomDevelopers\Selcom\Transport\TransportRequest;
use SelcomDevelopers\Selcom\Transport\TransportResponse;

/**
 * Wraps any Transport and logs each call through a PSR-3 logger (a Laravel logging channel),
 * passing everything through the selcom-php redactor first: secrets, PIN, Authorization and
 * Digest are masked, and so are phone numbers, e-mails and names.
 */
final class LoggingTransport implements Transport
{
    public function __construct(
        private readonly Transport $inner,
        private readonly LoggerInterface $logger,
        private readonly Redactor $redactor,
        private readonly string $level = 'info',
    ) {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $started = hrtime(true);
        try {
            $response = $this->inner->send($request);
        } catch (TransportException $e) {
            $this->logger->log($this->level, 'selcom.transport_error', $this->requestContext($request, $started) + [
                'failure' => $e->failure->value,
            ]);

            throw $e;
        }

        $this->logger->log($this->level, 'selcom.response', $this->requestContext($request, $started) + [
            'http_status' => $response->status,
            'response' => $this->redactor->redactJson($response->body),
        ]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(TransportRequest $request, int $started): array
    {
        $url = $request->url;
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $url = substr($url, 0, strlen($url) - strlen($query)) . $this->redactor->redactQuery($query);
        }

        return [
            'method' => $request->method,
            'url' => $url,
            'headers' => $this->redactor->redactHeaders($request->headers),
            'body' => $request->body === null ? null : $this->redactor->redactJson($request->body),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ];
    }
}
