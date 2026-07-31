<?php

/**
 * Deprecated alias for the couchdb-nouveau-query action, aliasing ApiOpenSearch_Search like
 * ApiCouchDB_Query_Lucene. See docs/migration-from-couchdb.md.
 */
class ApiCouchDB_Query_Nouveau extends ApiOpenSearch_Search {

	public function isDeprecated() {
		return true;
	}

}
