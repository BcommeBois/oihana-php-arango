<?php

namespace oihana\arango\clients\analyzer ;

use oihana\enums\Boolean ;
use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;

/**
 * Operations scoped to a single ArangoSearch analyzer on the server.
 *
 * Instances are obtained through {@see Database::analyzer()} or
 * {@see Database::createAnalyzer()}. The analyzer name is fixed at
 * construction time and is interpolated into the
 * `/_api/analyzer/{name}` routes by the helpers below.
 *
 * The class covers the analyzer lifecycle (`create` / `get` /
 * `drop` / `exists`) — there is no `update` route on the
 * analyzer API, so changes always go through a drop + create cycle.
 *
 * Example:
 * ```php
 * $analyzer = $db->createAnalyzer
 * (
 *     'text_en' ,
 *     new TextAnalyzer( locale : 'en' ) ,
 *     [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
 * ) ;
 *
 * if ( $analyzer->exists() )
 * {
 *     $analyzer->drop() ;
 * }
 * ```
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/analyzers/
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class Analyzer
{
    /**
     * @param Database $database Parent database (provides the shared HTTP transport).
     * @param string   $name     Name of the target analyzer on the server.
     */
    public function __construct( public Database $database , public string $name ) {}

    /**
     * Query parameter that forces the drop of an analyzer currently
     * in use by an arangosearch view or an inverted index
     * (`DELETE /_api/analyzer/{name}?force=true`).
     */
    public const string FORCE_PARAM = 'force' ;

    /**
     * Creates this analyzer on the server with the given options.
     *
     * Wraps `POST /_api/analyzer`. The analyzer name is taken from
     * {@see $name}. Features (`frequency` / `norm` / `position` /
     * `offset`, listed as entries of
     * {@see \oihana\arango\clients\analyzer\enums\AnalyzerFeature})
     * are optional — when omitted, the analyzer is created without
     * any feature, which is enough for plain `STARTS_WITH()` /
     * exact-match queries but not for `BM25()` / `TFIDF()` /
     * `PHRASE()`.
     *
     * @param AnalyzerOptions    $options  Type-specific options.
     * @param array<int, string> $features Optional list of analyzer features (entries of {@see \oihana\arango\clients\analyzer\enums\AnalyzerFeature}).
     *
     * @return array<string, mixed> Raw analyzer description as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function create( AnalyzerOptions $options , array $features = [] ) : array
    {
        $body = array_merge
        (
            [ AnalyzerField::NAME => $this->name ] ,
            $options->toArray() ,
        ) ;

        if ( $features !== [] )
        {
            $body[ AnalyzerField::FEATURES ] = array_values( $features ) ;
        }

        $response = $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::ANALYZER ,
            body   : $body ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Drops this analyzer from the server.
     *
     * Wraps `DELETE /_api/analyzer/{name}`. When `$force` is true,
     * the server allows the drop even when the analyzer is currently
     * referenced by an arangosearch view or an inverted index (the
     * reference is left dangling — use with care).
     *
     * @param bool $force Whether to force the drop even when the analyzer is in use.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function drop( bool $force = false ) : void
    {
        $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : $this->path() ,
            query  : $force ? [ self::FORCE_PARAM => Boolean::TRUE ] : [] ,
        ) ;
    }

    /**
     * Returns true when this analyzer currently exists on the server.
     *
     * Treats a 404 as a clean "missing" and rethrows everything else.
     *
     * @return bool
     *
     * @throws ArangoException When the request fails for a reason other than a 404.
     */
    public function exists() : bool
    {
        try
        {
            $this->database->request( method : HttpMethod::GET , path : $this->path() ) ;
            return true ;
        }
        catch ( HttpException $e )
        {
            if ( $e->getCode() === 404 )
            {
                return false ;
            }
            throw $e ;
        }
    }

    /**
     * Returns the raw server-side description of this analyzer
     * (`GET /_api/analyzer/{name}`).
     *
     * Carries `name` / `type` / `features` / `properties`. The
     * `properties` shape depends on the analyzer type — see
     * {@see TextAnalyzer}, {@see NormAnalyzer}, {@see StemAnalyzer}
     * and {@see IdentityAnalyzer}.
     *
     * @return array<string, mixed>
     *
     * @throws ArangoException When the request fails.
     */
    public function get() : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->path() ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Returns the analyzer name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Builds the `/_api/analyzer/{name}` path with the analyzer
     * name URL-encoded.
     *
     * @return string
     */
    private function path() : string
    {
        return ArangoRoute::ANALYZER . '/' . rawurlencode( $this->name ) ;
    }
}
