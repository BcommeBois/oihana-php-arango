<?php

namespace oihana\arango\models\traits\queries;

use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\ActiveTrait;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\FieldsTrait;
use oihana\arango\models\traits\aql\SearchTrait;
use oihana\exceptions\BindException;
use oihana\logging\DebugTrait;
use oihana\models\traits\ConditionsTrait;

use org\schema\constants\Schema;

use function oihana\arango\db\operations\aqlDesc;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLimit;
use function oihana\arango\db\operations\aqlSort;
use function oihana\core\strings\compile;

/**
 * Provides an ArangoDB query to fetch the last document from the collection.
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait LastQueryTrait
{
    use ActiveTrait     ,
        BindTrait       ,
        ConditionsTrait ,
        DebugTrait      ,
        FieldsTrait     ,
        SearchTrait     ;

    /**
     * Build the AQL query to fetch the last document from the collection.
     *
     * The query sorts the documents by a specific property (default: `Schema::MODIFIED`)
     * in descending order and returns the first item.
     *
     * Generated AQL structure:
     *  FOR doc IN @@collection
     *  SORT doc.<property> DESC
     *  LIMIT 1
     *  RETURN { ...fields }
     *
     * @param array $init Initialization parameters (see `last()` for details).
     * @param array $bindVars Reference to bind variables.
     *
     * @return string The compiled AQL query.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function buildLastQuery( array $init = [] , array &$bindVars = [] ) : string
    {
        $debug     = $init[ Arango::DEBUG ] ?? $this->debug ;
        $property  = $init[ Arango::PROPERTY  ] ?? Schema::MODIFIED ;
        $variables = $init[ Arango::VARIABLES ] ?? [] ;

        $for    = aqlFor   (  [ AQL::IN => $this->bindCollection($bindVars ) ] ) ;
        $filter = aqlFilter( $this->conditions );
        $limit  = aqlLimit ( 1  ) ;
        $sort   = aqlSort  ( aqlDesc( $property , AQL::DOC ) ) ;
        $return = $this->returnFields( $init , $variables ) ;

        $query = compile
        ([
            $for ,
            $variables ,
            $filter ,
            $sort ,
            $limit ,
            $return
        ]) ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
        }

        return $query ;
    }
}