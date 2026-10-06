# Integrations are modules behind small interfaces with swappable adapters

Distribution (`ChannelConnector`), ZanReporting and FiscalReceipting are separate modules. Each exposes a small interface and hides vendor protocol, batching, rate limiting and retries behind it. Core PMS code calls only the interfaces. The channel manager, ZAN Gateway and VFMS are adapters, and each module ships a fake adapter for Pest. A direct Booking.com connection (ADR 0001) is then a new `ChannelConnector` adapter, not a rewrite, and the full Booking.com lifecycle can be tested without any external system.
