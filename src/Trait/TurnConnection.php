<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\TURN\Trait;

use Amp\Socket\InternetAddress;
use Exception;
use Random\RandomException;
use Amp\DeferredFuture;
use Amp\Future;
use Revolt\EventLoop;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\STUN\Enum\MessageAttribute;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Enum\MessageMethod;
use Webrtc\STUN\Exception\TransactionException;
use Webrtc\STUN\Exception\TransactionExceptionInterface;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageInterface;

/**
 * This trait represents a TURN connection.
 *
 * TURN (Traversal Using Relays around NAT) is a protocol that allows clients
 * behind firewalls or Network Address Translators (NATs) to communicate with each other.
 */
trait TurnConnection
{
    private const UDP_TRANSPORT = 0x11000000;
    private const TCP_TRANSPORT = 0x06000000;

    /**
     * @var int The lifetime of the connection in seconds.
     */
    private int $lifetime = 600;

    /**
     * @var ?string The integrity key to sign the package.
     */
    private ?string $integrityKey = null;

    /**
     * @var InternetAddress|null The relayed address of the connection.
     */
    private ?InternetAddress $relayedAddress = null;

    /**
     * @var string The nonce used for authentication.
     */
    private string $nonce = "";

    /**
     * @var ?string The realm for authentication (if provided by the server).
     */
    private ?string $realm = null;

    /**
     * @var ?string Handle of the timer used for refreshing the connection.
     */
    private ?string $refreshPeriodicTimer = null;

    /**
     * @var array<string, Future> Channel binds in flight, keyed by peer address.
     *
     * Two sends to the same unbound peer must not each allocate a channel, so the second
     * waits on the bind the first started rather than starting its own.
     */
    private array $peerBinding = [];

    /**
     * @var array<string, int> An associative array to map peers to channels.
     * Key: peer address, Value: channel number.
     */
    private array $peerToChannel = [];

    /**
     * @var array<int, InternetAddress> An associative array to map channels to peers.
     * Key: channel number, Value: peer address.
     */
    private array $channelToPeer = [];

    /**
     * @var int The starting and ending range for channel numbers (inclusive).
     */
    private int $channelNumber = 0x4000; // to 0x7FFF (reference: https://datatracker.ietf.org/doc/html/rfc5766#section-11)

    /**
     * @var int The refresh time for channels in seconds.
     */
    private int $channelRefreshTime = 500;

    /**
     * @var array<int, int> An associative array to store channel refresh timestamps.
     * Key: channel number, Value: timestamp (seconds since epoch).
     */
    private array $channelRefreshAt = [];



    /**
     * Get the lifetime of the connection.
     *
     * @return int The lifetime in seconds.
     */
    public function getLifetime(): int
    {
        return $this->lifetime;
    }

    /**
     * Set the lifetime of the connection.
     *
     * @param int $lifetime The lifetime in seconds.
     * @throws \InvalidArgumentException if the lifetime is less than 0.
     */
    public function setLifetime(int $lifetime): void
    {
        if ($lifetime < 0) {
            throw new \InvalidArgumentException('Lifetime cannot be less than 0');
        }

        $this->lifetime = $lifetime;
    }

    /**
     * Binds a channel to a peer address.
     *
     * @param int $channelNumber The channel number.
     * @param InternetAddress $address The peer address.
     * @return void Returns once the channel is bound.
     * @throws RandomException
     */
    private function channelBind(int $channelNumber, InternetAddress $address): void
    {
        $messageAttr = [
            MessageAttribute::CHANNEL_NUMBER->name => $channelNumber,
            MessageAttribute::XOR_PEER_ADDRESS->name => $address
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::CHANNEL_BIND, $messageAttr);

        $this->requestWithRetry($message);
    }

    /**
     * Initiates a TURN connection and allocates a relay.
     *
     * This method establishes a connection with a TURN server and allocates resources.
     *
     * @return InternetAddress|null The relayed address the server allocated.
     * @throws RandomException
     */
    public function connect(): ?InternetAddress
    {
        $messageAttr = [
            MessageAttribute::LIFETIME->name => $this->lifetime,
            MessageAttribute::REQUESTED_TRANSPORT->name => self::UDP_TRANSPORT
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::ALLOCATE, $messageAttr);

        [$response] = $this->requestWithRetry($message);

        $timeToExpiry = null;
        if ($response instanceof Message) {
            $timeToExpiry = $response->attributes()->get(MessageAttribute::LIFETIME);
            $relayedAddress = $response->attributes()->get(MessageAttribute::XOR_RELAYED_ADDRESS);
            assert($relayedAddress instanceof InternetAddress);
            $this->relayedAddress = $relayedAddress;
        }

        if (is_int($timeToExpiry) && $timeToExpiry !== 0) {
            // Refresh well before the allocation expires, as RFC 8656 section 3.2 advises.
            $this->refreshPeriodicTimer = EventLoop::repeat(
                $timeToExpiry * 5 / 6,
                $this->onRefreshTimer(...),
            );
        }

        return $this->relayedAddress;
    }

    /**
     * Gets relayed address
     *
     * @return InternetAddress|null
     */
    public function getRelayedAddress(): ?InternetAddress
    {
        return $this->relayedAddress;
    }

    /**
     * Handles errors that occur during transmission.
     *
     * This method logs the error message and forwards it to the receiver for further handling.
     *
     * @param Throwable $e The exception object representing the error.
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    protected function onError(Throwable $e): void
    {
        $this->logger?->error("An error occurred while transmitting", ["ErrorMessage" => $e->getMessage()]);
        $this->receiver->onError($e);
        $this->delete();
    }

    /**
     * Called when the connection is closed.
     *
     * This method logs the closure event and informs the receiver about the closed connection.
     *
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    protected function onClose(): void
    {
        $this->logger?->debug("The connection has been closed", ["Address" => $this->getLocalAddress()]);
        $this->receiver->onClose();
        @$this->delete();
    }

    /**
     * Called when the connection is ended (similar to onClose).
     *
     * This method logs the ending event and informs the receiver about the ended connection.
     * The behavior is similar to `onClose` but might be used for specific purposes depending on the implementation.
     *
     * @return void
     */
    protected function onEnded(): void
    {
        $this->logger?->debug("The connection has been Ended", ["Address" => $this->getLocalAddress()]);
        $this->receiver->onClose();
    }

    /**
     * Handles received messages.
     *
     * This method parses received data and determines the message type based on its format.
     * It then calls appropriate methods for further handling depending on the message class and transaction ID.
     *
     * @param string $data The received data.
     * @param InternetAddress $peerAddress The address of the peer who sent the message.
     * @return void
     * @throws RandomException
     */
    public function onReceived(string $data, InternetAddress $peerAddress): void
    {
        if (strlen($data) >= 4 && $this->isChannelData($data)) {
            $unpacked = unpack("nChannel/nLength", substr($data, 0, 4));
            assert($unpacked !== false);
            $channel = (int) $unpacked['Channel'];
            $length = (int) $unpacked['Length'];
            if (strlen($data) >= $length + 4 && $peerAddress = $this->channelToPeer[$channel] ?? null) {
                $payload = substr($data, 4, $length);
                if ($message = $this->decodeMessage($payload)) {
                    $this->handleMessage($message, $peerAddress, $data);
                } else {
// The STUN layer's candidate type (Webrtc\ICE\RTCIceCandidate in older
                    // published releases of php-rtc-stun, Webrtc\STUN\IceCandidateInterface in the
                    // current one) may not be resolvable in some environments, because the ICE
                    // package (which contains the concrete RTCIceCandidate) is intentionally not a
                    // dependency of the TURN package. All we need from it is the component id.
                    $this->receiver->onDataReceived($payload, $this->getCandidate()?->getComponentId() ?? 0);
                }
            }

            return;
        }

        if ($message = $this->decodeMessage($data)) {
            $messageClass = $message->getMessageClass();
            $transactionId = $message->getTransactionId();

            if (in_array($messageClass, [MessageClass::RESPONSE, MessageClass::ERROR]) && isset($this->transactionIds[$transactionId])) {
                $transaction = $this->transactionIds[$transactionId];
                $transaction->responseReceived($message, $peerAddress);
            }elseif ($messageClass === MessageClass::REQUEST) {
                $this->receiver->onRequestReceived($message, $peerAddress, $this, $data);
            }
        }
    }

    /**
     * Attempts to decode a received message.
     *
     * This method tries to decode the provided data into a MessageInterface object.
     * It returns the decoded message on success or `false` if the decoding fails.
     *
     * @param string $data The data to be decoded.
     * @return MessageInterface|false The decoded message object or `false` if decoding fails.
     * @throws RandomException
     */
    private function decodeMessage(string $data): MessageInterface|false
    {
        try {
            return Message::decode($data);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Handles a newly received message.
     *
     * This method takes a decoded message object and processes it based on its class and transaction ID.
     * It performs actions like updating internal state, notifying the receiver, or handling errors.
     *
     * @param MessageInterface $message The decoded message object.
     * @param InternetAddress $address The address of the peer who sent the message.
     * @param string $data The raw received data (might be used for additional processing).
     * @return void
     */
    private function handleMessage(MessageInterface $message, InternetAddress $address, string $data): void
    {

        $this->logger?->info("A new TURN message has been received", ["Message" => $message->humanReadable(), "FromAddress" => $address]);

        $messageClass = $message->getMessageClass();
        $transactionId = $message->getTransactionId();

        if (in_array($messageClass, [MessageClass::RESPONSE, MessageClass::ERROR]) && isset($this->transactionIds[$transactionId])) {
            $transaction = $this->transactionIds[$transactionId];
            $transaction->responseReceived($message, $address);
        } elseif ($messageClass === MessageClass::REQUEST) {
            $this->receiver->onRequestReceived($message, $address, $this, $data);
        }
    }

    /**
     * Checks if the received data is formatted as a ChannelData message.
     *
     * This method analyzes the first byte of the data to determine if it follows the ChannelData message format.
     * It returns `true` if the format matches and `false` otherwise.
     *
     * @param string $data The received data.
     * @return bool Whether the data is formatted as a ChannelData message.
     * @link https://datatracker.ietf.org/doc/html/rfc5766#section-2.5 (RFC 5766 - ChannelData message format)
     */
    private function isChannelData(string $data): bool
    {
        return (ord($data[0]) & 0xC0) == 0x40;
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
    public function delete(): void
    {
        if ($this->refreshPeriodicTimer !== null) {
            EventLoop::cancel($this->refreshPeriodicTimer);
            $this->refreshPeriodicTimer = null;
        }

        $messageAttr = [
            MessageAttribute::LIFETIME->name => 0
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::REFRESH, $messageAttr);
        try {
            $this->requestWithRetry($message);
            $this->logger?->info("TURN allocation deleted", ["RelayedAddress" => $this->relayedAddress]);
        } catch (Throwable) {
            $this->logger?->error("Could not TURN allocation deleted", ["RelayedAddress" => $this->relayedAddress]);
        } finally {
            $this->close();
        }

    }

    /**
     * Refreshes the TURN allocation.
     *
     * This method sends a request to refresh the lifetime of the current TURN allocation.
     * It updates the internal state and logs the successful refresh with the new expiry time.
     * In case of errors, it logs the failure with
     *
     * @return void
     * @throws RandomException
     */
    public function onRefreshTimer(): void
    {
        $this->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function turnSerializeReplacements(): array
    {
        return [
            'refreshPeriodicTimer' => $this->refreshPeriodicTimer !== null,
            'peerBinding' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function consumeTurnRefreshFlag(array &$data): bool
    {
        $restartRefresh = false;
        /**
         * @var mixed $value
         */
        foreach ($data as $key => $value) {
            if (str_ends_with($key, "\0refreshPeriodicTimer")) {
                $restartRefresh = $value === true;
                $data[$key] = null;
            }
        }

        return $restartRefresh;
    }

    protected function restoreTurnTimers(bool $restartRefresh): void
    {
        $this->peerBinding = [];
        $this->refreshPeriodicTimer = null;
        if ($restartRefresh && $this->relayedAddress !== null) {
            $this->refreshPeriodicTimer = EventLoop::repeat(
                $this->lifetime * 5 / 6,
                $this->onRefreshTimer(...),
            );
        }
    }

    private function refresh(): void
    {
        $messageAttr = [
            MessageAttribute::LIFETIME->name => $this->lifetime
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::REFRESH, $messageAttr);

        try {
            [$response] = $this->requestWithRetry($message);

            if ($response instanceof Message) {
                $this->logger?->info("TURN allocation refreshed", ["RelatedAddress" => $this->relayedAddress, "ExpiresInSeconds" => $response->attributes()->get(MessageAttribute::LIFETIME)]);
            }
        } catch (Throwable $e) {
            $this->logger?->error("TURN allocation refreshed failed", ["RelatedAddress" => $this->relayedAddress, "ErrorMessage" => $e->getMessage()]);
        }
    }

    /**
     * Sends a request with retry logic for authentication errors.
     *
     * This method sends the given message and handles potential authentication failures. If an authentication error occurs, it updates the long-term credentials and retries the request with the updated credentials.
     *
     * @param MessageInterface $message The message to be sent.
     * @return array{MessageInterface, InternetAddress|null} The response and where it came from.
     * @throws TransactionExceptionInterface If the request failed for a reason retrying cannot fix.
     */
    private function requestWithRetry(MessageInterface $message): array
    {
        $this->addAuthenticatedAttributes($message);

        try {
            return $this->request($message, null, $this->integrityKey);
        } catch (TransactionException $e) {
            return $this->handleRetryRequestError($e, $message);
        }
    }

    /**
     * If an authentication error occurs, it updates the long-term credentials and retries the request with the updated credentials.
     *
     * @param TransactionException $error
     * @param MessageInterface $message
     * @return array{MessageInterface, InternetAddress|null} The response to the retried request.
     * @throws RandomException
     */
    private function handleRetryRequestError(TransactionException $error, MessageInterface $message): array
    {
        $stunMessage = $error->getStunMessage();
        if ($stunMessage === null) {
            $this->logger?->error("Error processing request: {$error->getMessage()}");

            throw $error;
        }

        $errorCodeAttribute = $stunMessage->attributes()->get(MessageAttribute::ERROR_CODE);
        assert(is_array($errorCodeAttribute) && isset($errorCodeAttribute[0]) && is_int($errorCodeAttribute[0]));
        $errorCode = $errorCodeAttribute[0];

        if ($this->configuration->getTurnUsername() !== null &&
            $this->configuration->getTurnPassword() !== null &&
            $stunMessage->attributes()->has(MessageAttribute::NONCE) &&
            ($errorCode === 401 || ($errorCode === 438 && $this->realm !== null))) {
            // Update long-term credentials
            $nonce = $stunMessage->attributes()->get(MessageAttribute::NONCE);
            assert(is_string($nonce));
            $this->nonce = $nonce;
            if ($errorCode == 401) {
                $realm = $stunMessage->attributes()->get(MessageAttribute::REALM);
                assert(is_string($realm));
                $this->realm = $realm;
            }

            // Retry request with authentication
            $message->setTransactionId(random_bytes(12));
            $this->makeIntegrityKey();
            $this->addAuthenticatedAttributes($message);

            try {
                return $this->request($message, null, $this->integrityKey);
            } catch (TransactionException $e) {
                $this->logger?->error("Failed to request with retry: {$e->getMessage()}");

                throw new TransactionException("Failed to request with retry: {$e->getMessage()}", $e->getCode(), $e);
            }
        }

        $this->logger?->error("Error processing request: {$error->getMessage()}");

        throw $error;
    }

    /**
     * @param MessageInterface $message
     * @return void
     */
    private function addAuthenticatedAttributes(MessageInterface $message): void
    {
        if ($this->integrityKey !== null) {
            $messageAttr = [
                MessageAttribute::USERNAME->name => $this->configuration->getTurnUsername(),
                MessageAttribute::NONCE->name => $this->nonce,
                MessageAttribute::REALM->name => $this->realm
            ];
            $message->attributes()->merge($messageAttr);
        }
    }

    /**
     * Creates an integrity key for authentication.
     *
     * This method generates an MD5 hash of the username, realm, and password to create the integrity key.
     *
     * @return void The generated integrity key.
     */
    private function makeIntegrityKey(): void
    {
        $this->integrityKey = md5(implode(":", [$this->configuration->getTurnUsername(), $this->realm, $this->configuration->getTurnPassword()]), true);
    }

    /**
     * Sends data to a specific address.
     *
     * Data travels over a TURN channel, which has to be bound to the peer first and rebound
     * before it expires. Both happen inline here: the fiber simply waits for the bind.
     *
     * @param string $data The data to be sent.
     * @param InternetAddress $address The address of the recipient.
     * @return void
     * @throws RandomException
     */
    public function sendData(string $data, InternetAddress $address): void
    {
        $addressKey = $address->toString();
        $this->ensureChannel($address, $addressKey);
        $this->sendPacket($this->peerToChannel[$addressKey], $data);
    }

    /**
     * Make sure a live channel exists for a peer, binding or rebinding it if not.
     *
     * @throws RandomException
     */
    private function ensureChannel(InternetAddress $address, string $addressKey): void
    {
        // Another fiber may already be binding this peer; take its result rather than
        // allocating a second channel for the same address.
        while (isset($this->peerBinding[$addressKey])) {
            $this->peerBinding[$addressKey]->await();
        }

        $now = time();
        $channel = $this->peerToChannel[$addressKey] ?? null;

        if ($channel !== null && $now <= ($this->channelRefreshAt[$channel] ?? 0)) {
            return;
        }

        $channel ??= $this->channelNumber++;
        $deferred = new DeferredFuture();
        $this->peerBinding[$addressKey] = $deferred->getFuture();

        try {
            $this->channelBind($channel, $address);

            $this->channelRefreshAt[$channel] = $now + $this->channelRefreshTime;
            $this->channelToPeer[$channel] = $address;
            $this->peerToChannel[$addressKey] = $channel;

            unset($this->peerBinding[$addressKey]);
            $deferred->complete();
        } catch (\Throwable $e) {
            unset($this->peerBinding[$addressKey]);
            $deferred->error($e);

            throw $e;
        }
    }

    /**
     * @param int $channel
     * @param string $data
     * @return void
     */
    private function sendPacket(int $channel, string $data): void
    {
        $header = pack("nn", $channel, strlen($data));
        $this->send($header . $data);
    }
}
