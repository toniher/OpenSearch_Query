<?php

class OpenSearch_Document {

	/**
	 * @param array $params request params: db, key
	 * @return array{ok:bool,httpCode:int,data:mixed,error:?string,notFound:bool}|null
	 */
	public static function processDocument( array $params ) {

		if ( empty( $params['key'] ) || empty( $params['db'] ) ) {
			return null;
		}

		$connectionParams = OpenSearch_Connection::resolveConnectionParams( 'document', $params['db'] );

		if ( empty( $connectionParams['index'] ) ) {
			return null;
		}

		$path = '/' . $connectionParams['index'] . '/_doc/' . rawurlencode( $params['key'] );

		$response = OpenSearch_Connection::request( $connectionParams, $path, 'GET' );
		$response['notFound'] = $response['ok'] && $response['httpCode'] === 404;

		return $response;
	}

}
