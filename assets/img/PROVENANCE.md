# Courier asset provenance

The files in this directory identify the courier integrations built into BG Commerce Suite.
They were imported into the unified plugin from the project owner's first-party BGCS release
artifact when version 3.0.0 was reconstructed.

| File | Project origin | Runtime use |
| --- | --- | --- |
| `speedy.svg` | Earlier first-party `bgcs-speedy` add-on | Speedy module identity |
| `econt.png` | BG Commerce Suite 3.0.0 first-party release artifact | Econt module identity |
| `boxnow.png` | BG Commerce Suite 3.0.0 first-party release artifact | BOX NOW module identity |
| `pigeon.png` | BG Commerce Suite 3.0.0 first-party release artifact | Pigeon Express module identity |

No file in this directory is loaded from, or creates a runtime dependency on, another courier
integration plugin. Courier names and marks remain the property of their respective owners and
are used only for service identification. See `../../THIRD-PARTY-NOTICES.md`.
