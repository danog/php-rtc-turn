<?php

namespace Tests\Webrtc\TURN;

use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\Message\MessageInterface;
use Webrtc\STUN\ReceiverInterface;

class Receiver implements ReceiverInterface
{
    private array $data = [];
    private array $messages = [];

    public function onDataReceived(string $data, int $componentId): void
    {
        $this->data[] = $data;
    }

    public function onRequestReceived(MessageInterface $message, string $address, IceConnectionProtocolInterface $protocol, string $data): void
    {
        $this->messages[] = $message;
    }

    public function onClose(): void
    {
    }

    public function onError(\Throwable $e): void
    {
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function clearData(): void
    {
        $this->data = [];
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clearMessage(): void
    {
        $this->messages = [];
    }
}