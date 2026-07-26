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

use Amp\Socket\Socket;
use Throwable;
use Webrtc\STUN\BaseProtocol;
use function Amp\async;
use function parse_url;

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
    public function __construct(protected Socket $socket)
    {
        $this->listen();
    }

    /**
     * Deliver incoming data to onTCPReceived() until the peer closes.
     *
     * Reading runs in its own fiber rather than through an event emitter, so a read error
     * reaches onError() rather than becoming an unobserved rejection.
     */
    private function listen(): void
    {
        async(function (): void {
            try {
                while (($chunk = $this->socket->read()) !== null) {
                    if ($this->paused) {
                        continue;
                    }

                    $this->onTCPReceived($chunk);
                }

                $this->onEnded();
                $this->onClose();
            } catch (Throwable $e) {
                $this->onError($e);
            }
        });
    }

    /**
     * Send data over the TCP connection
     *
     * @param string $data The data to send
     * @param string|null $remoteAddress Unused parameter (maintained for interface compatibility)
     * @return void
     */
    public function send(string $data, ?string $remoteAddress = null): void
    {
        $this->socket->write($this->padded($data));
    }

    /**
     * Immediately close the TCP connection
     *
     * @return void
     */
    public function close(): void
    {
        $this->socket->close();
    }

    /**
     * End the TCP connection gracefully after writing pending data
     *
     * @return void
     */
    public function end(): void
    {
        $this->socket->end();
    }

    /**
     * Resume reading from the connection
     *
     * @return void
     */
    public function resume(): void
    {
        $this->paused = false;
    }

    /**
     * Pause reading from the connection
     *
     * @return void
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    /**
     * Get the local connection address
     *
     * @return string The local address in "host:port" format
     */
    public function getLocalAddress(): string
    {
        return (string) $this->socket->getLocalAddress();
    }

    /**
     * Get the local host address
     *
     * @return string The local hostname/IP address
     */
    public function getLocalHost(): string
    {
        return parse_url($this->getLocalAddress(), PHP_URL_HOST);
    }

    /**
     * Get the local port number
     *
     * @return int The local port number
     */
    public function getLocalPort(): int
    {
        return parse_url($this->getLocalAddress(), PHP_URL_PORT);
    }

    /**
     * Get the remote connection address
     *
     * @return string|null The remote address in "host:port" format or null if not connected
     */
    public function getRemoteAddress(): ?string
    {
        $address = $this->socket->getRemoteAddress();

        return $address === null ? null : (string) $address;
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
