<?php

namespace oihana\arango\models\helpers;

use DI\Container;

use oihana\arango\db\enums\AQL;
use org\schema\constants\Schema;

/**
 * Builds an {@see \oihana\models\enums\Alter::MAP} callback that reshapes a flat
 * hierarchy projection into a nested `children[]` tree via {@see buildTree()}.
 *
 * Declare it on the field that carries the depth-ranged projection:
 *
 * ```php
 * use oihana\arango\models\enums\Alter ;
 * use function oihana\arango\models\helpers\buildTreeAlter ;
 *
 * AQL::FIELDS =>
 * [
 *     Prop::DESCENDANTS =>
 *     [
 *         Field::FILTER => Filter::EDGES ,
 *         Field::ALTERS => [ [ Alter::MAP , buildTreeAlter() ] ] ,
 *     ],
 * ],
 * AQL::EDGES =>
 * [
 *     Prop::DESCENDANTS =>
 *     [
 *         AQL::MODEL     => 'concept_links' ,
 *         AQL::DIRECTION => Traversal::OUTBOUND ,
 *         AQL::MAX_DEPTH => 5 ,
 *         AQL::WITH_PATH => true , // provides the `_parent` used to rebuild the tree
 *     ],
 * ],
 * ```
 *
 * At map time the callback:
 * - leaves the value untouched when it is not an array (an absent/scalar relation),
 * - reads the **root** from the enclosing document's `$keyField` (the traversal
 *   start vertex — its own `_key`),
 * - returns the nested tree built by {@see buildTree()}.
 *
 * The parent link source defaults to `AQL::_PARENT` (`_parent`, injected by
 * `AQL::WITH_PATH`); pass a stored field name instead (e.g. `'broader'`) when the
 * document already carries its parent.
 *
 * @param string $parentSource The key holding each node's parent key (default `AQL::_PARENT`).
 * @param string $childrenKey  The key under which children are nested (default `children`).
 * @param string $keyField     The identity key of a node and of the enclosing document (default `Schema::_KEY`).
 *
 * @return callable A callback matching the `Alter::MAP` signature
 *                  `( array|object $document, ?Container $container, string $key, mixed $value, array $params, array $context ): mixed`.
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz
 * @since 1.5.0
 */
function buildTreeAlter
(
    string $parentSource = AQL::_PARENT ,
    string $childrenKey  = AQL::CHILDREN ,
    string $keyField     = Schema::_KEY
)
: callable
{
    return function
    (
        array|object $document ,
        ?Container    $container = null ,
        string        $key       = '' ,
        mixed         $value     = null ,
        array         $params    = [] ,
        array         $context   = []
    )
    use ( $parentSource , $childrenKey , $keyField )
    : mixed
    {
        if ( !is_array( $value ) )
        {
            return $value ; // nothing to reshape (absent relation or scalar)
        }

        $rootKey = is_array( $document )
                 ? ( $document[ $keyField ] ?? null )
                 : ( $document->{ $keyField } ?? null ) ;

        return buildTree
        (
            $value ,
            $parentSource ,
            $rootKey !== null ? (string) $rootKey : null ,
            $childrenKey ,
            $keyField
        ) ;
    } ;
}
