<?php

declare(strict_types=1);

// A first sync that needs more than one run loads every chat, and one more
// run adds nothing. The real SyncService and WahaMapper run against the
// in-memory stand-ins of tests/stubs/SyncStubs.php, with a time box of 2 s.
// The message pass gets 1.5 s of it, so the media pass runs after a message
// pass that used its full share. A chat with no timestamp in the overview
// costs a message request only while the archive holds no message for it.
// The media pass asks WAHA by the plain key id. A file that cannot be written
// counts one try and stops the media pass for that run. The next run takes
// the files with no failed try first. A sync stops when WAHA gives no account
// for a source that knows one, and an error that does not come from WAHA ends
// with sync_failed.
// php -d zend.assertions=1 -d assert.exception=1 tests/sync_resume_check.php

$world = require __DIR__ . '/stubs/SyncStubs.php';
$w = $world(120);
$t0 = $w->client::T0;

// Run 1: the message pass ends at its share in chat 60, on the second
// overview page.
$w->client->slowChat = 60;
$w->client->slowSeconds = 1.7;
$w->folder::$failPrefix = 'K5';
$r = $w->run();
assert($r['stats']['chats'] === 60 && $r['stats']['messages'] === 60);
assert($r['chats_with_messages'] === 60);
assert($r['overview'] === [0, 50]);
assert($r['error'] === null && $r['state'] === null);
// The first sync stores the account of the session.
assert($r['me'] === '23099999@s.whatsapp.net');

// The media pass of run 1 runs in the rest of the box. The group image of
// another member is asked for by its plain key id and stored. The next image
// cannot be written: it counts a try, the pass stops, and the run does not fail.
assert($r['media_asked'] === [['3@g.us', 'K3'], ['5@c.us', 'K5']]);
assert($r['stats']['media'] === 1);
assert($r['media']['K3']['src_path'] === 'WAHA/' . gmdate('Y-m', $t0 - 3) . '/K3.jpg');
assert($r['media']['K5']['tries'] === 1 && $r['media']['K5']['file_id'] === null);
assert($r['media']['K9']['tries'] === 0 && $r['media']['K9']['file_id'] === null);
assert(count($r['warnings']) === 1 && !array_key_exists('exception', $r['warnings'][0][1]));

// Run 2 reads every overview page again. The chats of run 1 are up to date
// and cost no message request; the other 60 chats load. Chat 120 has no
// timestamp and no message yet, so it is paged from the start.
$r = $w->run();
assert($r['stats']['chats'] === 60 && $r['stats']['messages'] === 60);
assert($r['chats_with_messages'] === 120);
assert(array_slice($r['overview'], 0, 3) === [0, 50, 100] && count($r['overview']) <= 4);
$asked = array_values(array_unique(array_column($r['asked'], 0)));
assert($asked === array_map(static fn (int $i): string => $i . '@c.us', range(61, 120)));
assert(in_array(['120@c.us', 0], $r['asked'], true));
assert($r['error'] === null);
// The media pass takes the image with no failed try first and stores it. The
// image that failed in run 1 comes after it and fails again.
assert($r['media_asked'] === [['9@c.us', 'K9'], ['5@c.us', 'K5']]);
assert($r['stats']['media'] === 1 && $r['media']['K9']['file_id'] !== null && $r['media']['K5']['tries'] === 2);

// Run 3 adds nothing and costs no message request: chat 120 has no
// timestamp, but the archive holds its message.
$r = $w->run();
assert($r['stats']['chats'] === 0 && $r['stats']['messages'] === 0);
assert(array_slice($r['overview'], 0, 3) === [0, 50, 100]);
assert($r['asked'] === []);
assert($r['messages'] === 120);

// The image that cannot be written leaves the queue after 3 tries. One more
// run does not ask for it again.
assert($r['media']['K5']['tries'] === 3 && $r['media']['K9']['file_id'] !== null);
$r = $w->run();
assert($r['stats']['media'] === 0 && $r['media_asked'] === []);
assert($r['media']['K5']['tries'] === 3 && $r['media']['K5']['file_id'] === null);
assert($r['messages'] === 120 && $r['error'] === null);

// The source knows its account and WAHA gives no account: the sync stops
// before it adds a message, and the stored account stays.
$w = $world(5);
$w->source->syncMe = '23099999@s.whatsapp.net';
$w->client->me = null;
$r = $w->run();
assert($r['error'] === 'waha_no_account' && $r['state'] === null);
assert($r['overview'] === [] && $r['messages'] === 0);
assert($r['me'] === '23099999@s.whatsapp.net');

// An error that does not come from WAHA ends with sync_failed, and the log
// line holds no exception object.
$w = $world(5);
$w->client->overviewError = new \RuntimeException('boom');
$r = $w->run();
assert($r['error'] === 'sync_failed' && $r['state'] === null);
assert($r['messages'] === 0);
assert(count($r['warnings']) === 1 && $r['warnings'][0][1]['class'] === 'RuntimeException');
assert(!array_key_exists('exception', $r['warnings'][0][1]));

echo "sync_resume: OK\n";
