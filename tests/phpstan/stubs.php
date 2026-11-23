<?php
// phpstan only: the three private server classes the FullTextSearch provider instantiates
// and the viewer app's load event. nextcloud/ocp ships neither.

namespace OC\FullTextSearch\Model;

use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchTemplate;

class IndexDocument implements IIndexDocument {
	public function __construct(string $providerId, string $documentId) {
	}
}

class DocumentAccess implements IDocumentAccess {
	public function __construct(string $ownerId = '') {
	}
}

class SearchTemplate implements ISearchTemplate {
}

namespace OCA\Viewer\Event;

class LoadViewer extends \OCP\EventDispatcher\Event {
}
