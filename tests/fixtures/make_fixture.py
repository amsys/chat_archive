#!/usr/bin/env python3
"""Build the WhatsApp-shaped test fixtures.

Writes tests/fixtures/msgstore.db, a tiny SQLite database, then
msgstore.db.crypt15, the same database encrypted with the test key, and
key.hex, that key.
Needs ~/.venv with wa_crypt_tools (waencrypt).
With --old, build only tests/fixtures/msgstore_old.db (see below).
With --v2, build only tests/fixtures/msgstore_v2.db and msgstore_v2.db.crypt15:
a later backup of the same phone (see V2 below).

Chats in DATA:
  chat A = 23000001@s.whatsapp.net (sqlite chat 1, a lid chat), archived = 0
  chat B = 23000002-1600000000@g.us (sqlite chat 2, "Family group"), archived = 1

The default and --v2 builds add message_media.suspicious_content after DATA
(the --old build does not get the column). The photo media row of
message K3 (chat A, so it survives in both builds) gets suspicious_content=3
and a media_name with a U+202E right-to-left override character.

Changes in v2 against DATA:
  removed message   K8 (chat A, old-style missed call)      -> deleted 'missing'
  revoked message   K2 (chat A, text; now type 15, text NULL) -> deleted 'revoked'
  edited message    K1 (chat A, text; new text_data)          -> one edit version
  gone chat         chat B and all its messages (K4, K5, K9)  -> gone_at
  archived flipped  chat A 0 -> 1; chat B leaves the backup
  call_log does not change.

Expected stats of a forced import of v2 after v1 (ordered substring):
  "chats":1,"messages":0,"calls":0,"linked":0,"deleted":1,"revoked":1,"edited":1,"gone_chats":1
"""

import sqlite3
import subprocess
import sys
from pathlib import Path

here = Path(__file__).resolve().parent
DATA = """
CREATE TABLE jid (_id INTEGER PRIMARY KEY, user TEXT, server TEXT, raw_string TEXT);
CREATE TABLE jid_map (lid_row_id INTEGER, jid_row_id INTEGER);
CREATE TABLE chat (_id INTEGER PRIMARY KEY, jid_row_id INTEGER, subject TEXT, archived INTEGER DEFAULT 0);
CREATE TABLE message (_id INTEGER PRIMARY KEY, chat_row_id INTEGER, key_id TEXT, timestamp INTEGER,
  from_me INTEGER, message_type INTEGER, text_data TEXT, sender_jid_row_id INTEGER);
CREATE TABLE message_media (message_row_id INTEGER, chat_row_id INTEGER, file_hash TEXT, mime_type TEXT,
  file_size INTEGER, media_name TEXT, media_caption TEXT, file_path TEXT, direct_path TEXT,
  media_key BLOB, enc_file_hash TEXT);
CREATE TABLE call_log (_id INTEGER PRIMARY KEY, call_id TEXT, timestamp INTEGER, from_me INTEGER,
  video_call INTEGER, duration INTEGER, call_result INTEGER, jid_row_id INTEGER, group_jid_row_id INTEGER);
CREATE TABLE message_call_log (message_row_id INTEGER, call_log_row_id INTEGER);
CREATE TABLE missed_call_logs (_id INTEGER PRIMARY KEY, message_row_id INTEGER, timestamp INTEGER, video_call INTEGER);
INSERT INTO jid VALUES (1,'23000001','s.whatsapp.net','23000001@s.whatsapp.net');
INSERT INTO jid VALUES (2,'123456789','lid','123456789@lid');
INSERT INTO jid VALUES (3,'23000002-1600000000','g.us','23000002-1600000000@g.us');
INSERT INTO jid VALUES (4,'23000003','s.whatsapp.net','23000003@s.whatsapp.net');
INSERT INTO jid_map VALUES (2,1);
INSERT INTO chat VALUES (1,2,NULL,0);            -- chat A: lid chat, maps to jid 1
INSERT INTO chat VALUES (2,3,'Family group',1);  -- chat B: archived on the phone
INSERT INTO chat VALUES (3,4,NULL,0);            -- no messages: skipped
INSERT INTO message VALUES (1,1,'K1',1725000000000,0,0,'hello from alice',2);
INSERT INTO message VALUES (2,1,'K2',1725000060000,1,0,'hi alice',NULL);
INSERT INTO message VALUES (3,1,'K3',1725000120000,0,1,NULL,2);
INSERT INTO message VALUES (4,2,'K4',1725000180000,0,9,NULL,4);
INSERT INTO message VALUES (5,2,'K5',1725000240000,1,7,NULL,NULL);
INSERT INTO message VALUES (6,2,NULL,1725000300000,1,0,'no key id, skipped',NULL);
INSERT INTO message VALUES (7,1,'K7',1725001000000,1,90,NULL,NULL);   -- call log -> C1
INSERT INTO message VALUES (8,1,'K8',1725001500000,0,10,NULL,2);      -- old-style missed call
INSERT INTO message VALUES (9,2,'K9',1725001600000,0,1,CAST(X'636166c3a9200062616420ff6279746573' AS BLOB),4); -- NUL + invalid UTF-8 in the caption text
INSERT INTO message_call_log VALUES (7,1);
INSERT INTO missed_call_logs VALUES (1,8,1725001500000,1);
INSERT INTO message_media VALUES (3,1,'aGFzaDE=','image/jpeg',1234,NULL,'a photo',
  'Media/WhatsApp Images/IMG-20240830-WA0001.jpg','/v/t62.7118-24/x.enc',X'0102','ZW5j');
INSERT INTO message_media VALUES (4,2,'aGFzaDI=','application/pdf',5678,'invoice.pdf',NULL,
  'Media/WhatsApp Documents/invoice.pdf',NULL,NULL,NULL);
INSERT INTO message_media VALUES (9,2,NULL,'image/jpeg',10,'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn.jpg','caf\u00e9',
  '../../outside/evil.jpg',NULL,NULL,NULL);  -- name over the column length, path that leaves the folder
INSERT INTO call_log VALUES (1,'C1',1725001000000,1,0,65,5,1,NULL);
INSERT INTO call_log VALUES (2,'C2',1725002000000,0,1,0,2,NULL,3);
"""

# The later backup: DATA plus these changes. Keep this in step with the top docstring.
V2 = """
UPDATE chat SET archived = 1 WHERE _id = 1;
DELETE FROM message_media WHERE chat_row_id = 2;
DELETE FROM message WHERE chat_row_id = 2;
DELETE FROM chat WHERE _id = 2;
DELETE FROM missed_call_logs WHERE message_row_id = 8;
DELETE FROM message WHERE key_id = 'K8';
UPDATE message SET message_type = 15, text_data = NULL WHERE key_id = 'K2';
UPDATE message SET text_data = 'hello from alice, edited' WHERE key_id = 'K1';
"""
KEY = "00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff"


def encrypt(db: Path, out: Path) -> None:
    """Encrypt db into out as a crypt15 backup with the test key."""
    tool = str(Path("~/.venv/bin/waencrypt").expanduser())
    r = subprocess.run(
        [tool, "--type", "15", "--jid", "01", KEY, str(db), str(out)],
        capture_output=True,
        text=True,
        check=False,
    )
    if r.returncode != 0:
        sys.exit(r.stdout + r.stderr)
    sys.stdout.write(
        f"wrote {out} ({out.stat().st_size} bytes)"
        f" from {db} ({db.stat().st_size} bytes)\n"
    )


# --old: write only msgstore_old.db, plain SQLite, the same data without the
# optional tables of old backups. It does not touch the other fixtures and
# does not need wa_crypt_tools.
if "--old" in sys.argv[1:]:
    old = here / "msgstore_old.db"
    old.unlink(missing_ok=True)
    c = sqlite3.connect(old)
    c.executescript(DATA)
    c.executescript(
        "DROP TABLE jid_map; DROP TABLE call_log; DROP TABLE message_call_log; "
        "DROP TABLE missed_call_logs; ALTER TABLE chat DROP COLUMN archived;"
    )
    c.commit()
    c.execute("VACUUM")
    c.close()
    sys.stdout.write(f"wrote {old} ({old.stat().st_size} bytes)\n")
    sys.exit(0)

v2 = "--v2" in sys.argv[1:]
name = "msgstore_v2.db" if v2 else "msgstore.db"
db = here / name
db.unlink(missing_ok=True)
(here / "key.hex").write_text(KEY + "\n")

c = sqlite3.connect(db)
c.executescript(DATA)
if v2:
    c.executescript(V2)
c.executescript("ALTER TABLE message_media ADD COLUMN suspicious_content INTEGER;")
c.execute(
    "UPDATE message_media SET suspicious_content = 3, media_name = ? WHERE message_row_id = 3",
    ("IMG-20240830-WA0001\u202e.jpg",),
)
c.commit()
c.close()

encrypt(db, here / f"{name}.crypt15")
