<?php

namespace oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use org\schema\constants\Schema;

/**
 * Reconstructs a nested `children[]` tree from a **flat** list of nodes.
 *
 * The flat list is the shape produced by a depth-ranged edge projection
 * (see `buildEdgeVariable()` with `AQL::MAX_DEPTH`): every element is an
 * associative row that knows its parent — either through the `AQL::WITH_PATH`
 * injected `_parent` key (default), or through a parent field the document
 * already stores (pass its name as `$parentSource`, e.g. `'broader'`).
 *
 * Each returned node gains a `$childrenKey` (default `children`) holding its
 * direct children, recursively.
 *
 * ### Roots
 * - When `$rootKey` is given, the roots are the nodes whose parent equals it
 *   (the start vertex of the traversal — its own key). This is what
 *   {@see buildTreeAlter()} passes (the document's `_key`).
 * - When `$rootKey` is `null`, a node is a root when its parent key is **absent**
 *   from the list (a depth-1 node points at the — unlisted — start vertex).
 *
 * ### Robustness
 * The reconstruction is O(n) and **cycle-safe**: a node already seen on the
 * current branch is never descended into again (pathological self-referential
 * data cannot cause infinite recursion). A node whose parent is missing simply
 * becomes a root. Each node is expected to have a **single** parent — with
 * `AQL::WITH_PATH` this is guaranteed by the traversal's global vertex
 * uniqueness. Rows that are not associative arrays are ignored.
 *
 * @param array       $flat         The flat list of nodes (associative arrays).
 * @param string      $parentSource The key holding each node's parent key (default `AQL::_PARENT` = `_parent`).
 * @param string|null $rootKey      The start-vertex key; `null` infers the roots.
 * @param string      $childrenKey  The key under which children are nested (default `children`).
 * @param string      $keyField     The identity key of a node (default `Schema::_KEY` = `_key`).
 *
 * @return array The list of root nodes, each with a nested `$childrenKey`.
 *
 * @example
 * ```php
 * $flat =
 * [
 *     [ '_key' => 'mammals' , '_parent' => 'animals' ] ,
 *     [ '_key' => 'dogs'    , '_parent' => 'mammals' ] ,
 *     [ '_key' => 'cats'    , '_parent' => 'mammals' ] ,
 * ];
 * $tree = buildTree( $flat , rootKey: 'animals' ) ;
 * // [ [ '_key'=>'mammals', '_parent'=>'animals', 'children'=>[
 * //       [ '_key'=>'dogs', …, 'children'=>[] ], [ '_key'=>'cats', …, 'children'=>[] ] ] ] ]
 * ```
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz
 * @since 1.5.0
 */
function buildTree
(
    array   $flat ,
    string  $parentSource = AQL::_PARENT ,
    ?string $rootKey      = null ,
    string  $childrenKey  = AQL::CHILDREN ,
    string  $keyField     = Schema::_KEY
)
: array
{
    if ( $flat === [] )
    {
        return [] ;
    }

    $byParent = [] ; // parentKey (string) => list of node rows
    $known    = [] ; // set of node keys present in $flat

    foreach ( $flat as $node )
    {
        if ( !is_array( $node ) )
        {
            continue ;
        }

        $byParent[ (string) ( $node[ $parentSource ] ?? null ) ][] = $node ;

        if ( isset( $node[ $keyField ] ) )
        {
            $known[ (string) $node[ $keyField ] ] = true ;
        }
    }

    $attach = function ( string $parentKey , array $ancestors ) use ( &$attach , &$byParent , $keyField , $childrenKey ) : array
    {
        $out = [] ;
        foreach ( $byParent[ $parentKey ] ?? [] as $node )
        {
            $key = isset( $node[ $keyField ] ) ? (string) $node[ $keyField ] : null ;

            // Cycle guard: never descend into a node already on the current branch.
            $node[ $childrenKey ] = ( $key !== null && !isset( $ancestors[ $key ] ) )
                                  ? $attach( $key , $ancestors + [ $key => true ] )
                                  : [] ;

            $out[] = $node ;
        }
        return $out ;
    } ;

    if ( $rootKey !== null )
    {
        return $attach( $rootKey , [ $rootKey => true ] ) ;
    }

    // No explicit root: a node is a root when its parent is absent from the list.
    $roots = [] ;
    foreach ( $byParent as $parentKey => $nodes )
    {
        if ( $parentKey !== '' && isset( $known[ $parentKey ] ) )
        {
            continue ; // has an in-list parent → not a root
        }

        foreach ( $nodes as $node )
        {
            $key = isset( $node[ $keyField ] ) ? (string) $node[ $keyField ] : null ;
            $node[ $childrenKey ] = $key !== null ? $attach( $key , [ $key => true ] ) : [] ;
            $roots[] = $node ;
        }
    }

    return $roots ;
}
