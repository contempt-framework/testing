<?php

declare(strict_types=1);

namespace Contempt\Testing\Messaging;

use Contempt\Messaging\Transport\OutboundEnvelope;
use Contempt\Messaging\Transport\Sender;
use Contempt\Messaging\Transport\SendResult;

final class FakeTransport implements Sender
{
    /** @var list<OutboundEnvelope> */
    private array $messages = [];

    public function send(OutboundEnvelope $envelope): SendResult
    {
        $this->messages[] = $envelope;

        return new SendResult((string) $envelope->envelope->id);
    }

    /** @return list<OutboundEnvelope> */
    public function sent(): array
    {
        return $this->messages;
    }
}
