# User guide

Install: see the [README](../README.md).

## Setup

The setup has three parts: the 64-digit backup key in WhatsApp, the upload
from the phone, and the source in Nextcloud. The dialog **Add a source** in
Nextcloud under Settings → Personal → Chat Archive follows the same steps.
Parts 1 and 2 are the phone instructions. Skip them for a live-only source
(see *Live sync (WAHA)*).

### 1. Turn on the encrypted backup with a key (WhatsApp)

1. Open WhatsApp → Settings → Chats → Chat backup.
2. Tap **End-to-end encrypted backup** → **Turn on**.
3. Choose **Use 64-digit encryption key instead** → **Generate your 64-digit key**.
4. Save the key somewhere safe (a password manager). You need it once in
   step 3 below. WhatsApp cannot recover it for you.

From now on WhatsApp writes the local backup as `msgstore.db.crypt15`,
encrypted with that key. The backup runs every night around 02:00.

### 2. Upload the backup and the media (Nextcloud mobile app)

The WhatsApp folder is not a photo folder, so the app needs the **All files
access** permission:

- Android Settings → Apps → Special app access → All files access →
  Nextcloud → Allow.

Then add the WhatsApp folders to auto upload:

1. Nextcloud app → menu → Settings → **Auto upload** → **+** (add custom folder).
2. Pick Internal storage → Android → media → com.whatsapp → WhatsApp →
   **Databases**. On phones set up before 2021 the folder is Internal
   storage → WhatsApp.
3. Set the remote folder, for example `/WhatsApp/Databases`.
4. Tick **Also upload existing files**. Leave the original file in place.
5. Repeat for the media folders you want in the archive: `Media/WhatsApp
   Images`, `WhatsApp Video`, `WhatsApp Documents`, `WhatsApp Audio`,
   `WhatsApp Voice Notes`. Put them under the same parent, for example
   `/WhatsApp/Media/WhatsApp Images`. The layout does not have to match the
   phone: the archive also finds files by name.

### 3. Add the source (Nextcloud web)

Settings → Personal → **Chat Archive**. The page opens the dialog by itself
when you have no source. Otherwise choose **Add a source**. The dialog has
three steps. Use **Back** and **Next** to move.

1. **Mode.** Choose **Phone backup**. The second choice, **Live sync only**,
   appears only when the administrator allows live sync for you.
2. **Phone instructions and key.** The dialog repeats parts 1 and 2 above.
   Enter the backup key in the field **Backup key (64 characters)** and
   choose the parent folder from part 2 (`/WhatsApp`) with **Choose…**.
3. **Check.** The dialog looks for the backup file in the folder. It shows
   the file name, its size and the date it was written, or says that no
   backup is there yet. You can add the source in both cases: the import
   starts when the phone uploads the backup. Enter a name. Choose the delete
   policy (see *Delete policies*); the choice is hidden while *Preserve the
   archive* is on. Choose **Add and import**.

The import starts with the next background job. The page shows its progress.
A large backup takes a few minutes. Open **Chat Archive** from the app menu.
For WhatsApp Business repeat the steps with the `com.whatsapp.w4b` folder and
a second source.

To change a source, open its menu and choose **Edit**. Leave the backup key
field empty to keep the stored key. **Remove** deletes the chats and
messages of the source from the archive. The files stay in your folders.

## Import

| Trigger | When it runs |
|---|---|
| Automatic | Every 6 hours, for every source. |
| A new backup in the source folder | When the phone uploads the backup file, with the next background job, forced. |
| **Import now** in the settings | With the next background job, forced. |
| `occ chat_archive:import` | At once, from the command line. |

```sh
occ chat_archive:import [--user UID] [--source ID] [--force]
```

Only new messages are added, so a re-run is cheap. An import skips a source
whose backup did not change, unless you force it. Each import compares the
new backup with the archive, chat by chat, and marks what changed:

- A message deleted from the phone gets a trash mark. The archive keeps the
  original text.
- A message you edited in WhatsApp gets an "Edited" mark and shows the
  newest version. The archive keeps the first version and every later
  version as history. Open the mark to see the versions in order, oldest
  first.
- A chat you archived on the phone gets an "Archived on the phone" mark.
- A chat removed from the phone gets a "Deleted from the phone" mark, with
  the date of the import that first missed it.

An older backup file put in the source folder by mistake marks messages and
chats deleted, because they are missing from that older backup. The next
backup that has them again clears the mark, except a message deleted for
everyone on the phone: that mark stays. Each change of text between two
backups adds one version to the edit history. Thus, if you change between an
older and a newer backup many times, the history of an edited message gets a
new version at each change.

WhatsApp writes the full backup `msgstore.db.crypt15` every night. The import
reads only this file. When the folder also holds increment files
(`msgstore-increment-N.db.crypt15`), the import ignores them. Thus the archive
can be up to one day older than the phone. Messages that arrive after the
last full backup come into the archive with the next full backup. They do
not get a deleted mark.

The source shows the time of the last import and, after a failed run,
**Import failed:** with the error.

A live sync adds new messages between two backups; it never marks a message
or a chat as deleted, revoked, edited or archived. Only a backup import sets
those marks. A message that the live sync stored and the backup does not hold
yet gets no deleted mark.

A backup that is too large fails the import; see
[Troubleshooting](#troubleshooting).

After an app upgrade that adds a new flag to already-imported media, run
`occ chat_archive:import --force` once so the archive re-reads that flag
from the backup you already imported.

## Use the archive

**Chats** are listed newest first. Messages page in as you scroll. The
three-dots menu above the list holds **Sort**: *Most recent first* or *By
name*. With more than one source the same menu picks the source.

Each chat shows the time of its last message. A message of today shows
the clock time. Older messages show "Yesterday", the weekday in the last
week, or the date. The list reloads every minute while the tab shows, and
when you come back to the tab, so a chat with new messages moves up.

### Deleted and archived chats and messages

The list options menu (⋯ above the chat list) has a **Show** section with two
checkboxes. Both are on by default:

| Checkbox | When on | When off |
|---|---|---|
| Deleted | Deleted messages and chats show with a trash chip. | Deleted messages are left out of the message page and out of the in-app search. Deleted chats are left out of the chat list. |
| Archived on the phone | Archived chats show with an archive chip. | They are left out of the chat list. |

Both checkboxes change only the chat list, the message page and the in-app
search box above the chat list. They do not change the Nextcloud unified search (the
magnifying glass in the top bar) or full text search: those always include
every message.

#### Message markers

A deleted message shows a trash chip with text "Deleted". A message you edited
in WhatsApp shows an "Edited" chip. Both chips appear below the message text
and the time. Photos, videos and files get the same chips. The text and the
file of a deleted message show dimmed.

The edited chip is a button. Click it or press Enter to see the edit history.
The history shows each version in order, with the label "Seen in backup of
{date}" (the date you imported that version). The original text comes first,
labelled "Original".

**Jump to date** above an open chat scrolls to the chosen day.

**Keyboard**: `/` puts the cursor in the chat search box. `Escape` clears
that box, and closes the search filters when the result list is open. Both
keys do nothing while you type in a field.

**Contact names** come from your Nextcloud address books, so a number shows
as a name. The name is cached per chat. **Refresh contact names** clears
that cache after you edit a contact. A number that is in no address book
shows with the name that WhatsApp holds for it, when the source has live
sync. If not, the number shows.

**Media** opens from the message. A file that WhatsApp itself flagged as
suspicious shows a warning instead of a preview: the app never opens it and
never restores a deleted copy of it on its own. Only a **Download** button
is offered, and it asks you to confirm before it starts.

A file can show the chip "WhatsApp recorded a different file type". WhatsApp
recorded one type of file for the message, and Nextcloud sees a different
type for the file, for example a photo in WhatsApp and a program in
Nextcloud. Point at the chip to see the two types. Only the main type counts
(image, video, audio, application and so on). When Nextcloud cannot tell
the type of the file, no chip shows. The file can be a different kind of
file than the message shows. Open it only if you trust the sender.

A left click on any other file runs the action you choose in
Settings → Personal → Chat Archive:

| Action | What happens |
|---|---|
| Open in Nextcloud (default) | The Nextcloud viewer opens the file. A file the viewer does not know is downloaded. |
| Open the local copy with the Nextcloud desktop client | The path goes to the Nextcloud desktop client on that machine. |
| Open with a URL template | Your own URL, with `{path}` replaced by the path of the file. For a WebDAV mount or your own URL handler. |

In a chat, the menu on each file offers **Show in Files**, **Download** and
**Open locally**. A search result of a file shows a still preview and the
caption, with the search terms marked. When the file has no caption, the
result shows the file name. Open the result to get the file and its menu
in the chat.

## Search

The box above the chat list filters the chats as you type. Enter opens the
message search; the slider icon opens it with filters only.

| Filter | Values |
|---|---|
| Source | one source or all |
| Chat | one chat of the open source |
| Type | text, any file, photos, videos, voice and audio, documents, links, calls |
| From | anyone, me, others |
| When | any time, last 24 h / 7 d / 30 d / year, or a custom range |

A search term is optional once a filter is set, so "all documents in March"
is a valid search. Results come 50 at a time, newest first.

Click a result to open its chat at that message. The arrow **Back to
results** above the chat brings the result list back.

The Nextcloud search (the magnifying glass in the top bar) also finds
messages. Click a result there to open Chat Archive at that message.

### Full text search (optional)

Settings → Administration → Chat Archive has a switch for the Nextcloud
Full text search app. With the switch on, the best matches come first.
Messages become searchable at the next `occ fulltextsearch:index` run, not
at import. The index now also holds the caption and the file name of a
media file, and indexes a media message with no text. After an upgrade from
0.1.x run `occ fulltextsearch:index` again to add them.

When the index is empty or the platform is down, the search falls back to
the plain scan and shows no error.

## Calls

**Calls** above the chat list opens the calls of the source. Each row
shows the contact, the direction, the result and the time. A missed incoming
call is marked in red. Day bands separate the days.

| Filter | Values |
|---|---|
| Contact | part of a name or a number |
| Result | any, missed, no answer, connected, declined, failed |
| Direction | any, incoming, outgoing |

*Missed* is an incoming call that you did not answer. *No answer* is an
outgoing call that the other person did not answer.

The count above the list says how many calls match. **Clear filters**
removes all three.

**Jump to date** scrolls to the chosen day, or to the next older day with a
call.

Click a call to open the chat with that contact at the time of the call.
Calls without a chat in the archive stay in the list but do not open.

## Live sync (WAHA)

Live sync is optional. It reads new messages between two backups from a WAHA
server. WAHA is a self-hosted bridge that is linked to a WhatsApp number. The
administrator turns it on.

**Warning.** WAHA is an unofficial WhatsApp client. Its use is against the
WhatsApp Terms of Service, and WhatsApp can restrict or ban a number that
uses one. Chat Archive only reads; it never sends. In daily use it reads the
few messages since the last sync. The first sync of a live-only source loads
the history and, when the administrator allows it, its media in a short
time, which can look like automation. Start from a phone backup when you can.

### Set up a WAHA server

The administrator does this one time. WAHA runs as a container on the same
host as Nextcloud, or on a host that only Nextcloud can reach. This setup
was tested with WAHA 2026.10.1, engine GOWS, the free tier.

1. Make an API key and its SHA-512 hash. WAHA stores only the hash:

   ```
   openssl rand -hex 24 > api-key
   printf %s "$(cat api-key)" | sha512sum
   ```

2. Write the file `.env`:

   ```
   WHATSAPP_DEFAULT_ENGINE=GOWS
   WAHA_API_KEY=sha512:<the hash>
   WAHA_DASHBOARD_USERNAME=admin
   WAHA_DASHBOARD_PASSWORD=<a password>
   WHATSAPP_DOWNLOAD_MEDIA=false
   WAHA_API_DOWNLOAD_MEDIA=false
   ```

   With the two media lines, WAHA loads a media file only when Chat Archive
   asks for it.

3. Start the container. Bind the port to the local address, so that only
   this host can reach it:

   ```
   docker run -d --name waha --restart unless-stopped --env-file .env \
     -p 127.0.0.1:3000:3000 \
     -v ./sessions:/app/.sessions -v ./media:/app/.media \
     devlikeapro/waha:gows
   ```

4. Open `http://localhost:3000/dashboard`. Log in with the dashboard name
   and password. In the section **Workers**, choose **Connect** and enter
   the API key. Without the key, the dashboard shows the server as not
   connected and lists no sessions.
5. In the section **Sessions**, choose **Start New** and start the session
   `default`. The free tier has only this session.
6. On the phone, open WhatsApp → **Linked devices** → **Link a device** and
   scan the QR code of the dashboard. The QR code is valid for about two
   minutes. After that time, start the session again.
7. In Nextcloud, enter the server URL `http://127.0.0.1:3000` and the API
   key in the section **Live sync (WAHA)**, turn on **Allow live sync
   through WAHA** and choose **Save**. Then choose **Test connection**. The
   session `default` must show the status `WORKING`.

Link the phone one time and keep the link. WhatsApp sends the chat history
again each time the phone links.

When the host loses its network connection, WAHA connects again by itself.
This can take some minutes. A sync in that time fails with the session
status error. The next sync removes the error.

### What the administrator sets

Settings → Administration → Chat Archive, section **Live sync (WAHA)**:

- **Allow live sync through WAHA**: the switch for the whole server.
- **Server URL** and **API key**. The app stores the key encrypted (see
  [Security boundaries](DEVELOPER-GUIDE.md#security-boundaries)) and never
  shows it again; leave the field empty to keep it.
- **Media files per sync run**: 0 to 200. The default is 0: the sync loads
  no media.
- **Realtime updates for an open chat**: off by default. When it is on, a
  chat that is open in the app asks the WAHA server for its new messages.
  See [Realtime updates](#realtime-updates).
- **Test connection**: shows the WAHA version, engine and tier, or an error.
  The test uses the saved URL and key. It also lists the sessions.
- **Sessions**: enter a Nextcloud user ID for each session. One session
  belongs to one user. Leave the field empty to leave a session unassigned.

The section **Status** shows the background job mode, the number of sources
and the sources that failed. Live sync needs the system cron.

WhatsApp sends the chat history to WAHA when the phone links to it.
WhatsApp decides how far back that history goes. Chat Archive cannot limit
it. WAHA has settings that ask for a shorter history
(`WAHA_GOWS_DEVICE_HISTORY_SYNC_*`), but WhatsApp does not always obey them.
In a test with a limit of 30 days, one year of messages arrived.

### Add live sync to a source

Two ways exist:

- **Phone backup plus live sync.** Import a backup first. Then choose
  **Turn on live sync…** in the menu of the source, choose a session and
  tick **I understand the risk for my WhatsApp number.**
- **Live sync only.** In the dialog, step 1, choose **Live sync only**. Read
  the warning, choose a session and tick the box. Step 2 asks for a name and
  a folder only. Choose **Next**. Step 3 shows the delete policy. Choose
  **Add and sync**.

The first sync starts with the next background job. It loads the chat
history. A sync runs every 15 minutes and stops after about 40 seconds. The
next run continues where it stopped. Choose **Sync now** (live-only source)
or **Import now** (source with a backup) to queue one run. **Import now**
syncs after the import. The administrator can run
`occ chat_archive:sync [--source ID]`.

### Realtime updates

Without realtime updates, a new message shows after the next sync, at most
15 minutes later, and after you load the page again.

When the administrator turns on **Realtime updates for an open chat**, a
chat of a source with live sync that is open in the app shows a new message
after about 8 seconds. These rules apply:

- Only the chat that is open gets the updates. The other chats and the chat
  list wait for the sync.
- The page asks only while its browser tab is visible and while it shows the
  newest messages of the chat.
- The update loads message text only. Media files come with the sync.
- The requests go to the WAHA server, not to WhatsApp. WAHA answers from its
  own store.
- A source with a backup only gets no realtime updates.

Media files of the live sync go into the subfolder `WAHA/<year-month>` of
the source folder. WAHA cannot give the file of some messages. The sync
tries such a file three times and then leaves it out. A later backup import
adds it when the backup holds it. A contact card shows the name on the card,
and a location shows its coordinates. The sync does not read edits, revokes and deletions. A later backup import
sets them.

**Turn off live sync** in the menu of the source stops it. The stored
messages stay.

### Errors

The source shows **Live sync failed** with one of these messages.

| Message | What to do |
|---|---|
| Live sync is turned off on this server | Ask the administrator to turn it on. |
| This live sync session is no longer assigned to you | Ask the administrator to assign it again, or turn off live sync for the source. |
| The WAHA server does not answer | Try again later. Tell the administrator if it stays. |
| The WAHA server does not accept the API key | The administrator enters the correct key. |
| The WAHA server does not know this session | The administrator checks the session name. |
| The WhatsApp session on the WAHA server is not ready | The administrator links the phone again in WAHA. |
| This session now belongs to a different WhatsApp number | The sync stopped so that no foreign chats enter your archive. The administrator links the session to your number again. |
| The WAHA server sent an answer that the archive cannot read | Tell the administrator. A WAHA update can cause this. |
| Nextcloud blocked the connection to the WAHA server | The administrator allows local addresses in the server configuration. |
| The WAHA session does not report its WhatsApp account. The sync continues when it does. | Wait for the next sync. If the message stays, the administrator checks the session in WAHA. |
| The sync stopped with an internal error. The Nextcloud log has the details. | Tell the administrator. |
| The folder of this source does not exist | Edit the source and choose a folder. |

While you add or change a source, the dialog can also say that another
source uses the session, that you must tick the box, or that you must import
a backup first.

## Preserve the archive

**Preserve the archive** is a switch in Settings → Personal → Chat Archive.
It is on by default. While it is on:

- You cannot remove a source. **Remove** is disabled and the server refuses
  the request.
- The app acts as the policy *Restore* for every source. A linked file that
  you moved out of its place in the source folder is copied back to that
  place before it is deleted.
- A file deleted at its own place in the source folder is not copied. It goes
  to the Nextcloud trash, and a restore from the trash links it again.
- A file that WhatsApp flagged, and a file with no known place, is never
  copied back.

Turn the switch off to remove a source or to use the policies *Unlink* and
*Keep*.

## Delete policies

The policy is a property of the source. It decides what happens when you
delete a file elsewhere in Nextcloud that a message points to. The default
is *restore*.

| Policy | Use it when |
|---|---|
| Restore | The archive must stay complete. A delete elsewhere is not a decision about the archive: the app copies the file back into the source folder. |
| Unlink | You sort and delete files on purpose. The message then shows that the file is gone. |
| Keep | You use the trash as an undo. The link comes back with the file. |

Deleting the source folder itself, or a parent of it, never copies a file
back. The app takes that as a decision.

While *Preserve the archive* is on, every source acts as *Restore* (see above
for the cases it does not cover).

## Troubleshooting

**"The folder has no backup file. The file name is msgstore.db.crypt15."** —
the phone did not upload `msgstore.db.crypt15` yet, or the folder of the
source is not the parent of `Databases`. Check step 2 of the setup.

**"This source has no backup key. Enter the key in the settings of the
source."** — open the menu of the source, choose **Edit** and paste the
backup key.

**"The backup key does not open this backup. Check the key."** — the key does
not match this backup. WhatsApp writes a new key when you turn the encrypted
backup off and on again. No rows change on a failed import.

**"The backup file is damaged or not complete. Wait for the next backup from
the phone."** — the checksum or the decompression of the backup failed, for
example because the upload did not finish. No rows change. Wait for the next
nightly backup from the phone.

**"The backup is too large for the import."** — the decrypted backup is above
one of two limits:

- 1024 MiB (1 GiB). The app reads the backup in memory only and does not
  fall back to disk.
- 1032 times the size of the encrypted file. No real backup decompresses to
  more than this, so a backup above this limit is damaged. The limit stops a
  small file from using a large amount of memory.

In both cases the import stops with no rows changed.

**A message shows "not in Nextcloud"** — the media file is not in the source
folder. The phone may never have downloaded it: WhatsApp keeps such a file
on its servers only, and the archive cannot fetch it. The next import links
the file if it arrives later.

**"Open locally" does nothing** — the Nextcloud desktop client must run on
that machine and must sync the folder of the file. The browser asks once
before it hands a link to the client; allow it. When the server refuses the
link, the app shows the error "Could not open the file locally".

**A narrow date range returns fewer hits with full text search on** — the
index ranks the top hits first and the filters apply afterwards. Turn the
switch off to use the plain scan, which applies the filters in the database.
