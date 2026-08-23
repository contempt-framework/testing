<?php

declare(strict_types=1);

namespace Contempt\Testing\Messaging;

use Contempt\Core\Time\Duration;
use Contempt\Messaging\Delivery\DeliveryFailure;
use Contempt\Messaging\Delivery\OutboxBatch;
use Contempt\Messaging\Delivery\OutboxClaim;
use Contempt\Messaging\Delivery\OutboxMessage;
use Contempt\Messaging\Delivery\OutboxStore;
use Contempt\Messaging\Envelope\MessageId;
use Contempt\Messaging\Retry\RetryDisposition;

final class InMemoryOutbox implements OutboxStore
{
    /**
     * @var array<string, array{
     *     message: OutboxMessage,
     *     state: 'pending'|'leased'|'published'|'dead',
     *     availableAt: ?\DateTimeImmutable,
     *     leaseId: ?string,
     *     leaseUntil: ?\DateTimeImmutable,
     *     failure: ?DeliveryFailure
     * }>
     */
    private array $entries = [];

    private int $leaseSequence = 0;

    public function append(OutboxMessage $message): void
    {
        $id = (string) $message->id();

        if (isset($this->entries[$id])) {
            throw new \LogicException(\sprintf('Outbox message %s was appended twice.', $id));
        }

        $this->entries[$id] = [
            'message' => $message,
            'state' => 'pending',
            'availableAt' => null,
            'leaseId' => null,
            'leaseUntil' => null,
            'failure' => null,
        ];
    }

    public function claim(OutboxClaim $claim): OutboxBatch
    {
        ++$this->leaseSequence;
        $leaseId = \sprintf('%s-%d', $claim->workerId, $this->leaseSequence);
        $claimedAt = $claim->claimedAt->setTimezone(new \DateTimeZone('UTC'));
        $leaseUntil = self::add($claimedAt, $claim->lease);
        $messages = [];
        ksort($this->entries, SORT_STRING);

        foreach ($this->entries as $id => $entry) {
            if (\count($messages) >= $claim->limit || !$this->claimable($entry, $claimedAt)) {
                continue;
            }

            if ($entry['state'] === 'leased') {
                $entry['message'] = new OutboxMessage($entry['message']->outbound, $entry['message']->attempt + 1);
            }

            $entry['state'] = 'leased';
            $entry['leaseId'] = $leaseId;
            $entry['leaseUntil'] = $leaseUntil;
            $this->entries[$id] = $entry;
            $messages[] = $entry['message'];
        }

        return new OutboxBatch($leaseId, $messages);
    }

    public function markPublished(MessageId $id, string $leaseId): void
    {
        $entry = $this->leased((string) $id, $leaseId);
        $entry['state'] = 'published';
        $entry['leaseId'] = null;
        $entry['leaseUntil'] = null;
        $this->entries[(string) $id] = $entry;
    }

    public function markFailed(MessageId $id, string $leaseId, DeliveryFailure $failure): void
    {
        $key = (string) $id;
        $entry = $this->leased($key, $leaseId);
        $entry['failure'] = $failure;
        $entry['leaseId'] = null;
        $entry['leaseUntil'] = null;

        if ($failure->decision->disposition === RetryDisposition::DeadLetter) {
            $entry['state'] = 'dead';
            $entry['availableAt'] = null;
        } else {
            $entry['state'] = 'pending';
            $entry['availableAt'] = $failure->nextAttemptAt;
            $entry['message'] = new OutboxMessage(
                $entry['message']->outbound,
                $failure->decision->attempt + 1,
            );
        }

        $this->entries[$key] = $entry;
    }

    /** @return list<OutboxMessage> */
    public function pending(): array
    {
        return $this->messagesIn('pending');
    }

    /** @return list<OutboxMessage> */
    public function published(): array
    {
        return $this->messagesIn('published');
    }

    /** @return list<OutboxMessage> */
    public function deadLetters(): array
    {
        return $this->messagesIn('dead');
    }

    public function reset(): void
    {
        $this->entries = [];
        $this->leaseSequence = 0;
    }

    /**
     * @param array{
     *     message: OutboxMessage,
     *     state: 'pending'|'leased'|'published'|'dead',
     *     availableAt: ?\DateTimeImmutable,
     *     leaseId: ?string,
     *     leaseUntil: ?\DateTimeImmutable,
     *     failure: ?DeliveryFailure
     * } $entry
     */
    private function claimable(array $entry, \DateTimeImmutable $claimedAt): bool
    {
        if ($entry['state'] === 'pending') {
            return $entry['availableAt'] === null || $entry['availableAt'] <= $claimedAt;
        }

        return $entry['state'] === 'leased'
            && $entry['leaseUntil'] !== null
            && $entry['leaseUntil'] <= $claimedAt;
    }

    /**
     * @return array{
     *     message: OutboxMessage,
     *     state: 'pending'|'leased'|'published'|'dead',
     *     availableAt: ?\DateTimeImmutable,
     *     leaseId: ?string,
     *     leaseUntil: ?\DateTimeImmutable,
     *     failure: ?DeliveryFailure
     * }
     */
    private function leased(string $id, string $leaseId): array
    {
        $entry = $this->entries[$id] ?? throw new \OutOfBoundsException(\sprintf('Outbox message %s does not exist.', $id));

        if ($entry['state'] !== 'leased' || $entry['leaseId'] !== $leaseId) {
            throw new \LogicException(\sprintf('Outbox message %s is not owned by lease %s.', $id, $leaseId));
        }

        return $entry;
    }

    /**
     * @param 'pending'|'published'|'dead' $state
     * @return list<OutboxMessage>
     */
    private function messagesIn(string $state): array
    {
        return array_values(array_map(
            static fn(array $entry): OutboxMessage => $entry['message'],
            array_filter(
                $this->entries,
                static fn(array $entry): bool => $entry['state'] === $state,
            ),
        ));
    }

    private static function add(\DateTimeImmutable $time, Duration $duration): \DateTimeImmutable
    {
        $milliseconds = $duration->toMilliseconds();

        return $time
            ->modify(\sprintf('+%d seconds', intdiv($milliseconds, 1_000)))
            ->modify(\sprintf('+%d milliseconds', $milliseconds % 1_000));
    }
}
