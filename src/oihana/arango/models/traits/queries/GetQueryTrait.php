<?php

namespace oihana\arango\models\traits\queries;

use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\ActiveTrait;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\FacetTrait;
use oihana\arango\models\traits\aql\FieldsTrait;
use oihana\arango\models\traits\aql\FilterTrait;
use oihana\arango\models\traits\aql\SearchTrait;
use oihana\exceptions\BindException;
use oihana\models\traits\ConditionsTrait;

use org\schema\constants\Schema;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operators\equal;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Provides an ArangoDB query for a single document retrieval.
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait GetQueryTrait
{
    use ActiveTrait ,
        BindTrait   ,
        ConditionsTrait ,
        FacetTrait  ,
        FieldsTrait ,
        FilterTrait ,
        SearchTrait ;

    /**
     * Build the AQL query for a single document retrieval.
     *
     * @param array $init The initialization array with optional settings:
     * - value (mixed)       : The value to search for.
     * - key (?string)       : The attribute key to target (default "_key").
     * - prefix (?string)    : Document prefix (default "doc").
     * - binds               : Bind variables array.
     * - conditions          : Extra conditions for the AQL FILTER.
     * - extraQuery          : Extra AQL snippets to inject in the FILTER.
     * - active              : Optional active flag.
     * - fields (?array)     : Specific fields to return.
     * - skin (?string)      : Skin to apply on the result document.
     *
     * @param array $bindVars The bind variables reference.
     *
     * @return string The AQL query expression.
     *
     * @throws BindException If there's an error binding parameters to the AQL query.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException If a reflection error occurs (e.g., during internal AQL building).
     */
    public function buildGetQuery
    (
        array $init      = [] ,
        array &$bindVars = []
    )
    : string
    {
        $conditions = $init[ Arango::CONDITIONS  ] ?? [] ;
        $debug      = $init[ Arango::DEBUG       ] ?? $this->debug ;
        $key        = $init[ Arango::KEY         ] ?? Schema::_KEY ;
        $prefix     = $init[ Arango::PREFIX      ] ?? AQL::DOC ;
        $value      = $init[ Arango::VALUE       ] ?? null ;
        $variables  = $init[ Arango::VARIABLES   ] ?? [] ;

        // FOR doc in @@collection
        // FILTER doc._key == $value ....
        // RETURN { ...fields }

        // $init[ Arango::IN ] = 'description' ;

        $for    = aqlFor( [ AQL::IN => $this->bindCollection($bindVars ) ] ) ;
        $filter = aqlFilter
        ([
            ...$this->conditions ,
            equal( key( $key , $prefix ) , $this->bind( $value , $bindVars ) ),
            $this->prepareActive( $init , $bindVars ) ,
            ...$conditions
        ]) ;
        $return = $this->returnFields( $init , $variables ) ;

        $query = compile( [ $for , $variables , $filter , $return ] ) ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
        }

        // $this->debugQuery( __METHOD__ , $query , $bindVars ) ;

        return $query ;
    }
}