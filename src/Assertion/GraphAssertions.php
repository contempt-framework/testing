<?php

declare(strict_types=1);

namespace Contempt\Testing\Assertion;

use Contempt\Compiler\Graph\ApplicationGraph;

final class GraphAssertions
{
    /** @param class-string $id */
    public static function hasService(ApplicationGraph $graph, string $id): void
    {
        if (!isset($graph->services->all()[$id])) {
            throw new TestingAssertionFailed(\sprintf('Expected application graph to contain service %s.', $id));
        }
    }

    /**
     * @param class-string $consumer
     * @param class-string $dependency
     */
    public static function dependsOn(ApplicationGraph $graph, string $consumer, string $dependency): void
    {
        $service = $graph->services->all()[$consumer] ?? null;

        if ($service === null) {
            throw new TestingAssertionFailed(\sprintf('Expected application graph to contain consumer %s.', $consumer));
        }

        foreach ($service->dependencies as $edge) {
            if ($edge->targetService === $dependency) {
                return;
            }
        }

        throw new TestingAssertionFailed(\sprintf(
            'Expected service %s to depend on %s; actual targets: %s.',
            $consumer,
            $dependency,
            implode(', ', array_map(
                static fn($edge): string => $edge->targetService,
                $service->dependencies,
            )) ?: '(none)',
        ));
    }

    private function __construct() {}
}
