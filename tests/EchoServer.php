<?php

namespace Tests\Webrtc\TURN;

use Amp\Socket\InternetAddress;
use Ramsey\Uuid\Uuid;
use Amp\Socket\UdpSocket;
use Webrtc\Exception\RuntimeException;
use Webrtc\STUN\Datagram;
use function Amp\Socket\bindUdpSocket;

class EchoServer extends Datagram
{
    public function __construct(UdpSocket $socket)
    {
        parent::__construct($socket);
    }

    protected function onReceived(string $data, InternetAddress $peerAddress): void
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
        try {
            $socket = bindUdpSocket("$host:$port");
            return new static($socket);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf("Could not bind to %s - %s", "$host:$port", $e->getMessage()), $e->getCode(), $e);
        }
    }
}
