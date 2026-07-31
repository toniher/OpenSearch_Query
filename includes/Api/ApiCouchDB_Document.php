<?php

/**
 * Deprecated alias for the couchdb-document action. See docs/migration-from-couchdb.md.
 */
class ApiCouchDB_Document extends ApiOpenSearch_Document {

	public function isDeprecated() {
		return true;
	}

}
