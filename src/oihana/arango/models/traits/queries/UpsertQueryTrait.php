<?php

namespace oihana\arango\models\traits\queries;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use oihana\arango\db\enums\Operation;
use oihana\arango\models\traits\aql\PrepareDocumentTrait;
use oihana\arango\models\traits\ArangoTrait;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use function oihana\arango\db\operations\aqlRepsert;
use function oihana\arango\db\operations\aqlUpsert;

/**
 * Provides an ArangoDB query for an upsert clause (upsert or repsert modes).
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait UpsertQueryTrait
{
    use ArangoTrait   ,
        PrepareDocumentTrait ;

    /**
     * Build the AQL query for an upsert clause (upsert or repsert modes).
     *
     * @param 'update'|'replace' $mode Operation mode: 'update' for upsert, 'replace' for repsert.
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
     * @throws BindException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws ReflectionException           If a reflection error occurs (e.g., during internal AQL building).
     * @throws UnsupportedOperationException
     */
    public function buildUpsertQuery
    (
        string $mode           ,
        array  $init      = [] ,
        array  &$bindVars = []
    )
    : string
    {
        $collection = $init[ Arango::COLLECTION  ] ?? $this->collection ;
        $conditions = $init[ Arango::CONDITIONS  ] ?? [] ;
        $debug      = $init[ Arango::DEBUG       ] ?? $this->debug ;
        $removeKeys = $init[ Arango::REMOVE_KEYS ] ?? null ;
        $filter     = $init[ Arango::FILTER      ] ?? null ;
        $return     = $init[ Arango::RETURN      ] ?? null ;
        $search     = $init[ Arango::SEARCH      ] ?? null ;

        if( isset( $search ) )
        {
            $search = $this->prepareDocumentClause
            (
                $init[ Arango::SEARCH ] ?? null ,
                Operation::SEARCH ,
                $bindVars ,
                $removeKeys
            ) ;
        }

        $insert = $this->prepareDocumentClause
        (
            $init[ Arango::INSERT ] ?? null ,
            Operation::INSERT ,
            $bindVars ,
            $removeKeys
        ) ;

        $operation = $mode === AQL::REPLACE ? Operation::REPLACE : Operation::UPDATE ;
        $clauseKey = $mode === AQL::REPLACE ? AQL::REPLACE : AQL::UPDATE ;

        $updateOrReplace = $this->prepareDocumentClause
        (
            $init[ $clauseKey ] ?? null ,
            $operation ,
            $bindVars ,
            $removeKeys ,
            $conditions
        );

        $init =
        [
            ...$init ,
            AQL::COLLECTION => $collection ,
            AQL::FILTER     => $filter ,
            AQL::SEARCH     => $search ,
            AQL::INSERT     => $insert ,
            $clauseKey      => $updateOrReplace ,
            AQL::RETURN     => $return ,
        ] ;

        $query = $mode === AQL::REPLACE
            ? aqlRepsert ( $init )
            : aqlUpsert  ( $init ) ;

        // echo PHP_EOL . 'query     : ' . $query . PHP_EOL . PHP_EOL ;
        // echo PHP_EOL . 'bindsVars : ' . json_encode( $bindVars ) . PHP_EOL . PHP_EOL ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
        }

        return $query ;
    }
}