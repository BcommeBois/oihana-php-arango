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
 * Live validation of a **polymorphic edge** — an edge whose traversed collection
 * is chosen at query time from a discriminator field of the start vertex.
 *
 * Neutral graph (the source `kind` decides which edge collection to traverse):
 *
 * ```
 * nodes/n_wh (kind=warehouse) --[warehouse_edges]--> warehouses/w1
 * nodes/n_co (kind=company)   --[company_edges]-->   subsidiaries/s1
 * nodes/n_ct (kind=city)      --[region_edges]-->    regions/r1        (fallback)
 * ```
 *
 * The real {@see buildVariables()} dispatch (`isPolymorphic` → the polymorphic
 * edge builder → shared assembler → `APPEND` of guarded traversals) is driven
 * against a seeded, disposable database. A correct result proves the generated
 * `APPEND( ( FOR v,e IN OUTBOUND … ) , … )` parses AND routes to the right edge
 * collection on a real server — including the fail-closed per-branch gate and the
 * fallback — which the unit suite (frozen AQL string only) cannot.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class PolymorphicEdgeIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_polymorphic_edge_it' ;

    private const string NODES        = 'nodes' ;
    private const string WAREHOUSES   = 'warehouses' ;
    private const string SUBSIDIARIES = 'subsidiaries' ;
    private const string REGIONS      = 'regions' ;

    private const string WAREHOUSE_EDGES = 'warehouse_edges' ;
    private const string COMPANY_EDGES   = 'company_edges' ;
    private const string REGION_EDGES    = 'region_edges' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::NODES        )->create() ;
        $db->collection( self::WAREHOUSES   )->create() ;
        $db->collection( self::SUBSIDIARIES )->create() ;
        $db->collection( self::REGIONS      )->create() ;
        $db->edgeCollection( self::WAREHOUSE_EDGES )->create() ;
        $db->edgeCollection( self::COMPANY_EDGES   )->create() ;
        $db->edgeCollection( self::REGION_EDGES    )->create() ;

        $db->collection( self::NODES )->insert( [ '_key' => 'n_wh' , 'kind' => 'warehouse' ] ) ;
        $db->collection( self::NODES )->insert( [ '_key' => 'n_co' , 'kind' => 'company'   ] ) ;
        $db->collection( self::NODES )->insert( [ '_key' => 'n_ct' , 'kind' => 'city'      ] ) ;

        $db->collection( self::WAREHOUSES   )->insert( [ '_key' => 'w1' , 'name' => 'Central Warehouse' ] ) ;
        $db->collection( self::SUBSIDIARIES )->insert( [ '_key' => 's1' , 'name' => 'Paris Subsidiary'  ] ) ;
        $db->collection( self::REGIONS      )->insert( [ '_key' => 'r1' , 'name' => 'EU Region'         ] ) ;

        $db->edgeCollection( self::WAREHOUSE_EDGES )->insert( [ '_from' => 'nodes/n_wh' , '_to' => 'warehouses/w1'   ] ) ;
        $db->edgeCollection( self::COMPANY_EDGES   )->insert( [ '_from' => 'nodes/n_co' , '_to' => 'subsidiaries/s1' ] ) ;
        $db->edgeCollection( self::REGION_EDGES    )->insert( [ '_from' => 'nodes/n_ct' , '_to' => 'regions/r1'      ] ) ;
    }

    public function testWarehouseKindTraversesWarehouseEdges() :void
    {
        $this->assertSame
        (
            [ '_key' => 'w1' , 'name' => 'Central Warehouse' ] ,
            $this->resolveRelation( 'n_wh' , $this->polymorphicDefinition() )
        ) ;
    }

    public function testCompanyKindTraversesCompanyEdges() :void
    {
        $this->assertSame
        (
            [ '_key' => 's1' , 'name' => 'Paris Subsidiary' ] ,
            $this->resolveRelation( 'n_co' , $this->polymorphicDefinition() )
        ) ;
    }

    public function testUndeclaredKindResolvesToNullWithoutFallback() :void
    {
        $this->assertNull( $this->resolveRelation( 'n_ct' , $this->polymorphicDefinition() ) ) ;
    }

    public function testUndeclaredKindTraversesFallback() :void
    {
        $this->assertSame
        (
            [ '_key' => 'r1' , 'name' => 'EU Region' ] ,
            $this->resolveRelation( 'n_ct' , $this->polymorphicDefinition( withFallback: true ) )
        ) ;
    }

    public function testDeniedBranchIsFailClosedLive() :void
    {
        // The warehouse branch requires a permission the authorizer refuses, so
        // it is dropped from the APPEND — the warehouse target never surfaces.
        $definition = $this->polymorphicDefinition( gateWarehouse: true ) ;
        $init       = [ Arango::AUTHORIZER => fn( string $s ) => $s !== 'warehouse:read' ] ;

        $this->assertNull( $this->resolveRelation( 'n_wh' , $definition , $init ) ) ;
    }

    /**
     * Builds the polymorphic edge definition, each branch a live `Edges` model
     * bound to the disposable database.
     *
     * @param bool $withFallback
     * @param bool $gateWarehouse
     *
     * @return array
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TomlError
     * @throws Throwable
     */
    private function polymorphicDefinition( bool $withFallback = false , bool $gateWarehouse = false ) :array
    {
        [ $arangodb , $container ] = $this->context() ;

        $fields = [ '_key' => [] , 'name' => [] ] ;

        $warehouse = [ AQL::MODEL => $this->edges( $arangodb , $container , self::WAREHOUSE_EDGES , self::NODES , self::WAREHOUSES ) , AQL::FIELDS => $fields ] ;
        if ( $gateWarehouse )
        {
            $warehouse[ AQL::REQUIRES ] = 'warehouse:read' ;
        }

        $definition =
        [
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           =>
            [
                'warehouse' => $warehouse ,
                'company'   => [ AQL::MODEL => $this->edges( $arangodb , $container , self::COMPANY_EDGES , self::NODES , self::SUBSIDIARIES ) , AQL::FIELDS => $fields ] ,
            ] ,
        ] ;

        if ( $withFallback )
        {
            $definition[ Arango::FALLBACK ] = [ AQL::MODEL => $this->edges( $arangodb , $container , self::REGION_EDGES , self::NODES , self::REGIONS ) , AQL::FIELDS => $fields ] ;
        }

        return $definition ;
    }

    /**
     * Generates the polymorphic edge `LET` through the real `buildVariables()`
     * dispatch, runs it over a single node and returns the resolved `rel` vertex.
     *
     * @param string $nodeKey
     * @param array  $definition
     * @param array  $init
     *
     * @return array|null
     * @throws Throwable
     */
    private function resolveRelation( string $nodeKey , array $definition , array $init = [] ) :?array
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'rel' => [ Field::FILTER => Filter::EDGE ] ] ,
            [ 'rel' => $definition ] ,
            [] ,
            null ,
            AQL::DOC ,
            $init
        ) ;

        $let   = $variables[ 0 ] ;
        $query = "FOR doc IN " . self::NODES . " FILTER doc._key == '" . $nodeKey . "' " . $let . " RETURN { rel: FIRST(rel) }" ;

        foreach ( self::$db->query( $query ) as $row )
        {
            return json_decode( json_encode( $row ) , true )[ 'rel' ] ;
        }

        return null ;
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
