<?php

declare(strict_types=1);

namespace Contempt\Testing\Runtime;

use Contempt\Contracts\Container\Container;
use Contempt\Contracts\Runtime\Runtime;
use Contempt\Contracts\Runtime\RuntimeState;
use Contempt\Kernel\LifecycleParticipant;
use Contempt\Kernel\ManagedRuntime;

final readonly class TestRuntime implements Runtime
{
    private ManagedRuntime $runtime;

    /**
     * @param \Closure(): Container $bootstrap
     * @param (\Closure(Container): void)|null $teardown
     * @param iterable<LifecycleParticipant> $lifecycle
     */
    public function __construct(\Closure $bootstrap, ?\Closure $teardown = null, iterable $lifecycle = [])
    {
        $this->runtime = new ManagedRuntime($bootstrap, $teardown, $lifecycle);
    }

    public static function fromContainer(Container $container): self
    {
        return new self(static fn(): Container => $container);
    }

    public function boot(): void
    {
        $this->runtime->boot();
    }

    public function shutdown(): void
    {
        $this->runtime->shutdown();
    }

    public function state(): RuntimeState
    {
        return $this->runtime->state();
    }

    public function container(): Container
    {
        return $this->runtime->container();
    }
}
