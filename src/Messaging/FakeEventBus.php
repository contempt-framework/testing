<?php

declare(strict_types=1);

namespace Contempt\Testing\Messaging;

use Contempt\Contracts\Messaging\Event;
use Contempt\Contracts\Messaging\EventBus;

final class FakeEventBus implements EventBus
{
    /** @var list<Event> */
    private array $events = [];

    public function publish(Event $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<Event> */
    public function published(): array
    {
        return $this->events;
    }
}
