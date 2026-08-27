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
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\ReceiverInterface;

interface TurnConnectionInterface extends IceConnectionProtocolInterface
{
    public function connect(): ?InternetAddress;

    public function delete(): void;

    public function getRelayedAddress(): ?InternetAddress;

    public function sendData(string $data, InternetAddress $address): void;

}
