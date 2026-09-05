<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\TURN;

use Amp\Socket\InternetAddress;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Amp\Socket\UdpSocket;
use Throwable;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SerializableState;
use Webrtc\STUN\Datagram;
use Webrtc\STUN\ReceiverInterface;
use Webrtc\STUN\Trait\Request;
use Webrtc\TURN\Trait\TurnConnection;
use function Amp\Socket\bindUdpSocket;

/**
 * Class TurnUDPConnection
 * Protocol for handling TURN over UDP.
 */
final class TurnUdpConnection extends Datagram implements TurnConnectionInterface
{
    use Request, TurnConnection;

    private string $id;

    /**
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver
     * @param ?LoggerInterface $logger
     * @param UdpSocket $socket
     */
    public function __construct(private readonly TurnConfigurationInterface $configuration,
                                private readonly ReceiverInterface          $receiver,
                                private readonly ?LoggerInterface            $logger,
                                UdpSocket                                   $socket)
    {
        $turnServer = $this->configuration->getTurnServer();
        if ($turnServer === null || !isset($turnServer[0], $turnServer[1])) {
            throw new RuntimeException('No TURN server is configured');
        }
        $port = (int) $turnServer[1];
        if ($port < 0 || $port > 65535) {
            throw new RuntimeException('Invalid TURN server port');
        }
        $this->remoteAddress = new InternetAddress((string) $turnServer[0], $port);
        $this->id = Uuid::uuid4()->toString();
        parent::__construct($socket);
    }

    /**
     * @param string $transactionId
     * @return void
     */
    #[\Override]
    public function removeTransaction(string $transactionId): void
    {
        unset($this->transactionIds[$transactionId]);
    }

    /**
     * @return string
     */
    #[\Override]
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Create a turn udp instance.
     *
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver The receiver.
     * @param ?LoggerInterface $logger
     * @return self
     */
    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, ?LoggerInterface $logger = null): self
    {
        try {
            // Bind an ephemeral local socket; the server address is kept as the default
            // destination rather than connecting, so the same socket can also reach peers.
            $socket = bindUdpSocket('0.0.0.0:0');
            return new static($configuration, $receiver, $logger, $socket);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf("Could not bind to %s", $e->getMessage()), (int) $e->getCode(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function __serialize(): array
    {
        $address = $this->socket->getAddress();

        return SerializableState::export($this, [
            'socket' => ['_udp' => [$address->getAddress(), $address->getPort()]],
            ...$this->turnSerializeReplacements(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function __unserialize(array $data): void
    {
        $restartRefresh = $this->consumeTurnRefreshFlag($data);
        parent::__unserialize($data);
        $this->restoreTurnTimers($restartRefresh);
    }
}
