<?php

namespace oihana\arango\models\helpers\edges;

use Exception;
use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use function oihana\arango\db\operations\aqlLet;

/**
 * Builds a single AQL 'LET' subquery string for a specific edge relation.
 *
 * This method generates a complete traversal subquery, enclosed in parentheses,
 * which is assigned to a 'LET' variable. It handles direction, filtering,
 * sorting, and shaping of the results.
 *
 * The traversal body itself is produced by {@see buildEdgeSubquery()}; this
 * wrapper only resolves the `LET` variable name and prefixes the body.
 *
 * Example output:
 * `LET myFriends = ( FOR v, e IN OUTBOUND 'users/123' friends_edge ... RETURN v.name )`
 *
 * @param string|null $name The logical name for this variable (e.g., 'friends', 'comments').
 *                          This is used as the AQL 'LET' variable name.
 *
 * @param array $definition   Configuration array for the traversal. Expected keys:
 * - `AQL::MODEL`: (string) The class name of the Edges model.
 * - `AQL::DIRECTION`: (string|null) Traversal direction (OUTBOUND, INBOUND).
 * - `AQL::UNIQUE`: (string|null) Optional AQL variable name, overrides $name.
 * - `AQL::EDGES`: (array) Further edge definitions for nested queries.
 * - `AQL::JOINS`: (array) Join definitions for the target model.
 * - `AQL::SKIN`: (string|null) A 'skin' name to select specific fields.
 * - `AQL::SORT`: (string|array|null) Sort definition (see getSortEdgeVariableExpression).
 *
 * @param string              $startVertex  The AQL variable name of the starting vertex (default 'doc').
 * @param ?ContainerInterface $container    The DI Container reference.
 * @param array               $init         Optional associative array definitions.
 *
 * @return string The complete AQL 'LET' statement.
 *
 * @throws Exception                   If Traversal direction is invalid.
 * @throws ContainerExceptionInterface If the Edges model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface  If the Edges model cannot be resolved from the container.
 * @throws ReflectionException
 * @throws UnexpectedValueException    If $name is empty, the model is invalid, or the collection is not set.
 */
function buildEdgeVariable
(
    ?string             $name        ,
    array               $definition  = [] ,
    string              $startVertex = AQL::DOC ,
    ?ContainerInterface $container   = null ,
    array               $init        = []
)
: string
{
    $varName = $definition[ Arango::UNIQUE ] ?? $name ;

    return aqlLet( $varName , buildEdgeSubquery( $name , $definition , $startVertex , $container , $init ) ) ;
}
