<?php

declare(strict_types=1);

namespace Contempt\Testing\Messaging;

use Contempt\Contracts\Messaging\Query;
use Contempt\Contracts\Messaging\QueryBus;

final class FakeQueryBus implements QueryBus
{
    /** @var list<Query<mixed>> */
    private array $messages = [];

    /** @param array<class-string, \Closure(object): mixed> $handlers */
    public function __construct(private readonly array $handlers = []) {}

    public function ask(Query $query): mixed
    {
        $this->messages[] = $query;
        $handler = $this->handlers[$query::class] ?? throw new \LogicException(\sprintf(
            'Fake query bus has no behavior for %s.',
            $query::class,
        ));

        return $handler($query);
    }

    /** @return list<Query<mixed>> */
    public function asked(): array
    {
        return $this->messages;
    }
}
