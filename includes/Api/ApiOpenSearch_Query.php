<?php

class ApiOpenSearch_Query extends ApiBase {

	public function execute() {

		$params = $this->extractRequestParams();
		$response = OpenSearch_Index::processIndex( $params );

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
		$count = $data->hits->total->value ?? 0;
		$hits = $data->hits->hits ?? [];

		$fieldarr = ! empty( $params['fields'] ) ? explode( ',', $params['fields'] ) : [];

		$rows = [];
		foreach ( $hits as $hit ) {
			$rowid = $hit->_id;
			$source = $hit->_source ?? new stdClass();

			$newrow = [ 'id' => $rowid ];

			if ( is_object( $source ) && property_exists( $source, 'pagename' ) ) {
				$newrow['pagename'] = $source->pagename;
			} else {
				$page = \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromID( (int)$rowid );
				if ( $page ) {
					$newrow['pagename'] = $page->getTitle()->getFullText();
				}
			}

			if ( $fieldarr !== [] ) {
				$newrow['fields'] = [];
				foreach ( $fieldarr as $field ) {
					if ( is_object( $source ) && property_exists( $source, $field ) ) {
						$newrow['fields'][$field] = $source->$field;
					}
				}
			}

			$rows[] = $newrow;
		}

		$statusValue = [ 'status' => 'OK', 'count' => $count ];

		if ( isset( $data->aggregations->groups->buckets ) ) {
			$groups = [];
			foreach ( $data->aggregations->groups->buckets as $bucket ) {
				$groups[] = [ 'key' => $bucket->key, 'count' => $bucket->doc_count ];
			}
			$result->setIndexedTagName( $groups, 'group' );
			$statusValue['groups'] = $groups;
		}

		$result->addValue( null, $this->getModuleName(), $statusValue );

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
			'index' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
			'db' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
			'key' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
			'keys' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
			'startkey' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
			'endkey' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
			'fields' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => false ],
			'limit' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => false ],
			'skip' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => false ],
			'include_docs' => [ ApiBase::PARAM_TYPE => 'boolean', ApiBase::PARAM_REQUIRED => false ],
		];
	}

}
