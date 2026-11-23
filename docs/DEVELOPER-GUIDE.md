# Developer guide

Architecture, mechanism, the OCS API, and how to build, test and release the
app. For setup and daily use see the [user guide](USER-GUIDE.md).

## How it works

```
phone     WhatsApp writes msgstore.db.crypt15 every night (end-to-end encrypted, 64-digit key)
phone     Nextcloud app auto-uploads WhatsApp/Databases and WhatsApp/Media/* to your Nextcloud
server    Chat Archive decrypts the backup into memory only, reads the SQLite
          database from there, adds new chats, messages, media and calls
server    media rows get a Nextcloud file id by path, then by file name
browser   Chat Archive app: chats, messages, search, calls, previews by file id
```

The copy of the chats lives in the Nextcloud database. The media files stay
in the files of the user. The archive points to each file by its Nextcloud
file id.

## Components

| Component | Location | Function |
|---|---|---|
| App registration | `appinfo/info.xml` | Declares the app id, the cron job, the occ command, the settings pages, the full text search provider and the navigation entry. |
| Application | `lib/AppInfo/Application.php` | Registers the file listener, the backup listener and the unified search provider. |
| Migrations | `lib/Migration/` | Create and change the five tables. |
| Source | `lib/Db/Source.php`, `lib/Db/SourceMapper.php` | One configured backup or live source: provider, folder, encrypted secret, delete policy, import state, live sync fields. `SourceMapper::queue()` and `claim()` guard the import state. |
| Archive store | `lib/Db/ArchiveStore.php` | All reads and writes of chats, messages, media and calls. |
| Provider | `lib/Source/IProvider.php` | One chat platform. Finds the backup file and opens it. |
| Backup reader | `lib/Source/IBackupReader.php` | Yields platform-neutral rows: chats, messages, calls. |
| WhatsApp | `lib/Source/WhatsApp/` | `Crypt15` decrypts the backup. `MsgstoreReader` reads the SQLite file. `WhatsAppProvider` connects the two. |
| Provider registry | `lib/Source/ProviderRegistry.php` | Known providers by id. |
| Import service | `lib/Service/ImportService.php` | Backup file to rows. Safe to run again. |
| Sync service | `lib/Service/SyncService.php` | WAHA to rows, one source per run. See *Live sync*. |
| WAHA | `lib/Waha/` | `WahaClient` (GET requests only), `WahaConfig` (app config and the session assignments), `WahaMapper` (WAHA JSON to neutral rows), `WahaException` (a short error code). |
| Preserve | `lib/Service/Preserve.php` | Reads and writes the preserve switch of a user. On by default. |
| Media linker | `lib/Service/MediaLinker.php` | Gives media rows a file id. |
| Contact names | `lib/Service/ContactNames.php` | Phone number to name from the address books of the user. `namesFor()` runs one contacts search for every peer in the request, not one search per number. |
| Full text search | `lib/Service/FullTextSearchService.php`, `lib/FullTextSearch/ChatProvider.php` | The on/off switch and the index provider for the `fulltextsearch` app. |
| Unified search | `lib/Search/MessageSearchProvider.php` | Offers the messages of the calling user to the Nextcloud search box. |
| Click action | `lib/Service/ClickAction.php` | Reads and writes the left-click action of the user: `view`, `nc` or `custom`, and the URL template. |
| File listener | `lib/Listener/FileListener.php` | Keeps file pointers correct when the user moves or deletes files. |
| Backup listener | `lib/Listener/BackupListener.php` | Queues an import when a write creates or changes the backup file of a source. |
| Settings | `lib/Settings/Admin.php`, `lib/Settings/Personal.php`, `lib/Settings/PersonalSection.php` | `Admin` mounts the admin page: status, WAHA settings and the full text search switch. `Personal` mounts the personal page: sources, preserve switch and click action. `PersonalSection` adds the settings section with the app icon. |
| Controllers | `lib/Controller/` | `PageController` mounts the Vue app. `ApiController` reads the archive. `SettingsController` manages sources, the preserve switch and the full text search switch. `AdminController` serves the admin status and the WAHA settings. |
| Cron | `lib/Cron/` | `ImportJob` runs every 6 hours. `ImportSourceJob` runs one forced import, then a sync when the source has a session. `SyncJob` runs every 15 minutes. |
| Command | `lib/Command/Import.php`, `lib/Command/Sync.php` | `occ chat_archive:import` and `occ chat_archive:sync [--source ID]`. |
| Front end | `src/` | Vue 3 with `@nextcloud/vue` 9. One bundle for the app page, one for the settings pages. `SourceList.vue` and `SourceDialog.vue` build the personal page. `Admin.vue` builds the admin page. `errors.js` maps error codes to texts. |

## Data flow

1. The phone writes the backup and the media files.
2. The Nextcloud mobile app uploads them to one folder in the files of the user.
3. The cron job, or the occ command, starts the import service for each source.
4. The provider finds the backup file in the source folder.
5. If the backup did not change, the import service only runs the media linker.
6. If the backup changed, the provider decrypts it as a stream straight into
   an in-memory SQLite database. No decrypted bytes reach disk.
7. The reader yields chats, messages and calls from the in-memory database.
   The import service writes the new rows.
8. The import service closes the reader, which drops the in-memory database.
9. The media linker gives each new media row a Nextcloud file id.
10. The browser reads chats, messages, search results and calls through the OCS API.
11. The browser shows media through the Nextcloud preview and WebDAV endpoints, by file id.

## Tables

All tables carry the prefix `chatarchive_`. Table names stay under 23
characters because of the Oracle limit with the `oc_` prefix.

| Table | Content | Unique key |
|---|---|---|
| `source` | One configured backup per row. Holds `user_id`, `provider`, `label`, `folder`, `secret` (encrypted), `on_delete`, `last_backup`, `last_synced`, `last_error`, `import_state`, `import_since`, `mode` (`backup` or `live`), `sync_session`, `sync_me` (account of the session, set by the first sync), `sync_at`, `sync_error` (an error code), `tos_at` (time of the warning tick). | `id` |
| `chat` | One chat per source. Holds `platform_chat_id`, `name`, `display_name` (cache), `is_group`, `last_at`, `msg_count`, `phone_archived`, `gone_at`, `in_backup`. | `source_id, platform_chat_id` |
| `message` | One message. Holds `sent_at` (unix ms), `from_me`, `sender`, `kind`, `body`, `deleted`, `deleted_at`, `seen_hash`, `edited_at`, `in_backup`. | `chat_id, platform_msg_id` |
| `edit` | One row per edited version of a message, oldest first. Holds `source_id`, `message_id`, `seen_at` (unix ms), `body`. | `id` |
| `media` | At most one file per message. Holds `file_hash`, `mime`, `size`, `file_name`, `caption`, `src_path`, `file_id`, `suspicious`, `sync_tries`. | `message_id` |
| `call` | One call per source. Holds `peer`, `started_at`, `from_me`, `video`, `duration_s`, `result`. | `source_id, platform_call_id` |

`chat.in_backup` and `message.in_backup` are false for a row that only the
live sync stored, and true for a row that a backup import saw. A null value
counts as true. `media.sync_tries` counts the failed media fetches of the
live sync. The migration is `Version0200Date20261117000000`.

`media.file_id` is the Nextcloud file id. It is null when the file is not in
Nextcloud.

`message.body` and `message.kind` never change after the row is inserted.
Full text search relies on this: it treats a message document as never out
of date (`ChatProvider::isDocumentUpToDate` always returns true). An edit
found later goes into the `edit` table instead; see *Change detection*.

`chat.phone_archived` is the last WhatsApp `archived` flag seen for the
chat. It is null when the backup has no `archived` column. `chat.gone_at` is
the unix ms time of the first import whose backup did not have the chat; null
means the chat was in the newest backup.

`message.deleted` is null, `missing` (absent from a later backup) or
`revoked` (WhatsApp shows "This message was deleted"). `message.deleted_at`
is the unix ms time of the import that set it. `message.seen_hash` is the
hash of the last seen body (see *Change detection*). `message.edited_at` is
the unix ms time of the import that last found a new version, or null.

`media.suspicious` is the platform's own verdict on the file: null when the
backup carries no such flag, otherwise the raw value WhatsApp wrote. Any
value above 0 means flagged. The flag is sticky: once set above 0, no import
clears it. It carries over into `chatarchive_media.suspicious` through the
migration `Version0105Date20260926000000`.

## Security boundaries

A WhatsApp backup, and the media next to it, come from the phone and are not
trusted.

- Every API route checks that the source belongs to the calling user. A
  source of another user returns HTTP 404.
- The secret is encrypted with `OCP\Security\ICrypto` before it is stored.
  The API never returns it. The API only returns the flag `hasSecret`.
- The decrypted database never touches disk. `MemDb` loads it into a
  private SQLite in-memory database (`vfs=memdb`) for the length of one
  import run and drops it when the import ends, on success or on failure.
  See *WhatsApp database reader*.
- Every method that receives decrypted data or the backup key as a parameter
  is marked with `#[\SensitiveParameter]` (PHP 8.2+), so exception traces do
  not leak them into the log file. A method without the mark still leaks
  its arguments when an exception holds it in a trace; the full list is in
  `lib/Source/WhatsApp/Crypt15.php`, `lib/Source/WhatsApp/MemDb.php`,
  `lib/Source/IProvider.php` and `lib/Source/WhatsApp/WhatsAppProvider.php`.
- `MsgstoreReader` opens the in-memory database with `trusted_schema = OFF`,
  `temp_store = MEMORY` and `query_only = 1`, so a crafted schema cannot run
  and a large sort cannot spill a temp file to disk.
- A file that WhatsApp itself flagged as suspicious (`message_media.suspicious_content`)
  never gets a preview, and the app never opens or restores it on its own.
  The user can still download it, after a confirmation. See *Media pointers*.
- Every name shown in the browser — chat name, sender name, file name — is
  cleaned of control and bidirectional-override characters first. See
  *Untrusted text*.
- The media linker links only files owned by the source owner, even when the
  source folder holds a share received from someone else.
- The live sync adds these invariants. A test or a change must keep them.
  - I20.1: a sync never sets or clears `deleted`, `gone_at` or `phone_archived`, and never deletes a row or a file.
  - I20.2: a backup import marks a message `missing`, or a chat gone, only when its `in_backup` flag is true.
  - I20.3: the app sends only GET requests to WAHA. It sends no message and does not control a session.
  - I20.4: a WAHA session belongs to one Nextcloud user, set by the administrator, and feeds at most one source.
  - I20.5: a synced row has the shape of the backup reader for the same message: chat ids `<digits>@s.whatsapp.net`, `<id>@g.us` or an unmapped `<id>@lid`; the key is the WhatsApp key id; `sent_at` in ms; the kind names of `MsgstoreReader`.
  - I20.6: the WAHA API key is stored encrypted. It never goes to the browser, a log or an exception trace.
  - I20.7: no throwable of the import or sync chain goes to the logger, because its trace holds chat text. The log line has the message, the class and `file:line`.
  - I20.8: with the preserve switch on, the app refuses a request to remove a source, and copies a linked file back to its place in the source folder when the user moves it out and then deletes it. A file deleted at its own place goes to the Nextcloud trash; a flagged file or a file with no known place is never copied.
  - I20.9: a second sync over the same WAHA data inserts nothing.
  - I20.10: a sync run ends after its time box and continues later without a stored cursor.
- The admin switch for full text search has no `NoAdminRequired` attribute.
  A non-admin call returns HTTP 403.
- What the admin should also do: install `files_antivirus` with ClamAV on the
  source folder, keep `enabledPreviewProviders` to the types the app needs,
  and keep `files_pdfviewer` current. The Files app still makes its own
  thumbnails outside this app's control.

## Platform neutrality

The store, the linker, the listener and the front end only see the rows that
`IBackupReader` defines. A new platform adds one folder under `lib/Source/`
and one line in `ProviderRegistry::PROVIDERS`. Nothing else changes. See
*Add a platform*.

## Import

### Triggers

| Trigger | Class | Behaviour |
|---|---|---|
| Cron, every 6 hours | `Cron\ImportJob` | Imports every source. Skips a source when its backup did not change. |
| "Import now" in the settings | `Cron\ImportSourceJob` | One forced import of one source. Runs with the next cron tick. |
| A write to the backup file | `Listener\BackupListener` | The upload of a backup adds `Cron\ImportSourceJob` for that source. Runs with the next cron tick. |
| Command line | `Command\Import` | Imports at once. The [user guide](USER-GUIDE.md) lists the options. |

`SourceMapper::queue()` sets `queued` with one UPDATE that matches only an idle or stale state. The route and the backup listener add the job only when it returns true, so two clicks queue one job. `claim()` takes a source for a run in the same way.

`ImportService::importOne()` returns per source: `skipped` when the backup
did not change and the import was not forced; otherwise `chats`, `messages`,
`calls`, `linked` (media rows given a file id), `deleted` (messages newly
marked `missing`), `revoked`, `edited` and `gone_chats` (chats newly marked
`gone_at`). The four new keys come after `linked`, so the occ command's
JSON line still starts the same way it did before this feature.

The occ command prints one JSON line per source. It returns exit code 1 when
one import failed.

A decrypted backup above 1024 MiB (1 GiB) fails the import with that size in
`last_error`. There is no fallback to disk. The process that runs the import
(cron or occ) needs free RAM of about 1.05 times the decrypted backup size,
outside PHP's own `memory_limit`; two imports running at the same time need
that much RAM twice. See *WhatsApp database reader*.

An upgrade that adds the `suspicious` column does not flag files already in
the archive on its own. Run `occ chat_archive:import --force` once after the
upgrade so the known-message path re-reads the flag from the backup.

### Import state

The source row holds the progress:

| `import_state` | Meaning |
|---|---|
| `queued` | "Import now" was clicked. The job waits for cron. |
| `running` | The import service works on the source. |
| empty | Idle. |

`import_since` holds the time when the state was set. A state older than
6 hours counts as idle. This covers a crashed run. A second "Import now"
while a state is set queues nothing.

The import service sets `running` at the start. It clears the state in a
`finally` block, also after a failure. A failure message goes to
`last_error`. A success clears `last_error`.

### Change detection

The import service builds a stamp from the etag and the size of the backup
file. It stores the stamp in `last_backup`. When the stamp did not change
and `--force` is not set, the import only runs the media linker. This makes
the 6-hour cron cheap on days without a new backup.

Once the backup is read, the import compares each message and chat with
what the archive already has. See *Change detection rules*.

### Steps of one import

1. Find the backup file in the source folder. WhatsApp accepts
   `msgstore.db.crypt15` at the folder root or in `Databases/`.
2. Decrypt the secret with `ICrypto`. Drop it from memory after the reader opened.
3. Read the backup as a stream from Nextcloud. Decrypt it straight into an
   in-memory SQLite database (`MemDb`); fail with a size message above
   1024 MiB, before the loop that would grow the database.
5. For each chat from the reader:
   1. Insert the chat, or update its name, `phone_archived` and `gone_at`
      (`upsertChat`; see *Change detection rules*).
   2. Load the known messages of the chat (id, kind, `deleted`, `seen_hash`),
      backfilling `seen_hash` for rows imported before this feature.
   3. Open one transaction.
   4. For each message from the reader: insert it if its platform id is new,
      or apply the change detection rules to it if the id is already
      stored. Insert media rows for new messages.
   5. Mark as `missing` every stored, not-yet-deleted message of the chat
      whose platform id did not appear in this backup.
   6. Refresh `last_at` and `msg_count` when a message was added.
   7. Commit.
6. Insert each call whose platform id is new.
7. Mark `gone_at` on every chat of the source that this backup did not
   report and that is not already marked, now that the chat loop finished
   without an error.
8. Close the reader. The reader deletes the decrypted database.
9. Delete the temp folder.
10. Run the media linker.
11. Store the stamp and the time.

New messages are inserted; a stored message is never deleted, and its
`body` and `kind` never change. A message deleted or edited on the phone
gets a mark instead (deleted, revoked, or a new row in the `edit` table). A
forced re-import of the same backup adds no rows and sets no new marks. See
*Change detection rules*.

### Change detection rules

`ImportService` compares a message `b` from the backup with the stored row
`s` for the same `chat_id, platform_msg_id`, in this order:

1. `b.kind` is `revoked` and `s` is not already marked `revoked`: set
   `deleted = 'revoked'`, `deleted_at` = now. No further check on this
   message.
2. `s.deleted` is `missing`: clear `deleted` and `deleted_at`. The message
   came back in this backup. Continue to the next rule.
3. `b.kind === s.kind`, the kind is one of `ArchiveStore::EDITABLE` (`text`,
   `image`, `video`, `document`, `gif`), and `b.body` is not null: hash the
   scrubbed body with `ArchiveStore::bodyHash()`. If the hash differs from
   the stored `seen_hash`, insert a row in the `edit` table with the
   scrubbed body and set `seen_hash` and `edited_at`. `message.body` is
   never overwritten, so it always holds the first version.
4. Otherwise nothing changes.

A message whose platform id is not in this backup, and that is not already
marked `deleted`, is marked `missing` after the chat loop for that chat.

A chat whose `platform_chat_id` was not reported at all in this backup, and
that is not already marked, gets `gone_at` set after every chat in the
backup has been processed without an error (a throw part-way through marks
no chat gone; the per-chat rows still roll back with their transaction).

Both marks clear if the message or chat appears again in a later backup,
except `deleted = 'revoked'`, which is permanent: WhatsApp does not undo a
delete-for-everyone.

`ArchiveStore::bodyHash()` hashes the same scrubbed text that
`ArchiveStore::text()` produces for storage, so the comparison never reacts
to raw bytes that the store would clean the same way on both sides. This
keeps a forced re-import of an unchanged backup from recording false edits.

### WhatsApp backup format

`Crypt15::decrypt()` reads a single-file `msgstore.db.crypt15` backup as a
stream. No encrypted copy is made.

| Part | Size | Content |
|---|---|---|
| Prefix length | 1 byte | Length of the protobuf prefix. |
| Feature flag | 1 byte, optional | `0x01`. |
| Prefix | n bytes | Protobuf `BackupPrefix`. Field 3 holds `C15_IV`. Its field 1 holds the 16-byte IV. |
| Body | rest | AES-256-GCM ciphertext of a zlib stream, or of a raw SQLite file. |
| Tag | 16 bytes | GCM tag. Not verified. |
| Checksum | 16 bytes | md5 of everything before it. |

The AES key comes from the 64-digit key: HMAC-SHA256 with a zero key gives
the private key, HMAC-SHA256 of `"backup encryption" + 0x01` with the
private key gives the AES key.

The body is decrypted in AES-CTR mode with the GCM counter. The code cuts
each chunk where the low 32 bits of the counter would wrap, because GCM only
increments the low word. This keeps memory flat for a 1 GB backup.

The GCM tag is not verified. PHP has no streaming AEAD. The md5 trailer
catches corruption. A wrong key fails on the first chunk: the data is
neither a zlib header nor a SQLite header.

### WhatsApp database reader

`MsgstoreReader` reads the decrypted database with `PDO`, over the in-memory
connection that `MemDb` returns. The queries were checked against a real
2026 backup.

`MemDb` (`lib/Source/WhatsApp/MemDb.php`) is the sink `Crypt15` writes pages
into. It checks the SQLite header, rejects a backup whose page count times
its page size is above the 1 GiB cap before it allocates anything, loads
each page into a private, uniquely named `vfs=memdb` database, and hands
back a second connection to the same database. Dropping that connection
frees the memory; nothing under `open()` ever calls `fopen()` with mode
`w` or asks for a temp file. The cap prevents a forged header from making
the import allocate more than the encrypted input allows: the bound is
`N × pageSize <= encryptedSize × 1032`, where 1032 is zlib's maximum
compression ratio.

The reader opens the connection it is given with `PRAGMA trusted_schema =
OFF`, `PRAGMA temp_store = MEMORY` and `PRAGMA query_only = 1`, in that
order, before it reads anything else.

- Chats come from `chat` joined with `jid`. Chats without messages are
  skipped. WhatsApp does not list them either.
- A chat carries `archived`: true when the phone had it archived, false when
  not, null when the backup's `chat` table has no `archived` column. This
  column is not confirmed present in every real WhatsApp backup, so the
  reader treats it as optional and every chat row keeps the same shape
  either way. A chat folded from more than one row (a `@lid` row and its
  phone-jid row) reads `archived` true if any of the folded rows does.
- New `@lid` ids map to the phone jid through `jid_map` (if present). One person stays one chat.
- Messages come from `message` with optional `message_media`, `message_call_log`,
  `call_log` and `missed_call_logs`. Rows with a null `key_id` are skipped.
- `message_type` maps to a kind. Unknown types become `type_<n>`.
- Call messages have no body. They carry `call` with the video flag, the
  result and the duration. The UI words them.
- Calls come from `call_log` (if present). Group calls carry the group jid as peer.

The reader reads the schema one time, when it opens the file. It uses
`PRAGMA table_info` for each table that it reads.

| Table | Type | Columns that the reader uses |
|---|---|---|
| `jid` | Core | `_id`, `user`, `server`, `raw_string` |
| `chat` | Core, `archived` optional | `_id`, `jid_row_id`, `subject`, `archived` |
| `message` | Core | `_id`, `chat_row_id`, `key_id`, `timestamp`, `from_me`, `message_type`, `text_data`, `sender_jid_row_id` |
| `jid_map` | Optional: lid map | `lid_row_id`, `jid_row_id` |
| `message_media` | Optional: media | `message_row_id`, `file_hash`, `mime_type`, `file_size`, `media_name`, `media_caption`, `file_path` |
| `message_media` | Optional: suspicious flag | `suspicious_content`, its own feature, on independently of the rest of `message_media` |
| `message_call_log`, `call_log` | Optional: call facts on messages | `message_row_id`, `call_log_row_id`; `_id`, `video_call`, `duration`, `call_result` |
| `missed_call_logs` | Optional: missed call facts | `message_row_id`, `video_call` |
| `call_log` | Optional: call list | `call_id`, `timestamp`, `from_me`, `video_call`, `duration`, `call_result`, `jid_row_id`, `group_jid_row_id` |

Rules:

- If a core table or a core column is missing, the reader throws a
  `RuntimeException`. The message names each missing table or column. The
  import service writes the message to `last_error` on the source row.
- If an optional table or one of its columns is missing, the reader skips
  that feature only. The messages query does not join that table. The rows
  keep the same keys:
  - No lid map: an `@lid` chat and its sender keep the `@lid` jid.
  - No media: `media` is null.
  - No call facts or no missed call facts: `call` is null for those rows.
    The `kind` stays `call_log` or `missed_call`.
  - No call list: `calls()` yields no rows.
  - No `archived` column: every chat reads `archived => null` and
    `chat.phone_archived` in the archive is left unchanged.

`close()` drops the reader's own PDO connection. The last connection to the
in-memory database being dropped frees it.

### Untrusted text

Every text from a backup goes through `ArchiveStore` before it reaches the
database. The store removes NUL bytes and invalid UTF-8, which break
PostgreSQL and `json_encode`, and cuts each value to its column length. A
media path that starts with `/` or holds a `..` segment is stored as null,
so a crafted backup cannot point the restore policy outside the source
folder. The reader passes bytes through untouched; the store is the one
place that cleans them, for every provider.

Stored text keeps its characters, including control and bidirectional
characters: the message body, captions and edit history render as written.
A shown name is different: `Service\DisplayText::name()` strips control
characters and bidirectional-override characters (for example U+202E,
right-to-left override) every time one leaves the server. It covers a chat
name, a sender name, a media file name, a media source path (src_path), a
Nextcloud node path (displayPath) and a media mime type, so a crafted name
cannot make one kind of file look like another in the browser. It runs on
the way out only; the stored value used for linking a file by name does not
change. Every element that shows such a name also carries `dir="auto"`.

### Failure modes

| Failure | Result |
|---|---|
| No backup file in the folder | `last_error`: "no backup file found in …". |
| No key on the source | `last_error`: "no backup key set for this source". |
| Wrong key | `last_error`: "… wrong key?". No rows change. |
| Corrupt backup | `last_error` from zlib or the md5 trailer. No rows change: the decryption completes before the reader starts. |
| Database error while the reader runs | `last_error` from PDO. Rows of chats that were committed stay. The next run adds the rest. |
| Crash during import | `import_state` stays set for at most 6 hours, then counts as idle. |

## Live sync

The live sync is an opt-in, read-only second source of rows. A source with a
`sync_session` is synced; a source with `mode = live` has no backup file.
The user guide shows what the user sees.

### Sequence of one run

`SyncService::run()`, called by `Cron\SyncJob` (every 15 minutes, only when
the admin switch is on), by `Cron\ImportSourceJob` and by `occ chat_archive:sync`:

1. Take the source with `claim()`. Another import or sync on the source throws `ImportRunningException`.
2. Set a time box of 40 seconds (I20.10).
3. Check in order: the switch (`waha_off`), the session assignment (`waha_unassigned`), the session state `WORKING` (`waha_session_state`), the account against `sync_me` (`waha_account_changed`; the first sync stores it), the source folder (`folder_missing`).
4. Message pass: read every page of the chat overview, 50 chats each, from the first page to an empty page or the end of the message share (the first 30 seconds of the box). An up-to-date chat costs no message request, so a run after an interrupted run reaches the chats that it did not load. Read a chat only when WAHA's last time is newer than the stored `last_at`, or when the overview gives no last time and the chat has no `last_at`. Limit: such a chat gets new messages only when WAHA gives a last time again. Insert new messages in ascending order, one transaction per chat (I20.9). A new chat gets `in_backup = false`. A key that the backup holds is not inserted again.
5. Media pass: in the rest of the time box, fetch up to `waha_media_per_run` pending files into `<source folder>/WAHA/<YYYY-MM>/` and point the media row at them. Files with fewer failed fetches come first, then the newest. A failed fetch adds to `sync_tries`. WAHA can take 12 seconds to report that it has no file for a message, so a file that failed before must not hold back the files behind it. A file that cannot be written adds one try and stops the media pass for that run.
6. Set `sync_at` and clear `sync_error`, or set `sync_error` to the code of a `WahaException`. A throwable gives `waha_bad_response`. Clear the import state.

A WAHA failure does not throw out of `run()`. It lands in `sync_error`. The
codes `waha_down`, `waha_auth` and `waha_local_blocked` also stop the media
pass. `WahaClient` raises `waha_session_missing` for a 404 on a session path,
and `waha_bad_response` for a body that is not the expected JSON.
`WahaException` keeps no previous exception, because the HTTP trace holds the
key (I20.6).

### Admin routes

| Method | Route | Function |
|---|---|---|
| GET | `/admin/status` | Background job mode and last run, the source counts, failing sources, and the WAHA settings without the key (`hasKey` only). |
| PUT | `/admin/waha` | Save `enabled`, `url`, `apiKey`, `mediaPerRun` (0 to 200), `sessions` (session name to user ID) and `realtime`. A request without `apiKey`, `mediaPerRun`, `sessions` or `realtime` keeps the stored value of that field. A bad value returns 400 with `code`: `bad_url`, `bad_media_per_run`, `bad_session`, `unknown_user`. |
| POST | `/admin/waha/test` | Calls the WAHA version and session routes. Returns `ok`, `version`, `engine`, `tier` and `sessions`, or `ok: false` with a `code`. |

All three are admin only.

## Media pointers

### The pointer

`chatarchive_media.file_id` is the Nextcloud file id. Nextcloud keeps the id
when the user renames or moves a file inside one storage. Nextcloud moves
the cache row and does not create a new one. The id also survives a move to
the trash and a restore from the trash.

Nextcloud has no deduplication for user files. A pointer by id is the
replacement: one file, shown in the chat wherever it lives.

### Linking

`Service\MediaLinker::link()` runs at the end of every import, also when the
backup did not change. It only touches rows without a pointer, or rows whose
pointer does not resolve.

The linker skips a node not owned by the source folder's owner, and skips a
whole folder subtree the same way. This keeps a file shared into the source
folder by another user from being linked into the archive. If the source
folder reports no owner (for example, a storage without an owner attribute),
the linker uses the source's user id as the owner. A node that reports no
owner is skipped, unless the source folder also reports no owner.

The linker walks the source folder once and builds two indexes:

- path relative to the source folder, to file id
- bare file name, to the first file id with that name

For each row it tries, in this order:

1. `src_path` as the phone stored it, for example `Media/WhatsApp Images/IMG-20240830-WA0001.jpg`.
2. `WhatsApp/` + `src_path`, for an upload that mirrors the parent folder of the phone.
3. The bare file name of `src_path`.
4. `file_name` from the backup, for rows without `src_path`.

A name that ends in a dot or a space is also tried without it. Nextcloud
refuses such a name, so the file can only exist without it.

Step 3 makes a flat auto-upload folder work. The auto upload of the
Nextcloud mobile app puts each phone folder where the user says, not in a
mirror of the tree of the phone. WhatsApp file names are unique enough.

### The file listener

`Listener\FileListener` listens to `NodeRenamedEvent` and
`BeforeNodeDeletedEvent`. It never blocks the operation of the user. A
failure goes to the log and the operation continues.

| Event | Action |
|---|---|
| Rename or move in the same storage | Nothing. The id did not change. |
| Move to another storage | The id changed. The pointer follows the new id. |
| Move of a folder to another storage | Nothing. The next import relinks the files. |
| Delete, policy `restore` | Copy the file back to `<source folder>/<src_path>` before the delete continues. The pointer follows the copy. |
| Delete, policy `unlink` | Clear the pointer. The message shows "not in Nextcloud". |
| Delete, policy `keep` | Keep the pointer. A restore from the trash makes it valid again. |

Deleting the source folder itself, or a parent of it, never restores a file.
The user means it.

When a copy is already at the source path, the listener points the row at
that copy and copies nothing.

The policy is a property of the source. The user guide gives the default and
describes when to pick each one.

### The viewer

The API returns `path`, `exists`, `mime`, `hasPreview` and `suspicious` for
each media row. `hasPreview` is `IPreview::isAvailable()` for the file: a
preview provider exists for the mime type and the file is not too large,
forced to `false` when `suspicious` is true. A suspicious row shows a
warning instead of a preview or a player, and the app never opens or
restores the file on its own; the user can still download it, after a
confirmation dialog. The browser builds three URLs from the file id and the
path:

- the core preview endpoint, by file id, for thumbnails: the image itself,
  the poster of a video, and a thumbnail above the name of any other file
  with `hasPreview`
- the WebDAV URL of the path, for the file itself
- the Files app URL with the file selected, for "Show in Files"

A left click on an image or a file runs the action from the personal
settings (`Service\ClickAction`, stored through `OCP\Config\IUserConfig`).
A value set before this change, through the older `OCP\IConfig`, still
reads. A write through the app now stores it again through
`IUserConfig`:

| Action | What happens |
|---|---|
| `view` (default) | `OCA.Viewer.open()` when the viewer app lists the mime type. Otherwise the WebDAV URL, which the browser downloads. The app page dispatches `LoadViewer` so the viewer script is present. |
| `nc` | "Open locally": the browser posts the path to the Files OCS route `openlocaleditor` and gets a token. It then hands the file to the desktop client with `nc://open/<uid>@<host><path>?token=<token>`. A failed post shows an error toast and stops. |
| `custom` | The browser navigates to the URL template of the user with `{path}` replaced by the URL-encoded path. The template must start with a scheme; `javascript:` and `data:` are refused. |

## Search

### Filters

| Filter | Parameter | Values |
|---|---|---|
| Source | `source` | One source id, or none for all sources of the user. |
| Chat | `chat` | One chat id. |
| Type | `kind` | `text`, `image`, `video`, `audio`, `document`, `call`, `media` (any file), `link`. |
| From | `who` | `me`, `others`, or none. |
| When | `from`, `to` | Unix milliseconds. |

The kind filter maps to message kinds in `ArchiveStore::KINDS`. For example,
`image` covers `image`, `gif` and `view_once_image`. `link` is not a kind.
It matches a body that contains `http`.

A term is optional when at least one filter is set. A request with no term
and no filter returns nothing.

### Plain scan

`ArchiveStore::search()` runs a `LIKE` query over the message body, the
media caption and the media file name. Results come newest first, 50 per
page. The store fetches 51 rows to know if a next page exists.

This path always works. It needs no other app.

### Full text search

The admin switch is stored in the app config key `fulltext_search`.
`FullTextSearchService::isEnabled()` returns true only when all three hold:

1. The switch is on.
2. The `fulltextsearch` app is installed and a platform is configured.
3. The platform reports that the `chat_archive` provider is indexed.

When it is enabled, the search controller asks the index for the best
message ids, then loads and filters those rows in SQL. When a filter is set,
it asks for 10 pages of ids so a date filter does not empty the page.

When the index returns ids but the filters leave no hit on the first page, or when it returns no ids, the controller falls back to the plain scan.
An empty answer can mean "no match", "index stale" or "platform down". The
fallback treats all three the same. The user sees no error.

The index ranks the top hits, and the SQL filters apply afterwards. The
[user guide](USER-GUIDE.md) describes what the user sees from this.

### Indexing

`FullTextSearch\ChatProvider` offers messages to the `fulltextsearch` app.
Each document carries the message body, the media caption and file name (joined by newlines; a media message with no body is indexed), the chat name, the time, the owner
as access, and a tag `src_<source id>` for the source filter.

The [user guide](USER-GUIDE.md) tells when a message becomes searchable.

Switching the toggle off stops new indexing. Already-indexed messages stay
in the platform. Run `occ fulltextsearch:reset chat_archive` to remove them.

Deleting a source is different: `SettingsController::destroy()` reads the
source's message ids, then calls `FullTextSearchService::forgetSource()`
before it deletes the rows. `forgetSource()` removes those ids from the
index. It checks `IFullTextSearchManager::isAvailable()`, not the toggle: a
document stays indexed after the toggle goes off, so a source removed while
the toggle is off must still lose its documents. If the platform call fails,
`forgetSource()` logs a warning and returns. The source delete still
completes; a stale index entry is not a reason to keep the source.

### Unified search provider

`MessageSearchProvider` implements `OCP\Search\IFilteringProvider` and is
registered in `Application.php` with `registerSearchProvider()`. The provider
id is `chat_archive_messages`.

The provider reuses `ArchiveStore::search()` and shows messages across all
sources of the calling user. Each result has the chat name as the title and
the message text and time in the subline. Results link to the app root.

Supported filters:

| Filter | Type | Meaning |
|---|---|---|
| `term` | built-in | Search term. Optional when a filter is set. |
| `since`, `until` | built-in, datetime | Time range. The filter value is a `DateTime`. The provider converts `getTimestamp()` in seconds to the milliseconds `ArchiveStore::search()` expects. |
| `chat` | custom, string | One chat id. |
| `source` | custom, string | One source id. Absent means all of the caller's sources. |
| `kind` | custom, string | One of `ArchiveStore::KINDS`, plus `media` and `link`. |
| `who` | custom, string | `me` or `others`. |

Paging uses an offset cursor. The provider checks that each source in the
result belongs to the calling user. The provider uses the SQL path only, not
full text search. See the roadmap section "AI agent access" for the agent
paths that reach this provider.

## OCS API

All routes live under `/ocs/v2.php/apps/chat_archive`. Every request needs
the header `OCS-APIRequest: true` and a logged-in user or an app password.
Add `?format=json` for JSON.

Every route checks that the source or chat belongs to the calling user. A
source of another user returns HTTP 404, not 403. This does not tell the
caller whether the id exists.

Times are unix milliseconds unless the table says otherwise.

### Read routes

| Method | Route | Function |
|---|---|---|
| GET | `/api/sources/{id}/chats` | Chats of one source, most recent first. |
| GET | `/api/chats/{id}/messages` | One page of messages of one chat. |
| POST | `/api/chats/{id}/live` | Realtime update of an open chat. `SyncService::runChat()` adds the new messages of this one chat from WAHA, with the checks of a sync and no media pass. Returns `live`, `added` and `lastAt`; `live` is false when the app value `waha_realtime` is off or the source has no session. The page (`MessageList.vue`) calls it every 8 seconds while the tab is visible, then loads the new rows with the messages route. With a memory cache, the server asks WAHA at most one time in 4 seconds for each chat. A WAHA failure adds nothing and is not stored. |
| GET | `/api/search` | Messages across one or all sources. |
| GET | `/api/sources/{id}/calls` | Calls of one source, newest first, up to 5000. |
| POST | `/api/sources/{id}/names/refresh` | Clear the cached contact names of one source. |
| GET | `/api/messages/{id}/edits` | Earlier versions of one edited message, oldest first. |

#### Chats

Each chat carries `id`, `sourceId`, `platformChatId`, `name`, `displayName`,
`isGroup`, `lastAt`, `msgCount`, `phoneArchived` and `goneAt`. The route
resolves `name` for private chats from the address books of the user and
caches the result on the row. `phoneArchived` is false when the backup has
never reported the WhatsApp `archived` flag for the chat. `goneAt` is null
unless a later backup stopped reporting the chat.

#### Messages

Parameters select one page of 100 messages:

| Parameters | Page |
|---|---|
| none | The newest page. |
| `beforeAt`, `beforeId` | The page before the message with that time and id. |
| `afterAt`, `afterId` | The page after that message. |
| `at` | The first page at or after that time. |

The cursor uses two columns because message ids are not monotonic per chat.
Messages always come oldest first inside the page.

The answer holds `messages` and `senders`. `senders` maps each sender jid on
the page to a display name. A message with a file carries `media` with
`fileId`, `path`, `exists`, `mime`, `size`, `fileName`, `caption` and
`srcPath`. `path` is relative to the user folder. `exists` is false when the
pointer does not resolve.

Each message also carries `deleted` (`missing`, `revoked` or null) and
`editedAt` (unix ms of the newest recorded edit, or null). `body` is always
the first version the archive saw; it does not change when `editedAt` is
set. `deleted` and messages of a chat with `goneAt` set are left out of this
route's rows when the calling user's `showDeleted` view preference is off
(see *View preferences*); the route otherwise returns every row regardless
of the preference.

A call message (`kind` `call_log` or `missed_call`) has a null `body` and
carries `call` with `video`, `result` (`connected`, `missed`, `rejected`,
`failed`) and `durationS`. The client words the call in the language of the
user. Rows imported before version 0.1.2 keep their English body and have a
null `call`.

#### Message edits

The answer holds `edits`, a list of `{seenAt, body}` ordered oldest first,
one entry per version the archive saw after the first. The first version
stays in the message's own `body` and is not repeated here.

#### Search

| Parameter | Type | Meaning |
|---|---|---|
| `q` | string | The term. Optional when a filter is set. |
| `source` | int | One source id. Default: all sources of the user. |
| `chat` | int | One chat id. |
| `from`, `to` | int | Time range. |
| `kind` | string | `text`, `image`, `video`, `audio`, `document`, `call`, `media`, `link`. |
| `who` | string | `me` or `others`. |
| `offset` | int | Page offset. Pages hold 50 hits. |

The answer holds `messages`, `senders`, `chats` (chat id to name and source
id) and `more`.

When the calling user's `showDeleted` or `showArchived` view preference is
off, `ApiController::search` adds `hide_deleted` or `hide_archived` to the
filters passed to `ArchiveStore::search()`, after the empty-request guard
has already run on the user's own filters. A request with no term and no
filter still returns nothing, whatever the view preferences are. This is
the only place either preference reaches search: the unified search
provider and full text search build their own filters and never see
`hide_deleted` or `hide_archived`, so both keep returning every message.

#### Calls

Each call carries `id`, `peer`, `peerName`, `startedAt`, `fromMe`, `video`,
`durationS` and `result`. `result` is one of `connected`, `missed`,
`rejected`, `failed`, or the raw platform value.

#### Names refresh

The answer holds `reset`, the number of chats whose cached name was cleared.

### Settings routes

| Method | Route | Function |
|---|---|---|
| GET | `/settings/sources` | Sources of the user and the list of providers. |
| POST | `/settings/sources` | Create a source. |
| PUT | `/settings/sources/{id}` | Update a source. |
| DELETE | `/settings/sources/{id}` | Delete a source and all its rows. Returns 409 with `code: preserve_on` while the preserve switch is on, and 409 `running` during an import. |
| POST | `/settings/sources/{id}/import` | Queue one forced import. |
| POST | `/settings/probe` | Looks for a backup file in a folder of the user. Returns `found`, and name, size and mtime when found. |
| PUT | `/settings/preserve` | Sets the preserve switch: `preserve` boolean. `GET /settings/sources` returns it with the `live` state (`enabled` and the sessions of the user). |
| PUT | `/settings/fulltextsearch` | Admin only. Set the full text search switch. |
| PUT | `/settings/click` | Set the left-click action of the user: `action` is `view`, `nc` or `custom`; `template` is the URL template for `custom`. |
| PUT | `/settings/view` | Set the view preferences of the user: `showDeleted`, `showArchived`, both optional booleans. Returns the new `{showDeleted, showArchived}`. A parameter left out keeps its stored value. |

#### View preferences

`Service\ViewPrefs` reads and writes `showDeleted` and `showArchived`
through `OCP\Config\IUserConfig`, one boolean each, both defaulting to true
when the user has never set them. `PageController::index` sends the current
values to the browser as initial state. `ApiController` is the only reader:
it turns `showDeleted` into the `hideDeleted` flag for the messages route,
and turns both into `hide_deleted` / `hide_archived` search filters (see
*Search*). No other route reads these preferences.

#### Source fields

| Field | In | Out | Notes |
|---|---|---|---|
| `provider` | yes | yes | A provider id. Today only `whatsapp`. |
| `label` | yes | yes | Required. Shown in the UI. |
| `folder` | yes | yes | Path in the user folder. Must exist and be a folder. |
| `secret` | yes | no | WhatsApp: 64 hex characters. Empty on update keeps the stored one. |
| `hasSecret` | no | yes | True when a secret is stored. |
| `onDelete` | yes | yes | `restore`, `unlink` or `keep`. |
| `lastBackup` | no | yes | Etag and size of the last imported backup. |
| `lastSynced` | no | yes | Unix seconds. |
| `lastError` | no | yes | Message of the last failed import, or null. |
| `importState` | no | yes | `queued`, `running` or null. |
| `importSince` | no | yes | Unix seconds, or null. |
| `mode` | create only | yes | `backup` or `live`. An update ignores it. |
| `syncSession` | yes | yes | A session assigned to the user. On update, null keeps it and an empty string turns live sync off. |
| `tos` | yes | no | Must be true when the source has no `tosAt` yet. |
| `syncAt`, `syncError`, `tosAt` | no | yes | Unix seconds, an error code, unix seconds. |

A bad value returns HTTP 400 with an `error` message and a `code`. The secret is never
returned. The codes: `unknown_provider`, `bad_mode`, `folder_not_found`, `not_a_folder`, `bad_policy`, `label_required`, `bad_key`, `live_not_allowed`, `bad_session`, `session_in_use`, `tos_required`, `backup_first`. `src/errors.js` holds the text of each code.

The import route sets `importState` to `queued` and adds a background job.
It returns the source. A second call while a state is set changes nothing.

## Build

```sh
npm ci
npm run build    # production bundles into js/
npm run dev      # development build with source maps
```

The build writes two bundles: `chat_archive-main` for the app page and
`chat_archive-settings` for the personal and admin settings pages. The `js/`
folder is not in git.

Node 20, 22 or 24 is required.

## Tests

There is no PHPUnit suite. `tests/run.sh` runs every script below in one
command. Each script stops at the first failed assertion and prints its
name.

| Script | Needs | Covers |
|---|---|---|
| `tests/crypt15_check.php` | PHP with openssl and zlib, and the plain fixture `tests/fixtures/msgstore.db` (not in git, see Fixture) | Decrypt equals the plain fixture. A wrong key throws. A key of the wrong length throws. A corrupt file throws. Decrypting into a `MemDb` gives back the same bytes, page by page, as the plain fixture (WAL flag bytes aside). |
| `tests/memdb_check.php` | PHP with pdo_sqlite and the memdb VFS (use the docker image) | A WAL-header backup loads. A backup whose page count times page size is above the 1 GiB cap throws before it grows the database. A truncated stream throws on `finish()`. Two `MemDb` objects stay apart. `abort()` twice is safe. |
| `tests/media_linker_check.php` | PHP | The names the media linker tries for a backup name: as it is, then without a trailing dot or space. |
| `tests/waha_mapper_check.php` | PHP | The WAHA mapper: chat ids to jids, key ids, message kinds, the neutral message row (sender rules for groups, 1:1 and lid), and media file names. |
| `tests/sync_resume_check.php` | PHP | The real `SyncService` against the in-memory stand-ins of `tests/stubs/SyncStubs.php`, with 120 chats and a time box of 2 seconds: a run after an interrupted first run loads every chat, and one more run adds nothing. The media pass runs after a message pass that used its full share. A chat with no timestamp costs a message request only while it has no `last_at`. The media pass asks WAHA by the plain key id. A file that cannot be written counts one try and stops the media pass for that run. |
| `tests/display_text_check.php` | PHP | `DisplayText::name()` strips control and bidirectional-override characters and leaves a ZWJ emoji and a Persian ZWNJ word unchanged. `DisplayText::mimeMismatch()` on the mismatch cases of *Media pointers*. |
| `tests/reader_check.php` | PHP with pdo_sqlite (use the docker image) | Chats, lid mapping, group flag, message kinds, media rows, captions, call facts, calls, skipped rows, hostile bytes passed through, the `suspicious` flag, the reader construction guard after a throw. |
| `tests/nc_integration.sh` | Podman, curl, python3 | Full round trip in a throwaway Nextcloud. See below. |

```sh
php -d zend.assertions=1 -d assert.exception=1 tests/crypt15_check.php
php -d zend.assertions=1 -d assert.exception=1 tests/media_linker_check.php
php -d zend.assertions=1 -d assert.exception=1 tests/display_text_check.php
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
    php -d zend.assertions=1 -d assert.exception=1 tests/memdb_check.php
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
    php -d zend.assertions=1 -d assert.exception=1 tests/reader_check.php
./tests/nc_integration.sh
```

The test scripts use rootless podman. When `CI` is set, they use docker. Set `CTR` to use a different engine. The integration script removes its container and the container's volumes when it exits, also on Ctrl-C. Set `KEEP=1` to keep the container.

The integration script:

1. Starts `nextcloud:32-apache` on port 18080 with SQLite.
2. Copies the app in, enables it, seeds the fixture backup and one image.
3. Creates a source over OCS. Rejects a short key and an unknown provider.
4. Imports. Checks the counts. Checks that a second run skips.
5. Imports with a wrong key on a second source. Checks `lastError` and the cleared state.
6. Checks the import state after "Import now".
7. Reads chats, messages, search with each filter, and calls. Checks
   `hasPreview` on a linked image. Checks that NUL bytes, invalid UTF-8, an
   over-long file name and a `..` media path from the backup are cleaned.
8. Renders the app page, the settings page and the admin page.
9. Sets the left-click action. Checks that the app page carries it, and that
   a `javascript:` template and an unknown action get 400.
10. Sets the full text search switch. Checks the fallback and the 403 for a non-admin.
11. Checks that a second user gets 404 on every read and write route of the
    first user, and sees no source of theirs.
12. Imports the same backup as the second user. Each user sees only their
    own chats and search hits. Deleting the source of the second user leaves
    the rows of the first user in place.
13. Sets the user language to German and checks that the page loads the
    `l10n/de.js` bundle.
14. Moves the linked file. Checks that the pointer follows.
15. Deletes it under each policy: `restore`, `keep` with a trash restore, `unlink`.
16. Deletes the source. Checks the cascade.

The integration script also runs a fake WAHA (`tests/fixtures/waha_router.php`
under `php -S 127.0.0.1:3999` in the container). It checks that a request
without the key gets 401. It assigns the session, runs `occ chat_archive:sync`
and checks that two messages arrive and the shared key is not duplicated. A
second sync inserts nothing (I20.9). A forced import then does not mark the
synced message `missing` and does not mark the synced chat gone (I20.2). With
preserve on, `DELETE /settings/sources/{id}` gives 409 `preserve_on`; the
script turns preserve off before the steps that test the delete policies.

`KEEP=1` keeps the container after the run. `IMAGE=nextcloud:34-apache`
tests another server version.

## Fixture

`tests/fixtures/make_fixture.py` builds `msgstore.db` and
`msgstore.db.crypt15` with the key in `key.hex`. It needs `wa_crypt_tools`
in `~/.venv`. The plain database is not in git.

The fixture holds two chats with messages, one chat without messages, one
lid-mapped jid, two images, one document, two calls, one message without a
key id, and one hostile row: a body with a NUL byte and an invalid UTF-8
byte, a 300-character file name and a media path with `..`. The document
row carries `suspicious_content = 3`; one media row carries a name with a
U+202E right-to-left override character. The `--old` build has no
`suspicious_content` column, to check that an old backup still imports.

## Lint

```sh
npm run lint        # eslint (Nextcloud rules + SonarJS) and the translation check
npm run lint:fix    # apply the auto-fixable part
composer install
composer lint       # phpstan level 6 with the cognitive complexity rule
```

Both linters fail a function with a cognitive complexity above 15, the
SonarCloud threshold. `phpstan.neon` and `eslint.config.js` hold the rules.
`tests/phpstan/stubs.php` declares the three private server classes the full
text search provider instantiates; `nextcloud/ocp` ships only the
interfaces.

## Translations

Every user-facing string goes through `t('chat_archive', …)` in the front
end. `scripts/l10n.mjs` collects the strings and keeps `l10n/<lang>.json` in
step: it adds new keys with an empty value, drops keys that are gone, and
writes the `l10n/<lang>.js` file that Nextcloud loads in the browser.

```sh
npm run l10n                 # after you add or change a string
node scripts/l10n.mjs --check   # fails on an empty translation; part of npm run lint
```

Shipped languages: `de` (informal) and `de_DE` (formal). Translate the new
keys in both JSON files, then run `npm run l10n` again. Nextcloud picks `de`
for a user with `de_DE` only through the browser language, so both files are
needed.

## Deploy

The deploy script is host specific and not part of the repository. The steps
are: `npm run build`, copy the app into `custom_apps`, then `occ app:enable
chat_archive` and `occ upgrade`.

Bump `<version>` in `appinfo/info.xml` when `lib/Migration/` changes, and
when you add a route. `occ upgrade` only runs migrations of a new version,
and the route table sits in the local cache for one hour under a key hashed
from the app versions.

The browser caches the bundle under the same name. Use a hard reload after a
deploy.

## Release

The app store needs a signed tarball. The top folder in the tarball is
`chat_archive`. The workflow `.github/workflows/appstore-build-publish.yml`
makes it, signs it and sends it to the store.

To release:

1. Bump `<version>` in `appinfo/info.xml` and in `package.json`. Move the
   `[Unreleased]` entries in `CHANGELOG.md` under the new version.
2. Commit and push.
3. Publish a GitHub release with the tag `v<version>`, for example `v0.1.5`.
   The workflow stops if the tag and `<version>` do not agree.

The workflow runs `npm ci && npm run build` and `scripts/pack.sh`. It signs
the app folder with `occ integrity:sign-app` from the Nextcloud server of
`min-version`, attaches the tarball to the release and registers the release
in the store. `CHANGELOG.md`, in Keep a Changelog format, becomes the release
notes in the store.

`scripts/pack.sh` writes `build/chat_archive-<version>.tar.gz` from
`appinfo`, `img`, `js`, `l10n`, `lib`, `templates`, `CHANGELOG.md`,
`LICENSE` and `README.md`. It leaves out source maps, `node_modules`,
`vendor`, `src`, `tests` and the git folder. It does not sign.

### One-time setup

1. Certificate. Put `chat_archive.csr` in a folder `chat_archive/` in a fork
   of <https://github.com/nextcloud/app-certificate-requests> and open a pull
   request. After the merge, the workflow downloads
   `chat_archive/chat_archive.crt` from that repository. The private key,
   `chat_archive.key`, never goes into this repository.
2. Register the app at <https://apps.nextcloud.com/developer/apps/new>. The
   form needs the certificate and this signature of the app id:
   `echo -n chat_archive | openssl dgst -sha512 -sign chat_archive.key | openssl base64`
   The store refuses a release of an app that it does not know.
3. Add two secrets to the GitHub repository: `APP_PRIVATE_KEY` (the content
   of `chat_archive.key`) and `APPSTORE_TOKEN` (the API token from the app
   store account page).

### Release by hand

Use this if the workflow cannot run:

1. Run `npm ci && npm run build`, then `scripts/pack.sh`.
2. Extract the tarball and sign the folder. This writes
   `appinfo/signature.json`:
   `occ integrity:sign-app --privateKey=chat_archive.key --certificate=chat_archive.crt --path=/path/to/chat_archive`
3. Pack the signed folder again, then sign the tarball:
   `openssl dgst -sha512 -sign chat_archive.key chat_archive-<version>.tar.gz | openssl base64`
4. Upload the tarball to a public URL, for example a GitHub release. Register
   the URL and the signature at <https://apps.nextcloud.com/developer/apps/releases/new>.

### Next major version

A new Nextcloud major can change the OCP interfaces the app uses. The canary
workflow, `.github/workflows/nextcloud-next.yml`, runs every Monday and on
demand. It installs the app's own dependencies, then swaps in the
`nextcloud/ocp` stubs of the newest supported branch and of `master`, loads
every class in `lib/`, and runs `composer lint`. A red canary is an early
warning, not a broken build: the app still works on the versions named in
`max-version`. A scheduled failure opens, or comments on, the issue
"Nextcloud next: canary failing".

Traps found moving to Nextcloud 35 and to the NC 36 (master) stubs:

- Nextcloud 35 answers the first OCS request after `occ app:enable` with 404,
  while it rebuilds its route cache. `tests/nc_integration.sh` polls the
  first route until it answers 200 instead of failing on the first try.
- `max-version` in `appinfo/info.xml` must go up before the app can enable on
  a new Nextcloud major. An older `max-version` refuses to enable.
- `composer.json` pins `platform.php` to `8.1`, the app's minimum PHP. The
  `nextcloud/ocp` stubs for a new major need PHP 8.3. The canary raises the
  platform to 8.3 for its own job only; the app's own minimum does not change.
- Nextcloud master (NC 36) removes `OCP\IServerContainer`. Use the PSR
  container, `Psr\Container\ContainerInterface`, autowired since NC 20.

Checklist for each new Nextcloud major:

1. The canary is green against `dev-master`.
2. Test the app by hand against a beta of the new major.
3. At RC1 of the new major, raise `max-version` in `appinfo/info.xml` and add
   the new version to the integration matrix in `.github/workflows/ci.yml`.

## Add a platform

1. Create `lib/Source/<Name>/`.
2. Implement `IProvider`: `id()`, `name()`, `needsSecret()`, `findBackup()`, `open()`.
3. Implement `IBackupReader`: `chats()`, `messages()`, `calls()`, `close()`.
   Yield the row shapes that the interface documents.
4. Add the provider class to `ProviderRegistry::PROVIDERS`.
5. Write a reader check like `tests/reader_check.php` with a small fixture.

`open()` must keep the decrypted data in memory. It must not write it to
disk, at any point, in any form.

## Conventions

- PHP 8.1, `declare(strict_types=1)`, constructor promotion, attributes for routes.
- Front end: Vue 3 options API, `@nextcloud/vue` 9 components, `t('chat_archive', …)` for every string.
- Comments and documentation in ASD-STE100 Simplified Technical English.
- A `ponytail:` comment marks a deliberate shortcut and names the upgrade path.
