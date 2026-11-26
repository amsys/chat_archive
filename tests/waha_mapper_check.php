<?php

declare(strict_types=1);

// Id, kind and message mapping of the WAHA mapper.
// php -d zend.assertions=1 -d assert.exception=1 tests/waha_mapper_check.php

require __DIR__ . '/../lib/Waha/WahaException.php';
require __DIR__ . '/../lib/Waha/WahaMapper.php';

use OCA\ChatArchive\Waha\WahaException;
use OCA\ChatArchive\Waha\WahaMapper as W;

assert(W::jid('123@c.us') === '123@s.whatsapp.net');
assert(W::jid('55@g.us') === '55@g.us');
assert(W::jid('9@lid') === '9@lid');
foreach (['status@broadcast', '1@newsletter', '1@broadcast', '', null] as $bad) {
    assert(W::jid($bad) === null);
}

assert(W::keyId('false_123@c.us_3EB0AB') === '3EB0AB');
assert(W::keyId('false_1@g.us_3EB0AB_55@c.us') === '3EB0AB');
assert(W::keyId('3EB0AB') === '3EB0AB');
assert(W::keyId('false_123@c.us') === null);
// A group id with a participant suffix: the key id is segment 2, whatever the digits of chat and participant.
assert(W::keyId('true_120363041234567890@g.us_3EB0C0FFEE01_23012345@c.us') === '3EB0C0FFEE01');
assert(W::keyId('false_23012345-1700000000@g.us_ABCDEF12_99887766@lid') === 'ABCDEF12');
assert(W::keyId('') === null);

$media = fn(string $mime): array => ['hasMedia' => true, 'media' => ['mimetype' => $mime]];
assert(W::kind($media('image/webp')) === 'sticker');
assert(W::kind($media('image/jpeg')) === 'image');
assert(W::kind($media('video/mp4')) === 'video');
assert(W::kind($media('audio/ogg')) === 'audio');
assert(W::kind($media('application/pdf')) === 'document');
assert(W::kind(['location' => ['lat' => 1]]) === 'location');
assert(W::kind(['vCards' => ['BEGIN:VCARD']]) === 'vcard');
assert(W::kind(['vCards' => ['BEGIN:VCARD', 'BEGIN:VCARD']]) === 'vcard_array');
assert(W::kind(['body' => 'hi']) === 'text');
// A contact card carries its names as the body, a location its position.
$noLid = static fn (string $lid): ?string => null;
$card = static fn (string $fn): string => "BEGIN:VCARD\nVERSION:3.0\nN:;x;;;\n$fn\nTEL;type=CELL:+1 555\nEND:VCARD";
$row = W::message(['id' => 'K1', 'vCards' => [$card('FN:Ann Lee')]], false, $noLid);
assert($row['kind'] === 'vcard' && $row['body'] === 'Ann Lee' && $row['media'] === null);
$row = W::message(['id' => 'K2', 'vCards' => [$card('FN;CHARSET=UTF-8:Ann Lee'), $card('FN:Bo')]], false, $noLid);
assert($row['kind'] === 'vcard_array' && $row['body'] === 'Ann Lee, Bo');
$row = W::message(['id' => 'K3', 'vCards' => ["BEGIN:VCARD\nEND:VCARD"]], false, $noLid);
assert($row['kind'] === 'vcard' && $row['body'] === null);
$row = W::message(['id' => 'K4', 'location' => ['latitude' => '-20.16', 'longitude' => '57.5']], false, $noLid);
assert($row['kind'] === 'location' && $row['body'] === '(-20.16, 57.5)');
$row = W::message(['id' => 'K5', 'location' => ['latitude' => 1.5, 'longitude' => 2, 'name' => 'Port']], false, $noLid);
assert($row['body'] === 'Port (1.5, 2)');
$row = W::message(['id' => 'K6', 'location' => ['live' => true]], false, $noLid);
assert($row['kind'] === 'location' && $row['body'] === null);
// Media that WAHA did not download can come without a mimetype.
assert(W::kind(['hasMedia' => true, 'media' => null]) === 'document');
assert(W::kind(['hasMedia' => true, 'media' => ['url' => null]]) === 'document');
assert(W::kind(['hasMedia' => true]) === 'document');

$lid = fn(string $l): ?string => $l === '9@lid' ? '777@c.us' : null;
$base = ['id' => 'false_1@g.us_K1_5@c.us', 'timestamp' => 1700000000.6, 'fromMe' => false, 'body' => 'hi'];

$r = W::message($base + ['participant' => '5@c.us', 'from' => '1@g.us'], true, $lid);
assert($r['platform_msg_id'] === 'K1' && $r['sent_at'] === 1700000000600);
assert($r['sender'] === '5@s.whatsapp.net' && $r['kind'] === 'text' && $r['body'] === 'hi');
assert($r['media'] === null && $r['call'] === null && $r['from_me'] === false);

$r = W::message($base + ['author' => '6@c.us', 'from' => '1@g.us'], true, $lid);
assert($r['sender'] === '6@s.whatsapp.net');
$r = W::message($base + ['participant' => '9@lid'], true, $lid);
assert($r['sender'] === '777@s.whatsapp.net');
$r = W::message($base + ['from' => '4@c.us'], false, $lid);
assert($r['sender'] === '4@s.whatsapp.net');
$r = W::message(['fromMe' => true, 'from' => '4@c.us'] + $base, false, $lid);
assert($r['sender'] === null && $r['from_me'] === true);
assert(W::message(['id' => 'false_1@c.us'] + $base, false, $lid) === null);

$r = W::message(['hasMedia' => true, 'media' => ['mimetype' => 'image/jpeg', 'filename' => 'a.jpg'], 'body' => ''] + $base, false, $lid);
assert($r['kind'] === 'image' && $r['body'] === null);
assert($r['media'] === [
    'file_hash' => null, 'mime' => 'image/jpeg', 'size' => null, 'file_name' => 'a.jpg',
    'caption' => null, 'src_path' => null, 'suspicious' => null,
]);
$r = W::message($media('video/mp4') + $base, false, $lid);
assert($r['media']['caption'] === 'hi' && $r['body'] === null);
$r = W::message(['hasMedia' => true, 'media' => null] + $base, false, $lid);
assert($r['kind'] === 'document' && $r['body'] === null);
assert($r['media'] === [
    'file_hash' => null, 'mime' => null, 'size' => null, 'file_name' => null,
    'caption' => 'hi', 'src_path' => null, 'suspicious' => null,
]);

assert(W::mediaName('3EB0AB', 'image/jpeg', null) === '3EB0AB.jpg');
assert(W::mediaName('3EB0AB', 'image/webp', null) === '3EB0AB.webp');
assert(W::mediaName('3EB0AB', 'x/unknown', 'a.php') === '3EB0AB.bin');
assert(W::mediaName('../a/b', null, null) === 'ab.bin');

// The account of the session against the account that the source knows.
$accountError = static function (?string $known, ?string $wahaMe): ?string {
    try {
        W::account($known, $wahaMe);
    } catch (WahaException $e) {
        return $e->errorCode;
    }
    return null;
};
// The source knows an account and WAHA gives none: the sync stops.
assert($accountError('123@s.whatsapp.net', null) === 'waha_no_account');
assert($accountError('123@s.whatsapp.net', '') === 'waha_no_account');
// WAHA gives a different account: the sync stops.
assert($accountError('123@s.whatsapp.net', '456@c.us') === 'waha_account_changed');
// The first sync gets the account to store.
assert(W::account(null, '123@c.us') === '123@s.whatsapp.net');
// Nothing to store: the account is the same, or neither side has one.
assert(W::account('123@s.whatsapp.net', '123@c.us') === null);
assert(W::account(null, null) === null);

echo "waha_mapper: OK\n";
