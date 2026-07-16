<?php

namespace tests\oihana\arango\integration;

use Devium\Toml\TomlError;
use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use PHPUnit\Framework\Attributes\Group;

use ReflectionException;
use Throwable;

use function oihana\arango\models\helpers\buildVariables;
use function oihana\init\initConfig;

/**
 * Live validation of `Arango::SOURCE` — a regular relation reading its anchor at
 * an absolute path in the document, decoupled from its output field name.
 *
 * Neutral graph:
 *
 * ```
 * offers/o1 { selector: { providerId: "p1", providerRef: "providers/p1" } }
 *
 * offers/o1.selector.providerId  --(join)-------------> providers/p1     (foreign key value)
 * offers/o1.selector.providerRef --(edge start vertex)-> providers/p1 --[supplied_by]--> suppliers/sup1
 * ```
 *
 * The real {@see buildVariables()} dispatch is driven against a seeded, disposable
 * database. A correct result proves the generated `FILTER doc_join._key ==
 * doc.selector.providerId` (join) and `FOR v,e IN OUTBOUND doc.selector.providerRef
 * …` (edge) — a shape the unit suite (frozen AQL string only) cannot execute —
 * actually parse AND anchor at the right place on a real server. Each case pairs
 * with a negative control: without `SOURCE`, the name-derived anchor (`doc.provider`
 * / a traversal from `doc`) resolves to nothing.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class SourceAnchorIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_source_anchor_it' ;

    private const string OFFERS      = 'offers' ;
    private const string PROVIDERS   = 'providers' ;
    private const string SUPPLIERS   = 'suppliers' ;
    private const string SUPPLIED_BY = 'supplied_by' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::OFFERS    )->create() ;
        $db->collection( self::PROVIDERS )->create() ;
        $db->collection( self::SUPPLIERS )->create() ;
        $db->edgeCollection( self::SUPPLIED_BY )->create() ;

        $db->collection( self::PROVIDERS )->insert( [ '_key' => 'p1'   , 'name' => 'ACME Provider' ] ) ;
        $db->collection( self::SUPPLIERS )->insert( [ '_key' => 'sup1' , 'name' => 'Global Supply' ] ) ;

        // The provider id lives under `selector`, NOT under a `provider` field:
        // a name-derived anchor (doc.provider) would find nothing. providerId is a
        // bare _key (join), providerRef is the full _id (edge start vertex).
        $db->collection( self::OFFERS )->insert(
        [
            '_key'     => 'o1' ,
            'selector' => [ 'providerId' => 'p1' , 'providerRef' => 'providers/p1' ] ,
        ]) ;

        $db->edgeCollection( self::SUPPLIED_BY )->insert( [ '_from' => 'providers/p1' , '_to' => 'suppliers/sup1' ] ) ;
    }

    // ---- join ------------------------------------------------------------

    public function testJoinSourceResolvesTheForeignKeyFromAnAbsolutePath() :void
    {
        $this->assertSame
        (
            [ '_key' => 'p1' , 'name' => 'ACME Provider' ] ,
            $this->resolveJoin( [
                AQL::MODEL     => $this->providerModel() ,
                Arango::SOURCE => 'selector.providerId' ,
                AQL::FIELDS    => [ '_key' => [] , 'name' => [] ] ,
            ] )
        ) ;
    }

    public function testJoinWithoutSourceFindsNothingAtTheNameDerivedPath() :void
    {
        // No SOURCE → the match falls back on doc.provider, which does not exist.
        $this->assertNull
        (
            $this->resolveJoin( [
                AQL::MODEL  => $this->providerModel() ,
                AQL::FIELDS => [ '_key' => [] , 'name' => [] ] ,
            ] )
        ) ;
    }

    // ---- edge ------------------------------------------------------------

    public function testEdgeSourceMovesTheTraversalStartVertex() :void
    {
        $this->assertSame
        (
            [ '_key' => 'sup1' , 'name' => 'Global Supply' ] ,
            $this->resolveEdge( [
                AQL::MODEL     => $this->suppliedByModel() ,
                AQL::DIRECTION => Traversal::OUTBOUND ,
                Arango::SOURCE => 'selector.providerRef' ,
                AQL::FIELDS    => [ '_key' => [] , 'name' => [] ] ,
            ] )
        ) ;
    }

    public function testEdgeWithoutSourceStartsFromTheCurrentDocumentAndFindsNothing() :void
    {
        // No SOURCE → the traversal departs from the offer itself, which has no
        // supplied_by edge, so it resolves to null.
        $this->assertNull
        (
            $this->resolveEdge( [
                AQL::MODEL     => $this->suppliedByModel() ,
                AQL::DIRECTION => Traversal::OUTBOUND ,
                AQL::FIELDS    => [ '_key' => [] , 'name' => [] ] ,
            ] )
        ) ;
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Generates a regular join `LET` through the real `buildVariables()` dispatch,
     * runs it over the seeded offer and returns the resolved `provider` object.
     *
     * @param array $definition
     *
     * @return array|null
     * @throws Throwable
     */
    private function resolveJoin( array $definition ) :?array
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'provider' => [ Field::FILTER => Filter::JOIN ] ] ,
            [] ,
            [ 'provider' => $definition ]
        ) ;

        $let   = $variables[ 0 ] ;
        $query = "FOR doc IN " . self::OFFERS . " FILTER doc._key == 'o1' " . $let . " RETURN { provider: FIRST(provider) }" ;

        foreach ( self::$db->query( $query ) as $row )
        {
            return json_decode( json_encode( $row ) , true )[ 'provider' ] ;
        }

        return null ;
    }

    /**
     * Generates a regular edge `LET` through the real `buildVariables()` dispatch,
     * runs it over the seeded offer and returns the resolved `supplier` vertex.
     *
     * @param array $definition
     *
     * @return array|null
     * @throws Throwable
     */
    private function resolveEdge( array $definition ) :?array
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'supplier' => [ Field::FILTER => Filter::EDGE ] ] ,
            [ 'supplier' => $definition ] ,
            []
        ) ;

        $let   = $variables[ 0 ] ;
        $query = "FOR doc IN " . self::OFFERS . " FILTER doc._key == 'o1' " . $let . " RETURN { supplier: FIRST(supplier) }" ;

        foreach ( self::$db->query( $query ) as $row )
        {
            return json_decode( json_encode( $row ) , true )[ 'supplier' ] ;
        }

        return null ;
    }

    /**
     * @return Documents
     * @throws Throwable
     */
    private function providerModel() :Documents
    {
        [ $arangodb , $container ] = $this->context() ;
        return $this->documents( $arangodb , $container , self::PROVIDERS ) ;
    }

    /**
     * @return Edges
     * @throws Throwable
     */
    private function suppliedByModel() :Edges
    {
        [ $arangodb , $container ] = $this->context() ;
        return $this->edges( $arangodb , $container , self::SUPPLIED_BY , self::PROVIDERS , self::SUPPLIERS ) ;
    }

    /**
     * A live ArangoDB façade + container wired to the disposable database.
     *
     * @return array{ 0: ArangoDB , 1: Container }
     * @throws TomlError
     * @throws Throwable
     */
    private function context() :array
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return [ $arangodb , $container ] ;
    }

    /**
     * A live `Documents` model bound to a collection on the disposable database.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function documents( ArangoDB $arangodb , Container $container , string $collection ) :Documents
    {
        return new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => $collection , AQL::LAZY => false ] ) ;
    }

    /**
     * A live `Edges` model (from → to) bound to the disposable database.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function edges( ArangoDB $arangodb , Container $container , string $collection , string $from , string $to ) :Edges
    {
        return new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => $collection ,
            AQL::FROM        => $this->documents( $arangodb , $container , $from ) ,
            AQL::TO          => $this->documents( $arangodb , $container , $to   ) ,
            AQL::LAZY        => false ,
        ]) ;
    }
}
