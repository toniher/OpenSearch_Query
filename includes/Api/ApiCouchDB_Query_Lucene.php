<?php

/**
 * Deprecated alias for the couchdb-lucene-query action. Lucene and Nouveau both collapse into
 * OpenSearch's query_string search, so this is a thin alias of ApiOpenSearch_Search.
 * See docs/migration-from-couchdb.md.
 */
class ApiCouchDB_Query_Lucene extends ApiOpenSearch_Search {

	public function isDeprecated() {
		return true;
	}

}
