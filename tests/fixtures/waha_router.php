<?php

// Fake WAHA server for the integration test. Run it inside the test
// container with `php -S 127.0.0.1:3999 waha_router.php`.
//
// It serves fixed data for the session "default" (GET only, I20.3):
// - chat 23000001@c.us is chat A of the fixture backup. It holds K8, a
//   message that the backup also holds, and WAHA0001, a newer message.
// - chat 23000009-1700000000@g.us is not in the backup. It holds WAHA0002,
//   sent by a lid participant that /lids maps to a phone number.
// A request without the right X-Api-Key header gets 401.

declare(strict_types=1);

const API_KEY = 'ca-test-waha-key';

const CHATS = [
	'23000009-1700000000@g.us' => [
		'name' => 'WAHA group',
		'messages' => [
			[
				'id' => 'false_23000009-1700000000@g.us_WAHA0002_987654321@lid',
				'timestamp' => 1725100100,
				'from' => '23000009-1700000000@g.us',
				'participant' => '987654321@lid',
				'fromMe' => false,
				'body' => 'group hello from waha',
				'hasMedia' => false,
			],
		],
	],
	'23000001@c.us' => [
		'name' => 'Anna from WhatsApp',
		'messages' => [
			[
				'id' => 'false_23000001@c.us_K8',
				'timestamp' => 1725001500,
				'from' => '23000001@c.us',
				'fromMe' => false,
				'body' => '',
				'hasMedia' => false,
			],
			[
				'id' => 'false_23000001@c.us_WAHA0001',
				'timestamp' => 1725100000,
				'from' => '23000001@c.us',
				'fromMe' => false,
				'body' => 'new from waha',
				'hasMedia' => false,
			],
		],
	],
];

const LIDS = ['987654321@lid' => '23000004@c.us'];

/** Send a JSON body with a status code and stop. */
function reply(int $status, mixed $body): never {
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($body);
	exit;
}

/**
 * Query parameters as sent. $_GET is not used: PHP renames the key
 * `filter.timestamp.gte` to `filter_timestamp_gte`.
 *
 * @return array<string, string>
 */
function query(): array {
	$out = [];
	foreach (explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
		if ($pair === '') {
			continue;
		}
		[$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
		$out[rawurldecode($k)] = rawurldecode($v);
	}
	return $out;
}

/**
 * One page of a list, from the limit and offset parameters.
 *
 * @param list<mixed> $rows
 * @param array<string, string> $q
 * @return list<mixed>
 */
function page(array $rows, array $q): array {
	return array_slice($rows, (int)($q['offset'] ?? 0), (int)($q['limit'] ?? 100));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	reply(405, ['error' => 'GET only']);
}
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
	reply(401, ['message' => 'Unauthorized']);
}

$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$seg = array_map('rawurldecode', explode('/', trim($path, '/')));
$q = query();

if ($seg === ['api', 'server', 'version']) {
	reply(200, ['version' => '2026.1.0', 'engine' => 'GOWS', 'tier' => 'CORE']);
}
if ($seg === ['api', 'sessions']) {
	reply(200, [['name' => 'default', 'status' => 'WORKING']]);
}
if ($seg === ['api', 'sessions', 'default']) {
	reply(200, ['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '23099999@c.us', 'pushName' => 'Test']]);
}
if (($seg[0] ?? '') !== 'api' || ($seg[1] ?? '') !== 'default') {
	reply(404, ['error' => 'unknown session or path']);
}
$rest = array_slice($seg, 2);

if ($rest === ['chats', 'overview']) {
	// Newest chat first, as WAHA sorts the overview.
	$rows = [];
	foreach (CHATS as $id => $chat) {
		$last = end($chat['messages']);
		$rows[] = ['id' => $id, 'name' => $chat['name'], 'lastMessage' => $last];
	}
	usort($rows, static fn (array $a, array $b) => $b['lastMessage']['timestamp'] <=> $a['lastMessage']['timestamp']);
	reply(200, page($rows, $q));
}
if (count($rest) === 3 && $rest[0] === 'chats' && $rest[2] === 'messages') {
	$chat = CHATS[$rest[1]] ?? null;
	if ($chat === null) {
		reply(404, ['error' => 'unknown chat']);
	}
	// The messages of a chat are stored oldest first; the sync asks for that order.
	$gte = (int)($q['filter.timestamp.gte'] ?? 0);
	$rows = array_values(array_filter($chat['messages'], static fn (array $m) => $m['timestamp'] >= $gte));
	if (($q['sortOrder'] ?? 'desc') !== 'asc') {
		$rows = array_reverse($rows);
	}
	reply(200, page($rows, $q));
}
if (count($rest) === 2 && $rest[0] === 'lids') {
	$pn = LIDS[$rest[1]] ?? null;
	if ($pn === null) {
		reply(404, ['error' => 'unknown lid']);
	}
	reply(200, ['lid' => $rest[1], 'pn' => $pn]);
}
reply(404, ['error' => 'unknown path']);
