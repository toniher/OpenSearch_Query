<?php
// This extension has been renamed to OpenSearch_Query.
// In LocalSettings.php, load it with:
//   wfLoadExtension( 'OpenSearch_Query' );
//
// The old require_once style is no longer supported.

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

die( 'CouchDB_Query has been renamed to OpenSearch_Query. Load it using wfLoadExtension( \'OpenSearch_Query\' ) in LocalSettings.php.' );
