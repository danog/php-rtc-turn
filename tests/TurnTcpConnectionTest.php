<?php

namespace Tests\Webrtc\TURN;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageAttributeCollection;
use Webrtc\STUN\Message\MessageAttributeEncoder;
use Webrtc\STUN\Transaction;
use Webrtc\STUN\Utils;
use Webrtc\TURN\TurnTcpConnection;
use PHPUnit\Framework\TestCase;

#[UsesClass(RTCIceProtocolConfiguration::class)]
#[UsesClass(Message::class)]
#[UsesClass(MessageAttributeCollection::class)]
#[UsesClass(MessageAttributeEncoder::class)]
#[UsesClass(Transaction::class)]
#[UsesClass(Utils::class)]
#[CoversClass(TurnTcpConnection::class)]
class TurnTcpConnectionTest extends TestCase
{
    protected TurnTcpConnection $protocol;
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

        $this->protocol = TurnTcpConnection::create($this->turnConfiguration, $this->receiver);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->protocol->delete();
    }

    public function testReceiveStunFragmented()
    {
        $data = file_get_contents(__DIR__ . "/fixture/binding_request.bin");
        $this->protocol->onTCPReceived(substr($data, 0, 10));
        $this->protocol->onTCPReceived(substr($data, 10));

        $this->assertInstanceOf(Message::class, $this->receiver->getMessages()[0]);
        $this->assertEquals(
            "ID: 4e766678336c553746554246 - Class: REQUEST - Method: BINDING - Attributes: No Attributes",
            $this->receiver->getMessages()[0]->humanReadable()
        );
    }

    public function testReceiveJunk()
    {
        $this->receiver->clearMessage();
        $this->protocol->onTCPReceived(str_repeat("\x00", 20));
        $this->assertEmpty($this->receiver->getMessages());
    }
}
