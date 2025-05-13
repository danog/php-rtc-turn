<?php

namespace Tests\Webrtc\TURN;

use PHPUnit\Framework\Attributes\CoversClass;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\TURN\TurnUdpConnection;
use PHPUnit\Framework\TestCase;


#[CoversClass(TurnUDPConnection::class)]
class TurnUDPConnectionTest extends TestCase
{
    protected $protocol;
    private RTCIceProtocolConfiguration $turnConfiguration;
    private Receiver $receiver;

    protected function setUp(): void
    {
        // Default TURN configuration
        $this->turnConfiguration = new RTCIceProtocolConfiguration();
        $this->turnConfiguration->setTurnServer(["127.0.0.1", 3478]);
        $this->turnConfiguration->setTurnUsername("quasarstream");
        $this->turnConfiguration->setTurnPassword("123");
        $this->turnConfiguration->setTurnSsl(false);
        $this->turnConfiguration->setTurnTransport('tcp');

        $this->receiver = new Receiver();

        $this->protocol = TurnUdpConnection::create($this->turnConfiguration, $this->receiver);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->protocol->delete();
    }

    public function testReceiveJunk()
    {
        $this->protocol->OnReceived(str_repeat("\x00", 20), "127.0.0.1:4321");
        $this->assertEmpty($this->receiver->getData());
        $this->assertEmpty($this->receiver->getMessages());
    }
}
