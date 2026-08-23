<?php

declare(strict_types=1);

namespace Contempt\Testing\Tests;

use Contempt\Compiler\Graph\ApplicationGraph;
use Contempt\Compiler\Graph\DependencyEdge;
use Contempt\Compiler\Graph\DependencyKind;
use Contempt\Compiler\Graph\GraphMetadata;
use Contempt\Compiler\Graph\ServiceNode;
use Contempt\Compiler\Graph\ServiceNodes;
use Contempt\Contracts\Container\Container;
use Contempt\Contracts\Container\Scope;
use Contempt\Contracts\Runtime\RuntimeState;
use Contempt\Core\SourceLocation;
use Contempt\Core\Time\Duration;
use Contempt\Messaging\Delivery\ConsumerId;
use Contempt\Messaging\Delivery\DeliveryFailure;
use Contempt\Messaging\Delivery\Failure;
use Contempt\Messaging\Delivery\OutboxClaim;
use Contempt\Messaging\Delivery\OutboxMessage;
use Contempt\Messaging\Envelope\Envelope;
use Contempt\Messaging\Envelope\MessageHeaders;
use Contempt\Messaging\Envelope\MessageId;
use Contempt\Messaging\Retry\RetryDecision;
use Contempt\Messaging\Retry\RetryDisposition;
use Contempt\Messaging\Transport\OutboundEnvelope;
use Contempt\Testing\Assertion\ContainerAssertions;
use Contempt\Testing\Assertion\GraphAssertions;
use Contempt\Testing\Assertion\MessageAssertions;
use Contempt\Testing\Assertion\TestingAssertionFailed;
use Contempt\Testing\Messaging\FakeCommandBus;
use Contempt\Testing\Messaging\InMemoryInbox;
use Contempt\Testing\Messaging\InMemoryOutbox;
use Contempt\Testing\Runtime\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryInbox::class)]
#[CoversClass(InMemoryOutbox::class)]
#[CoversClass(ContainerAssertions::class)]
#[CoversClass(GraphAssertions::class)]
#[CoversClass(MessageAssertions::class)]
#[CoversClass(TestingAssertionFailed::class)]
#[CoversClass(TestRuntime::class)]
final class ProductionTestingSupportTest extends TestCase
{
    public function testInboxDeduplicatesPerConsumerWithoutConflatingSubscribers(): void
    {
        $inbox = new InMemoryInbox();
        $id = MessageId::fromString('018f7c6a-2b1d-7def-8abc-0123456789ab');
        $at = new \DateTimeImmutable('2026-08-22T10:00:00Z');

        self::assertTrue($inbox->acquire($id, new ConsumerId('billing'), $at));
        self::assertFalse($inbox->acquire($id, new ConsumerId('billing'), $at->modify('+1 second')));
        self::assertTrue($inbox->acquire($id, new ConsumerId('analytics'), $at));
        self::assertCount(2, $inbox->entries());
    }

    public function testOutboxRequiresLeaseOwnershipAndReclaimsOnlyAfterExpiry(): void
    {
        $outbox = new InMemoryOutbox();
        $message = self::outboxMessage();
        $outbox->append($message);
        $claimedAt = new \DateTimeImmutable('2026-08-22T10:00:00Z');
        $first = $outbox->claim(new OutboxClaim('worker-a', 10, Duration::seconds(5), $claimedAt));

        self::assertCount(1, $first->messages);
        self::assertSame(1, $first->messages[0]->attempt);
        self::assertCount(0, $outbox->claim(new OutboxClaim(
            'worker-b',
            10,
            Duration::seconds(5),
            $claimedAt->modify('+4999 milliseconds'),
        ))->messages);

        $this->expectException(\LogicException::class);
        $outbox->markPublished($message->id(), 'worker-b:forged');
    }

    public function testOutboxRetryBecomesDueAtExactBoundaryAndDeadLettersTerminalFailure(): void
    {
        $outbox = new InMemoryOutbox();
        $message = self::outboxMessage();
        $outbox->append($message);
        $at = new \DateTimeImmutable('2026-08-22T10:00:00Z');
        $first = $outbox->claim(new OutboxClaim('worker-a', 1, Duration::seconds(5), $at));
        $failure = new DeliveryFailure(
            new Failure(\RuntimeException::class, 'temporary'),
            new RetryDecision(RetryDisposition::Retry, 1, Duration::milliseconds(10)),
            $at,
        );
        $outbox->markFailed($message->id(), $first->leaseId, $failure);

        self::assertCount(0, $outbox->claim(new OutboxClaim('worker-b', 1, Duration::seconds(5), $at->modify('+9 milliseconds')))->messages);
        $retry = $outbox->claim(new OutboxClaim('worker-b', 1, Duration::seconds(5), $at->modify('+10 milliseconds')));
        $retryMessages = $retry->messages;
        $retried = array_shift($retryMessages);
        self::assertInstanceOf(OutboxMessage::class, $retried);
        self::assertSame(2, $retried->attempt);

        $terminal = new DeliveryFailure(
            new Failure(\LogicException::class, 'terminal'),
            new RetryDecision(RetryDisposition::DeadLetter, 2, Duration::zero()),
            $at,
        );
        $outbox->markFailed($message->id(), $retry->leaseId, $terminal);
        self::assertCount(1, $outbox->deadLetters());
        self::assertCount(0, $outbox->claim(new OutboxClaim('worker-c', 1, Duration::seconds(5), $at->modify('+1 day')))->messages);
    }

    public function testFrameworkAssertionsProduceActionableFailuresWithoutPhpunitDependency(): void
    {
        $container = new AssertionContainer([\stdClass::class => new \stdClass()]);
        ContainerAssertions::has($container, \stdClass::class);
        ContainerAssertions::same($container, \stdClass::class);
        $graph = self::graph();
        GraphAssertions::hasService($graph, GraphConsumer::class);
        GraphAssertions::dependsOn($graph, GraphConsumer::class, GraphDependency::class);
        $commands = new FakeCommandBus([CreateThing::class => static fn(): null => null]);
        $commands->dispatch(new CreateThing('one'));
        MessageAssertions::dispatched($commands, CreateThing::class, 1);

        $this->expectException(TestingAssertionFailed::class);
        $this->expectExceptionMessage(GraphDependency::class);
        GraphAssertions::dependsOn($graph, GraphDependency::class, GraphConsumer::class);
    }

    public function testRuntimeNeverExposesContainerBeforeBoot(): void
    {
        $container = new AssertionContainer([\stdClass::class => new \stdClass()]);
        $runtime = TestRuntime::fromContainer($container);

        self::assertSame(RuntimeState::Created, $runtime->state());
        $this->expectException(\LogicException::class);
        $runtime->container();
    }

    public function testRuntimeUsesRealLifecycle(): void
    {
        $container = new AssertionContainer([\stdClass::class => new \stdClass()]);
        $runtime = TestRuntime::fromContainer($container);
        $runtime->boot();
        self::assertSame($container, $runtime->container());
        $runtime->shutdown();
        self::assertSame(RuntimeState::Stopped, $runtime->state());
    }

    private static function outboxMessage(): OutboxMessage
    {
        $id = MessageId::fromString('018f7c6a-2b1d-7def-8abc-0123456789ab');

        return new OutboxMessage(new OutboundEnvelope(new Envelope(
            $id,
            new CreateThing('queued'),
            new MessageHeaders(['message_id' => (string) $id]),
        ), 'commands'), 1);
    }

    private static function graph(): ApplicationGraph
    {
        $source = new SourceLocation('src/GraphConsumer.php', 10);

        return new ApplicationGraph(new ServiceNodes([
            new ServiceNode(GraphDependency::class, GraphDependency::class, Scope::Singleton, [], [], [], false, $source),
            new ServiceNode(GraphConsumer::class, GraphConsumer::class, Scope::Singleton, [
                new DependencyEdge(
                    GraphConsumer::class,
                    'dependency',
                    GraphDependency::class,
                    GraphDependency::class,
                    DependencyKind::Constructor,
                    $source,
                ),
            ], [], [], true, $source),
        ]), new GraphMetadata(
            '1.0.0',
            str_repeat('a', 64),
            str_repeat('b', 64),
            'test',
            true,
            [],
        ));
    }
}

final readonly class AssertionContainer implements Container
{
    /** @param array<class-string, object> $services */
    public function __construct(private array $services) {}

    public function get(string $id): object
    {
        $service = $this->services[$id] ?? throw new \LogicException('missing');

        if (!$service instanceof $id) {
            throw new \LogicException('wrong type');
        }

        return $service;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

final class GraphDependency {}

final class GraphConsumer
{
    public function __construct(public GraphDependency $dependency) {}
}
