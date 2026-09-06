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
use Amp\Socket\Socket;
use Throwable;
use Webrtc\Mixin\SerializableState;
use Webrtc\STUN\BaseProtocol;
use function Amp\async;
use function Amp\Socket\connect;

/**
 * Abstract TCP Connection Class
 *
 * Provides base functionality for TCP-based communication in TURN protocol.
 * Handles TCP socket operations, event forwarding, and requires implementation
 * of protocol-specific message handling.
 */
abstract class TCPConnection extends BaseProtocol
{
    /** Whether the read loop should keep delivering data. */
    private bool $paused = false;

    /**
     * TCP connection constructor.
     *
     * @param Socket $socket The established TCP socket connection
     */
/**
     * TCP connection constructor.
     *
     * @param Socket $socket The established TCP socket connection
     */
    public function __construct(protected Socket $socket)
    {
        $this->listen();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return SerializableState::export($this, [
            'socket' => ['_tcp' => (string) $this->socket->getRemoteAddress()],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $remote = null;
        /**
         * @var mixed $value
         */
        foreach ($data as $key => $value) {
            if (!is_array($value) || !array_key_exists('_tcp', $value)) {
                continue;
            }
            unset($data[$key]);
            if (is_string($value['_tcp'])) {
                $remote = $value['_tcp'];
            }
        }
        SerializableState::import($this, $data);
        $this->restoreTcpSocket($remote);
    }

    /**
     * Reconnect the TCP socket after unserialize. Subclasses may wrap TLS around this.
     */
    protected function restoreTcpSocket(?string $remote): void
    {
        if ($remote === null || $remote === '') {
            return;
        }
        $this->socket = connect($remote);
        $this->listen();
    }

    protected ?InternetAddress $remoteAddress = null;

    /**
     * Deliver incoming data to onTCPReceived() until the peer closes.
     *
     * Reading runs in its own fiber rather than through an event emitter, so a read error
     * reaches onError() rather than becoming an unobserved rejection.
     */
    protected function listen(): void
    {
        // The read loop must not capture $this, or the fiber the event loop parks in read() would
        // pin this connection forever and an unset()+gc could never reclaim it. It holds the socket
        // and only a weak reference back; __destruct() closes the socket to unwind the fiber once
        // the last real reference is gone.
        $weak = \WeakReference::create($this);
        $socket = $this->socket;
        async(static function () use ($weak, $socket): void {
            try {
                while (($chunk = $socket->read()) !== null) {
                    $self = $weak->get();
                    if ($self === null) {
                        $socket->close();

                        return;
                    }
                    if ($self->paused) {
                        continue;
                    }

                    // Dispatch in its own fiber: handling data can block on a transaction of
                    // its own, and doing that inline would stop this loop from reading the
                    // reply it is waiting for.
                    async(static fn () => $weak->get()?->onTCPReceived($chunk))->ignore();
                    unset($self);
                }

                $self = $weak->get();
                if ($self !== null) {
                    $self->onEnded();
                    $self->onClose();
                }
            } catch (Throwable $e) {
                $weak->get()?->onError($e);
            }
        });
    }

    /**
     * Release the socket when the connection is garbage-collected, unblocking the parked read loop.
     */
    public function __destruct()
    {
        // socket is always set here: the constructor connects it, a successful unserialize
        // reconnects it, and __destruct never runs on an object whose __unserialize threw.
        $this->socket->close();
    }

    /**
     * Send data over the TCP connection
     *
     * @param string $data The data to send
     * @param InternetAddress|null $remoteAddress Unused parameter (maintained for interface compatibility)
     * @return void
     */
    #[\Override]
    public function send(string $data, ?InternetAddress $remoteAddress = null): void
    {
        $this->socket->write($this->padded($data));
    }

    /**
     * Immediately close the TCP connection
     *
     * @return void
     */
    #[\Override]
    public function close(): void
    {
        $this->socket->close();
    }

    /**
     * End the TCP connection gracefully after writing pending data
     *
     * @return void
     */
    #[\Override]
    public function end(): void
    {
        $this->socket->end();
    }

    /**
     * Resume reading from the connection
     *
     * @return void
     */
    #[\Override]
    public function resume(): void
    {
        $this->paused = false;
    }

    /**
     * Pause reading from the connection
     *
     * @return void
     */
    #[\Override]
    public function pause(): void
    {
        $this->paused = true;
    }

    /**
     * Get the local connection address
     *
     * @return InternetAddress The local address
     */
    #[\Override]
    public function getLocalAddress(): InternetAddress
    {
        return InternetAddress::fromString($this->socket->getLocalAddress()->toString());
    }

    /**
     * Get the local host address
     *
     * @return string The local hostname/IP address
     */
    #[\Override]
    public function getLocalHost(): string
    {
        return $this->getLocalAddress()->getAddress();
    }

    /**
     * Get the local port number
     *
     * @return int The local port number
     */
    #[\Override]
    public function getLocalPort(): int
    {
        return $this->getLocalAddress()->getPort();
    }

    /**
     * Get the remote connection address
     *
     * @return InternetAddress|null The remote address or null if not connected
     */
    #[\Override]
    public function getRemoteAddress(): ?InternetAddress
    {
        return InternetAddress::fromString($this->socket->getRemoteAddress()->toString());
    }

    /**
     * Handle received TCP data (abstract method)
     *
     * @param string $data The received data
     * @return void
     */
    protected abstract function onTCPReceived(string $data): void;

    /**
     * Handle connection errors (abstract method)
     *
     * @param Throwable $e The thrown exception
     * @return void
     */
    protected abstract function onError(Throwable $e): void;

    /**
     * Handle connection end event (abstract method)
     *
     * @return void
     */
    protected abstract function onEnded(): void;

    /**
     * Handle connection close event (abstract method)
     *
     * @return void
     */
    protected abstract function onClose(): void;

    /**
     * Apply protocol-specific padding to data (abstract method)
     *
     * @param string $data The data to pad
     * @return string The padded data
     */
    protected abstract function padded(string $data): string;

}
