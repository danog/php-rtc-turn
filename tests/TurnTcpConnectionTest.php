<?php

namespace Tests\Webrtc\TURN;

use Amp\Socket\InternetAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\STUN\Message\Message;
use Webrtc\TURN\TurnTcpConnection;
use PHPUnit\Framework\TestCase;

#[CoversClass(TurnTcpConnection::class)]
class TurnTcpConnectionTest extends TestCase
{
    protected TurnTcpConnection $protocol;
    private TurnConfiguration $turnConfiguration;
    private Receiver $receiver;

    protected function setUp(): void
    {
        // Default TURN configuration
        $this->turnConfiguration = new TurnConfiguration();
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
        // A connectivity check of a peer, relayed on the channel bound to it.
        $channel = 0x4000;
        (new \ReflectionProperty(TurnTcpConnection::class, 'channelToPeer'))->setValue($this->protocol, [$channel => new InternetAddress('192.0.2.1', 4000)]);
        $request = file_get_contents(__DIR__ . "/fixture/binding_request.bin");
        $data = pack("nn", $channel, \strlen($request)) . $request;
        $this->protocol->onTCPReceived(substr($data, 0, 10));
        $this->protocol->onTCPReceived(substr($data, 10));

        $this->assertInstanceOf(Message::class, $this->receiver->getMessages()[0]);
        $this->assertEquals(
            "ID: 4e766678336c553746554246 - Class: REQUEST - Method: BINDING - Attributes: No Attributes",
            $this->receiver->getMessages()[0]->humanReadable()
        );
    }

    public function testIgnoreRequestNotRelayed()
    {
        // Sent straight to the connection of the allocation rather than through the relay, which the data never
        // comes from: answering it would validate a path that drops all data.
        $this->protocol->onTCPReceived(file_get_contents(__DIR__ . "/fixture/binding_request.bin"));

        $this->assertEmpty($this->receiver->getMessages());
    }

    public function testReceiveJunk()
    {
        $this->receiver->clearMessage();
        $this->protocol->onTCPReceived(str_repeat("\x00", 20));
        $this->assertEmpty($this->receiver->getMessages());
    }
}
