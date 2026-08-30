<?php

namespace Tests\Webrtc\TURN;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\Exception\RuntimeException;
use Webrtc\STUN\Exception\TransactionExceptionInterface;
use Webrtc\TURN\Turn;
use Webrtc\TURN\TurnTcpConnection;
use Webrtc\TURN\TurnUdpConnection;
use function Amp\async;
use function Amp\delay;

#[UsesClass(TurnTcpConnection::class)]
#[UsesClass(TurnUdpConnection::class)]
#[CoversClass(Turn::class)]
class TurnTest extends TestCase
{
    private TurnConfiguration $turnConfiguration;
    private EchoServer $echoServer1;
    private EchoServer $echoServer2;
    private Receiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        // Default TURN configuration
        $this->turnConfiguration = new TurnConfiguration();
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
        $turn->connect();

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
        $turn->connect();

        $this->assertNotNull($turn->getLocalHost());
        $this->assertNotNull($turn->getRelayedAddress());

        // Send packet 10 to two servers and get data back
        for ($i =1; $i <= 10; $i++) {
              $turn->send("ping$i", $this->{"echoServer" . ($i > 5 ? 2 : 1)}->getLocalAddress());
        }

        delay(.1);

        // Relayed data is UDP, so nothing orders echoes coming back from two different peers.
        // The previous implementation queued every send behind the last one's round trip,
        // which paced them enough to arrive in order; sends now block only on the channel
        // bind for their own peer, so only the set of replies is meaningful.
        $received = $this->receiver->getData();
        sort($received);
        $expected = array_map(fn($i) => "ping$i", range(1, 10));
        sort($expected);
        $this->assertEquals($expected, $received);

        $turn->delete();
    }

    private function testTransportWrongPassword($transport, $ssl)
    {
        $turn = $this->createTurn($transport, $ssl, true);
        
        async(function () use ($turn) {
            delay(1);
            $turn->delete();
        });

        $this->expectException(TransactionExceptionInterface::class);
        // The reason phrase is advisory and server-dependent — coturn sends none — so the
        // assertion is on the 401 error code, which is what the protocol actually guarantees.
        $this->expectExceptionMessageMatches('/^Failed to request with retry: STUN transaction failed \(401\b/');
        $turn->connect();
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
