<?php

namespace oihana\arango\models\traits\queries;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\exceptions\BindException;
use oihana\enums\Char;
use oihana\models\traits\ConditionsTrait;

use org\schema\constants\Prop;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\in;
use function oihana\core\arrays\toArray;
use function oihana\core\arrays\unique;
use function oihana\core\strings\key;

/**
 * Provides an ArangoDB query to check the existence of documents.
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait ExistQueryTrait
{
    use BindTrait ,
        ConditionsTrait ;

    /**
     * Build the AQL query to check for the existence of documents.
     *
     * @param array $init Initialization array with optional parameters:
     * - value (?int|string|array) : Single value or array of values to check
     * - key (?string)             : Document attribute to match (default "_key")
     * - prefix (?string)          : Document alias (default "doc")
     * - match (?string)           : Matching strategy (ArrayComparator::ALL|ANY)
     * - conditions (?array)       : Additional AQL filter conditions
     *
     * @param array $bindVars Reference to bind variables array
     *
     * @return string The compiled AQL query
     *
     * @throws BindException If there's an error binding parameters to the AQL query.
     * @throws ReflectionException If a reflection error occurs (e.g., during internal AQL building).
     */
    public function buildExistQuery( array $init = [] , array &$bindVars = [] ): string
    {
        $values = $init[ Arango::VALUE ] ?? [] ;

        if ( !is_array($values) )
        {
            $values = toArray( $values ) ;
        }

        $values = unique( $values ) ;

        $key        = $init[ Arango::KEY        ] ?? Prop::_KEY ;
        $prefix     = $init[ Arango::PREFIX     ] ?? AQL::DOC ;
        $conditions = $init[ Arango::CONDITIONS ] ?? [] ;

        $docKey = key( $key , $prefix ) ;

        $in = [] ;
        foreach ( $values as $value )
        {
            $in[] = $this->bind( $value , $bindVars ) ;
        }

        $in = Char::LEFT_BRACKET . implode( Char::COMMA , $in ) . Char::RIGHT_BRACKET ;

        return aqlReturn( length
        ([
            aqlFor    ( [ AQL::IN => $this->bindCollection($bindVars ) ] ) ,
            aqlFilter ( [ ...$this->conditions , in( $docKey , $in ) , ...$conditions ] ) ,
            aqlReturn (1 )
        ]));
    }
}