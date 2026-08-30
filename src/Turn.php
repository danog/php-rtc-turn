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
use Random\RandomException;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\STUN\IceCandidateInterface;
use Webrtc\STUN\Message\MessageInterface;
use Webrtc\STUN\ReceiverInterface;

/**
 * Class TurnTransport
 * Behaves like a Datagram transport but uses a TURN allocation.
 */
final class Turn implements TurnInterface
{
    public function __construct(private TurnConnectionInterface $connectionProtocol)
    {
    }

    /**
     * Initiates a TURN connection and allocates a relay.
     *
     * @return InternetAddress|null The relayed address the server allocated.
     * @throws RandomException
     */
    #[\Override]
    public function connect(): ?InternetAddress
    {
        return $this->connectionProtocol->connect();
    }

    /**
     * Deletes the TURN allocation and closes the connection.
     *
     * This method sends a request to deallocate the previously allocated resources and then closes the connection.
     * It also logs the successful deletion of the allocation.
     *
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    #[\Override]
    public function delete(): void
    {
        $this->connectionProtocol->delete();
    }

    /**
     * Send the data bytes to the remote peer at the given address.
     * This will bind a TURN channel as necessary.
     *
     * @param string $data The data to send.
     * @param InternetAddress|null $remoteAddress The remote address.
     * @throws RandomException
     */
    #[\Override]
    public function send(string $data, ?InternetAddress $remoteAddress = null): void
    {
        if ($remoteAddress === null) {
            throw new InvalidArgumentException('A remote address is required for TURN data.');
        }

        $this->connectionProtocol->sendData($data, $remoteAddress);
    }

    /**
     * Close the transport.
     * After the TURN allocation has been deleted, the protocol's
     * connection_lost() method will be called with None as its argument.
     *
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    #[\Override]
    public function close(): void
    {
        $this->delete();
    }

    /**
     * End the connection gracefully.
     *
     * @return void
     */
    #[\Override]
    public function end(): void
    {
        $this->connectionProtocol->end();
    }

    /**
     * Resume the connection.
     *
     * @return void
     */
    #[\Override]
    public function resume(): void
    {
        $this->connectionProtocol->resume();
    }

    /**
     * Pause the connection.
     *
     * @return void
     */
    #[\Override]
    public function pause(): void
    {
        $this->connectionProtocol->pause();
    }

    /**
     * Get the local address.
     *
     * @return InternetAddress The local address.
     */
    #[\Override]
    public function getLocalAddress(): InternetAddress
    {
        return $this->connectionProtocol->getLocalAddress();
    }

    /**
     * Get the local host.
     *
     * @return string The local address.
     */
    #[\Override]
    public function getLocalHost(): string
    {
        return $this->connectionProtocol->getLocalHost();
    }

    /**
     * Get the local host.
     *
     * @return int The local address.
     */
    #[\Override]
    public function getLocalPort(): int
    {
        return $this->connectionProtocol->getLocalPort();
    }

    /**
     * Get the remote address.
     *
     * @return InternetAddress|null The remote address.
     */
    #[\Override]
    public function getRemoteAddress(): ?InternetAddress
    {
        return $this->connectionProtocol->getRemoteAddress();
    }

    /**
     * @return IceCandidateInterface|null
     *
     * The candidate type is negotiated with the STUN layer. When the TURN package is used
     * standalone the published danog/php-rtc-stun still types these methods with the concrete
     * Webrtc\ICE\RTCIceCandidate from the (intentionally, due to the circular dependency, not
     * installed) ICE package; psalm cannot see either class here, so it cannot verify the
     * signature against the STUN interface until a STUN release carrying IceCandidateInterface
     * is published. See Webrtc\STUN\IceCandidateInterface in php-rtc-stun.
     *
     * USER OVERRIDE: do not suppress these issues, fix the root cause, instead.
     */
    #[\Override]
    public function getCandidate(): ?IceCandidateInterface
    {
        return $this->connectionProtocol->getCandidate();
    }

    /**
     * @param IceCandidateInterface $candidate
     * @return void
     *
     * See getCandidate() for why the STUN-layer candidate signature cannot be verified here yet.
     *
     * USER OVERRIDE: do not suppress these issues, fix the root cause, instead.
     */
    #[\Override]
    public function setCandidate(IceCandidateInterface $candidate): void
    {
        $this->connectionProtocol->setCandidate($candidate);
    }

    /**
     * Send a STUN message.
     *
     * @param MessageInterface $message
     * @param InternetAddress|null $address
     * @return void
     */
    #[\Override]
    public function sendMessage(MessageInterface $message, ?InternetAddress $address): void
    {
        $this->connectionProtocol->sendMessage($message, $address);
    }

    /**
     * Execute a STUN transaction and return the response.
     *
     * @param MessageInterface $message
     * @param InternetAddress|null $address
     * @param ?string $integrity_key
     * @param int $retransmissions
     * @return array{MessageInterface, InternetAddress|null} The response and where it came from.
     */
    #[\Override]
    public function request(MessageInterface $message, ?InternetAddress $address, ?string $integrity_key, int $retransmissions = 0): array
    {
        return $this->connectionProtocol->request($message, $address, $integrity_key, $retransmissions);
    }

    /**
     * Remove a pending transaction by its ID.
     *
     * @param string $transactionId
     * @return void
     */
    #[\Override]
    public function removeTransaction(string $transactionId): void
    {
        $this->connectionProtocol->removeTransaction($transactionId);
    }

    /**
     * Protocol ID
     *
     * @return string
     */
    #[\Override]
    public function getId(): string
    {
        return $this->connectionProtocol->getId();
    }

    /**
     * Gets relayed address
     *
     * @return InternetAddress|null
     */
    #[\Override]
    function getRelayedAddress(): ?InternetAddress
    {
        return $this->connectionProtocol->getRelayedAddress();
    }

    /**
     * Gets relayed host
     *
     * @return string
     */
    #[\Override]
    function getRelayedHost(): string
    {
        $relayedAddress = $this->connectionProtocol->getRelayedAddress();
        assert($relayedAddress !== null);

        return $relayedAddress->getAddress();
    }

    /**
     * Gets relayed port
     *
     * @return int
     */
    #[\Override]
    function getRelayedPort(): int
    {
        $relayedAddress = $this->connectionProtocol->getRelayedAddress();
        assert($relayedAddress !== null);

        return $relayedAddress->getPort();
    }

    /**
     * Create an instance of TURN object based on the configuration provided
     *
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver
     * @param LoggerInterface|null $logger
     * @return Turn
     */
    #[\Override]
    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, ?LoggerInterface $logger = null): Turn
    {
        $turnConnection = $configuration->getTurnTransport() === "tcp" ?
            TurnTcpConnection::create($configuration, $receiver, $logger) : TurnUdpConnection::create($configuration, $receiver, $logger);

        return new static($turnConnection);
    }
}
