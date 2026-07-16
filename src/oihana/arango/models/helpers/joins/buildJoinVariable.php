<?php

namespace oihana\arango\models\helpers\joins;

use Exception;
use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\enums\Arango;
use oihana\arango\db\enums\AQL;

use function oihana\arango\db\operations\aqlLet;

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
 * The subquery body itself is produced by {@see buildJoinSubquery()}; this
 * wrapper only resolves the `LET` variable name and parenthesizes the body.
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
 * - `Arango::SOURCE` (string|null) Optional absolute key path, read from the main document, that anchors
 *                                  the join match (e.g. `selector.providerId` → `doc.selector.providerId`).
 *                                  Decoupled from the output field name; defaults to `$name`.
 * - `Arango::PROPERTY` (string|array|null) Optional property appended, relative to the key path, to the join key.
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
    $varName = $definition[ Arango::UNIQUE ] ?? $name ;

    // Arango::SOURCE anchors the join key at an absolute path in the document,
    // decoupled from the output field name ($name). Absent → $keyPath stays null
    // and buildJoinSubquery falls back on $name (historical behaviour).
    $keyPath = $definition[ Arango::SOURCE ] ?? null ;

    return aqlLet
    (
        $varName ,
        buildJoinSubquery( $name , $definition , $docRef , $container , $init , $isArray , [] , $keyPath ) ,
        true
    ) ;
}
