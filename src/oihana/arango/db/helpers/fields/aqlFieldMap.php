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
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\db\helpers\aqlSafeArray;
use function oihana\arango\db\operations\aqlFilter;
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
 * With a `Field::WHERE` condition, a `FILTER` restricts the projected elements:
 * ```aql
 * addresses: ( FOR item IN doc.addresses
 *              FILTER item.region IN @allowedRegions
 *              RETURN { street: item.street, city: item.city } )
 * ```
 *
 * @param string $key The field key in the parent document (e.g., "addresses").
 * @param string $doc The document reference for AQL (e.g., "doc").
 * @param array $options Field options:
 *                          - Field::FIELDS: Array of sub-field definitions to include in each mapped item
 *                          - Field::NAME: Optional source field name (defaults to $key)
 *                          - Field::WHERE: Optional condition (Field::WHEN grammar) restricting the
 *                            projected elements; its value may be an AqlBindReference (aqlBindRef).
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
 * @throws ValidationException If a Field::WHERE condition attribute name is unsafe.
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

    // Field::WHERE restricts which array elements are projected. It reuses the
    // Field::WHEN condition grammar (buildWhenCondition), compiled against the
    // item reference, and inserts a FILTER between the sub-query variables and
    // the RETURN. A condition value may be an AqlBindReference (aqlBindRef) so
    // the retained set is decided by a bind supplied at query time — an absent
    // bind fails the query (fail-closed), never silently widens it.
    $where  = $options[ Field::WHERE ] ?? null ;
    $filter = $where !== null ? aqlFilter( buildWhenCondition( $where , $docRef ) ) : null ;

    $subQuery = compile
    ([
        aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => aqlSafeArray( $fieldSource ) ] ) ,
        $subVariables ,
        $filter ,
        aqlReturn( aqlDocument( $subQueryFields ) )
    ]);

    return keyValue( $key , betweenParentheses( $subQuery , trim: false ) ) ;
}
