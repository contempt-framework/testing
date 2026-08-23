<?php

declare(strict_types=1);

namespace Contempt\Testing\Tests;

use Contempt\Cache\CacheKey;
use Contempt\Contracts\Http\HttpMethod;
use Contempt\Contracts\Messaging\Command;
use Contempt\Contracts\Messaging\Event;
use Contempt\Contracts\Messaging\Query;
use Contempt\Core\Time\Duration;
use Contempt\Http\Body;
use Contempt\Http\Request;
use Contempt\Http\Response;
use Contempt\Lock\LockKey;
use Contempt\Lock\LockOwner;
use Contempt\Lock\LockUnavailable;
use Contempt\Messaging\Envelope\Envelope;
use Contempt\Messaging\Envelope\MessageHeaders;
use Contempt\Messaging\Envelope\MessageId;
use Contempt\Messaging\Transport\OutboundEnvelope;
use Contempt\Testing\Cache\FakeCache;
use Contempt\Testing\Clock\FakeClock;
use Contempt\Testing\Http\TestHttpClient;
use Contempt\Testing\Lock\FakeLockManager;
use Contempt\Testing\Messaging\FakeCommandBus;
use Contempt\Testing\Messaging\FakeEventBus;
use Contempt\Testing\Messaging\FakeQueryBus;
use Contempt\Testing\Messaging\FakeTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeClock::class)]
#[CoversClass(FakeCache::class)]
#[CoversClass(FakeLockManager::class)]
#[CoversClass(FakeCommandBus::class)]
#[CoversClass(FakeQueryBus::class)]
#[CoversClass(FakeEventBus::class)]
#[CoversClass(FakeTransport::class)]
#[CoversClass(TestHttpClient::class)]
final class TestingToolkitTest extends TestCase
{
    public function testClockAndCacheExpireExactlyAtDeterministicBoundary(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-08-22T10:00:00.000Z'));
        $cache = new FakeCache($clock);
        $key = new CacheKey('users.42');
        $value = new \stdClass();
        $cache->put($key, $value, Duration::milliseconds(10));

        $clock->advance(Duration::milliseconds(9));
        self::assertSame($value, $cache->get($key));
        $clock->advance(Duration::milliseconds(1));
        self::assertNull($cache->get($key));

        $this->expectException(\InvalidArgumentException::class);
        $clock->set(new \DateTimeImmutable('2026-08-22T09:59:59Z'));
    }

    public function testFakeLockProvidesMonotonicFencingAndRealContention(): void
    {
        $manager = new FakeLockManager();
        $key = new LockKey('aggregate/42');
        $first = $manager->acquire($key, new LockOwner('worker-a'), Duration::zero());

        try {
            $manager->acquire($key, new LockOwner('worker-b'), Duration::zero());
            self::fail('A held fake lock must model contention.');
        } catch (LockUnavailable) {
        }

        $first->release();
        $second = $manager->acquire($key, new LockOwner('worker-b'), Duration::zero());
        self::assertSame(2, $second->token()->value);
        $second->release();
        $second->release();
        self::assertTrue($second->isReleased());
    }

    public function testMessageFakesRecordInputsAndRequireExplicitQueryAndCommandBehavior(): void
    {
        $commands = new FakeCommandBus([CreateThing::class => static function (object $command): string {
            if (!$command instanceof CreateThing) {
                throw new \LogicException('Unexpected fake command type.');
            }

            return $command->name;
        }]);
        $queries = new FakeQueryBus([FindThing::class => static function (object $query): ?string {
            if (!$query instanceof FindThing) {
                throw new \LogicException('Unexpected fake query type.');
            }

            return $query->found ? 'found' : null;
        }]);
        $events = new FakeEventBus();
        $transport = new FakeTransport();

        self::assertSame('safe', $commands->dispatch(new CreateThing('safe')));
        self::assertNull($queries->ask(new FindThing(false)));
        $events->publish(new ThingCreated('42'));
        $result = $transport->send(new OutboundEnvelope(self::envelope(), 'events'));

        self::assertCount(1, $commands->dispatched());
        self::assertCount(1, $queries->asked());
        self::assertCount(1, $events->published());
        self::assertCount(1, $transport->sent());
        self::assertSame('018f7c6a-2b1d-7def-8abc-0123456789ab', $result->transportMessageId);

        $this->expectException(\LogicException::class);
        $queries->ask(new UnknownQuery());
    }

    public function testHttpClientInvokesRealKernelAndRetainsHistory(): void
    {
        $client = new TestHttpClient(static fn(Request $request): Response => new Response(
            418,
            body: Body::fromString($request->path),
        ));

        $response = $client->request(HttpMethod::Get, '/corner');

        self::assertSame(418, $response->status);
        self::assertSame('/corner', $response->body->contents());
        self::assertSame('/corner', ($client->requests()[0] ?? null)?->path);
        $client->reset();
        self::assertSame([], $client->requests());
    }

    private static function envelope(): Envelope
    {
        $id = MessageId::fromString('018f7c6a-2b1d-7def-8abc-0123456789ab');

        return new Envelope($id, new ThingCreated('42'), new MessageHeaders([
            'message_id' => (string) $id,
            'message_type' => 'thing.created.v1',
            'schema_version' => 1,
        ]));
    }
}

final readonly class CreateThing implements Command
{
    public function __construct(public string $name) {}
}

/** @implements Query<?string> */
final readonly class FindThing implements Query
{
    public function __construct(public bool $found) {}
}

/** @implements Query<string> */
final readonly class UnknownQuery implements Query {}

final readonly class ThingCreated implements Event
{
    public function __construct(public string $id) {}
}
