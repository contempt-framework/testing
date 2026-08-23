<?php

declare(strict_types=1);

namespace Contempt\Testing\Assertion;

use Contempt\Contracts\Container\Container;

final class ContainerAssertions
{
    /** @param class-string $id */
    public static function has(Container $container, string $id): void
    {
        if (!$container->has($id)) {
            throw new TestingAssertionFailed(\sprintf('Expected container to expose service %s.', $id));
        }
    }

    /** @param class-string $id */
    public static function missing(Container $container, string $id): void
    {
        if ($container->has($id)) {
            throw new TestingAssertionFailed(\sprintf('Expected container not to expose service %s.', $id));
        }
    }

    /** @param class-string $id */
    public static function same(Container $container, string $id): void
    {
        self::has($container, $id);
        $first = $container->get($id);
        $second = $container->get($id);

        if ($first !== $second) {
            throw new TestingAssertionFailed(\sprintf('Expected service %s to resolve to the same instance.', $id));
        }
    }

    private function __construct() {}
}
