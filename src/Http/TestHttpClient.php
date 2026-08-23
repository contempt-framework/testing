<?php

declare(strict_types=1);

namespace Contempt\Testing\Http;

use Contempt\Contracts\Http\HttpMethod;
use Contempt\Http\Body;
use Contempt\Http\Headers;
use Contempt\Http\Kernel\HttpKernel;
use Contempt\Http\Request;
use Contempt\Http\Response;

final class TestHttpClient
{
    /** @var list<Request> */
    private array $history = [];

    /** @param HttpKernel|\Closure(Request): Response $kernel */
    public function __construct(private readonly HttpKernel|\Closure $kernel) {}

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string|list<string>> $query
     */
    public function request(
        HttpMethod $method,
        string $path,
        array $headers = [],
        string $body = '',
        array $query = [],
        string $host = '',
        string $scheme = 'https',
    ): Response {
        $request = new Request($method, $path, new Headers($headers), Body::fromString($body), $query, $host, $scheme);
        $this->history[] = $request;

        return $this->kernel instanceof HttpKernel
            ? $this->kernel->handle($request)
            : ($this->kernel)($request);
    }

    /** @return list<Request> */
    public function requests(): array
    {
        return $this->history;
    }

    public function reset(): void
    {
        $this->history = [];
    }
}
