<?php

declare(strict_types=1);

namespace Contempt\Testing\Cache;

use Contempt\Cache\CacheKey;
use Contempt\Cache\TypedCache;
use Contempt\Core\Time\Duration;
use Psr\Clock\ClockInterface;

/** @implements TypedCache<object> */
final class FakeCache implements TypedCache
{
    /** @var array<string, array{value: object, expiresAt: ?\DateTimeImmutable}> */
    private array $entries = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function get(CacheKey $key): ?object
    {
        $entry = $this->entries[$key->value] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= $this->clock->now()) {
            unset($this->entries[$key->value]);

            return null;
        }

        return $entry['value'];
    }

    public function put(CacheKey $key, object $value, ?Duration $timeToLive = null): void
    {
        if ($timeToLive?->isZero()) {
            unset($this->entries[$key->value]);

            return;
        }

        $expiresAt = $timeToLive === null ? null : self::add($this->clock->now(), $timeToLive);
        $this->entries[$key->value] = ['value' => $value, 'expiresAt' => $expiresAt];
    }

    public function delete(CacheKey $key): void
    {
        unset($this->entries[$key->value]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    private static function add(\DateTimeImmutable $time, Duration $duration): \DateTimeImmutable
    {
        $milliseconds = $duration->toMilliseconds();

        return $time
            ->modify(\sprintf('+%d seconds', intdiv($milliseconds, 1_000)))
            ->modify(\sprintf('+%d milliseconds', $milliseconds % 1_000));
    }
}
