<?php

namespace Tests\Webrtc\TURN;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\TURN\TurnConfigurationInterface;

class TurnConfiguration implements TurnConfigurationInterface
{
    private ?array $turnServer = null;
    private bool $turnSsl = false;
    private string $turnTransport = 'udp';
    private ?string $turnUsername = null;
    private ?string $turnPassword = null;

    public function getTurnServer(): ?array
    {
        return $this->turnServer;
    }

    public function setTurnServer(?array $turnServer): void
    {
        $this->turnServer = $turnServer;
    }

    public function getTurnSsl(): bool
    {
        return $this->turnSsl;
    }

    public function setTurnSsl(bool $turnSsl): void
    {
        $this->turnSsl = $turnSsl;
    }

    public function getTurnUsername(): ?string
    {
        return $this->turnUsername;
    }

    public function setTurnUsername(?string $turnUsername): void
    {
        $this->turnUsername = $turnUsername;
    }

    public function getTurnPassword(): ?string
    {
        return $this->turnPassword;
    }

    public function setTurnPassword(?string $turnPassword): void
    {
        $this->turnPassword = $turnPassword;
    }

    public function getTurnTransport(): string
    {
        return $this->turnTransport;
    }

    public function setTurnTransport(string $turnTransport): void
    {
        if (!in_array($turnTransport, ['udp', 'tcp'], true)) {
            throw new InvalidArgumentException('Invalid turn transport');
        }
        $this->turnTransport = $turnTransport;
    }
}