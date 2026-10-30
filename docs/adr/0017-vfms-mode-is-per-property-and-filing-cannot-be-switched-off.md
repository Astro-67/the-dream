# VFMS mode is set per property, and filing cannot be switched off

Each property has a VFMS mode: **fake** (Thedream's own adapter, ADR 0010), **ZRA test** or **production**. Production uses the property's own integration ID and token, stored encrypted and never shown after saving (FSC-01). ZRA test uses the shared test credentials ZRA prints in its test guide; they come from server configuration, never from the repository or a property form, because every vendor shares that test business unit and its data. CI always runs fake; the official server runs ZRA test until the pilot goes live.

Unlike Uhuru, which has an "Auto-fire fiscal" switch, a Zanzibar property cannot turn filing off. Check-in, extra services and check-out always queue their VFMS call through the outbox; only the mode decides where it goes.

## Considered options

- **One environment-wide VFMS mode.** Rejected: the pilot hotel goes live while other properties on the same server are still testing.
- **Copy Uhuru's auto-fire switch.** Rejected: a stay filed by hand later, or never, is a compliance gap (ADR 0015 lets us depart from Uhuru where it is wrong).

## Consequences

- The fiscal check-out report shows when it came from ZRA test, so a test report is never mistaken for a real one.
- The report's QR code encodes Thedream's own `/r/{report number}` link, which redirects to the ZRA viewer for the property's mode. A change in ZRA's viewer address is a configuration change, and the QR is drawn on our server, not by a third-party image service.
