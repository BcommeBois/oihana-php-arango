<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;

use oihana\exceptions\ValidationException;
use org\schema\constants\Schema;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\db\functions\documents\translate;
use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\core\arrays\isAssociative;
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
 *     options   : [ Field::PATH => '/observation/{observation}/workspace/{workspace}/places' ],
 *     init      : [ Arango::ARGS => ['observation' => '15454', 'workspace' => '787878'] ],
 *     container : $container
 * );
 * // Returns:
 * // url:CONCAT('https://base.url/observation/15454/workspace/787878/places','/',doc._key)
 * ```
 *
 * Discriminant routing (`Field::PATHS`):
 *
 * When `Field::PATHS` is provided, the path is resolved **at query time** from a
 * discriminant attribute of the document (default `Schema::ADDITIONAL_TYPE`, overridable
 * via `Field::PROPERTY`) using the AQL `TRANSLATE()` function. `Field::PATH` is then
 * **mandatory** and is used as the fallback route for documents whose discriminant value
 * is not present in the map — emitting it as the third `TRANSLATE()` argument guarantees an
 * unmatched value never leaks the raw discriminant into the URL.
 *
 * ```php
 * echo aqlFieldUrl(
 *     key     : 'url',
 *     options :
 *     [
 *         Field::PATH     => '/thing' ,                                  // fallback (mandatory with PATHS)
 *         Field::PATHS    => [ 'Place' => '/places' , 'Person' => '/people' ] ,
 *         Field::PROPERTY => Schema::ADDITIONAL_TYPE ,                   // optional discriminant
 *     ],
 *     container : $container
 * );
 * // Returns:
 * // url:CONCAT(TRANSLATE(doc.additionalType,{Place:'https://base.url/places',Person:'https://base.url/people'},'https://base.url/thing'),'/',doc._key)
 * ```
 *
 * @param string                  $key       The field key in the parent document (e.g., 'url').
 * @param string                  $doc       The document reference for AQL (default: `AQL::DOC`).
 * @param array                   $options   The field definition. Recognised keys: `Field::PATH` (URL path
 *                                           pattern / fallback route), `Field::PATHS` (discriminant → route map),
 *                                           `Field::PROPERTY` (discriminant attribute, default
 *                                           `Schema::ADDITIONAL_TYPE`) and `Field::NAME` (document key name to
 *                                           append, default `_key`).
 * @param ContainerInterface|null $container Optional DI container to fetch the base URL.
 * @param array                   $init      Optional initialization array. Use `Arango::ARGS` to provide
 *                                           an associative array of placeholder values.
 * @param string|null             $urlKey    Optional container key for the base URL (default: `Arango::BASE_URL`).
 *
 * @return string Returns an AQL snippet mapping the field to a full URL expression, e.g.:
 *                `url:CONCAT('fullUrl','/',doc._key)`.
 *
 * @throws ValidationException           If the discriminant attribute name is unsafe.
 * @throws ContainerExceptionInterface   If fetching the base URL from the container fails.
 * @throws NotFoundExceptionInterface    If the container does not contain the requested base URL.
 * @throws UnsupportedOperationException If the base URL retrieved from the container is not a string, or if
 *                                       `Field::PATHS` is malformed (empty / not an associative map) or used
 *                                       without an explicit `Field::PATH` fallback.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldUrl
(
    string              $key ,
    string              $doc          = AQL::DOC ,
    array               $options      = [] ,
    ?ContainerInterface $container    = null ,
    array               $init         = [] ,
    ?string             $urlKey       = null ,
)
: string
{
    $path     = $options[ Field::PATH     ] ?? null ;
    $paths    = $options[ Field::PATHS    ] ?? null ;
    $property = $options[ Field::PROPERTY ] ?? null ;
    $keyName  = $options[ Field::NAME     ] ?? null ;

    $keyName ??= Schema::_KEY ;
    $urlKey  ??= Arango::BASE_URL ;

    $baseUrl = Char::EMPTY ;
    if ( $urlKey !== Char::EMPTY && $container?->has( $urlKey ) )
    {
        $url = $container->get( $urlKey );
        $baseUrl = is_string( $url ) ? $url : Char::EMPTY ;
    }

    $args = $init[ Arango::ARGS ] ?? null ;

    // Resolve a single path pattern: replace `{param}` placeholders from the request
    // args, then prefix the base URL with slash normalization.
    $resolve = static function ( ?string $value ) use ( $baseUrl , $args ) : string
    {
        $value ??= Char::EMPTY ;
        if ( !empty( $args ) )
        {
            $value = replacePathPlaceholders( $value , $args ) ;
        }
        return joinPaths( $baseUrl , $value ) ;
    } ;

    // Discriminant routing: the path is chosen at query time from a document attribute.
    if ( $paths !== null )
    {
        if ( !is_array( $paths ) || $paths === [] || !isAssociative( $paths ) )
        {
            throw new UnsupportedOperationException( __FUNCTION__ . " failed, Field::PATHS must be a non-empty associative map of '<discriminant value>' => '<route>'." ) ;
        }

        if ( $path === null )
        {
            throw new UnsupportedOperationException( __FUNCTION__ . " failed, Field::PATHS requires an explicit Field::PATH fallback for unmatched discriminant values." ) ;
        }

        $attribute = $property ?? Schema::ADDITIONAL_TYPE ;
        assertAttributeName( $attribute ) ;

        $lookup = array_map( $resolve , $paths ) ;

        // key : CONCAT( TRANSLATE( doc.<attr> , { map } , 'default' ) , '/' , doc._key )
        // The lookup map and the fallback are pre-rendered (object literal / quoted string)
        // because translate() joins its arguments verbatim — it does not format them.
        return keyValue( $key , concat
        ([
            translate( key( $attribute , $doc ) , aqlValue( $lookup ) , aqlValue( $resolve( $path ) ) ) ,
            Char::SLASH ,
            key( $keyName , $doc ) ,
        ])) ;
    }

    // key : CONCAT( 'fullPath' , '/' , doc._key )
    return keyValue( $key , concat
    ([
        $resolve( $path ) ,
        Char::SLASH ,
        key( $keyName , $doc ) ,
    ])) ;
}
