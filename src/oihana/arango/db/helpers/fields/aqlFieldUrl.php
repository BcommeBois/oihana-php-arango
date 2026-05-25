<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\constants\Schema;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\db\functions\strings\concat;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;
use function oihana\core\strings\replacePathPlaceholders;
use function oihana\files\path\joinPaths;

/**
 * Generates an AQL expression for a document URL field with optional dynamic placeholders.
 *
 * This function constructs a full URL for a document field by optionally combining:
 * 1. A base URL retrieved from a DI container (if `$container` and `$urlKey` are provided).
 * 2. A path pattern (`$path`) that can contain placeholders in the form `{param}` or `{param:regex}`.
 * 3. The document key (defaulting to `_key` or a custom `$keyName`).
 *
 * Placeholders in the path will be replaced by values provided in the `$init` array under
 * the key `Arango::ARGS`. If a placeholder has no corresponding value in `$init`, it remains
 * unchanged in the resulting URL.
 *
 * Example URL pattern:
 * ```php
 * '/observation/{observation:[A-Za-z0-9_]+}/workspace/{workspace}/places'
 * ```
 *
 * Example usage:
 * ```php
 * echo aqlFieldUrl(
 *     key       : 'url',
 *     path      : '/observation/{observation}/workspace/{workspace}/places',
 *     init      : [ Arango::ARGS => ['observation' => '15454', 'workspace' => '787878'] ],
 *     container : $container
 * );
 * // Returns:
 * // url:CONCAT('https://base.url/observation/15454/workspace/787878/places','/',doc._key)
 * ```
 *
 * @param string                  $key       The field key in the parent document (e.g., 'url').
 * @param string                  $doc       The document reference for AQL (default: `AQL::DOC`).
 * @param string|null             $path      Optional URL path pattern containing placeholders.
 * @param string|null             $keyName   The document key name to append (default: '_key').
 * @param ContainerInterface|null $container Optional DI container to fetch the base URL.
 * @param array                   $init      Optional initialization array. Use `Arango::ARGS` to provide
 *                                           an associative array of placeholder values.
 * @param string|null             $urlKey    Optional container key for the base URL (default: `Arango::BASE_URL`).
 *
 * @return string Returns an AQL snippet mapping the field to a full URL expression, e.g.:
 *                `url:CONCAT('fullUrl','/',doc._key)`.
 *
 * @throws ContainerExceptionInterface   If fetching the base URL from the container fails.
 * @throws NotFoundExceptionInterface    If the container does not contain the requested base URL.
 * @throws UnsupportedOperationException If the base URL retrieved from the container is not a string.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldUrl
(
    string              $key ,
    string              $doc          = AQL::DOC ,
    ?string             $path         = null ,
    ?string             $keyName      = null ,
    ?ContainerInterface $container    = null ,
    array               $init         = [] ,
    ?string             $urlKey       = null ,
)
: string
{
    $keyName ??= Schema::_KEY ;
    $urlKey  ??= Arango::BASE_URL ;

    $baseUrl = Char::EMPTY ;
    if ( $urlKey !== Char::EMPTY && $container?->has( $urlKey ) )
    {
        $url = $container->get( $urlKey );
        $baseUrl = is_string( $url ) ? $url : Char::EMPTY ;
    }

    $fullPath = $path ?? Char::EMPTY ;

    $args = $init[ Arango::ARGS ] ?? null ;
    if ( !empty( $args ) )
    {
        $fullPath = replacePathPlaceholders( $fullPath , $args ) ;
    }

    $fullPath = joinPaths( $baseUrl , $fullPath ) ;

    // key : CONCAT( 'fullPath' , '/' , doc._key )
    return keyValue( $key , concat
    ([
        $fullPath ,
        Char::SLASH ,
        key( $keyName , $doc ) ,
    ])) ;
}
