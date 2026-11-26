<?php

declare(strict_types=1);

// Name candidates and owner rule of the media linker.
// php -d zend.assertions=1 -d assert.exception=1 tests/media_linker_check.php

require __DIR__ . '/../lib/Service/MediaLinker.php';

use OCA\ChatArchive\Service\MediaLinker;

assert(MediaLinker::nameCandidates('IMG-1.jpg') === ['IMG-1.jpg']);
assert(MediaLinker::nameCandidates('DOC-20250210-WA0027.') === ['DOC-20250210-WA0027.', 'DOC-20250210-WA0027']);
assert(MediaLinker::nameCandidates('report. ') === ['report. ', 'report']);
assert(MediaLinker::nameCandidates('...') === ['...']);

// Owner rule. Normal case: folder has an owner, node must match it.
assert(MediaLinker::isForeign('alice', 'alice', 'alice') === false);
assert(MediaLinker::isForeign('bob', 'alice', 'alice') === true);
// A share node reports no owner: foreign inside an owned folder.
assert(MediaLinker::isForeign(null, 'alice', 'alice') === true);
// Owner-less source folder: fall back to the source's user id.
assert(MediaLinker::isForeign('alice', null, 'alice') === false);
assert(MediaLinker::isForeign('bob', null, 'alice') === true);
// A null-owner node in a null-owner folder is accepted, not treated as foreign.
assert(MediaLinker::isForeign(null, null, 'alice') === false);

echo "media_linker_check: OK\n";
