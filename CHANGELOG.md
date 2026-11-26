# Changelog

All notable changes to this app go in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The repository history was rebuilt on 2026-09-05. Entries before 0.1.3 come
from the code and the documentation, not from single commits, so their dates
are the date of the release that contains them.

## [Unreleased]

## [0.2.1] - 2026-11-26

### Fixed

- The chat list did not reload while the page stayed open, so chats with
  new messages kept their old place. It now reloads every minute while
  the tab shows, and when the tab shows again.
- A chat with a message of today shows the clock time instead of
  "Today", so the order of today's chats is visible.
- A photo or a document that the live sync stored first got a false
  "Edited" mark at the next backup import. The sync keeps the caption or
  the file name in the media row, and the backup gives the same text as the
  message text. The import now takes that text as the first version. The
  upgrade removes the false marks: a media message with no text and one
  edit that is equal to its caption or file name.
- A deleted photo, video or file is now dimmed like a deleted text.
- A result of the Nextcloud search opened the start page of the app. It
  now opens the chat at that message.
- A new message of a live chat did not show when it came in while older
  messages loaded. The next step now loads it.
- A chat that you close while a page of messages loads no longer shows
  "Could not load messages".
- A group that gets a name of digits only, for example "2019", kept its
  old name after a backup import.
- The call list opened slowly in an archive with thousands of calls. It
  now shows 200 calls and adds more while you scroll. "Jump to date" and
  the filters still use all calls.

## [0.2.0] - 2026-11-25

### Added

- An opt-in, read-only live sync through a WAHA server. The administrator
  sets the switch, the URL, the API key, the media limit per run and the
  session of each user in Settings → Administration (`GET /admin/status`,
  `PUT /admin/waha`, `POST /admin/waha/test`). A user adds a live-only source
  or turns live sync on for a source with a backup, after a warning about the
  WhatsApp Terms of Service. `SyncJob` runs every 15 minutes;
  `occ chat_archive:sync [--source ID]` runs it by hand. Media goes into the
  subfolder `WAHA` of the source folder. The sync never sets a deleted,
  revoked or gone mark; only a backup import does.
- An admin switch "Realtime updates for an open chat", off by default. While
  it is on, an open chat of a source with live sync asks for its new messages
  every 8 seconds (`POST /api/chats/{id}/live`).
- A per-user switch "Preserve the archive", on by default, set through
  `PUT /settings/preserve`. While it is on, a linked file that was moved out
  of its place in the source folder is copied back there before it is deleted,
  and `DELETE /settings/sources/{id}` returns 409 `preserve_on`.
- A rebuilt personal settings page with a source list and an add dialog in
  three steps, `POST /settings/probe` to look for the backup file, and
  error codes with a text for each. A rebuilt admin page with a status
  section.
- Columns `mode`, `sync_session`, `sync_me`, `sync_at`, `sync_error` and
  `tos_at` on the source, `in_backup` on chats and messages, and
  `sync_tries` on media.
- `tests/waha_mapper_check.php` and a fake WAHA in `tests/fixtures` for the
  integration test.
- The import now decrypts a WhatsApp backup into memory only; no decrypted
  bytes reach disk. A backup that decrypts to more than 1 GiB fails clearly
  instead of importing.
- WhatsApp's own `suspicious` flag on a media file is now kept and shown. A
  flagged file gets no preview and the app never opens or restores it on
  its own; the user can still download it after a confirmation. Run
  `occ chat_archive:import --force` once after the upgrade to flag media
  already in the archive.
- Every chat name, sender name and file name shown in the browser is now
  stripped of control and bidirectional-override characters, so a crafted
  name cannot make one kind of file look like another.
- The media linker now links only files owned by the source folder's owner,
  so a file shared into that folder by another user is skipped.
- The WhatsApp reader now opens its SQLite connection with
  `trusted_schema = OFF`, `temp_store = MEMORY` and `query_only = 1`.
- Every import now compares the new backup with the archive, chat by chat.
  A message removed from the phone is marked deleted; a message shown as
  "This message was deleted" in WhatsApp is marked revoked; an edited
  message keeps its first version as the stored text and keeps every later
  version in a new edit history, reachable through
  `GET /api/messages/{id}/edits`. A chat removed from the phone is marked
  gone; a chat archived on the phone is marked phone-archived, when the
  backup reports the WhatsApp `archived` flag (a backup without it imports
  as before, with the mark left unset).
- Two view preferences, on by default: "Deleted" and "Archived on the
  phone" in the group "Show" of the chat list menu, set through
  `PUT /settings/view`. They filter only the in-app message page and the
  in-app search box; the Nextcloud unified search and full text search are
  unchanged.

### Changed

- The full text index now holds the media caption and file name, and
  indexes a media message with no text. Run `occ fulltextsearch:index` again
  after the upgrade.
- A failed import or sync logs the message, the class and `file:line`, not
  the exception trace, because the trace can hold chat text.
- `Service\ClickAction` now stores `click_action` and `click_template`
  through `OCP\Config\IUserConfig` instead of the older `OCP\IConfig`. A
  value stored before this change still reads.
- An open chat starts at the last message and stays there while images
  load. It follows new messages while you are at the end of the chat.
- A failed import shows a clear text in the language of the user, not the
  English exception text.
- Each JSON line of `occ chat_archive:sync` now names the source.
- Saving a source refuses a folder that you do not own, for example a
  folder that another user shares with you (`folder_not_found`).
- For a live source, `occ chat_archive:import` prints the line
  `<user> [<id>] <label> (<folder>): skipped, live source`, not the JSON line
  `{"source":ID,"skipped":"live"}`.

### Fixed

- Two clicks on "Import now", or an upload during a click, queued two
  imports. One UPDATE now decides which call queues the job. A stale
  `running` claim after a crash is taken over.
- A search with the full text index on returned nothing when the index hit
  ids and the filters removed all of them. The plain scan now runs then.
- Viewer fixes: texts with placeholders no longer escape twice, the call row
  is read with its peer and time, "Refresh contact names" shows the success
  toast only after success, the selected source survives a reload, an import
  that ends reloads the open chat, a failed date jump stops, and a page
  append skips a hit already in the list.
- Lint leftovers in `tests/fixtures/make_fixture.py`, `tests/run.sh`,
  `tests/nc_integration.sh` and `scripts/pack.sh`.
- Deleting a source now removes its documents from the full text index. It
  used to leave them behind, so a deleted chat could still turn up in a
  search.
- Call list contact lookups now run one contacts search for all call peers
  in the request, instead of one search per number. Opening a call list
  with many contacts is faster.
- An old WhatsApp backup that has no `jid_map`, `message_media` or call
  tables now imports. The reader skips only the feature that needs the
  missing table or column. A backup without a core table (`jid`, `chat`,
  `message`) fails with an error on the source row that names the missing
  table or column.
- A private chat whose number is in no address book now shows the name that
  WhatsApp holds for it, when the source has live sync. A later backup
  import does not put the number back. The Nextcloud unified search uses the
  same name as the app.
- A missed call in the call list was almost invisible on Nextcloud 32 and
  later: light red text on a white page. It now uses the error text colour.
- Firefox cut the year off in the "Jump to date" field.
- A search result with a photo no longer covers the next rows.
- The result filter of the calls has a new value "No answer" for an outgoing
  call that the other person did not answer. "Missed" now shows only
  incoming calls.
- A search result of a file shows the caption, not only the file name.
- The chat filter of the search no longer clears when the chat list loads
  again.
- `node scripts/l10n.mjs --check` writes no file. It only reports the keys
  that have no German text.

### Removed

- Support for Nextcloud 31. The app now needs Nextcloud 32 or later.

## [0.1.5] - 2026-09-23

### Added

- Support for Nextcloud 35.
- `tests/run.sh` runs every check in one command. `npm test` and
  `composer test` call it.
- A GitHub Actions workflow runs the lints, the static analysis and the three
  PHP checks, and the integration test in a second job.
- `scripts/pack.sh` builds the app store tarball.
- This changelog.
- German translations for the call log and for the new error messages.
- Nextcloud 34 to the integration test matrix.
- A weekly canary workflow that runs the static analysis against the OCP
  stubs of the next Nextcloud major, so a removed or changed interface shows
  up before the release does.

### Fixed

- "Open locally" now works. The request went to an `index.php` path, but the
  Files app registers that route under OCS only. The request failed without a
  message. The app now uses the OCS path and shows an error if it fails.
- A change of source no longer keeps the open chat on screen.
- The import no longer leaves the decrypted database and its folder on disk
  when the backup cannot be opened.
- Full text search now pages correctly. Page 2 showed one row.
- The import claim is now a single atomic update, so two imports of one
  source can no longer both proceed. Deleting a source while its import runs
  now answers with an error and leaves the source in place, instead of
  deleting it partway and leaving orphaned rows behind; the Remove button is
  disabled while the import runs.
- Import progress polling no longer stops after one failed request.
- The source form and the admin page now show the real reason a request
  failed, instead of a generic message.
- Reading messages and reading calls no longer cancel each other's request.
- Opening a search hit keeps "Back to results" working. Before, it closed the
  search results without showing that they were still there.
- A search offset outside the valid range can no longer be sent to the full
  text index.
- A jump to a date, or a reset, while a page loads no longer shows an empty
  chat. A late answer can no longer overwrite newer results.
- A failed request now shows a message. The chat list, the call log, the
  message list and the search said "nothing found" instead.
- Search results no longer put a link and a video player inside another link.
  A result shows a still preview, which the keyboard can reach.
- Enter returns to the search results while a result is open.
- The custom date filters send one request when the date is complete, not one
  for each key press.

### Changed

- The provider registry asks for the PSR container instead of the deprecated
  `OCP\IServerContainer`, which Nextcloud master (the next major) removes.
- The backup listener asks for the sources only on instances that have one.
- The call log route reports up to 5000 calls in the documentation, which is
  what the code returns.
- The documentation matches the code again: the unified search provider, the
  import that an upload starts, the call log, the keyboard shortcuts and the
  sort options.

## [0.1.4] - 2026-09-22

### Added

- A unified search provider, so the Nextcloud search box finds messages.
- A call log view with filters for contact, result and direction.

### Fixed

- A migration removes and re-adds the import job, because Nextcloud never
  resets the time-sensitive flag of a job that exists.

## [0.1.3] - 2026-09-16

### Added

- The upload of a backup file starts an import at once, instead of waiting for
  the next run of the timed job.

### Changed

- The import job is time sensitive again and runs every 6 hours. It ran once a
  day, in the maintenance window, so an import could lag a day behind the
  phone.

## [0.1.2] - 2026-09-16

### Changed

- Calls carry structured facts (`video`, `result`, `durationS`), so the client
  words a call in the language of the user. Rows imported before this version
  keep their English text.

## [0.1.1] - 2026-09-05

### Fixed

- Media whose name ends with a dot or a space now match the file in Nextcloud.

## [0.1.0] - 2026-09-05

### Added

- First release: import of an encrypted WhatsApp backup, the chat archive,
  media pointers by file id, and search.
