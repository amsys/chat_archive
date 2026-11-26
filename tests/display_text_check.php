<?php

declare(strict_types=1);

// DisplayText::name() and DisplayText::mimeMismatch(). No SQLite needed: runs
// on the host php.
// php -d zend.assertions=1 -d assert.exception=1 tests/display_text_check.php

require __DIR__ . '/../lib/Service/DisplayText.php';

use OCA\ChatArchive\Service\DisplayText;

// A right-to-left override cannot hide the real file extension.
assert(DisplayText::name("invoice\u{202E}fdp.exe") === 'invoicefdp.exe');
// A ZWJ family emoji stays a family emoji (ZWJ joins the parts).
assert(DisplayText::name("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}") === "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}");
// A Persian ZWNJ word keeps its shape (ZWNJ splits two letters, no override).
assert(DisplayText::name("\u{0645}\u{06CC}\u{200C}\u{0631}\u{0648}\u{0645}") === "\u{0645}\u{06CC}\u{200C}\u{0631}\u{0648}\u{0645}");
// A plain control byte (not a bidi mark) is stripped too (\p{Cc}).
assert(DisplayText::name("a\x01b") === 'ab');
// Null in, null out: a name that was never set stays unset, not "".
assert(DisplayText::name(null) === null);
// A name with nothing to strip is unchanged.
assert(DisplayText::name('Alice') === 'Alice');

// A real kind that differs from the declared kind (top-level type) is a mismatch.
assert(DisplayText::mimeMismatch('image/jpeg', 'application/x-msdownload') === true);
// A parameter on one side is not a mismatch; both are "audio".
assert(DisplayText::mimeMismatch('audio/ogg; codecs=opus', 'audio/ogg') === false);
// Two aliases of the same top-level type ("application") are not a mismatch.
assert(DisplayText::mimeMismatch('application/x-zip-compressed', 'application/zip') === false);
// An unknown real mime (application/octet-stream) never flags, whatever was declared.
assert(DisplayText::mimeMismatch('image/jpeg', 'application/octet-stream') === false);
// Either mime missing means nothing to compare.
assert(DisplayText::mimeMismatch(null, 'image/jpeg') === false);
assert(DisplayText::mimeMismatch('image/jpeg', null) === false);
// An empty declared mime (after trimming) is not a mismatch.
assert(DisplayText::mimeMismatch('', 'image/jpeg') === false);
// Whitespace-only declared mime (after trimming is empty) is not a mismatch.
assert(DisplayText::mimeMismatch('  ', 'image/jpeg') === false);
// An empty real mime (after trimming) is not a mismatch.
assert(DisplayText::mimeMismatch('image/jpeg', '') === false);
// The same top-level type on both sides is not a mismatch.
assert(DisplayText::mimeMismatch('video/mp4', 'video/quicktime') === false);

// A stored chat name that is empty or only a number gives no name.
assert(DisplayText::noName(null) && DisplayText::noName('') && DisplayText::noName('23000001'));
assert(DisplayText::noName('+230 5000-0000') && DisplayText::noName('(230) 59.77'));
assert(!DisplayText::noName('Anna') && !DisplayText::noName('Anna 2') && !DisplayText::noName('شركة'));
// A backup number does not replace a stored name; a backup name does. A sync fills a row with no name only.
assert(DisplayText::backupName('Anna', '23000001') === 'Anna' && DisplayText::backupName('23000001', 'Family') === 'Family');
assert(DisplayText::backupName('Anna', null) === 'Anna' && DisplayText::backupName(null, '23000001') === '23000001');
assert(DisplayText::backupName('Old group', 'New group') === 'New group');
assert(DisplayText::backupName('Class of 2019', '2019', true) === '2019' && DisplayText::backupName('Class of 2019', null, true) === 'Class of 2019');
assert(DisplayText::syncName('23000001', 'Anna') === 'Anna' && DisplayText::syncName(null, 'Anna') === 'Anna');
assert(DisplayText::syncName('Anna', 'Bea') === 'Anna' && DisplayText::syncName('23000001', null) === '23000001');

echo "display_text_check: OK\n";
