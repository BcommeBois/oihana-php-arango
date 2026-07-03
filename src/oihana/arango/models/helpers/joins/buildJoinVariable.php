<?php

namespace oihana\arango\models\helpers\joins;

use Exception;
use ReflectionException;
use ReflectionFunction;
use UnexpectedValueException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\enums\Arango;
use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\db\helpers\resolveSkinFields;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\arango\db\operators\ternary;
use function oihana\arango\models\helpers\authorizeRelationFields;
use function oihana\arango\models\helpers\buildVariables;
use function oihana\arango\models\helpers\getDocuments;
use function oihana\core\strings\betweenBraces;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\randomKey;

/**
 * Builds a single AQL 'LET' subquery string for a specific join relation.
 *
 * This method generates a complete subquery, enclosed in parentheses,
 * which is assigned to a 'LET' variable. It handles:
 * - Filtering based on the document keys or custom conditions
 * - Sorting (if $isArray is true)
 * - Nested edges and joins
 * - Field selection and skinning
 *
 * Example output:
 * ```
 * LET myJoinVar = (
 *     FOR doc_join IN @@collection
 *         FILTER doc_join._key == doc.relatedKey
 *         RETURN { _key: doc_join._key, name: doc_join.name }
 * )
 * ```
 *
 * @param string|null $name The logical name for this variable (e.g., 'friends', 'subsidiaries').
 *                          Used as the AQL 'LET' variable name.
 *
 * @param array $definition Configuration array for the join. Possible keys:
 * - `AQL::MODEL` (string)          The Documents model class to query.
 * - `AQL::UNIQUE` (string|null)    Optional AQL variable name, overrides $name.
 * - `AQL::FIELDS` (array|null)     Array of fields to include in the result.
 * - `AQL::EDGES` (array)           Array of nested edge definitions.
 * - `AQL::JOINS` (array)           Array of nested join definitions.
 * - `AQL::SKIN` (string|null)      Optional 'skin' name for field selection.
 * - `Arango::KEY` (string)         The key property of the document to match (default Schema::_KEY).
 * - `Arango::PROPERTY` (string|array|null) Optional property of the main document used as the join key.
 * - `Arango::SORT` (string|array|null) Optional sort definition when $isArray is true.
 * - `Arango::CONDITIONS` (callable|array|null) Optional filter conditions:
 *       - If array, it must be a list of AQL filter expressions.
 *       - If callable, it receives one or two arguments:
 *         1. `$docJoin` (string) – the join document variable name
 *         2. `$docRef` (string, optional) – the main document variable name
 *       - Must return an array of AQL filter expressions.
 *
 * @param string                  $docRef    The AQL variable name of the main document reference (default 'doc').
 * @param ContainerInterface|null $container Optional DI container instance used to resolve models.
 * @param array                   $init      Optional associative array used for variable initialization in nested joins.
 * @param bool                    $isArray   If true, the join key is treated as an array of keys, generating an `IN` filter.
 *
 * @return string The complete AQL 'LET' statement.
 *
 * @throws Exception                   If a traversal or join cannot be built properly.
 * @throws ContainerExceptionInterface If the Documents model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface  If the Documents model cannot be found in the container.
 * @throws ReflectionException         If a callable conditions closure fails reflection.
 * @throws UnexpectedValueException    If $name is empty, the model is invalid, collection not set,
 *                                     or CONDITIONS does not return an array.
 */
function buildJoinVariable
(
    ?string             $name        ,
    array               $definition  = [] ,
    string              $docRef      = AQL::DOC ,
    ?ContainerInterface $container   = null ,
    array               $init        = [] ,
    bool                $isArray     = false ,
)
: string
{
    if( empty( $name ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the name of the join attribute not must be null or empty.' ) ;
    }

    $documents = getDocuments( $definition[ AQL::MODEL ] ?? null , $container ) ;
    if( !( $documents instanceof Documents ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the model reference must be an instance of Documents.' ) ;
    }

    $collection = $documents->collection ;
    if( empty( $collection ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the edge collection not must be null or empty.' ) ;
    }

    $edges      = $definition[ Arango::EDGES      ] ?? [] ;
    $joins      = $definition[ Arango::JOINS      ] ?? [] ;
    $key        = $definition[ Arango::KEY        ] ?? Schema::_KEY ;
    $property   = $definition[ Arango::PROPERTY   ] ?? null ; // string or array
    // Fall back on the request-level skin from $init so a join projection
    // can vary with `?skin=...` (sub-fields opt in via Field::SKINS).
    $skin       = $definition[ Arango::SKIN       ] ?? $init[ Arango::SKIN ] ?? null ;
    // Same SKIN_FIELDS resolution as edges — see buildEdgeVariable.
    $fields     = resolveSkinFields( $definition , $skin ) ;
    $varName    = $definition[ Arango::UNIQUE     ] ?? $name ;

    $subVariables = [] ;

    $docJoin = randomKey( AQL::DOC_JOIN ) ;
    $docKey  = key( $name , $docRef ) ;

    if ( $property !== null )
    {
        $docKey = key( $property , $docKey ) ;
    }

    $for = aqlFor([ AQL::DOC_REF => $docJoin , AQL::IN => $collection ]);

    $conditions = [] ;

    if ( isset($definition[ Arango::CONDITIONS ] ) )
    {
        $cond = $definition[ Arango::CONDITIONS ] ;
        if ( is_callable( $cond ) )
        {
            $reflection = new ReflectionFunction( $cond );
            $args       = $reflection->getNumberOfParameters() === 2 ? [ $docJoin , $docRef ] : [ $docJoin ] ;
            $conditions = $cond( ...$args ) ;
        }
        else
        {
            $conditions = $cond ;
        }

        if ( !is_array( $conditions ) )
        {
            throw new UnexpectedValueException
            (
                __METHOD__ . ' expected $conditions to be an array, ' . gettype( $conditions ) . ' given'
            ) ;
        }
    }

    if( $isArray )
    {
        // FOR doc_join IN @@collection
        // FILTER doc_join._key IN ( IS_ARRAY( doc_ref.name ) ? doc_ref.name : [] )
        // RETURN doc_join
        $filter = aqlFilter
        ([
            in
            (
                key( $key , $docJoin ) ,
                betweenParentheses( ternary( isArray( $docKey ) , $docKey , aqlArray() ) ) ,
            )
            ,
            ...$conditions
        ]) ;
    }
    else
    {
        // FOR doc_join IN @@collection
        // FILTER doc_join._key == doc_ref.name
        // RETURN doc_join
        $filter = aqlFilter
        ([
            equal( key( $key  , $docJoin ) , $docKey ) ,
            ...$conditions
        ]) ;
    }

    $fields = $documents->prepareQueryFields( $fields , $skin , $name ) ;
    if( is_array( $fields ) && count( $fields ) > 0 )
    {
        // Definition-level gating: purge the relation markers whose nested
        // definition is denied BEFORE the `LET` walk (buildVariables) and the
        // projection walk (aqlFields), which share this fields array.
        $fields = authorizeRelationFields( $fields , $edges , $joins , $init ) ;

        buildVariables
        (
            $subVariables ,
            $fields ,
            $edges ,
            $joins ,
            $container ,
            $docJoin ,
            $init
        ) ;
        $return = aqlReturn( betweenBraces( aqlFields( $fields , $docJoin , $container , $init ) ) ) ;
    }
    else
    {
        $return = aqlReturn( $docJoin ) ;
    }

    $sort = $isArray ? sortJoinVariable( $definition , $docJoin ) : null ;

    return aqlLet
    (
        $varName ,
        compile( [ $for , $subVariables , $filter , $sort , $return ] ) ,
        true
    ) ;
}