<?php

namespace oihana\arango\db\operations;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\options\ForOptions;
use oihana\enums\Char;

use function oihana\core\strings\compile;

/**
 * Builds an ArangoDB AQL `FOR` clause, optionally including `SEARCH` and `OPTIONS` segments.
 *
 * The generated query follows this canonical form:
 * ```
 * FOR <variableName> IN <expression> [SEARCH <searchExpression>] [OPTIONS { ... }]
 * ```
 *
 * This function simplifies the construction of AQL loops by automatically
 * compiling the parts (`IN`, `SEARCH`, `OPTIONS`) using helper functions such as
 * {@see aqlSearch()} and {@see aqlOptions()}.
 *
 * ### Supported `$init` keys
 *
 * | Key | Type | Description |
 * |-----|------|-------------|
 * | `AQL::DOC_REF` | `string|null` | Name of the iteration variable (e.g. `"doc"`) |
 * | `AQL::IN` | `string|null` | The collection name or expression to iterate over (e.g. `"users"`, `"@@collection"`, or a subquery) |
 * | `AQL::SEARCH` | `string|null` | Optional search expression to filter results (requires ArangoSearch view) |
 * | `AQL::OPTIONS` | `array|object|string|null` | Options controlling the behavior of the FOR operation (hydrated into {@see ForOptions}) |
 *
 * ### Example: basic usage
 * ```php
 * echo aqlFor([
 *     AQL::DOC_REF => 'doc',
 *     AQL::IN      => 'users'
 * ]);
 * // → "FOR doc IN users"
 * ```
 *
 * ### Example: with SEARCH and OPTIONS
 * ```php
 * echo aqlFor
 * ([
 *     AQL::DOC_REF  => 'u',
 *     AQL::IN       => 'searchUsers',
 *     AQL::SEARCH   => 'u.active == true',
 *     AQL::OPTIONS  =>
 *     [
 *         'indexHint'       => 'byActive',
 *         'forceIndexHint'  => true,
 *         'disableIndex'    => false,
 *         'useCache'        => true,
 *         'lookahead'       => 5
 *     ]
 * ]);
 * // → "FOR u IN searchUsers SEARCH u.active == true OPTIONS {\"indexHint\":\"byActive\",\"forceIndexHint\":true,\"disableIndex\":false,\"useCache\":true,\"lookahead\":5}"
 * ```
 *
 * ### Example: using a `ForOptions` schema object
 * ```php
 * use oihana\arango\db\options\ForOptions;
 *
 * $opts = new ForOptions([
 *     'useCache'  => false,
 *     'lookahead' => 3
 * ]);
 *
 * echo aqlFor
 * `([
 *     AQL::DOC_REF => 'd',
 *     AQL::IN      => 'documents',
 *     AQL::OPTIONS => $opts
 * ]);
 * // → "FOR d IN documents OPTIONS {\"useCache\":false,\"lookahead\":3}"
 * ```
 *
 * @param array $init
 *     An associative array defining the `FOR` clause elements.
 *     Example structure:
 *     ```php
 *     [
 *         AQL::DOC_REF => 'doc',
 *         AQL::IN      => 'users',
 *         AQL::SEARCH  => 'doc.age > 30',
 *         AQL::OPTIONS => [ 'useCache' => true ]
 *     ]
 *     ```
 *
 * @return string
 *     The complete AQL `FOR` clause, or an empty string if no valid input is provided.
 *
 * @throws ReflectionException
 *     If hydration of options into {@see ForOptions} fails.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/for
 * @see aqlSearch()
 * @see aqlOptions()
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlFor( array $init = [] ) : string
{
    $docRef   = $init[ AQL::DOC_REF ] ?? AQL::DOC ;
    $in       = compile($init[ AQL::IN ] ?? null ) ;
    $inClause = $in !== Char::EMPTY ? Comparator::IN . Char::SPACE . $in : Char::EMPTY ;
    return compile
    ([
        Operation::FOR ,
        $docRef ,
        $inClause ,
        aqlSearch  ( $init ) ,
        aqlOptions ( $init , ForOptions::class )
    ]);
}