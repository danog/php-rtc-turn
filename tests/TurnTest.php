<?php

namespace Tests\Webrtc\TURN;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Webrtc\Exception\RuntimeException;
use Webrtc\ICE\RTCIceProtocolConfiguration;
use Webrtc\MDNS\MulticastExecutor;
use Webrtc\SCTP\RTCSctpTransport;
use Webrtc\SCTP\SctpUtility;
use Webrtc\SCTP\Trait\DataChannel;
use Webrtc\STUN\Exception\TransactionException;
use Webrtc\STUN\Exception\TransactionExceptionInterface;
use Webrtc\STUN\Exception\TransactionFailedException;
use Webrtc\STUN\Exception\TransactionTimeoutException;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageAttributeCollection;
use Webrtc\STUN\Message\MessageAttributeEncoder;
use Webrtc\STUN\Message\MessageIntegrity;
use Webrtc\STUN\Transaction;
use Webrtc\STUN\Utils;
use Webrtc\TURN\Turn;
use Webrtc\TURN\TurnTcpConnection;
use Webrtc\TURN\TurnUdpConnection;
use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;

#[UsesClass(TurnTcpConnection::class)]
#[UsesClass(TurnUdpConnection::class)]
#[UsesClass(RTCIceProtocolConfiguration::class)]
#[UsesClass(TransactionException::class)]
#[UsesClass(TransactionFailedException::class)]
#[UsesClass(TransactionTimeoutException::class)]
#[UsesClass(Message::class)]
#[UsesClass(MessageAttributeCollection::class)]
#[UsesClass(MessageAttributeEncoder::class)]
#[UsesClass(MessageIntegrity::class)]
#[UsesClass(Transaction::class)]
#[UsesClass(Utils::class)]
#[UsesClass(RTCSctpTransport::class)]
#[UsesClass(SctpUtility::class)]
#[UsesClass(DataChannel::class)]
#[UsesClass(MulticastExecutor::class)]
#[CoversClass(Turn::class)]
class TurnTest extends TestCase
{
    private RTCIceProtocolConfiguration $turnConfiguration;
    private EchoServer $echoServer1;
    private EchoServer $echoServer2;
    private Receiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        // Default TURN configuration
        $this->turnConfiguration = new RTCIceProtocolConfiguration();
        $this->turnConfiguration->setTurnUsername("quasarstream");

        $this->receiver = new Receiver();

        $this->echoServer1 = EchoServer::create();
        $this->echoServer2 = EchoServer::create();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->echoServer1->close();
        $this->echoServer2->close();
    }

    public function testTcpTransport()
    {
        $this->testTransport("tcp");
    }

    public function testTlsTransport()
    {
        $this->testTransport("tcp", true);
    }

    public function testUdpTransport()
    {
        $this->testTransport("udp");
    }

    private function testTransport($transport, bool $ssl = false): void
    {
        $this->testTransportOk($transport, $ssl);
        $this->testTransportOkMulti($transport, $ssl);
        $this->testTransportWrongPassword($transport, $ssl);
    }

    private function testTransportOk($transport, $ssl)
    {
        $turn = $this->createTurn($transport, $ssl);
        await($turn->connect());

        $this->assertNotNull($turn->getLocalHost());
        $this->assertNotNull($turn->getRelayedAddress());

        $turn->send("ping", $this->echoServer1->getLocalAddress());
        delay(.1);
        $this->assertEquals("ping", $this->receiver->getData()[0]);

        $this->receiver->clearData();
        delay(3);

        $turn->send("ping", $this->echoServer1->getLocalAddress());
        delay(.1);
        $this->assertEquals("ping", $this->receiver->getData()[0]);

        $turn->delete();
    }

    private function testTransportOkMulti($transport, $ssl)
    {
        $turn = $this->createTurn($transport, $ssl);
        await($turn->connect());

        $this->assertNotNull($turn->getLocalHost());
        $this->assertNotNull($turn->getRelayedAddress());

        // Send packet 10 to two servers and get data back
        for ($i =1; $i <= 10; $i++) {
              $turn->send("ping$i", $this->{"echoServer" . ($i > 5 ? 2 : 1)}->getLocalAddress());
        }

        delay(.1);
        $this->assertEquals(array_map(fn($i) => "ping$i", range(1, 10)), $this->receiver->getData());

        $turn->delete();
    }

    private function testTransportWrongPassword($transport, $ssl)
    {
        $turn = $this->createTurn($transport, $ssl, true);
        
        async(function () use ($turn) {
            delay(1);
            $turn->delete();
        })();

        $this->expectException(TransactionExceptionInterface::class);
        $this->expectExceptionMessage("Failed to request with retry: STUN transaction failed (401 - Unauthorized)");
        await($turn->connect());
    }

    private function createTurn(string $transport, bool $ssl, bool $wrongPass = false): Turn
    {
        $this->turnConfiguration->setTurnTransport($transport);
        $this->turnConfiguration->setTurnPassword($wrongPass ? "wrong_password" : "123");
        $this->turnConfiguration->setTurnSsl($ssl);
        $port = $ssl ? 5349 : 3478;
        $this->turnConfiguration->setTurnServer(["127.0.0.1", $port]);

        $this->receiver->clearData();

        try {
            return Turn::create($this->turnConfiguration, $this->receiver);
        } catch (\Exception $e) {
            throw new RuntimeException("Cannot connect to the turn server. Make sure the Coturn is running on port " . $port . " and set the configuration file and try again." . "\n" . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
