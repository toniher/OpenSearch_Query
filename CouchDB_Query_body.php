<?php

class CouchDB_Query {

	/**
	* @param $parser Parser
	* @param $frame PPFrame
	* @param $args array
	* @return string
	*/

	public static function process_CouchDB_Query_table( $parser, $frame, $args ) {

		// Get data from parser args and to data
		// Defaults

		$attrs = array();
		$attrs["limit"] =  "25";
		$attrs["header"] = "Page name";
		$attrs["fields"] = "*";
		$attrs["type"] = "";
		$attrs["index"] = "";
		$attrs["query"] = "";
		$attrs["extra"] = "";
		$attrs["prefix"] = "";
		$attrs["prefixurl"] = "";
		$attrs["prefixcondurl"] = "";
		$attrs["full"] = "";
		$attrs["class"] = "wikitable sortable jquery-tablesorter";
		$attrs["db"] = \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( 'DBname' );
		$attrs["default"] = "";

		$startstr = "";
		$endstr = "";

		$attrs_ref = array( "limit", "full", "header", "fields", "type", "index", "query", "class", "start", "end", "db", "text", "extra", "prefix", "prefixurl", "prefixcondurl" );

		foreach ( $args as $arg ) {
			$arg_clean = trim( $frame->expand( $arg ) );
			$arg_proc = explode( "=", $arg_clean, 2 );

			if ( count( $arg_proc ) == 2 ){

				if ( in_array( trim( $arg_proc[0] ), $attrs_ref ) ) {
					$attrs[ trim( $arg_proc[0] ) ] = trim( $arg_proc[1] );
				}
			}
		}

		if ( key_exists( "start", $attrs ) ) {
			$startstr = " data-start = '".$attrs["start"]."'";
		}
		if ( key_exists( "end", $attrs ) ) {
			$endstr = " data-end = '".$attrs["end"]."'";
		}

		$out = $parser->getOutput();
		$out->addModules( [ 'ext.CouchDB_Query' ] );

		$returnhtml = "<div class='couchdb-query-table' data-full='".$attrs['full']."' data-text='".$attrs["text"]."' data-extra='".$attrs["extra"]."' data-total=0 data-skip=0 data-class='".$attrs["class"]."' data-db='".$attrs["db"]."' ".$startstr.$endstr;
		$returnhtml.= " data-limit='".$attrs["limit"]."' data-header='".$attrs["header"]."' data-fields='".$attrs["fields"]."' data-query='".$attrs["query"]."' data-index='".$attrs["index"]."' data-prefix='".$attrs["prefix"]."' data-prefixurl='".$attrs["prefixurl"]."' data-prefixcondurl='".$attrs["prefixcondurl"]."' data-type='".$attrs["type"]."'></div>";

		return array( $returnhtml, 'noparse' => true, 'isHTML' => true );

	}

	/**
	* @param $parser Parser
	* @param $frame PPFrame
	* @param $args array
	* @return string
	*/

	public static function process_CouchDB_Query_field( $parser, $frame, $args ) {

		$returnhtml = "";

		$attrs["tag"] = "input";
		$attrs["type"] = "text";
		$attrs["query"] = "";
		$attrs["values"] = "";
		$attrs["class"] = "";
		$attrs["id"] = "";
		$attrs["default"] = "";


		$attrs_ref = array( "tag", "type", "query", "values", "class", "id", "default" );

		foreach ( $args as $arg ) {
			$arg_clean = trim( $frame->expand( $arg ) );
			$arg_proc = explode( "=", $arg_clean, 2 );

			if ( count( $arg_proc ) == 2 ){

				if ( in_array( trim( $arg_proc[0] ), $attrs_ref ) ) {
					$attrs[ trim( $arg_proc[0] ) ] = trim( $arg_proc[1] );
				}
			}
		}

		$returnhtml = "<div class='couchdb-query-field' data-default='".$attrs["default"]."' data-tag='".$attrs["tag"]."' data-type='".$attrs["type"]."' data-values='".$attrs["values"]."' data-query='".$attrs["query"]."' class='".$attrs["class"]."' id='".$attrs["id"]."'></div>";

		// Return HTML
		return array( $returnhtml, 'noparse' => true, 'isHTML' => true );

	}

}
