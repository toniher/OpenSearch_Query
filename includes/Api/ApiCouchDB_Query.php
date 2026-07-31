<?php

/**
 * Deprecated alias for the couchdb-query action, kept so existing wiki pages and API callers
 * keep working. See docs/migration-from-couchdb.md.
 */
class ApiCouchDB_Query extends ApiOpenSearch_Query {

	public function isDeprecated() {
		return true;
	}

}
