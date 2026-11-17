<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A real HTTP client that works a given number of times, then cannot connect.
 *
 * For what only shows when an outside service fails part-way: the receipt run issues
 * one document per volunteer through Gotenberg, and the case worth testing is the one
 * where the first few went through.
 */
final class FailingHttpClient implements HttpClientInterface
{
    private int $requests = 0;

    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly int $successesBeforeFailing,
    ) {
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (++$this->requests > $this->successesBeforeFailing) {
            throw new TransportException('Simulated outage: the service does not answer.');
        }

        return $this->inner->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->successesBeforeFailing);
    }
}
