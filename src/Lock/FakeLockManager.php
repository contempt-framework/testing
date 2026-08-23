<?php

declare(strict_types=1);

namespace Contempt\Testing\Lock;

use Contempt\Core\Time\Duration;
use Contempt\Lock\FencingToken;
use Contempt\Lock\Lock;
use Contempt\Lock\LockKey;
use Contempt\Lock\LockManager;
use Contempt\Lock\LockOwner;
use Contempt\Lock\LockUnavailable;

final class FakeLockManager implements LockManager
{
    /** @var array<string, FakeLock> */
    private array $held = [];

    /** @var array<string, int> */
    private array $tokens = [];

    public function acquire(LockKey $key, LockOwner $owner, Duration $wait): Lock
    {
        $held = $this->held[$key->value] ?? null;

        if ($held !== null && !$held->isReleased()) {
            throw new LockUnavailable($key, $owner);
        }

        $token = ($this->tokens[$key->value] ?? 0) + 1;
        $this->tokens[$key->value] = $token;
        $lock = new FakeLock(
            $key,
            $owner,
            new FencingToken($token),
            function () use ($key): void {
                unset($this->held[$key->value]);
            },
        );
        $this->held[$key->value] = $lock;

        return $lock;
    }
}

final class FakeLock implements Lock
{
    private bool $released = false;

    /** @param \Closure(): void $onRelease */
    public function __construct(
        private readonly LockKey $key,
        private readonly LockOwner $owner,
        private readonly FencingToken $token,
        private readonly \Closure $onRelease,
    ) {}

    public function key(): LockKey
    {
        return $this->key;
    }

    public function owner(): LockOwner
    {
        return $this->owner;
    }

    public function token(): FencingToken
    {
        return $this->token;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        ($this->onRelease)();
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
