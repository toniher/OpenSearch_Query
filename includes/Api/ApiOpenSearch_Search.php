<?php

class ApiOpenSearch_Search extends ApiBase {

	public function execute() {

		$params = $this->extractRequestParams();
		$response = OpenSearch_Search::processIndex( $params );

		$result = $this->getResult();

		if ( $response === null ) {
			$this->reportError( $result, 'unknown-db-or-index' );
			return true;
		}

		if ( ! $response['ok'] ) {
			$this->reportError( $result, $response['error'] );
			return true;
		}

		if ( $response['httpCode'] >= 400 ) {
			$this->reportError( $result, 'http-' . $response['httpCode'] );
			return true;
		}

		$data = $response['data'];
		$count = ( is_object( $data ) && isset( $data->hits->total->value ) ) ? $data->hits->total->value : 0;
		$hits = ( is_object( $data ) && isset( $data->hits->hits ) && is_array( $data->hits->hits ) ) ? $data->hits->hits : [];

		$db = $params['db'];
		$index = $params['index'];
		$config = OpenSearch_Connection::getConfig();

		$rows = [];
		foreach ( $hits as $hit ) {

			$rowid = property_exists( $hit, '_id' ) ? $hit->_id : null;
			$score = property_exists( $hit, '_score' ) ? $hit->_score : null;
			$fields = property_exists( $hit, '_source' ) ? $hit->_source : new stdClass();

			$page = $rowid !== null
				? \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromID( (int)$rowid )
				: null;

			$newrow = [ 'id' => $rowid, 'score' => $score ];

			if ( $page ) {
				$newrow['pagename'] = $page->getTitle()->getFullText();
			} elseif ( ! empty( $config['map'][$db][$index]['pagename'] ) ) {
				$pagenameField = $config['map'][$db][$index]['pagename'];
				if ( is_object( $fields ) && property_exists( $fields, $pagenameField ) ) {
					$newrow['pagename'] = $fields->$pagenameField;
				}
			}

			$newrow['fields'] = $fields;

			$rows[] = $newrow;
		}

		$statusValue = [ 'status' => 'OK', 'count' => $count ];

		if ( ! empty( $response['warning'] ) ) {
			$statusValue['warning'] = $response['warning'];
		}

		$result->addValue( null, $this->getModuleName(), $statusValue );

		$results = [];
		foreach ( $rows as $row ) {
			$result->setIndexedTagName( $row, 'result' );
			$results[] = $row;
		}

		$result->setIndexedTagName( $results, 'result' );
		$result->addValue( $this->getModuleName(), 'results', $results );

		if ( isset( $data->aggregations ) ) {
			$result->addValue( $this->getModuleName(), 'aggregations', json_decode( json_encode( $data->aggregations ), true ) );
		}

		return true;
	}

	private function reportError( $result, $error ) {
		$result->addValue( null, $this->getModuleName(), [ 'status' => 'ERROR', 'count' => 0, 'error' => $error ] );
		$result->addValue( $this->getModuleName(), 'results', [] );
	}

	public function getAllowedParams() {
		return [
			'index' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
			'db' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
			'q' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
			'limit' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => false ],
			'skip' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => false ],
			'sort' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
			'full' => [ ApiBase::PARAM_TYPE => 'boolean', ApiBase::PARAM_REQUIRED => false ],
			'aggs' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
		];
	}

}
