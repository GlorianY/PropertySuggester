<?php

namespace PropertySuggester\Suggesters;

use InvalidArgumentException;
use LogicException;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikimedia\Rdbms\LoadBalancer;
use Wikimedia\Rdbms\ResultWrapper;

/**
 * a Suggester implementation that creates suggestion via MySQL
 * Needs the wbs_propertypairs table filled with pair probabilities.
 *
 * @licence GNU GPL v2+
 */
class SimpleSuggester implements SuggesterEngine {

	/**
	 * @var int[]
	 */
	private $deprecatedPropertyIds = [];

	/**
	 * @var array Numeric property ids as keys, values are meaningless.
	 */
	private $classifyingPropertyIds = [];

	/**
	 * @var Suggestion[]
	 */
	private $initialSuggestions = [];

	/**
	 * @var LoadBalancer
	 */
	private $lb;

	/**
	 * @param LoadBalancer $lb
	 */
	public function __construct( LoadBalancer $lb ) {
		$this->lb = $lb;
	}

	/**
	 * @param int[] $deprecatedPropertyIds
	 */
	public function setDeprecatedPropertyIds( array $deprecatedPropertyIds ) {
		$this->deprecatedPropertyIds = $deprecatedPropertyIds;
	}

	/**
	 * @param int[] $classifyingPropertyIds
	 */
	public function setClassifyingPropertyIds( array $classifyingPropertyIds ) {
		$this->classifyingPropertyIds = array_flip( $classifyingPropertyIds );
	}

	/**
	 * @param int[] $initialSuggestionIds
	 */
	public function setInitialSuggestions( array $initialSuggestionIds ) {
		$suggestions = [];
		foreach ( $initialSuggestionIds as $id ) {
			$suggestions[] = new Suggestion( PropertyId::newFromNumber( $id ), 1.0 );
		}

		$this->initialSuggestions = $suggestions;
	}

	/**
	 * @param int[] $propertyIds
	 * @param array[] $idTuples Array of ( int property ID, int item ID ) tuples
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @throws InvalidArgumentException
	 * @return Suggestion[]
	 */
	private function getSuggestions( array $propertyIds, array $idTuples, $limit, $minProbability, $context ) {
		if ( !is_int( $limit ) ) {
			throw new InvalidArgumentException( '$limit must be int!' );
		}
		if ( !is_float( $minProbability ) ) {
			throw new InvalidArgumentException( '$minProbability must be float!' );
		}
		if ( !$propertyIds ) {
			return $this->initialSuggestions;
		}

		if(isset($_GET['all_suggestions']))
	   	{
			if($_GET['all_suggestions'] == "true")
			{
				$excludedIds = [];
				array_push($excludedIds, $propertyIds[0]); //only exclude classifying properties (i.e. P31 and P279) from the suggestion result
				$excludedIds = array_merge($excludedIds, $this->deprecatedPropertyIds);
				$count = 1;
				$probability_query = "probability";
			}
			else
			{
				$excludedIds = array_merge( $propertyIds, $this->deprecatedPropertyIds );
				$count = count( $propertyIds );		
				$probability_query = "sum(probability)";
			}
		}
		else
		{
			$excludedIds = array_merge( $propertyIds, $this->deprecatedPropertyIds );
			$count = count( $propertyIds );		
			$probability_query = "sum(probability)";
		}

		$dbr = $this->lb->getConnection( DB_REPLICA );

		$tupleConditions = [];
		foreach ( $idTuples as $tuple ) {
			$tupleConditions[] = $this->buildTupleCondition( $tuple[0], $tuple[1] );
		}

		if ( empty( $tupleConditions ) ) {
			$condition = 'pid1 IN (' . $dbr->makeList( $propertyIds ) . ')';
		} else {
			$condition = $dbr->makeList( $tupleConditions, LIST_OR );
		}
		$res = $dbr->select(
			'wbs_propertypairs',
			[
				'pid' => 'pid2',
				'prob' => "$probability_query/$count",
			],
			[
				$condition,
				'pid2 NOT IN (' . $dbr->makeList( $excludedIds ) . ')',
				'context' => $context,
			],
			__METHOD__,
			[
				'GROUP BY' => 'pid2',
				'ORDER BY' => 'prob DESC',
				'LIMIT'    => $limit,
				'HAVING'   => 'prob > ' . floatval( $minProbability )
			]
		);
		$this->lb->reuseConnection( $dbr );

		return $this->buildResult( $res );
	}

	/**
	 * @see SuggesterEngine::suggestByPropertyIds
	 *
	 * @param PropertyId[] $propertyIds
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @return Suggestion[]
	 */
	public function suggestByPropertyIds( array $propertyIds, $limit, $minProbability, $context ) {
		$numericIds = array_map( function( PropertyId $propertyId ) {
			return $propertyId->getNumericId();
		}, $propertyIds );

		return $this->getSuggestions( $numericIds, [], $limit, $minProbability, $context );
	}

	/**
	 * @see SuggesterEngine::suggestByEntity
	 *
	 * @param Item $item
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @throws LogicException
	 * @return Suggestion[]
	 */
	public function suggestByItem( Item $item, $limit, $minProbability, $context ) {
		$ids = [];
		$idTuples = [];

		foreach ( $item->getStatements()->toArray() as $statement ) {
			$mainSnak = $statement->getMainSnak();
			$numericPropertyId = $mainSnak->getPropertyId()->getNumericId();
			$ids[] = $numericPropertyId;

			if ( !isset( $this->classifyingPropertyIds[$numericPropertyId] ) ) {
				$idTuples[] = [ $numericPropertyId, 0 ];
			} elseif ( $mainSnak instanceof PropertyValueSnak ) {
				$dataValue = $mainSnak->getDataValue();

				if ( !( $dataValue instanceof EntityIdValue ) ) {
					throw new LogicException(
						"Property $numericPropertyId in wgPropertySuggesterClassifyingPropertyIds"
						. ' does not have value type wikibase-entityid'
					);
				}

				$entityId = $dataValue->getEntityId();

				if ( !( $entityId instanceof ItemId ) ) {
					throw new LogicException(
						"PropertyValueSnak for $numericPropertyId, configured in " .
						' wgPropertySuggesterClassifyingPropertyIds, has an unexpected value ' .
						'and data type (not wikibase-item).'
					);
				}

				$numericEntityId = $entityId->getNumericId();
				$idTuples[] = [ $numericPropertyId, $numericEntityId ];
			}
		}

		return $this->getSuggestions( $ids, $idTuples, $limit, $minProbability, $context );
	}

	/**
	 * @param int $pid
	 * @param int $qid
	 * @return string
	 */
	private function buildTupleCondition( $pid, $qid ) {
		return '(pid1 = ' . (int)$pid . ' AND qid1 = ' . (int)$qid . ')';
	}

	/**
	 * Converts the rows of the SQL result to Suggestion objects
	 *
	 * @param ResultWrapper $res
	 * @return Suggestion[]
	 */
	private function buildResult( ResultWrapper $res ) {
		$resultArray = [];
		foreach ( $res as $row ) {
			$pid = PropertyId::newFromNumber( $row->pid );
			$suggestion = new Suggestion( $pid, $row->prob );
			$resultArray[] = $suggestion;
		}
		return $resultArray;
	}

}
