<?php

namespace oihana\arango\clients\view ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\view\enums\ViewField ;
use oihana\arango\clients\view\enums\ViewType ;

/**
 * Operations scoped to a single ArangoSearch view on the server.
 *
 * Instances are obtained through {@see Database::view()} or
 * {@see Database::createView()}. The view name is fixed at
 * construction time and is interpolated into the
 * `/_api/view/{name}[/properties]` routes by the helpers below.
 *
 * The class covers the full lifecycle of a view: creation,
 * existence probe, raw description, full properties read,
 * partial update (PATCH — additive on `links`), total replace
 * (PUT — wipes everything not in the payload), and drop. The
 * `rename` operation is intentionally not exposed in V1 (parity
 * with {@see \oihana\arango\clients\analyzer\Analyzer} which has
 * no rename either, and the operation is not supported on cluster
 * deployments).
 *
 * Both view types are covered: `arangosearch` (the view owns its
 * inverted index through `links`, via {@see create()}) and
 * `search-alias` (a thin alias over per-collection `inverted`
 * indexes, via {@see createSearchAlias()}).
 *
 * Example:
 * ```php
 * $articles = $db->collection( 'articles' ) ;
 * $articles->create() ;
 *
 * $textEn = $db->createAnalyzer( 'text_en' , new TextAnalyzer( locale : 'en' ) , [
 *     AnalyzerFeature::FREQUENCY ,
 *     AnalyzerFeature::POSITION  ,
 *     AnalyzerFeature::NORM      ,
 * ] ) ;
 *
 * $view = $db->createView
 * (
 *     'articles_view' ,
 *     links :
 *     [
 *         'articles' => new ArangoSearchLink
 *         (
 *             fields :
 *             [
 *                 'title' => new ArangoSearchLink( analyzers : [ 'text_en' ] ) ,
 *                 'body'  => new ArangoSearchLink( analyzers : [ 'text_en' ] ) ,
 *             ] ,
 *         ) ,
 *     ] ,
 * ) ;
 *
 * $cursor = $db->query
 * (
 *     aql( 'FOR doc IN articles_view SEARCH ANALYZER(doc.title IN TOKENS(?, ?), ?) RETURN doc' ,
 *          'hello' , 'text_en' , 'text_en' ) ,
 * ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/views/arangosearch-views/
 *
 * @package oihana\arango\clients\view
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class View
{
    /**
     * @param Database $database Parent database (provides the shared HTTP transport).
     * @param string   $name     Name of the target view on the server.
     */
    public function __construct( public Database $database , public string $name ) {}

    /**
     * Sub-route exposing the per-view configuration
     * (`/_api/view/{name}/properties`).
     */
    private const string PROPERTIES_SUB_ROUTE = '/properties' ;

    /**
     * Creates this view on the server.
     *
     * Wraps `POST /_api/view`. The view name and type are taken
     * from {@see $name} and forced to
     * {@see ViewType::ARANGOSEARCH} — for a `search-alias` view use
     * {@see createSearchAlias()} instead.
     *
     * `$links` is the per-collection link map: keys are collection
     * names, values are {@see ArangoSearchLink} instances describing
     * how each field of the collection is indexed. Plain arrays are
     * accepted as well — useful when round-tripping a server-side
     * description through `create()`.
     *
     * `$options` is forwarded verbatim as the rest of the create
     * body. Recognised keys include `cleanupIntervalStep`,
     * `consolidationIntervalMsec`, `commitIntervalMsec`,
     * `consolidationPolicy`, `writebufferIdle`,
     * `writebufferActive`, `writebufferSizeMax`, `primarySort`,
     * `storedValues`.
     *
     * @param array<string, ArangoSearchLink|array<string, mixed>> $links   Per-collection link map.
     * @param array<string, mixed>                                 $options Extra arangosearch options forwarded verbatim.
     *
     * @return array<string, mixed> Raw view description as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function create( array $links = [] , array $options = [] ) : array
    {
        $body = array_merge
        (
            $options ,
            [
                ViewField::NAME => $this->name ,
                ViewField::TYPE => ViewType::ARANGOSEARCH ,
            ] ,
        ) ;

        if ( $links !== [] )
        {
            $body[ ViewField::LINKS ] = $this->normaliseLinks( $links ) ;
        }

        $response = $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::VIEW ,
            body   : $body ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Creates this view on the server as a `search-alias` view.
     *
     * Wraps `POST /_api/view` with the `search-alias` type. Unlike an
     * `arangosearch` view (which owns its inverted index through `links`), a
     * search-alias view is a thin alias over one `inverted` index per
     * collection, referenced through `$indexes`.
     *
     * `$indexes` is the server-ready list of `{collection, index}` entries
     * (e.g. the output of
     * {@see \oihana\arango\db\options\views\SearchAliasView::getIndexes()}).
     * `$options` is forwarded verbatim as the rest of the create body.
     *
     * @param array<int, array{collection:string, index:string}> $indexes The `{collection, index}` entries.
     * @param array<string, mixed>                                $options Extra options forwarded verbatim.
     *
     * @return array<string, mixed> Raw view description as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function createSearchAlias( array $indexes = [] , array $options = [] ) : array
    {
        $body = array_merge
        (
            $options ,
            [
                ViewField::NAME => $this->name ,
                ViewField::TYPE => ViewType::SEARCH_ALIAS ,
            ] ,
        ) ;

        if ( $indexes !== [] )
        {
            $body[ ViewField::INDEXES ] = array_values( $indexes ) ;
        }

        $response = $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::VIEW ,
            body   : $body ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Drops this view from the server.
     *
     * Wraps `DELETE /_api/view/{name}`. Underlying source
     * collections are NOT touched — only the view metadata and
     * its inverted index are removed.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function drop() : void
    {
        $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : $this->path() ,
        ) ;
    }

    /**
     * Returns true when this view currently exists on the server.
     *
     * Treats a 404 as a clean "missing" and rethrows everything
     * else.
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
     * Returns the raw server-side description of this view
     * (`GET /_api/view/{name}`).
     *
     * Carries `type` / `id` / `globallyUniqueId` / `name` only —
     * call {@see properties()} for the full configuration
     * (links, consolidation, writebuffer, …).
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
     * Returns the view name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Returns the full per-view configuration
     * (`GET /_api/view/{name}/properties`).
     *
     * Includes every field exposed by the arangosearch type:
     * `links`, `cleanupIntervalStep`, `consolidationIntervalMsec`,
     * `commitIntervalMsec`, `consolidationPolicy`,
     * `writebufferIdle`, `writebufferActive`,
     * `writebufferSizeMax`, `primarySort`, `storedValues`, plus
     * the four top-level fields ({@see get()}).
     *
     * @return array<string, mixed>
     *
     * @throws ArangoException When the request fails.
     */
    public function properties() : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->path() . self::PROPERTIES_SUB_ROUTE ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Replaces the per-view configuration in full
     * (`PUT /_api/view/{name}/properties`).
     *
     * Every field absent from the payload reverts to the server's
     * default — in particular, sending `links: []` wipes every
     * existing link. Use {@see updateProperties()} for an additive
     * merge.
     *
     * `$properties['links']` may carry {@see ArangoSearchLink}
     * value objects, plain arrays, or a mix — they are normalised
     * recursively before the request leaves.
     *
     * @param array<string, mixed> $properties New configuration.
     *
     * @return array<string, mixed> Raw properties payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function replaceProperties( array $properties = [] ) : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::PUT ,
            path   : $this->path() . self::PROPERTIES_SUB_ROUTE ,
            body   : $this->normalisePropertiesBody( $properties ) ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Merges new fields into the per-view configuration
     * (`PATCH /_api/view/{name}/properties`).
     *
     * The merge is additive on `links`: sending
     * `links: { collA: {...} }` updates `collA` but leaves any
     * other linked collection untouched. To drop links, use
     * {@see replaceProperties()} with an empty `links` map.
     *
     * `$properties['links']` may carry {@see ArangoSearchLink}
     * value objects, plain arrays, or a mix — they are normalised
     * recursively before the request leaves.
     *
     * @param array<string, mixed> $properties Partial configuration.
     *
     * @return array<string, mixed> Raw properties payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function updateProperties( array $properties = [] ) : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::PATCH ,
            path   : $this->path() . self::PROPERTIES_SUB_ROUTE ,
            body   : $this->normalisePropertiesBody( $properties ) ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Walks a `links` map and turns every {@see ArangoSearchLink}
     * value object into its array shape, leaving plain arrays
     * untouched.
     *
     * @param array<string, ArangoSearchLink|array<string, mixed>> $links
     *
     * @return array<string, array<string, mixed>>
     */
    private function normaliseLinks( array $links ) : array
    {
        $normalized = [] ;

        foreach ( $links as $collection => $link )
        {
            $link = $link instanceof ArangoSearchLink ? $link->toArray() : $link ;
            // A `null` link is the PATCH idiom to drop a collection from the
            // view (used to force an inverted-index rebuild) — pass it through.
            $normalized[ $collection ] = is_array( $link ) ? $this->normaliseLinkNode( $link ) : $link ;
        }

        return $normalized ;
    }

    /**
     * Recursively shapes a link node for the wire: descends the `fields` map
     * and turns every empty node into an empty JSON **object**.
     *
     * A link node is always a JSON object server-side, but `json_encode([])`
     * emits `[]`, which the server rejects as an invalid link definition. A
     * field whose Analyzer equals the link default is declared as an empty
     * node (see {@see \oihana\arango\models\traits\aql\SearchTrait::buildViewLink()}) —
     * it must reach the server as `{}` to be indexed with the link defaults.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>|object The node, with empty entries cast to objects.
     */
    private function normaliseLinkNode( array $node ) : array|object
    {
        if ( isset( $node[ ViewField::FIELDS ] ) && is_array( $node[ ViewField::FIELDS ] ) )
        {
            $fields = [] ;
            foreach ( $node[ ViewField::FIELDS ] as $name => $child )
            {
                $child = $child instanceof ArangoSearchLink ? $child->toArray() : $child ;
                $fields[ $name ] = is_array( $child ) ? $this->normaliseLinkNode( $child ) : $child ;
            }
            $node[ ViewField::FIELDS ] = $fields ;
        }

        return $node === [] ? (object) [] : $node ;
    }

    /**
     * Applies {@see normaliseLinks()} to a properties body when
     * the `links` key is present. Pure function — leaves the
     * other keys untouched and never mutates the input.
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function normalisePropertiesBody( array $properties ) : array
    {
        if ( isset( $properties[ ViewField::LINKS ] ) && is_array( $properties[ ViewField::LINKS ] ) )
        {
            $properties[ ViewField::LINKS ] = $this->normaliseLinks( $properties[ ViewField::LINKS ] ) ;
        }

        return $properties ;
    }

    /**
     * Builds the `/_api/view/{name}` path with the view name
     * URL-encoded.
     *
     * @return string
     */
    private function path() : string
    {
        return ArangoRoute::VIEW . '/' . rawurlencode( $this->name ) ;
    }
}
