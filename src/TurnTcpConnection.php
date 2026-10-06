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

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use function Amp\Socket\connect;
use Throwable;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SerializableState;
use Webrtc\STUN\ReceiverInterface;
use Webrtc\STUN\Trait\Request;
use Webrtc\STUN\Utils;
use Webrtc\TURN\Trait\TurnConnection;

/**
 * Class TurnTcpConnection
 * Protocol for handling TURN over TCP.
 */
final class TurnTcpConnection extends TCPConnection implements TurnConnectionInterface
{
    use Request, TurnConnection {
        TurnConnection::sendMessage insteadof Request;
    }

    private string $id;
    private string $buffer = "";

    /**
     * The receiver of the messages, which owns this protocol: not owned by it, so that a pending
     * request doesn't keep the receiver alive.
     *
     * @var \WeakReference<ReceiverInterface>|null
     */
    private ?\WeakReference $receiver;

    /**
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver
     * @param ?LoggerInterface $logger
     * @param Socket $socket
     */
    public function __construct(private readonly TurnConfigurationInterface $configuration,
                                ReceiverInterface                           $receiver,
                                private readonly ?LoggerInterface            $logger,
                                Socket                                      $socket)
    {
        $this->receiver = \WeakReference::create($receiver);
        parent::__construct($socket);
        $this->id = Uuid::uuid4()->toString();
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
     * Handle received messages.
     *
     * @param string $data The received data.
     * @return void
     * @throws RandomException
     */
    #[\Override]
    public function onTCPReceived(string $data): void
    {
        $this->buffer .= $data;

        while (strlen($this->buffer) >= 4) {
            $unpacked = unpack("nChannel/nLength", substr($this->buffer, 0, 4));
            assert($unpacked !== false);
            $length = (int) $unpacked['Length'];
            $length += Utils::paddingLength($length);

            if ($this->isChannelData($this->buffer)) {
                $fullLength = 4 + $length;
            } else {
                $fullLength = 20 + $length;
            }

            if (strlen($this->buffer) < $fullLength) {
                break;
            }

            $address = $this->getRemoteAddress();
            assert($address !== null);

            $data = substr($this->buffer, 0, $fullLength);
            $this->onReceived($data, $address);
            $this->buffer = substr($this->buffer, $fullLength);
        }
    }

    /**
     * Add pad if needed
     *
     * @param string $data
     * @return string
     */
    #[\Override]
    protected function padded(string $data): string
    {
        $padLen = Utils::paddingLength(strlen($data));
        return $data . str_repeat("\0", $padLen);

    }
    /**
     * Create a TurnTCP instance.
     *
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver The receiver.
     * @param ?LoggerInterface $logger
     * @return self
     */
    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, ?LoggerInterface $logger = null): self
    {
        $turnServer = $configuration->getTurnServer();
        if ($turnServer === null || !isset($turnServer[0], $turnServer[1])) {
            throw new RuntimeException('No TURN server is configured');
        }
        $address = implode(":", array_map(static fn (mixed $part): string => (string) $part, $turnServer));

        try {
            $context = (new ConnectContext())->withTlsContext(
                (new ClientTlsContext((string) $turnServer[0]))->withoutPeerVerification()
            );

            $socket = connect($address, $context);
            if ($configuration->getTurnSsl()) {
                // TURN over TLS starts the handshake immediately rather than after a STARTTLS
                // style upgrade, so it has to happen before the first allocation is sent.
                $socket->setupTls();
            }

            return new static($configuration, $receiver, $logger, $socket);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf("Could not connect to %s - %s", $address, $e->getMessage()), (int) $e->getCode(), $e);
        }
    }

    #[\Override]
    protected function restoreTcpSocket(?string $remote): void
    {
        $turnServer = $this->configuration->getTurnServer();
        if ($turnServer === null || !isset($turnServer[0], $turnServer[1])) {
            return;
        }
        $address = implode(":", array_map(static fn (mixed $part): string => (string) $part, $turnServer));
        $context = (new ConnectContext())->withTlsContext(
            (new ClientTlsContext((string) $turnServer[0]))->withoutPeerVerification()
        );
        $socket = connect($address, $context);
        if ($this->configuration->getTurnSsl()) {
            $socket->setupTls();
        }
        $this->socket = $socket;
        $this->listen();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function __serialize(): array
    {
        return SerializableState::export($this, [
            'socket' => ['_tcp' => (string) $this->socket->getRemoteAddress()],
            'receiver' => $this->receiver?->get(),
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
        $key = self::class . "\0receiver";
        // Before the socket is listened to again.
        $this->receiver = isset($data[$key]) && $data[$key] instanceof ReceiverInterface ? \WeakReference::create($data[$key]) : null;
        unset($data[$key]);
        parent::__unserialize($data);
        $this->restoreTurnTimers($restartRefresh);
    }
}
