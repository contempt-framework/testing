<?php

declare(strict_types=1);

namespace Contempt\Testing\Clock;

use Contempt\Core\Time\Duration;
use Psr\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $current;

    public function __construct(\DateTimeImmutable $current)
    {
        $this->current = $current->setTimezone(new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(Duration $duration): void
    {
        $milliseconds = $duration->toMilliseconds();
        $seconds = intdiv($milliseconds, 1_000);
        $remainder = $milliseconds % 1_000;
        $this->current = $this->current
            ->modify(\sprintf('+%d seconds', $seconds))
            ->modify(\sprintf('+%d milliseconds', $remainder));
    }

    public function set(\DateTimeImmutable $current): void
    {
        $utc = $current->setTimezone(new \DateTimeZone('UTC'));

        if ($utc < $this->current) {
            throw new \InvalidArgumentException('A fake monotonic wall clock cannot move backwards.');
        }

        $this->current = $utc;
    }
}
