#!/usr/bin/env bash
# End-to-end check in a throwaway Nextcloud container:
# enable the app, upload the fixture, import, query the OCS API, move and
# delete a linked media file and watch the pointer follow / the file come back.
set -euo pipefail
cd "$(dirname "$0")/.."
IMAGE=${IMAGE:-nextcloud:32-apache}
NAME=chat-archive-test
PORT=${PORT:-18080}
BASE="http://localhost:$PORT"
AUTH=(-u admin:admin)
OCS=(-H OCS-APIREQUEST:true)

# Container engine: rootless podman by default, docker in CI
CTR=${CTR:-}
if [ -z "$CTR" ]; then
	if [ -n "${CI:-}" ]; then
		CTR=docker
	else
		# Reject root run outside CI: rootless podman root run lands in another store and leaks
		if [ "$(id -u)" = 0 ]; then
			echo "FAIL: tests must run as a non-root user (not in CI); use rootless podman" >&2
			exit 1
		fi
		CTR=podman
	fi
fi

occ() { $CTR exec -u www-data $NAME php occ "$@"; }
api() { curl -sf "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/apps/chat_archive$1?format=json" "${@:2}"; }
dav() { curl -sf "${AUTH[@]}" "$BASE/remote.php/dav/files/admin$1" "${@:2}"; }
TMP=$(mktemp -d)
fail() { echo "FAIL: $*" >&2; exit 1; }

trap 'rm -rf "$TMP"; if [ "${KEEP:-0}" = 1 ]; then echo "container $NAME kept"; else $CTR rm -fv $NAME >/dev/null; fi' EXIT

$CTR rm -fv $NAME >/dev/null 2>&1 || true
# rootless podman's default pids limit kills the nextcloud entrypoint with 'Cannot fork'
$CTR run -d --pids-limit=-1 --name $NAME -p "$PORT:80" \
  -e SQLITE_DATABASE=nc -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin \
  -e NEXTCLOUD_TRUSTED_DOMAINS=localhost "$IMAGE" >/dev/null

echo "waiting for install..."
for _ in $(seq 1 90); do
  if occ status 2>/dev/null | grep -q "installed: true"; then break; fi
  sleep 2
done
occ status | grep -q "installed: true" || fail "nextcloud did not install"
for _ in $(seq 1 60); do curl -sf "$BASE/status.php" >/dev/null && break; sleep 2; done
curl -sf "$BASE/status.php" >/dev/null || fail "apache not reachable on $BASE"
# a bind mount owned by another uid makes the apps-dir permission check fail, so copy instead
$CTR exec $NAME mkdir -p /var/www/html/custom_apps/chat_archive
tar --exclude=node_modules --exclude=vendor --exclude=.git --exclude='*.map' -cf - . | $CTR exec -i $NAME tar -xf - -C /var/www/html/custom_apps/chat_archive
$CTR exec $NAME chown -R www-data:www-data /var/www/html/custom_apps/chat_archive
occ app:enable chat_archive
occ app:list | grep chat_archive >/dev/null || fail "app not enabled"   # grep -q exits early; under pipefail the SIGPIPE on occ fails the line
# Nextcloud 35 answers the first request after app:enable with 404 (OCS 998)
# while it rebuilds the route cache. Wait for the route, as the script already
# waits for the server itself.
code() { curl -s -o /dev/null -w '%{http_code}' "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/apps/chat_archive/settings/sources?format=json"; }
for _ in $(seq 1 30); do
  [ "$(code)" = 200 ] && break
  sleep 2
done
[ "$(code)" = 200 ] || fail "the app's OCS route never answered (last code $(code))"

echo "seeding files..."
$CTR exec $NAME bash -c 'mkdir -p "/var/www/html/data/admin/files/WhatsApp/Media/WhatsApp Images" /var/www/html/data/admin/files/Moved'
$CTR cp tests/fixtures/msgstore.db.crypt15 $NAME:/var/www/html/data/admin/files/WhatsApp/msgstore.db.crypt15
printf 'not really a jpeg' > "$TMP/ca_img.jpg"
$CTR cp "$TMP/ca_img.jpg" "$NAME:/var/www/html/data/admin/files/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg"
$CTR exec $NAME chown -R www-data:www-data /var/www/html/data/admin/files
occ files:scan admin >/dev/null

echo "creating source over OCS..."
KEY=$(cat tests/fixtures/key.hex)
api /settings/sources -X POST -d provider=whatsapp -d label=Test -d folder=/WhatsApp -d secret="$KEY" -d onDelete=restore > "$TMP/ca_src.json"
SID=$(python3 -c "import json;print(json.load(open('$TMP/ca_src.json'))['ocs']['data']['id'])")
python3 -c "import json;d=json.load(open('$TMP/ca_src.json'))['ocs']['data'];assert d['hasSecret'] and d['folder']=='/WhatsApp', d" || fail "the new source does not report its key and folder"
api /settings/sources -X POST -d provider=whatsapp -d label=Bad -d folder=/WhatsApp -d secret=short -d onDelete=restore >/dev/null 2>&1 && fail "bad key accepted"
api /settings/sources -X POST -d provider=telegram -d label=Bad -d folder=/WhatsApp -d onDelete=restore >/dev/null 2>&1 && fail "unknown provider accepted"

echo "importing..."
OUT=$(occ chat_archive:import --user admin --force)
echo "$OUT"
echo "$OUT" | grep -q '"chats":2,"messages":8,"calls":2,"linked":1' || fail "unexpected import stats"
OUT2=$(occ chat_archive:import --user admin)
echo "$OUT2" | grep -q '"skipped":true' || fail "second run should skip an unchanged backup"

echo "preserve on: the server refuses to remove a source..."
# The preserve switch defaults on and no value is stored yet.
CODE=$(curl -s -o "$TMP/ca_del.json" -w '%{http_code}' "${AUTH[@]}" "${OCS[@]}" -X DELETE "$BASE/ocs/v2.php/apps/chat_archive/settings/sources/$SID?format=json")
[ "$CODE" = 409 ] || fail "delete with preserve on did not answer 409 (got $CODE)"
python3 -c "import json;d=json.load(open('$TMP/ca_del.json'))['ocs']['data'];assert d['code']=='preserve_on',d" || fail "delete with preserve on did not give code preserve_on"
api "/api/sources/$SID/chats" >/dev/null || fail "delete with preserve on removed the source"
# The delete and delete-policy steps below need the switch off.
api /settings/preserve -X PUT -d preserve=false | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d=={'preserve':False},d" || fail "PUT /settings/preserve did not turn preserve off"

echo "delete and a second import are refused while the source is claimed as running..."
PREFIX=$(occ config:system:get dbtableprefix 2>/dev/null || echo oc_)
$CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_source SET import_state = ?, import_since = ? WHERE id = ?')
	->execute(['running', time(), $SID]);
"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "${AUTH[@]}" "${OCS[@]}" -X DELETE "$BASE/ocs/v2.php/apps/chat_archive/settings/sources/$SID?format=json")
[ "$CODE" = 409 ] || fail "delete during a running import did not answer 409 (got $CODE)"
OUT3=$(occ chat_archive:import --user admin --source "$SID" --force)
echo "$OUT3" | grep -q 'an import or a sync is running' || fail "import while running did not report a running import or sync: $OUT3"
$CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_source SET import_state = NULL, import_since = NULL WHERE id = ?')
	->execute([$SID]);
"

echo "wrong key: import fails, the error lands on the source row..."
api /settings/sources -X POST -d provider=whatsapp -d label=WrongKey -d folder=/WhatsApp -d secret="$(printf 'ab%.0s' {1..32})" -d onDelete=restore > "$TMP/ca_src2.json"
SID2=$(python3 -c "import json;print(json.load(open('$TMP/ca_src2.json'))['ocs']['data']['id'])")
occ chat_archive:import --user admin --source "$SID2" --force >/dev/null 2>&1 && fail "wrong key import returned success"
# a failed import must not leave the decrypted database, or a SQLite temp-file spill, on disk
LEFT=$($CTR exec $NAME find /tmp /var/tmp -name msgstore.db -o -name 'etilqs_*')   # no pipe: grep -q would SIGPIPE find, see the app:list line
[ -z "$LEFT" ] || fail "decrypted database or a SQLite temp file left behind after a failed import: $LEFT"
api /settings/sources | python3 -c "
import json,sys
s=[x for x in json.load(sys.stdin)['ocs']['data']['sources'] if x['id']==$SID2][0]
assert s['lastError'] == 'wrong_key', s
assert s['importState'] is None and s['lastSynced'] is None, s" || fail "wrong key: the source row does not hold the code wrong_key"
api "/settings/sources/$SID2" -X DELETE >/dev/null

echo "PUT backup file via WebDAV queues ImportSourceJob..."
dav "/WhatsApp/msgstore.db.crypt15" -T tests/fixtures/msgstore.db.crypt15
occ background-job:list --class='OCA\ChatArchive\Cron\ImportSourceJob' | grep -q "$SID" || fail "ImportSourceJob not queued for sourceId $SID"

echo "import state..."
api "/settings/sources/$SID/import" -X POST | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d['importState']=='queued' and d['importSince'],d" || fail "POST import did not queue the source"
api /settings/sources | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data']['sources'][0];assert d['importState']=='queued',d" || fail "the source list does not show the queued state"
occ chat_archive:import --user admin >/dev/null
api /settings/sources | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data']['sources'][0];assert d['importState'] is None and d['importSince'] is None,d" || fail "the import did not clear the queued state"

echo "querying..."
api "/api/sources/$SID/chats" > "$TMP/ca_chats.json"
python3 - <<PY || fail "unexpected chat list after the import"
import json
chats = json.load(open('$TMP/ca_chats.json'))['ocs']['data']
assert len(chats) == 2, chats
byname = {c['name']: c for c in chats}
assert '+23000001' in byname and 'Family group' in byname, byname.keys()
assert byname['+23000001']['msgCount'] == 5 and byname['Family group']['msgCount'] == 3, chats
open('$TMP/ca_chat_id', 'w').write(str(byname['+23000001']['id']))
open('$TMP/ca_group_id', 'w').write(str(byname['Family group']['id']))
PY
CID=$(cat "$TMP/ca_chat_id")
api "/api/chats/$CID/messages" > "$TMP/ca_msgs.json"
python3 - <<PY || fail "unexpected message rows after the import"
import json
d = json.load(open('$TMP/ca_msgs.json'))['ocs']['data']
m = d['messages']
assert [x['body'] for x in m[:2]] == ['hello from alice', 'hi alice'], m
assert d['senders'].get('23000001@s.whatsapp.net') == '+23000001', d['senders']
md = m[2]['media']
assert md['exists'] and md['path'] == '/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg', md
assert md['caption'] == 'a photo' and md['fileId'], md
assert md['suspicious'] is True, md                               # WhatsApp's own verdict, kept
assert md['hasPreview'] is False, md                              # a flagged file is never previewed, even a jpg
assert md['realName'] == 'IMG-20240830-WA0001.jpg', md            # the real file name, not the backup's claim
assert '\u202e' not in (md['fileName'] or '') and '\u202e' not in md['realName'], md  # the API returns fileName without bidi override
assert m[0]['call'] is None, m[0]
assert m[3]['body'] is None and m[3]['call'] == {'video': False, 'result': 'connected', 'durationS': 65}, m[3]
assert m[4]['call'] == {'video': True, 'result': 'missed', 'durationS': None}, m[4]
PY

# Verify the database still stores file_name raw with U+202E (output is cleaned, stored value is not)
# U+202E as its UTF-8 bytes: the file holds no literal bidi control, and no locale changes the bytes
RLO=$'\xe2\x80\xae'
$CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$stmt = \$pdo->prepare('SELECT file_name FROM ${PREFIX}chatarchive_media WHERE file_name LIKE \"%${RLO}%\" LIMIT 1');
\$stmt->execute();
\$row = \$stmt->fetch(PDO::FETCH_ASSOC);
if (!\$row) { echo 'FAIL: no U+202E found in chatarchive_media.file_name' . PHP_EOL; exit(1); }
echo 'OK: stored file_name still has U+202E' . PHP_EOL;
" || fail "stored file_name does not retain U+202E"

echo "backfill: a value cleared from the flagged row returns on the next forced import..."
$CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_media SET suspicious = NULL WHERE source_id = ?')->execute([$SID]);
"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['suspicious'] is False,md" || fail "suspicious did not clear before the backfill check"
occ chat_archive:import --user admin --force >/dev/null
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['suspicious'] is True,md" || fail "backfill did not re-flag a known message"

echo "hostile backup text is cleaned on import..."
api "/api/chats/$(cat "$TMP/ca_group_id")/messages" > "$TMP/ca_group.json"
python3 - <<PY || fail "hostile backup text was not cleaned on import"
import json
m = json.load(open('$TMP/ca_group.json'))['ocs']['data']['messages'][2]
assert m['body'] == 'caf\u00e9 bad ?bytes', repr(m['body'])   # NUL dropped, invalid byte replaced
md = m['media']
assert md['srcPath'] is None and not md['exists'] and md['hasPreview'] is False, md   # a path with '..' is not stored
assert len(md['fileName']) == 255, len(md['fileName'])           # cut to the column length
PY
search() { api /api/search -G "$@" | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];print(len(d['messages']),d['more'],sorted(set(x['kind'] for x in d['messages'])),list((d['chats'] or {}).values()))"; }
[ "$(search -d q=alice)" = "2 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "search alice: $(search -d q=alice)"
[ "$(search -d "source=$SID" -d q=alice -d who=me)" = "1 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "who=me"
[ "$(search -d kind=image)" = "2 False ['image'] [{'name': '+23000001', 'sourceId': $SID}, {'name': 'Family group', 'sourceId': $SID}]" ] || fail "kind=image: $(search -d kind=image)"
[ "$(search -d q=invoice)" = "1 False ['document'] [{'name': 'Family group', 'sourceId': $SID}]" ] || fail "file name match"
[ "$(search -d kind=call -d "chat=$CID")" = "2 False ['call_log', 'missed_call'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "kind=call in chat"
api /api/search -G -d kind=call -d "chat=$CID" | python3 -c "import json,sys;m=json.load(sys.stdin)['ocs']['data']['messages'];assert all(x['call'] for x in m),m" || fail "a call hit of the search has no call data"
[ "$(search -d kind=media -d from=1725000000000 -d to=1725000000000)" = "0 False [] []" ] || fail "date range excludes"
[ "$(search -d q=alice -d from=1725000000000 -d to=1725000000000)" = "1 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "date range includes"
[ "$(search -d source=999999 -d q=alice)" = "0 False [] []" ] || fail "foreign source leaks"
[ "$(search)" = "0 False [] []" ] || fail "empty search returns rows"
api "/api/sources/$SID/calls" | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert len(d)==2 and d[1]['result']=='connected',d" || fail "unexpected call list of the source"

echo "call list resolves a contact name in one batch..."
# a short TEL ("0001") must not match the peer 23000001 only because the peer ends with it
curl -sf "${AUTH[@]}" -X PUT "$BASE/remote.php/dav/addressbooks/users/admin/contacts/ca-short.vcf" \
  -H "Content-Type: text/vcard" \
  --data-binary $'BEGIN:VCARD\nVERSION:3.0\nUID:ca-short\nFN:Short Number\nTEL:0001\nEND:VCARD\n' >/dev/null
api "/api/sources/$SID/calls" | python3 -c "
import json, sys
d = json.load(sys.stdin)['ocs']['data']
byPeer = {c['peer']: c['peerName'] for c in d}
assert byPeer['23000001@s.whatsapp.net'] == '+23000001', d
" || fail "a short TEL number matched the peer by its last digits"
curl -sf "${AUTH[@]}" -X DELETE "$BASE/remote.php/dav/addressbooks/users/admin/contacts/ca-short.vcf" >/dev/null
curl -sf "${AUTH[@]}" -X PUT "$BASE/remote.php/dav/addressbooks/users/admin/contacts/ca-test.vcf" \
  -H "Content-Type: text/vcard" \
  --data-binary $'BEGIN:VCARD\nVERSION:3.0\nUID:ca-test\nFN:Alice Contact\nTEL:+23000001\nEND:VCARD\n' >/dev/null
api "/api/sources/$SID/calls" | python3 -c "
import json, sys
d = json.load(sys.stdin)['ocs']['data']
byPeer = {c['peer']: c['peerName'] for c in d}
assert byPeer['23000001@s.whatsapp.net'] == 'Alice Contact', d      # matched by CardDAV TEL
other = [c for c in d if c['peer'] != '23000001@s.whatsapp.net'][0]
assert other['peerName'] != 'Alice Contact', d                      # group peer keeps its own fallback
" || fail "the call list does not show the CardDAV contact name"
curl -sf "${AUTH[@]}" -X DELETE "$BASE/remote.php/dav/addressbooks/users/admin/contacts/ca-test.vcf" >/dev/null

# These grep a whole HTML page, which is far bigger than the pipe buffer.
# grep -q would exit on the match and SIGPIPE curl, and pipefail would then
# fail the line at random. Same trap as the app:list line above.
curl -sf "${AUTH[@]}" "$BASE/index.php/apps/chat_archive/" | grep 'id="chat_archive"' >/dev/null || fail "app page did not render"
curl -sf "${AUTH[@]}" "$BASE/index.php/settings/user/chat_archive" | grep 'id="chat_archive_settings"' >/dev/null || fail "settings page did not render"

echo "left click action..."
# seed the old IConfig storage path directly, before any write through the API,
# to prove a value stored before the OCP\Config\IUserConfig change still reads.
# There is no GET /settings/click route (only PUT), so the read is checked the
# same way the rest of this file already checks it: the app page's embedded
# initial state, which carries ClickAction::read()'s own result.
occ user:setting admin chat_archive click_action nc
occ user:setting admin chat_archive click_template 'myhandler://{path}'
curl -sf "${AUTH[@]}" "$BASE/index.php/apps/chat_archive/" | python3 -c "
import base64,json,re,sys
m = re.search(r'initial-state-chat_archive-clickAction\" value=\"([^\"]+)\"', sys.stdin.read())
assert m, 'clickAction state missing from the app page'
assert json.loads(base64.b64decode(m.group(1))) == {'action': 'nc', 'template': 'myhandler://{path}'}
" || fail "click action not read from pre-existing storage"
api /settings/click -X PUT -d action=custom -d template='myhandler://{path}' | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d=={'action':'custom','template':'myhandler://{path}'},d" || fail "PUT /settings/click did not store the custom action"
curl -sf "${AUTH[@]}" "$BASE/index.php/apps/chat_archive/" | python3 -c "
import base64,json,re,sys
m = re.search(r'initial-state-chat_archive-clickAction\" value=\"([^\"]+)\"', sys.stdin.read())
assert m, 'clickAction state missing from the app page'
assert json.loads(base64.b64decode(m.group(1))) == {'action': 'custom', 'template': 'myhandler://{path}'}
" || fail "click action not in the app page state"
api /settings/click -X PUT -d action=custom -d template='javascript:alert(1)' >/dev/null 2>&1 && fail "javascript: template accepted"
CODE=$(curl -s -o "$TMP/ca_click.json" -w '%{http_code}' "${AUTH[@]}" "${OCS[@]}" -X PUT "$BASE/ocs/v2.php/apps/chat_archive/settings/click?format=json" \
  -d action=custom -d template='javascript:alert(1)')
[ "$CODE" = 400 ] || fail "a javascript: template did not answer 400 (got $CODE)"
python3 -c "import json;d=json.load(open('$TMP/ca_click.json'))['ocs']['data'];assert d['code']=='bad_click_action',d" || fail "a javascript: template did not give code bad_click_action"
api /settings/click -X PUT -d action=bogus >/dev/null 2>&1 && fail "unknown click action accepted"
api /settings/click -X PUT -d action=view | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d['action']=='view',d" || fail "PUT /settings/click did not store the view action"

echo "german translation is loaded..."
occ user:setting admin core lang de
curl -sf "${AUTH[@]}" "$BASE/index.php/apps/chat_archive/" | grep 'chat_archive/l10n/de.js' >/dev/null || fail "de.js not loaded"
occ user:setting admin core lang de_DE
curl -sf "${AUTH[@]}" "$BASE/index.php/apps/chat_archive/" | grep 'chat_archive/l10n/de_DE.js' >/dev/null || fail "de_DE.js not loaded"
occ user:setting admin core lang en

echo "full text search toggle..."
$CTR exec $NAME php -l /var/www/html/custom_apps/chat_archive/lib/FullTextSearch/ChatProvider.php >/dev/null || fail "ChatProvider syntax error"
curl -sf "${AUTH[@]}" "$BASE/index.php/settings/admin/chat_archive" | grep 'id="chat_archive_admin"' >/dev/null || fail "admin page did not render"
api /settings/fulltextsearch -X PUT -d enabled=true >/dev/null
occ config:app:get chat_archive fulltext_search | grep -q '^1$' || fail "toggle not persisted"
# the fulltextsearch app is not installed in this container, so isEnabled() stays false and search must fall back to LIKE
[ "$(search -d q=alice)" = "2 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "LIKE fallback with toggle on"
$CTR exec -u www-data -e OC_PASS=Ca-Test-User1-2026x $NAME php occ user:add --password-from-env user1 >/dev/null
curl -s -u user1:Ca-Test-User1-2026x "${OCS[@]}" "$BASE/ocs/v2.php/apps/chat_archive/settings/fulltextsearch?format=json" -X PUT -d enabled=false | grep -q '"statuscode":403' || fail "non-admin set the toggle"
api /settings/fulltextsearch -X PUT -d enabled=false >/dev/null

echo "unified search provider..."
# the provider is listed with its filters (ocs.data is a list of providers)
curl -sf "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/search/providers?format=json" | python3 -c "import json,sys;p={x['id']:x for x in json.load(sys.stdin)['ocs']['data']};f=set(p['chat_archive_messages']['filters']);assert {'since','until','chat','source','kind','who'}<=f,f" || fail "provider chat_archive_messages not listed with its filters"

usearch() { curl -sf "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/search/providers/chat_archive_messages/search?format=json" -G "$@" | python3 -c "import json,sys;print(len(json.load(sys.stdin)['ocs']['data']['entries']))"; }

# usearch term=alice should return same count as search q=alice (2)
[ "$(usearch -d term=alice)" = "2" ] || fail "usearch alice count: $(usearch -d term=alice)"

# First alice entry should have '+23000001' as title
FIRST_ENTRY=$(curl -sf "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/search/providers/chat_archive_messages/search?format=json" -G -d term=alice | python3 -c "import json,sys;entries=json.load(sys.stdin)['ocs']['data']['entries'];print(entries[0]['title'] if entries else '')")
[ "$FIRST_ENTRY" = "+23000001" ] || fail "first alice entry title: $FIRST_ENTRY"

# The entry links to the app with the source, chat, message id and time of the hit (App.vue opens the chat there)
curl -sf "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/search/providers/chat_archive_messages/search?format=json" -G -d term=invoice | python3 -c "
import json,sys,urllib.parse as u
e = json.load(sys.stdin)['ocs']['data']['entries'][0]
url = u.urlsplit(e['resourceUrl'])
q = {k: v[0] for k, v in u.parse_qs(url.query).items()}
assert url.path.endswith('/apps/chat_archive/'), url
assert sorted(q) == ['at', 'chat', 'msg', 'source'] and all(v.isdigit() for v in q.values()), q
assert q['source'] == '$SID' and q['at'] == '1725000180000', q   # K4, the invoice document
" || fail "unified search entry does not link to its message"

# A media hit carries the path of its file, relative to the user's root, so an agent file tool can open it
curl -sf "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/search/providers/chat_archive_messages/search?format=json" -G -d kind=image | python3 -c "
import json,sys
e = json.load(sys.stdin)['ocs']['data']['entries']
a = {x['attributes'].get('path'): x['attributes'] for x in e}
assert len(e) == 2 and len(a) == 2, e
img = a.get('/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg')
assert img and img['fileId'].isdigit() and img['mime'] == 'image/jpeg' and img['kind'] == 'image', e
# the group image whose backup path left the folder has no file, so no path, file id or mime
assert set(a[None]) == {'messageId', 'chatId', 'sourceId', 'kind'}, e
assert all(x['attributes']['messageId'].isdigit() and x['attributes']['sourceId'] == '$SID' for x in e), e
" || fail "unified search media hit has no path"

# usearch term=invoice should return 1
[ "$(usearch -d term=invoice)" = "1" ] || fail "usearch invoice: $(usearch -d term=invoice)"

# Date range filter with ISO timestamps matching the millisecond timestamp 1725000000000
ISO_TIME="2024-08-30T06:40:00Z"
[ "$(usearch -d term=alice -d since=$ISO_TIME -d until=$ISO_TIME)" = "1" ] || fail "date range alice: $(usearch -d term=alice -d since=$ISO_TIME -d until=$ISO_TIME)"
[ "$(usearch -d kind=media -d since=$ISO_TIME -d until=$ISO_TIME)" = "0" ] || fail "date range media: $(usearch -d kind=media -d since=$ISO_TIME -d until=$ISO_TIME)"

# Chat filter
[ "$(usearch -d kind=call -d "chat=$CID")" = "2" ] || fail "usearch call in chat: $(usearch -d kind=call -d "chat=$CID")"

# who filter
[ "$(usearch -d term=alice -d who=me)" = "1" ] || fail "usearch alice who=me: $(usearch -d term=alice -d who=me)"

# source filter with non-existent source
[ "$(usearch -d source=999999 -d term=alice)" = "0" ] || fail "usearch foreign source: $(usearch -d source=999999 -d term=alice)"

echo "a later backup (v2): deleted, revoked and edited messages, a gone chat, a phone-archived chat..."
# The expected stats come from the fixture script header, so the two cannot drift apart.
V2STATS=$(sed -n '/^Expected stats of a forced import of v2/{n;s/^ *//;p;}' tests/fixtures/make_fixture.py)
case "$V2STATS" in *'"linked":0,"deleted":'*'"gone_chats":'*) ;; *) fail "no v2 stats line in the make_fixture.py header: '$V2STATS'" ;; esac
# The toggles have no stored value yet: the defaults are ON.
curl -sf "${AUTH[@]}" "$BASE/index.php/apps/chat_archive/" | python3 -c "
import base64,json,re,sys
m = re.search(r'initial-state-chat_archive-viewPrefs\" value=\"([^\"]+)\"', sys.stdin.read())
assert m, 'viewPrefs state missing from the app page'
assert json.loads(base64.b64decode(m.group(1))) == {'showDeleted': True, 'showArchived': True}
" || fail "view toggles do not default to on"
edit_rows() { $CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
echo \$pdo->query('SELECT COUNT(*) FROM ${PREFIX}chatarchive_edit')->fetchColumn();
"; }
dav "/WhatsApp/msgstore.db.crypt15" -T tests/fixtures/msgstore_v2.db.crypt15
OUT=$(occ chat_archive:import --user admin --force)
echo "$OUT"
echo "$OUT" | grep -F "$V2STATS" >/dev/null || fail "unexpected v2 import stats (want $V2STATS)"
# chat rows: A is archived on the phone now, B left the backup (it keeps its v1 flag)
api "/api/sources/$SID/chats" | python3 -c "
import json,sys
c = {x['id']: x for x in json.load(sys.stdin)['ocs']['data']}
a, b = c[$CID], c[$(cat "$TMP/ca_group_id")]
assert a['phoneArchived'] is True and a['goneAt'] is None, a
assert b['phoneArchived'] is True and isinstance(b['goneAt'], int), b
assert a['msgCount'] == 5 and b['msgCount'] == 3, c
" || fail "v2 chat rows"
# message rows, defaults on: all 5 rows show. Body and kind do not change.
api "/api/chats/$CID/messages" > "$TMP/ca_v2.json"
python3 - <<PY || fail "v2 message rows"
import json
m = {x['sentAt']: x for x in json.load(open('$TMP/ca_v2.json'))['ocs']['data']['messages']}
assert len(m) == 5, m
k1, k2, k3, k8 = m[1725000000000], m[1725000060000], m[1725000120000], m[1725001500000]
assert k1['body'] == 'hello from alice' and k1['kind'] == 'text' and k1['deleted'] is None, k1
assert isinstance(k1['editedAt'], int), k1
assert k1['latestBody'] == 'hello from alice, edited', k1
assert k2['body'] == 'hi alice' and k2['kind'] == 'text', k2
assert k2['deleted'] == 'revoked' and isinstance(k2['deletedAt'], int), k2
assert k8['deleted'] == 'missing' and isinstance(k8['deletedAt'], int), k8
assert k3['deleted'] is None and k3['editedAt'] is None, k3
assert k3['latestBody'] is None, k3
open('$TMP/ca_k1', 'w').write(str(k1['id']))
open('$TMP/ca_k8', 'w').write(str(k8['id']))
PY
K1=$(cat "$TMP/ca_k1")
api "/api/messages/$K1/edits" | python3 -c "
import json,sys
e = json.load(sys.stdin)['ocs']['data']['edits']
assert len(e) == 1 and e[0]['body'] == 'hello from alice, edited' and isinstance(e[0]['seenAt'], int), e
" || fail "edits route"
api "/api/messages/$(cat "$TMP/ca_k8")/edits" | python3 -c "import json,sys;e=json.load(sys.stdin)['ocs']['data']['edits'];assert e==[],e" || fail "edits route for an unedited message"

echo "safe re-run: the same v2 backup again adds no marks and no edit rows..."
EDITS=$(edit_rows)
OUT=$(occ chat_archive:import --user admin --force)
echo "$OUT"
echo "$OUT" | grep -F '"chats":1,"messages":0,"calls":0,"linked":0,"deleted":0,"revoked":0,"edited":0,"gone_chats":0' >/dev/null || fail "second forced v2 import changed marks"
[ "$(edit_rows)" = "$EDITS" ] || fail "second forced v2 import added edit rows ($EDITS -> $(edit_rows))"

echo "skip path: an unchanged backup does no compare and no marks..."
api "/api/sources/$SID/chats" > "$TMP/ca_before_chats.json"
api "/api/chats/$CID/messages" > "$TMP/ca_before_msgs.json"
OUT=$(occ chat_archive:import --user admin)
echo "$OUT"
echo "$OUT" | grep -F '"skipped":true' >/dev/null || fail "unchanged v2 backup was not skipped"
case "$OUT" in *'"deleted"'*|*'"revoked"'*|*'"edited"'*|*'"gone_chats"'*) fail "skip path reported mark counters: $OUT" ;; esac
[ "$(api "/api/sources/$SID/chats")" = "$(cat "$TMP/ca_before_chats.json")" ] || fail "skip path changed chat rows"
[ "$(api "/api/chats/$CID/messages")" = "$(cat "$TMP/ca_before_msgs.json")" ] || fail "skip path changed message rows"
[ "$(edit_rows)" = "$EDITS" ] || fail "skip path added edit rows"

echo "view toggles filter the in-app page and search only..."
view() { api /settings/view -X PUT -H 'Content-Type: application/json' -d "$1" | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];print(d['showDeleted'],d['showArchived'])"; }
nmsgs() { api "/api/chats/$CID/messages" | python3 -c "import json,sys;print(len(json.load(sys.stdin)['ocs']['data']['messages']))"; }
# defaults on: everything shows, a gone chat included
[ "$(nmsgs)" = 5 ] || fail "defaults: message page hides rows ($(nmsgs))"
[ "$(search -d q=alice)" = "2 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "defaults: search alice: $(search -d q=alice)"
[ "$(search -d q=invoice)" = "1 False ['document'] [{'name': 'Family group', 'sourceId': $SID}]" ] || fail "defaults: gone chat hidden from search"
[ "$(view '{"showDeleted":false}')" = "False True" ] || fail "PUT /settings/view showDeleted=false"
[ "$(nmsgs)" = 3 ] || fail "show deleted off: message page still has deleted rows ($(nmsgs))"
[ "$(search -d q=alice)" = "1 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "show deleted off: search alice: $(search -d q=alice)"
[ "$(search -d q=invoice)" = "0 False [] []" ] || fail "show deleted off: gone chat in search: $(search -d q=invoice)"
[ "$(view '{"showArchived":false}')" = "False False" ] || fail "PUT /settings/view showArchived=false"
[ "$(search -d q=alice)" = "0 False [] []" ] || fail "both off: search shows a phone-archived chat: $(search -d q=alice)"
[ "$(search)" = "0 False [] []" ] || fail "both off: a bare search returns rows: $(search)"
[ "$(view '{"showDeleted":true}')" = "True False" ] || fail "PUT /settings/view showDeleted=true"
[ "$(nmsgs)" = 5 ] || fail "archived off: the message page must not filter ($(nmsgs))"
[ "$(search -d q=alice)" = "0 False [] []" ] || fail "archived off: search alice: $(search -d q=alice)"
[ "$(view '{"showDeleted":false}')" = "False False" ] || fail "PUT /settings/view showDeleted=false again"
# unified search ignores the toggles: the deleted, gone and phone-archived rows stay
[ "$(usearch -d term=alice)" = "2" ] || fail "toggles leak into unified search alice: $(usearch -d term=alice)"
[ "$(usearch -d term=invoice)" = "1" ] || fail "toggles leak into unified search invoice: $(usearch -d term=invoice)"
[ "$(view '{"showDeleted":true,"showArchived":true}')" = "True True" ] || fail "PUT /settings/view back on"
[ "$(search -d q=alice)" = "2 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "toggles back on: search alice: $(search -d q=alice)"

echo "v1 back in place: missing and gone marks clear, revoked stays..."
# K9 as the live sync stores a photo: no body, its text in the media caption, not in a backup yet.
# The backup gives the same text as the body. That is no edit.
k9_sql() { $CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$id = \$pdo->query(\"SELECT id FROM ${PREFIX}chatarchive_message WHERE source_id = $SID AND platform_msg_id = 'K9'\")->fetchColumn();
$1
"; }
k9_sql "
\$k9 = \$pdo->query(\"SELECT body FROM ${PREFIX}chatarchive_message WHERE id = \$id\")->fetchColumn();
file_put_contents('/tmp/ca_k9_caption', \$pdo->query(\"SELECT caption FROM ${PREFIX}chatarchive_media WHERE message_id = \$id\")->fetchColumn());
file_put_contents('/tmp/ca_k9_body', \$k9);
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_media SET caption = ? WHERE message_id = ?')->execute([\$k9, \$id]);
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_message SET body = NULL, seen_hash = ?, in_backup = 0 WHERE id = ?')->execute([sha1(\"\\0null\"), \$id]);
" || fail "could not set up K9 as a synced photo"
dav "/WhatsApp/msgstore.db.crypt15" -T tests/fixtures/msgstore.db.crypt15
OUT=$(occ chat_archive:import --user admin --force)
echo "$OUT"
# K1 reads its v1 text again, which is a new version after the v2 text. K9 is no edit.
echo "$OUT" | grep -F '"chats":2,"messages":0,"calls":0,"linked":0,"deleted":0,"revoked":0,"edited":1,"gone_chats":0' >/dev/null || fail "unexpected v1 restore stats"
[ "$(k9_sql "echo \$pdo->query(\"SELECT COALESCE(edited_at, 'none') || ' ' || in_backup || ' ' || (SELECT COUNT(*) FROM ${PREFIX}chatarchive_edit WHERE message_id = \$id) FROM ${PREFIX}chatarchive_message WHERE id = \$id\")->fetchColumn();")" = "none 1 0" ] \
  || fail "the first backup of a synced photo recorded an edit"
k9_sql "
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_media SET caption = ? WHERE message_id = ?')->execute([file_get_contents('/tmp/ca_k9_caption'), \$id]);
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_message SET body = ? WHERE id = ?')->execute([file_get_contents('/tmp/ca_k9_body'), \$id]);
" || fail "could not restore K9"
api "/api/sources/$SID/chats" | python3 -c "
import json,sys
c = {x['id']: x for x in json.load(sys.stdin)['ocs']['data']}
a, b = c[$CID], c[$(cat "$TMP/ca_group_id")]
assert a['phoneArchived'] is False and a['goneAt'] is None, a
assert b['phoneArchived'] is True and b['goneAt'] is None, b
" || fail "v1 restore: chat rows"
api "/api/chats/$CID/messages" | python3 -c "
import json,sys
m = {x['sentAt']: x for x in json.load(sys.stdin)['ocs']['data']['messages']}
k1, k2, k8 = m[1725000000000], m[1725000060000], m[1725001500000]
assert k8['deleted'] is None and k8['deletedAt'] is None, k8
assert k2['deleted'] == 'revoked' and k2['body'] == 'hi alice', k2
assert k1['body'] == 'hello from alice' and k1['deleted'] is None, k1
" || fail "v1 restore: message rows"
api "/api/messages/$K1/edits" | python3 -c "
import json,sys
e = json.load(sys.stdin)['ocs']['data']['edits']
assert [x['body'] for x in e] == ['hello from alice, edited', 'hello from alice'], e
" || fail "v1 restore: edit history"

echo "another user must not read this user's archive..."
other() { curl -s -u user1:Ca-Test-User1-2026x "${OCS[@]}" "$BASE/ocs/v2.php/apps/chat_archive$1?format=json" "${@:2}"; }
other "/api/sources/$SID/chats" | grep -q '"statuscode":404' || fail "user1 read admin's chats"
other "/api/chats/$CID/messages" | grep -q '"statuscode":404' || fail "user1 read admin's messages"
other "/api/sources/$SID/calls" | grep -q '"statuscode":404' || fail "user1 read admin's calls"
other "/api/messages/$K1/edits" | grep -q '"statuscode":404' || fail "user1 read admin's edit history"
other "/settings/sources/$SID/import" -X POST | grep -q '"statuscode":404' || fail "user1 queued admin's import"
[ "$(other /api/search -G -d "source=$SID" -d q=alice | python3 -c "import json,sys;print(len(json.load(sys.stdin)['ocs']['data']['messages']))")" = 0 ] || fail "user1 searched admin's source"
[ "$(other /api/search -G -d q=alice | python3 -c "import json,sys;print(len(json.load(sys.stdin)['ocs']['data']['messages']))")" = 0 ] || fail "user1 searched admin's messages across sources"
[ "$(curl -sf -u user1:Ca-Test-User1-2026x "${OCS[@]}" "$BASE/ocs/v2.php/search/providers/chat_archive_messages/search?format=json" -G -d term=alice | python3 -c "import json,sys;print(len(json.load(sys.stdin)['ocs']['data']['entries']))")" = 0 ] || fail "user1 unified search alice: $(curl -sf -u user1:Ca-Test-User1-2026x "${OCS[@]}" "$BASE/ocs/v2.php/search/providers/chat_archive_messages/search?format=json" -G -d term=alice | python3 -c "import json,sys;print(len(json.load(sys.stdin)['ocs']['data']['entries']))")"
other /settings/sources | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d['sources']==[],d" || fail "user1 sees the sources of admin"
other "/settings/sources/$SID" -X PUT -d provider=whatsapp -d label=Stolen -d folder=/ -d onDelete=keep | grep -q '"statuscode":404' || fail "user1 edited admin's source"
other "/settings/sources/$SID" -X DELETE | grep -q '"statuscode":404' || fail "user1 deleted admin's source"

echo "a second user imports the same backup into an archive of their own..."
$CTR exec $NAME bash -c 'mkdir -p /var/www/html/data/user1/files/WA'
$CTR cp tests/fixtures/msgstore.db.crypt15 $NAME:/var/www/html/data/user1/files/WA/msgstore.db.crypt15
$CTR exec $NAME chown -R www-data:www-data /var/www/html/data/user1/files
occ files:scan user1 >/dev/null
other /settings/sources -X POST -d provider=whatsapp -d label=Mine -d folder=/WA -d secret="$KEY" -d onDelete=restore > "$TMP/ca_src_u1.json"
SID_U1=$(python3 -c "import json;print(json.load(open('$TMP/ca_src_u1.json'))['ocs']['data']['id'])")
occ chat_archive:import --user user1 --force | grep -q '"chats":2,"messages":8,"calls":2,"linked":0' || fail "user1 import stats"
other "/api/sources/$SID_U1/chats" | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert len(d)==2 and {c['msgCount'] for c in d}=={5,3},d" || fail "unexpected chat rows of user1"
[ "$(other /api/search -G -d q=alice | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];print(len(d['messages']), {c['sourceId'] for c in d['chats'].values()})")" = "2 {$SID_U1}" ] || fail "user1 search"
[ "$(search -d q=alice)" = "2 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "admin search sees user1 rows"
curl -s "${AUTH[@]}" "${OCS[@]}" "$BASE/ocs/v2.php/apps/chat_archive/api/sources/$SID_U1/chats?format=json" | grep -q '"statuscode":404' || fail "admin read user1's chats"
api /settings/sources | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data']['sources'];assert [x['id'] for x in d]==[$SID],d" || fail "the source list of admin does not hold only its own source"
other /settings/preserve -X PUT -d preserve=false | grep -q '"preserve":false' || fail "user1 could not turn preserve off"
other "/settings/sources/$SID_U1" -X DELETE >/dev/null
other "/api/sources/$SID_U1/chats" | grep -q '"statuscode":404' || fail "user1 source still readable after delete"
[ "$(search -d q=alice)" = "2 False ['text'] [{'name': '+23000001', 'sourceId': $SID}]" ] || fail "user1 delete touched admin rows"

echo "reorganizing: the whole media folder moves out of the source (pointer must follow, re-import must not touch it)..."
dav "/Archive" -X MKCOL
dav "/WhatsApp/Media/WhatsApp%20Images" -X MOVE -H "Destination: $BASE/remote.php/dav/files/admin/Archive/2024"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/Archive/2024/IMG-20240830-WA0001.jpg',md" || fail "pointer did not follow the folder move"
occ chat_archive:import --user admin --force | grep -q '"chats":2,"messages":0,"calls":0,"linked":0' || fail "re-import after the folder move added rows or relinked"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/Archive/2024/IMG-20240830-WA0001.jpg',md" || fail "re-import after the folder move changed the pointer"
dav "/Archive/2024" -X MOVE -H "Destination: $BASE/remote.php/dav/files/admin/WhatsApp/Media/WhatsApp%20Images"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg',md" || fail "pointer did not follow the folder move back"

echo "moving the linked file elsewhere (pointer must follow)..."
dav "/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg" -X MOVE -H "Destination: $BASE/remote.php/dav/files/admin/Moved/renamed.jpg"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/Moved/renamed.jpg',md" || fail "pointer did not follow the file move"

echo "deleting it there (flagged file: on_delete=restore must not bring a copy back)..."
dav "/Moved/renamed.jpg" -X DELETE
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['suspicious'] is True and not md['exists'] and md['path'] is None,md" || fail "the pointer of the deleted flagged file did not clear"
dav "/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg" -o /dev/null && fail "a copy of the flagged file was restored to the source path"
# Put the file back where the rest of the script expects it, by hand: Nextcloud's own trash
# restore, not the app's on_delete policy, so the keep/unlink checks below still have their file.
occ trashbin:restore admin >/dev/null
dav "/Moved/renamed.jpg" -X MOVE -H "Destination: $BASE/remote.php/dav/files/admin/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg',md" || fail "pointer did not follow the move back to the source folder"

echo "a move into a share: the pointer stays valid..."
# Nextcloud keeps the file id on a move between two local storages, so this
# move does not change the id. The step checks that the rename listener
# breaks nothing on a move across mounts.
curl -sf -u user1:Ca-Test-User1-2026x -X MKCOL "$BASE/remote.php/dav/files/user1/ShareIn" >/dev/null
curl -sf -u user1:Ca-Test-User1-2026x "${OCS[@]}" -X POST "$BASE/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json" \
  -d path=/ShareIn -d shareType=0 -d shareWith=admin -d permissions=31 >/dev/null || fail "user1 could not share a folder with admin"
dav "/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg" -X MOVE -H "Destination: $BASE/remote.php/dav/files/admin/ShareIn/x.jpg"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/ShareIn/x.jpg',md" || fail "pointer lost after a move into a share"
dav "/ShareIn/x.jpg" -X MOVE -H "Destination: $BASE/remote.php/dav/files/admin/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg',md" || fail "pointer lost after the move back"

echo "a move that changes the file id: the pointer follows..."
# No move in this container changes the id, so the step sends the two rename
# events through the real dispatcher, with two real files as source and target.
dav "/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg" -X COPY -H "Destination: $BASE/remote.php/dav/files/admin/Moved/copy.jpg"
# shellcheck disable=SC2016  # reason: the single quotes hold PHP code; PHP, not the shell, reads its variables
rename_events() { $CTR exec -u www-data $NAME php -r '
require "/var/www/html/lib/base.php";
$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
$from = $root->get("/admin/files/" . $argv[1]);
$to = $root->get("/admin/files/" . $argv[2]);
$d = \OC::$server->get(\OCP\EventDispatcher\IEventDispatcher::class);
$d->dispatchTyped(new \OCP\Files\Events\Node\BeforeNodeRenamedEvent($from, $to));
$d->dispatchTyped(new \OCP\Files\Events\Node\NodeRenamedEvent($from, $to));
' "$1" "$2"; }
rename_events "WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg" "Moved/copy.jpg" || fail "rename events not sent"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/Moved/copy.jpg',md" || fail "pointer did not follow a changed file id"
rename_events "Moved/copy.jpg" "WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg" || fail "rename events not sent back"
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg',md" || fail "pointer did not follow the changed file id back"
dav "/Moved/copy.jpg" -X DELETE

echo "keep policy: the pointer stays and a trash restore makes it valid again..."
api "/settings/sources/$SID" -X PUT -d provider=whatsapp -d label=Test -d folder=/WhatsApp -d onDelete=keep \
  | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d['hasSecret'] and d['onDelete']=='keep',d" || fail "PUT source with onDelete=keep did not store the policy or kept no key"
dav "/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg" -X DELETE
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert not md['exists'] and md['fileId'],md" || fail "keep policy: the pointer did not stay after the delete"
occ trashbin:restore admin >/dev/null
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'] and md['path']=='/WhatsApp/Media/WhatsApp Images/IMG-20240830-WA0001.jpg',md" || fail "keep policy: the trash restore did not make the pointer valid"

echo "unlink policy..."
api "/settings/sources/$SID" -X PUT -d provider=whatsapp -d label=Test -d folder=/WhatsApp -d onDelete=unlink >/dev/null
# no stored switch: preserve is on and overrides unlink, so the pointer stays (I20.8)
occ user:setting admin chat_archive preserve --delete
dav "/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg" -X DELETE
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert not md['exists'] and md['fileId'],md" || fail "preserve on: the unlink policy dropped the pointer"
occ trashbin:restore admin >/dev/null
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert md['exists'],md" || fail "preserve on: trash restore did not bring the file back"
api /settings/preserve -X PUT -d preserve=false >/dev/null
dav "/WhatsApp/Media/WhatsApp%20Images/IMG-20240830-WA0001.jpg" -X DELETE
api "/api/chats/$CID/messages" | python3 -c "import json,sys;md=json.load(sys.stdin)['ocs']['data']['messages'][2]['media'];assert not md['exists'] and md['fileId'] is None,md" || fail "unlink policy: the pointer stayed after the delete"

echo "live sync from a fake WAHA server..."
# tests/fixtures/waha_router.php runs in the container beside Nextcloud. It
# serves chat A of the backup with K8 (a key the backup holds) and WAHA0001
# (newer than the backup), and a group the backup does not hold with WAHA0002.
WAHA_KEY=ca-test-waha-key
$CTR exec -d -u www-data $NAME php -S 127.0.0.1:3999 /var/www/html/custom_apps/chat_archive/tests/fixtures/waha_router.php >/dev/null
# HTTP status of GET /api/sessions on the fake, sent with the key $1 (empty: no key header)
# shellcheck disable=SC2016  # reason: the single quotes hold PHP code; PHP, not the shell, reads its variables
waha_code() { $CTR exec $NAME php -r '
$h = curl_init("http://127.0.0.1:3999/api/sessions");
curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
if ($argv[1] !== "") {
	curl_setopt($h, CURLOPT_HTTPHEADER, ["X-Api-Key: " . $argv[1]]);
}
curl_exec($h);
echo curl_getinfo($h, CURLINFO_HTTP_CODE);
' "$1"; }
for _ in $(seq 1 15); do [ "$(waha_code "$WAHA_KEY")" = 200 ] && break; sleep 1; done
[ "$(waha_code "$WAHA_KEY")" = 200 ] || fail "the fake WAHA server did not start (last code $(waha_code "$WAHA_KEY"))"
[ "$(waha_code '')" = 401 ] || fail "the fake WAHA answered a request without the key ($(waha_code ''))"
[ "$(waha_code wrong-key)" = 401 ] || fail "the fake WAHA answered a request with a wrong key ($(waha_code wrong-key))"
# The admin route writes the config: the key is stored encrypted, so occ cannot write it.
api /admin/waha -X PUT -H 'Content-Type: application/json' \
  -d "{\"enabled\":true,\"url\":\"http://127.0.0.1:3999\",\"apiKey\":\"$WAHA_KEY\",\"mediaPerRun\":0,\"sessions\":{\"default\":\"admin\"}}" \
  > "$TMP/ca_waha.json" || fail "PUT /admin/waha failed"
python3 -c "import json;d=json.load(open('$TMP/ca_waha.json'))['ocs']['data'];assert d=={'enabled':True,'url':'http://127.0.0.1:3999','hasKey':True,'mediaPerRun':0,'sessions':{'default':'admin'},'realtime':False},d" || fail "WAHA config not stored"
grep -F "$WAHA_KEY" "$TMP/ca_waha.json" >/dev/null && fail "PUT /admin/waha sent the API key back (I20.6)"
# A request with only the two required fields keeps the key, the limit and the sessions.
api /admin/waha -X PUT -H 'Content-Type: application/json' -d '{"enabled":true,"url":"http://127.0.0.1:3999"}' \
  | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d==dict(enabled=True,url='http://127.0.0.1:3999',hasKey=True,mediaPerRun=0,sessions={'default':'admin'},realtime=False),d" || fail "PUT /admin/waha without the optional fields"
# Test connection asks the fake for /api/server/version with the saved URL and key.
api /admin/waha/test -X POST | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d['ok'] is True and d['engine']=='GOWS',d" || fail "POST /admin/waha/test against the fake WAHA"
api /settings/sources | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data']['live'];assert d=={'enabled':True,'sessions':['default']},d" || fail "live sync state of the user"
# A new live source needs a session. The request gives none, so the server refuses it.
CODE=$(curl -s -o "$TMP/ca_live.json" -w '%{http_code}' "${AUTH[@]}" "${OCS[@]}" -X POST "$BASE/ocs/v2.php/apps/chat_archive/settings/sources?format=json" \
  -d provider=whatsapp -d label=Live -d folder=/ -d onDelete=restore -d mode=live)
[ "$CODE" = 400 ] || fail "a live source with no session did not answer 400 (got $CODE)"
python3 -c "import json;d=json.load(open('$TMP/ca_live.json'))['ocs']['data'];assert d['code']=='session_required',d" || fail "a live source with no session did not give code session_required"
api "/settings/sources/$SID" -X PUT -d provider=whatsapp -d label=Test -d folder=/WhatsApp -d onDelete=unlink -d syncSession=default -d tos=true \
  | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d['syncSession']=='default' and isinstance(d['tosAt'],int),d" || fail "session not set on the source"
# One row per key of the three WAHA messages: key, in_backup of the message, sender, deleted, chat id, in_backup of the chat, gone_at of the chat
waha_rows() { $CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$q = \$pdo->prepare('SELECT m.platform_msg_id, m.in_backup, m.sender, m.deleted, c.platform_chat_id, c.in_backup, c.gone_at
  FROM ${PREFIX}chatarchive_message m JOIN ${PREFIX}chatarchive_chat c ON c.id = m.chat_id
  WHERE m.source_id = ? AND m.platform_msg_id IN (?, ?, ?) ORDER BY m.platform_msg_id');
\$q->execute([$SID, 'K8', 'WAHA0001', 'WAHA0002']);
echo json_encode(\$q->fetchAll(PDO::FETCH_NUM));
"; }
# The rows after a sync, and after a forced import: the import marks no synced row (I20.2)
check_waha_rows() { waha_rows | python3 -c "
import json,sys
rows = [[int(v) if k in (1, 5) else v for k, v in enumerate(r)] for r in json.load(sys.stdin)]
assert [r[0] for r in rows] == ['K8', 'WAHA0001', 'WAHA0002'], rows   # one row per key: the shared key K8 is not added again
k8, w1, w2 = rows
assert k8[1] == 1 and k8[4] == '23000001@s.whatsapp.net', k8
assert w1 == ['WAHA0001', 0, '23000001@s.whatsapp.net', None, '23000001@s.whatsapp.net', 1, None], w1
assert w2 == ['WAHA0002', 0, '23000004@s.whatsapp.net', None, '23000009-1700000000@g.us', 0, None], w2   # lid sender mapped by /lids
"; }
OUT=$(occ chat_archive:sync) || fail "chat_archive:sync failed: $OUT"
echo "$OUT"
[ "$OUT" = "{\"source\":$SID,\"chats\":2,\"messages\":2,\"media\":0}" ] || fail "unexpected sync stats: $OUT"
check_waha_rows || fail "synced rows after the first sync"
api /settings/sources | python3 -c "import json,sys;s=json.load(sys.stdin)['ocs']['data']['sources'][0];assert s['syncError'] is None and isinstance(s['syncAt'],int) and s['lastError'] is None,s" || fail "sync state on the source"
api "/api/sources/$SID/chats" | python3 -c "
import json,sys
c = {x['platformChatId']: x for x in json.load(sys.stdin)['ocs']['data']}
assert c['23000001@s.whatsapp.net']['msgCount'] == 6, c
assert c['23000009-1700000000@g.us']['msgCount'] == 1 and c['23000009-1700000000@g.us']['goneAt'] is None, c
" || fail "chat rows after the sync"
# The backup names a private chat by its number. The sync stores the name that WAHA holds, and the chat list shows it.
api "/api/sources/$SID/chats" | python3 -c "
import json,sys
c = {x['platformChatId']: x for x in json.load(sys.stdin)['ocs']['data']}
assert c['23000001@s.whatsapp.net']['name'] == 'Anna from WhatsApp', c['23000001@s.whatsapp.net']['name']
" || fail "the chat list does not show the name from WAHA"
echo "a second sync over the same WAHA data adds nothing (I20.9)..."
OUT=$(occ chat_archive:sync) || fail "second chat_archive:sync failed: $OUT"
echo "$OUT"
[ "$OUT" = "{\"source\":$SID,\"chats\":0,\"messages\":0,\"media\":0}" ] || fail "second sync added rows: $OUT"
check_waha_rows || fail "synced rows after the second sync"
echo "realtime update of an open chat: off by default, then on..."
# shellcheck disable=SC2016  # reason: the single quotes hold Python code
live() { api "/api/chats/$CID/live" -X POST | python3 -c 'import json,sys;print(json.dumps(json.load(sys.stdin)["ocs"]["data"],sort_keys=True))'; }
[ "$(live)" = '{"live": false}' ] || fail "realtime answered while the admin switch is off: $(live)"
api /admin/waha -X PUT -H 'Content-Type: application/json' -d '{"enabled":true,"url":"http://127.0.0.1:3999","realtime":true}' \
  | python3 -c "import json,sys;d=json.load(sys.stdin)['ocs']['data'];assert d['realtime'] is True and d['hasKey'] and d['sessions']=={'default':'admin'},d" || fail "realtime switch not stored"
# The chat is up to date: the call asks the fake WAHA and adds nothing. The source is free again after it.
live | python3 -c "import json,sys;d=json.load(sys.stdin);assert d['live'] is True and d['added']==0 and isinstance(d['lastAt'],int),d" || fail "realtime call on an up-to-date chat"
# One synced message less in the archive: the realtime call brings it back, one time.
$CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$pdo->prepare('DELETE FROM ${PREFIX}chatarchive_message WHERE chat_id = ? AND platform_msg_id = ?')->execute([$CID, 'WAHA0001']);
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_chat SET last_at = (SELECT MAX(sent_at) FROM ${PREFIX}chatarchive_message WHERE chat_id = ?) WHERE id = ?')->execute([$CID, $CID]);
"
sleep 5   # longer than the gap between two WAHA requests for one chat, when the server has a memory cache
live | python3 -c "import json,sys;d=json.load(sys.stdin);assert d['live'] is True and d['added']==1,d" || fail "realtime call did not add the new message"
live | python3 -c "import json,sys;d=json.load(sys.stdin);assert d['live'] is True and d['added']==0,d" || fail "second realtime call added a row"
check_waha_rows || fail "synced rows after the realtime calls"
api /settings/sources | python3 -c "import json,sys;s=json.load(sys.stdin)['ocs']['data']['sources'][0];assert s['importState'] is None and s['syncError'] is None,s" || fail "the realtime call left the source claimed"
echo "a forced backup import does not mark synced rows (I20.2)..."
OUT=$(occ chat_archive:import --user admin --force)
echo "$OUT"
case "$OUT" in *'"deleted":0,'*'"gone_chats":0'*) ;; *) fail "forced import after a sync marked rows: $OUT" ;; esac
check_waha_rows || fail "synced rows after a forced import"
api "/api/sources/$SID/chats" | python3 -c "
import json,sys
c = {x['platformChatId']: x for x in json.load(sys.stdin)['ocs']['data']}
assert c['23000001@s.whatsapp.net']['name'] == 'Anna from WhatsApp', c['23000001@s.whatsapp.net']['name']
" || fail "a backup import put the number back in place of the name from WAHA"

echo "a synced photo with no file yet shows as pending while a media pass can fetch it..."
# WAHA0001 is a synced message (in_backup false). Give it a media row with no file, as a sync with a media limit stores it.
$CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$id = \$pdo->query(\"SELECT id FROM ${PREFIX}chatarchive_message WHERE chat_id = $CID AND platform_msg_id = 'WAHA0001'\")->fetchColumn();
\$pdo->prepare('INSERT INTO ${PREFIX}chatarchive_media (source_id, message_id, mime, file_name, sync_tries) VALUES (?, ?, ?, ?, 0)')->execute([$SID, \$id, 'image/jpeg', 'waha.jpg']);
\$pdo->prepare('UPDATE ${PREFIX}chatarchive_message SET kind = ? WHERE id = ?')->execute(['image', \$id]);
"
# Prints the pending flag of the WAHA0001 photo and of every backup media row on the newest page
pending() { api "/api/chats/$CID/messages" | python3 -c "
import json,sys
m = [x for x in json.load(sys.stdin)['ocs']['data']['messages'] if x.get('media')]
w = [x['media']['pending'] for x in m if x['media']['fileName'] == 'waha.jpg']
print(w, sorted(set(x['media']['pending'] for x in m if x['media']['fileName'] != 'waha.jpg')))"; }
[ "$(pending)" = "[False] [False]" ] || fail "pending with a media limit of 0: $(pending)"
api /admin/waha -X PUT -H 'Content-Type: application/json' -d '{"enabled":true,"url":"http://127.0.0.1:3999","mediaPerRun":5}' >/dev/null
[ "$(pending)" = "[True] [False]" ] || fail "pending with a media limit of 5: $(pending)"
# The media pass takes the same row (ArchiveStore::syncPendingSql). The fake WAHA has no such message, so the fetch fails once.
OUT=$(occ chat_archive:sync) || fail "chat_archive:sync with a media limit failed: $OUT"
[ "$OUT" = "{\"source\":$SID,\"chats\":0,\"messages\":0,\"media\":0}" ] || fail "unexpected sync stats with a pending file: $OUT"
[ "$($CTR exec $NAME php -r "echo (new PDO('sqlite:/var/www/html/data/nc.db'))->query(\"SELECT sync_tries FROM ${PREFIX}chatarchive_media WHERE file_name = 'waha.jpg'\")->fetchColumn();")" = 1 ] \
  || fail "the media pass did not try the pending file"
[ "$(pending)" = "[True] [False]" ] || fail "pending after one failed fetch: $(pending)"
# After SYNC_TRIES failed fetches the media pass gives the file up, so the page shows it as missing
$CTR exec $NAME php -r "
\$pdo = new PDO('sqlite:/var/www/html/data/nc.db');
\$pdo->exec(\"UPDATE ${PREFIX}chatarchive_media SET sync_tries = 3 WHERE file_name = 'waha.jpg'\");
"
[ "$(pending)" = "[False] [False]" ] || fail "pending after 3 failed fetches: $(pending)"
api /admin/waha -X PUT -H 'Content-Type: application/json' -d '{"enabled":true,"url":"http://127.0.0.1:3999","mediaPerRun":0}' >/dev/null

echo "removing the source cascades..."
api /settings/fulltextsearch -X PUT -d enabled=true >/dev/null
api "/settings/sources/$SID" -X DELETE >/dev/null
api "/api/sources/$SID/chats" >/dev/null 2>&1 && fail "source still readable after delete"
# This container has no fulltextsearch platform, so no check can see documents
# leave an index. The delete above runs with the toggle on: api uses curl -f,
# so it proves that the delete still answers 200 and cascades.
api /settings/fulltextsearch -X PUT -d enabled=false >/dev/null
echo "INTEGRATION OK"
