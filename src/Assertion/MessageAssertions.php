<?php

declare(strict_types=1);

namespace Contempt\Testing\Assertion;

use Contempt\Contracts\Messaging\Event;
use Contempt\Contracts\Messaging\Query;
use Contempt\Messaging\Envelope\Envelope;
use Contempt\Testing\Messaging\FakeCommandBus;
use Contempt\Testing\Messaging\FakeEventBus;
use Contempt\Testing\Messaging\FakeQueryBus;

final class MessageAssertions
{
    /** @param class-string $type */
    public static function dispatched(FakeCommandBus $bus, string $type, int $count = 1): void
    {
        self::count($bus->dispatched(), $type, $count, 'dispatched');
    }

    /** @param class-string<Query<mixed>> $type */
    public static function asked(FakeQueryBus $bus, string $type, int $count = 1): void
    {
        self::count($bus->asked(), $type, $count, 'asked');
    }

    /** @param class-string<Event> $type */
    public static function published(FakeEventBus $bus, string $type, int $count = 1): void
    {
        self::count($bus->published(), $type, $count, 'published');
    }

    public static function header(Envelope $envelope, string $name, string|int|bool $expected): void
    {
        $actual = $envelope->headers->get($name);

        if ($actual !== $expected) {
            throw new TestingAssertionFailed(\sprintf(
                'Expected message header %s to equal %s, got %s.',
                $name,
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    /**
     * @param list<object> $messages
     * @param class-string $type
     */
    private static function count(array $messages, string $type, int $expected, string $verb): void
    {
        if ($expected < 0) {
            throw new \InvalidArgumentException('An expected message count cannot be negative.');
        }

        $actual = \count(array_filter(
            $messages,
            static fn(object $message): bool => $message instanceof $type,
        ));

        if ($actual !== $expected) {
            throw new TestingAssertionFailed(\sprintf(
                'Expected %d %s message(s) of type %s, got %d.',
                $expected,
                $verb,
                $type,
                $actual,
            ));
        }
    }

    private function __construct() {}
}
