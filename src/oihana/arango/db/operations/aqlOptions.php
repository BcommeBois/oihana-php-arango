<?php

namespace oihana\arango\db\operations;

use JsonSerializable;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\options\QueryOptions;
use oihana\enums\Char;

use function oihana\core\arrays\clean;
use function oihana\core\arrays\isAssociative;

/**
 * Builds the AQL `OPTIONS` clause from the provided options.
 *
 * If `$schema` is provided, the method attempts to hydrate the options array into
 * an instance of the specified schema class, provided it exists.
 *
 * Supported input types for `$init[AQL::OPTIONS]`:
 * - **Associative array** → converted to JSON after cleaning.
 * - **Object implementing JsonSerializable** → serialized via `jsonSerialize()`.
 * - **Generic object** → cast to array and encoded as JSON if associative.
 * - **Pre-encoded JSON string** → used directly.
 *
 * If the resulting options are valid, the method returns a properly formatted
 * `OPTIONS { ... }` clause. Otherwise, it returns an empty string.
 *
 * @param array       $init   Initial options array. If it contains the key `AQL::OPTIONS`,
 *                            its value will be processed. If absent, the method returns an empty string.
 * @param string|null $schema Optional fully-qualified class name of a schema to hydrate options into.
 *
 * @return string The generated AQL `OPTIONS` clause, or an empty string if no valid options are provided.
 *
 * @throws ReflectionException If hydration fails due to reflection issues.
 *
 * @example Basic usage with array:
 * ```php
 * options([AQL::OPTIONS => ["fullCount" => true]]) ; // → "OPTIONS {\"fullCount\":true}"
 * ```
 *
 * @example Usage with schema hydration:
 * ```php
 * options
 * (
 *     [ AQL::OPTIONS => ["fullCount" => true, "batchSize" => 500 ] ],
 *     QueryOptions::class
 * );
 * ```
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlOptions
(
      array $init = [] ,
    ?string $schema = null
)
: string
{
    $options = $init[ AQL::OPTIONS ] ?? null ;

    if ( $options === null )
    {
        return Char::EMPTY ;
    }


    if( isset( $schema ) && is_array( $options ) && class_exists( $schema ) && is_a( $schema ,  QueryOptions::class , true ) )
    {
        $options = new $schema( $options ) ;
    }

    if( $options instanceof JsonSerializable )
    {
        $options = $options->jsonSerialize() ;
    }

    if( is_object( $options ) )
    {
        $options = (array) $options ;
    }

    if( is_array( $options ) && isAssociative( $options ) )
    {
        $options = json_encode( clean( $options ) ) ;
    }

    if( is_string( $options ) && $options != Char::EMPTY )
    {
        return Clause::OPTIONS . Char::SPACE . $options ;
    }

    return Char::EMPTY ;
}