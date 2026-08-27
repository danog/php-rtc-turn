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
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\ReceiverInterface;

interface TurnInterface extends IceConnectionProtocolInterface
{
    public function connect(): ?InternetAddress;

    public function delete(): void;

    function getRelayedAddress(): ?InternetAddress;

    function getRelayedHost(): string;

    function getRelayedPort(): int;

    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, LoggerInterface $logger): Turn;
}
