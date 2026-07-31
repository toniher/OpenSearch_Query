<?php

class OpenSearch_Index {

	/**
	 * @param array $params request params: index, db, key/keys/startkey/endkey, fields, limit, skip,
	 *   include_docs
	 * @return array{ok:bool,httpCode:int,data:mixed,error:?string}|null null when db/index unconfigured
	 */
	public static function processIndex( array $params ) {

		$queryConfig = self::resolveQueryConfig( $params );
		if ( $queryConfig === null ) {
			return null;
		}

		$connectionParams = OpenSearch_Connection::resolveConnectionParams( 'params', $params['db'] );
		$dsl = OpenSearch_QueryBuilder::buildViewQuery( $params, $queryConfig );
		$path = '/' . $queryConfig['index'] . '/_search';

		return OpenSearch_Connection::request( $connectionParams, $path, 'POST', $dsl );
	}

	/**
	 * Shared by OpenSearch_Search: resolve queries[$db][$index] into its normalised config block.
	 *
	 * @return array|null
	 */
	public static function resolveQueryConfig( array $params ) {

		if ( empty( $params['index'] ) || empty( $params['db'] ) ) {
			return null;
		}

		$db = $params['db'];
		$index = $params['index'];

		$config = OpenSearch_Connection::getConfig();

		if ( ! isset( $config['queries'][$db][$index] ) ) {
			return null;
		}

		$queryConfig = OpenSearch_QueryBuilder::normaliseQueryConfig( $config['queries'][$db][$index] );

		if ( empty( $queryConfig['index'] ) ) {
			return null;
		}

		return $queryConfig;
	}

}
