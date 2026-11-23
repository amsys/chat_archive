<?php

declare(strict_types=1);

// DisplayText::name() and DisplayText::mimeMismatch(). No SQLite needed: runs
// on the host php. arch: D4.1, D4.2, D4.3, D4.4
// php -d zend.assertions=1 -d assert.exception=1 tests/display_text_check.php

require __DIR__ . '/../lib/Service/DisplayText.php';

use OCA\ChatArchive\Service\DisplayText;

// I4.2: a right-to-left override cannot hide the real file extension.
assert(DisplayText::name("invoice\u{202E}fdp.exe") === 'invoicefdp.exe');
// I4.2: a ZWJ family emoji stays a family emoji (ZWJ joins the parts).
assert(DisplayText::name("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}") === "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}");
// I4.2: a Persian ZWNJ word keeps its shape (ZWNJ splits two letters, no override).
assert(DisplayText::name("\u{0645}\u{06CC}\u{200C}\u{0631}\u{0648}\u{0645}") === "\u{0645}\u{06CC}\u{200C}\u{0631}\u{0648}\u{0645}");
// A plain control byte (not a bidi mark) is stripped too (\p{Cc}).
assert(DisplayText::name("a\x01b") === 'ab');
// Null in, null out: a name that was never set stays unset, not "".
assert(DisplayText::name(null) === null);
// A name with nothing to strip is unchanged.
assert(DisplayText::name('Alice') === 'Alice');

// D4.3: a real kind that differs from the declared kind (top-level type) is a mismatch.
assert(DisplayText::mimeMismatch('image/jpeg', 'application/x-msdownload') === true);
// D4.3: a parameter on one side is not a mismatch; both are "audio".
assert(DisplayText::mimeMismatch('audio/ogg; codecs=opus', 'audio/ogg') === false);
// D4.3: two aliases of the same top-level type ("application") are not a mismatch.
assert(DisplayText::mimeMismatch('application/x-zip-compressed', 'application/zip') === false);
// D4.3: an unknown real mime (application/octet-stream) never flags, whatever was declared.
assert(DisplayText::mimeMismatch('image/jpeg', 'application/octet-stream') === false);
// D4.3: either mime missing means nothing to compare.
assert(DisplayText::mimeMismatch(null, 'image/jpeg') === false);
assert(DisplayText::mimeMismatch('image/jpeg', null) === false);
// D4.3: an empty declared mime (after trimming) is not a mismatch.
assert(DisplayText::mimeMismatch('', 'image/jpeg') === false);
// D4.3: whitespace-only declared mime (after trimming is empty) is not a mismatch.
assert(DisplayText::mimeMismatch('  ', 'image/jpeg') === false);
// D4.3: an empty real mime (after trimming) is not a mismatch.
assert(DisplayText::mimeMismatch('image/jpeg', '') === false);
// D4.3: the same top-level type on both sides is not a mismatch.
assert(DisplayText::mimeMismatch('video/mp4', 'video/quicktime') === false);

echo "display_text_check: OK\n";
