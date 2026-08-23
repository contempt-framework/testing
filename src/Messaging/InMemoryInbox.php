<?php

declare(strict_types=1);

namespace Contempt\Testing\Messaging;

use Contempt\Messaging\Delivery\ConsumerId;
use Contempt\Messaging\Delivery\InboxStore;
use Contempt\Messaging\Envelope\MessageId;

final class InMemoryInbox implements InboxStore
{
    /** @var array<string, array{id: MessageId, consumer: ConsumerId, processedAt: \DateTimeImmutable}> */
    private array $entries = [];

    public function acquire(MessageId $id, ConsumerId $consumer, \DateTimeImmutable $processedAt): bool
    {
        $key = (string) $consumer . "\0" . (string) $id;

        if (isset($this->entries[$key])) {
            return false;
        }

        $this->entries[$key] = [
            'id' => $id,
            'consumer' => $consumer,
            'processedAt' => $processedAt->setTimezone(new \DateTimeZone('UTC')),
        ];

        return true;
    }

    /** @return list<array{id: MessageId, consumer: ConsumerId, processedAt: \DateTimeImmutable}> */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
