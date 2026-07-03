<?php

namespace oihana\arango\db\helpers\fields;

use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;
use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\db\helpers\aqlSafeArray;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\models\helpers\authorizeRelationFields;
use function oihana\arango\models\helpers\buildVariables;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;
use function oihana\core\strings\randomKey;

/**
 * Generates an AQL expression for mapping an array field to structured documents.
 *
 * This method creates a sub-query that iterates over an array in the document
 * and returns each element with only the specified fields.
 *
 * Example output:
 * ```aql
 * addresses: ( FOR item IN doc.addresses RETURN { street: item.street, city: item.city } )
 * ```
 *
 * @param string $key The field key in the parent document (e.g., "addresses").
 * @param string $doc The document reference for AQL (e.g., "doc").
 * @param array $options Field options:
 *                          - Field::FIELDS: Array of sub-field definitions to include in each mapped item
 *                          - Field::NAME: Optional source field name (defaults to $key)
 * @param ContainerInterface|null $container The optional DI Container reference.
 * @param array $init The optional associative array definition.
 *
 * @return string AQL snippet with the array mapping expression.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws ReflectionException
 * @throws UnsupportedOperationException
 * @throws BindException
 * @throws ConstantException
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldMap
(
    string              $key ,
    string              $doc ,
    array               $options ,
    ?ContainerInterface $container = null ,
    array               $init      = []
)
: string
{
    $subFields = $options[ Field::FIELDS ] ?? [] ;

    if ( empty( $subFields ) )
    {
        return keyValue( $key , '[]' ) ; // Return empty array if no sub-fields are defined
    }

    $docRef = randomKey( AQL::ITEM ) ; // The AQL variable for the item in the sub-collection loop (e.g., "item")

    // The source of the array in the document (e.g., "doc.addresses")
    $fieldSource = key( $options[ Field::NAME ] ?? $key , $doc );

    $edges = $options[ Field::EDGES ] ?? [] ;
    $joins = $options[ Field::JOINS ] ?? [] ;

    // Definition-level gating: purge the relation markers whose nested definition
    // is denied BEFORE the `LET` walk (buildVariables) and the projection walk
    // (aqlFields), which share this sub-fields array.
    $subFields = authorizeRelationFields( $subFields , $edges , $joins , $init ) ?? [] ;

    $subVariables = [] ;

    buildVariables( $subVariables , $subFields , $edges , $joins , $container , $docRef , $init ) ;

    $subQueryFields = aqlFields( $subFields , $docRef , $container , $init ) ;

    $subQuery = compile
    ([
        aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => aqlSafeArray( $fieldSource ) ] ) ,
        $subVariables ,
        aqlReturn( aqlDocument( $subQueryFields ) )
    ]);

    return keyValue( $key , betweenParentheses( $subQuery , trim: false ) ) ;
}
