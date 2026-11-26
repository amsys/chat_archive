<!--
  - SPDX-FileCopyrightText: 2026 Martin <dev@amsys.cz>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Support

## Read first

- [User guide](docs/USER-GUIDE.md) — setup, import, search, troubleshooting.
- [Developer guide](docs/DEVELOPER-GUIDE.md) — architecture, API, build, tests.

## Report a bug or ask a question

Open an issue: <https://github.com/amsys/chat_archive/issues>

Give the Nextcloud version, the app version, the platform of the source
(WhatsApp or WhatsApp Business), and whether the import ran from cron or
from "Import now". Add the text that the source shows after "Import failed:"
or "Live sync failed:", and the
related lines from `nextcloud.log`.

Never paste your 64-digit backup key. Remove message text, phone numbers
and file names from a log extract before you send it.

For other messages, write to <dev@amsys.cz>.

Other projects: <https://github.com/amsys>

## Privacy

The archive stays on your server. Without live sync, the app makes no
outbound connection: it reads the backup file from your Nextcloud files and
writes rows into your Nextcloud database. With live sync, the app connects
to the WAHA server that the administrator set, and to no other server.

The archive database holds no media files, only pointers to the files in
your Nextcloud. A live sync stores the files that it fetches in the
subfolder `WAHA` of the source folder.

The app stores the backup key encrypted and never shows it again; see
[Security boundaries](docs/DEVELOPER-GUIDE.md#security-boundaries). The
decrypted database exists only in the memory of the server, for the length
of one import run. It never goes to disk.

Every API route checks that the source belongs to the account that calls it.

## Donate

BTC

[`bc1qmsdth54d65ygcq98wm5kmlkhk6s0xfg52fqrty`](bitcoin:bc1qmsdth54d65ygcq98wm5kmlkhk6s0xfg52fqrty?message=Website%20amsys.cz)

XMR

[`8BWektFzAp88qt2H6jCgY5hNJtz5LXZNRUPyFzpkyhq97Zn8CHsLSYRTjaGj5p1ZU3aeD1UX8WDJLCH73RdJkTRXBEK3YoU`](monero:8BWektFzAp88qt2H6jCgY5hNJtz5LXZNRUPyFzpkyhq97Zn8CHsLSYRTjaGj5p1ZU3aeD1UX8WDJLCH73RdJkTRXBEK3YoU?tx_description=Website%20amsys.cz)
