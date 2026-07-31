<?php

class ApiOpenSearch_Document extends ApiBase {

	public function execute() {

		$params = $this->extractRequestParams();
		$response = OpenSearch_Document::processDocument( $params );

		$result = $this->getResult();

		if ( $response === null ) {
			$this->reportError( $result, 'unknown-db' );
			return true;
		}

		if ( ! $response['ok'] ) {
			$this->reportError( $result, $response['error'] );
			return true;
		}

		if ( ! empty( $response['notFound'] ) ) {
			$result->addValue( null, $this->getModuleName(), [ 'status' => 'OK', 'count' => 0 ] );
			$result->addValue( $this->getModuleName(), 'results', [] );
			return true;
		}

		if ( $response['httpCode'] >= 400 ) {
			$this->reportError( $result, 'http-' . $response['httpCode'] );
			return true;
		}

		$data = $response['data'];
		$rows = [];

		if ( is_object( $data ) && property_exists( $data, '_id' ) ) {
			$row = [ '_id' => $data->_id ];
			if ( property_exists( $data, '_source' ) && is_object( $data->_source ) ) {
				foreach ( $data->_source as $key => $value ) {
					$row[$key] = $value;
				}
			}
			$rows[] = $row;
		}

		$result->addValue( null, $this->getModuleName(), [ 'status' => 'OK', 'count' => count( $rows ) ] );

		$results = [];
		foreach ( $rows as $row ) {
			$result->setIndexedTagName( $row, 'result' );
			$results[] = $row;
		}

		$result->setIndexedTagName( $results, 'result' );
		$result->addValue( $this->getModuleName(), 'results', $results );

		return true;
	}

	private function reportError( $result, $error ) {
		$result->addValue( null, $this->getModuleName(), [ 'status' => 'ERROR', 'count' => 0, 'error' => $error ] );
		$result->addValue( $this->getModuleName(), 'results', [] );
	}

	public function getAllowedParams() {
		return [
			'db' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
			'key' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
		];
	}

}
