<?php

declare(strict_types=1);

namespace Contempt\Testing\Messaging;

use Contempt\Contracts\Messaging\CommandBus;

final class FakeCommandBus implements CommandBus
{
    /** @var list<object> */
    private array $messages = [];

    /** @param array<class-string, \Closure(object): mixed> $handlers */
    public function __construct(private readonly array $handlers = []) {}

    public function dispatch(object $command): mixed
    {
        $this->messages[] = $command;
        $handler = $this->handlers[$command::class] ?? throw new \LogicException(\sprintf(
            'Fake command bus has no behavior for %s.',
            $command::class,
        ));

        return $handler($command);
    }

    /** @return list<object> */
    public function dispatched(): array
    {
        return $this->messages;
    }
}
