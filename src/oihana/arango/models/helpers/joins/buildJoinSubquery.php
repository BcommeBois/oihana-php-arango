<?php

namespace oihana\arango\models\helpers\joins;

use Exception;
use ReflectionException;
use ReflectionFunction;
use UnexpectedValueException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\db\helpers\resolveSkinFields;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\arango\db\operators\ternary;
use function oihana\arango\models\helpers\authorizeRelationFields;
use function oihana\arango\models\helpers\authorizeTargetFields;
use function oihana\arango\models\helpers\buildVariables;
use function oihana\arango\models\helpers\getDocuments;
use function oihana\core\strings\betweenBraces;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\randomKey;

/**
 * Builds the inner AQL join sub-query body — everything a join `LET` wraps, WITHOUT the enclosing `LET name = ( … )`.
 *
 * The returned string is the compiled body:
 * ```
 * FOR doc_join IN collection [<nested LETs>] FILTER … [SORT …] RETURN …
 * ```
 * {@see buildJoinVariable()} wraps it into `LET name = ( … )` for a regular
 * join, while {@see buildPolymorphicJoinVariable()} wraps several such bodies
 * into a single `APPEND( ( … ) , ( … ) )` array so the collection can vary
 * with a discriminator field.
 *
 * Extracting this body from {@see buildJoinVariable()} lets a polymorphic join
 * reuse the whole join machinery (filtering, sorting, skinning, nested edges /
 * joins, definition-level gating) per branch. The only addition over the
 * historical logic is `$extraConditions`, a list of ready-made AQL predicates
 * (typically the discriminator guard) prepended to the branch filter.
 *
 * @param string|null            $name       The logical name of the join relation — used to skin the
 *                                            projection and to prefix the generated variable names of
 *                                            nested relations. Also the default parent key path when
 *                                            `$keyPath` is null.
 * @param array                  $definition Configuration array for the join — same keys as
 *                                           {@see buildJoinVariable()} (`AQL::MODEL`, `AQL::FIELDS`,
 *                                           `Arango::KEY`, `Arango::PROPERTY`, `Arango::CONDITIONS`, …).
 * @param string                 $docRef     The AQL variable name of the main document reference.
 * @param ContainerInterface|null $container Optional DI container used to resolve models.
 * @param array                  $init       Optional associative array used for variable initialization.
 * @param bool                   $isArray    If true, the join key is treated as an array of keys (`IN`).
 * @param array                  $extraConditions Ready-made AQL predicate strings prepended to the
 *                                                 branch filter, right after the key match (e.g. the
 *                                                 discriminator guard of a polymorphic join).
 * @param string|null            $keyPath    The parent key path used to match the join, absolute from
 *                                            `$docRef` (e.g. `selector.providerId` → `doc.selector.providerId`).
 *                                            Null falls back on `$name`, keeping the historical
 *                                            "output name = key path" behaviour. Decoupling it from
 *                                            `$name` lets `Arango::SOURCE` anchor the key elsewhere and
 *                                            keeps nested-variable prefixes free of the (possibly dotted)
 *                                            key path. A polymorphic branch passes the shared key path here.
 *
 * @return string The compiled join sub-query body (no `LET`, no enclosing parentheses).
 *
 * @throws Exception                   If a traversal or join cannot be built properly.
 * @throws ContainerExceptionInterface If the Documents model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface  If the Documents model cannot be found in the container.
 * @throws ReflectionException         If a callable conditions closure fails reflection.
 * @throws UnexpectedValueException    If $name is empty, the model is invalid, collection not set,
 *                                     or CONDITIONS does not return an array.
 */
function buildJoinSubquery
(
    ?string             $name            ,
    array               $definition      = [] ,
    string              $docRef          = AQL::DOC ,
    ?ContainerInterface $container        = null ,
    array               $init            = [] ,
    bool                $isArray         = false ,
    array               $extraConditions = [] ,
    ?string             $keyPath         = null ,
)
: string
{
    if( empty( $name ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the name of the join attribute not must be null or empty.' ) ;
    }

    $documents = getDocuments( $definition[ AQL::MODEL ] ?? null , $container ) ;
    if( !( $documents instanceof Documents ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the model reference must be an instance of Documents.' ) ;
    }

    $collection = $documents->collection ;
    if( empty( $collection ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the edge collection not must be null or empty.' ) ;
    }

    $edges    = $definition[ AQL::EDGES      ] ?? [] ;
    $joins    = $definition[ AQL::JOINS      ] ?? [] ;
    $key      = $definition[ AQL::KEY        ] ?? Schema::_KEY ;
    $property = $definition[ AQL::PROPERTY   ] ?? null ; // string or array
    $skin     = $definition[ AQL::SKIN       ] ?? $init[ AQL::SKIN ] ?? null ; // Fall back on the request-level skin from $init so a join projection can vary with `?skin=...` (sub-fields opt in via Field::SKINS).

    // Same SKIN_FIELDS resolution as edges — see buildEdgeVariable.
    $fields = resolveSkinFields( $definition , $skin ) ;

    $subVariables = [] ;

    $docJoin = randomKey( AQL::DOC_JOIN ) ;

    // The key path (racine of the match) is decoupled from $name: $name still
    // prefixes the nested-relation variable names below (prepareQueryFields),
    // while $keyPath — when supplied by Arango::SOURCE or a polymorphic branch —
    // anchors the match elsewhere in the document. Null keeps the historical
    // "output name = key path" behaviour (doc.<name>).
    $docKey  = key( $keyPath ?? $name , $docRef ) ;

    if ( $property !== null )
    {
        $docKey = key( $property , $docKey ) ;
    }

    $for = aqlFor([ AQL::DOC_REF => $docJoin , AQL::IN => $collection ]);

    $conditions = [] ;

    if ( isset($definition[ AQL::CONDITIONS ] ) )
    {
        $cond = $definition[ AQL::CONDITIONS ] ;
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
                __FUNCTION__ . ' expected $conditions to be an array, ' . gettype( $conditions ) . ' given'
            ) ;
        }
    }

    // The discriminator guard (and any other injected predicate) sits right
    // after the key match, before the definition-level CONDITIONS.
    $conditions = [ ...$extraConditions , ...$conditions ] ;

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

    // An ad-hoc AQL::FIELDS on the definition replaces the target's $fields, so
    // re-apply the target model's own Field::REQUIRES (T6): a field masked from
    // reading stays masked through the join.
    $fields = authorizeTargetFields( $fields , $documents , $init ) ;

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

    return compile( [ $for , $subVariables , $filter , $sort , $return ] ) ;
}
