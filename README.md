# TURN Library for PHP

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A PHP implementation of TURN (Traversal Using Relays around NAT) to facilitate NAT traversal for WebRTC by relaying media through a TURN server when peer-to-peer fails.

##  Features

- Encode and decode TURN allocation and channel messages
- Relay address allocation
- Integrates with STUN and ICE workflows
- Message integrity and credential verification

## Requirements

- PHP ≥ 8.4

## Documentation

This package is part of the PHP WebRTC library. For complete documentation, examples, and API reference, visit:

[PHP WebRTC Documentation](https://www.quasarstream.com/php-webrtc)

## Credits

### Authors

- **Amin Yazdanpanah**  
  - Website: [aminyazdanpanah.com](https://www.aminyazdanpanah.com)
  - Email: [github@aminyazdanpanah.com](mailto:github@aminyazdanpanah.com)

- **Sana Moniri**  
  - GtiHub: [sanamoniri](https://github.com/sanamoniri)

## Reporting Issues

Found a bug? Please report it on our [issues](https://github.com/php-webrtc/TURN/issues).

## License

BSD 3-Clause License. See [LICENSE](LICENSE) for details.

## References

- [RFC 5766 – Traversal Using Relays around NAT (TURN)](https://datatracker.ietf.org/doc/html/rfc5766)
- [RFC 8656 – TURNbis](https://datatracker.ietf.org/doc/html/rfc8656)