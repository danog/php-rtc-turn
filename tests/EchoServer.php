<?php

namespace Tests\Webrtc\TURN;

use Ramsey\Uuid\Uuid;
use React\Datagram\Factory;
use React\Datagram\SocketInterface;
use Webrtc\Exception\RuntimeException;
use Webrtc\STUN\Datagram;
use function React\Async\await;

class EchoServer extends Datagram
{
    public function __construct(SocketInterface $socket)
    {
        parent::__construct($socket);
    }

    protected function onReceived(string $data, string $peerAddress): void
    {
        $this->send($data, $peerAddress);
    }

    protected function onError(\Throwable $e): void
    {
    }

    protected function onClose(): void
    {
    }

    public static function create(string $host = "127.0.0.1", int $port = 0): self
    {
        $factory = new Factory();
        try {
            $socket = await($factory->createServer("$host:$port"));
            return new static($socket);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf("Could not bind to %s - %s", "$host:$port", $e->getMessage()), $e->getCode(), $e);
        }
    }
}