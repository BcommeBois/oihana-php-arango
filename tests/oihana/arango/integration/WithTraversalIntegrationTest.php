<?php

namespace tests\oihana\arango\integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use DI\Container;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the automatic `WITH` declaration prepended by the edge
 * traversal methods ({@see \oihana\arango\models\traits\edges\EdgesGetTrait::getVertices()}
 * and {@see \oihana\arango\models\traits\edges\EdgesCountTrait::countVertices()})
 * on anonymous (collection-set) traversals.
 *
 * The real model methods are executed end-to-end against a seeded, disposable
 * ArangoDB database. A correct vertex set / count proves that the generated
 * `WITH <vertexCollections> FOR ... OUTBOUND ...` (and the `COLLECT WITH COUNT`
 * count variant) actually parses AND runs on a real server — the placement of
 * the `WITH` clause at the top of the query is exactly what the unit suite
 * (which only freezes the AQL string) cannot prove.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class WithTraversalIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_with_it' ;

    private const string USERS = 'users' ;
    private const string ROLES = 'roles' ;
    private const string EDGES = 'user_has_role' ;

    /**
     * Seeds two vertex collections (`users`, `roles`) and an edge collection
     * wiring: u1 → {r1, r2}, u2 → {r1}.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::USERS )->create() ;
        $db->collection( self::ROLES )->create() ;
        $db->edgeCollection( self::EDGES )->create() ;

        $db->collection( self::USERS )->insert( [ '_key' => 'u1' ] ) ;
        $db->collection( self::USERS )->insert( [ '_key' => 'u2' ] ) ;
        $db->collection( self::ROLES )->insert( [ '_key' => 'r1' ] ) ;
        $db->collection( self::ROLES )->insert( [ '_key' => 'r2' ] ) ;

        $db->edgeCollection( self::EDGES )->insert( [ '_from' => 'users/u1' , '_to' => 'roles/r1' ] ) ;
        $db->edgeCollection( self::EDGES )->insert( [ '_from' => 'users/u1' , '_to' => 'roles/r2' ] ) ;
        $db->edgeCollection( self::EDGES )->insert( [ '_from' => 'users/u2' , '_to' => 'roles/r1' ] ) ;
    }

    /**
     * A live `Edges` model wired to the disposable database, with its `_from`
     * (`users`) and `_to` (`roles`) vertex models set — which is what enables
     * the automatic `WITH` declaration.
     */
    private function edges() :Edges
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $users = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::USERS , AQL::LAZY => false ] ) ;
        $roles = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::ROLES , AQL::LAZY => false ] ) ;

        return new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::EDGES ,
            AQL::FROM        => $users ,
            AQL::TO          => $roles ,
            AQL::LAZY        => false ,
        ]) ;
    }

    /**
     * Extracts the `_key` of each returned vertex, sorted, for stable assertions.
     *
     * @param object|array|null $vertices
     * @return array<int,string>
     */
    private function keys( object|array|null $vertices ) :array
    {
        $keys = array_map( fn( $v ) => is_array( $v ) ? $v[ '_key' ] : $v->_key , (array) $vertices ) ;
        sort( $keys ) ;
        return $keys ;
    }

    // ---------------------------------------------------------------- getVertices

    public function testOutboundReturnsToVertices() :void
    {
        // WITH roles FOR vertex IN OUTBOUND 'users/u1' @@edges RETURN vertex
        $vertices = $this->edges()->getOutboundVertices( 'users/u1' , [ Arango::RAW => true ] ) ;
        $this->assertSame( [ 'r1' , 'r2' ] , $this->keys( $vertices ) ) ;
    }

    public function testInboundReturnsFromVertices() :void
    {
        // WITH users FOR vertex IN INBOUND 'roles/r1' @@edges RETURN vertex
        $vertices = $this->edges()->getInboundVertices( 'roles/r1' , [ Arango::RAW => true ] ) ;
        $this->assertSame( [ 'u1' , 'u2' ] , $this->keys( $vertices ) ) ;
    }

    public function testAnyReturnsBothDirections() :void
    {
        // WITH users, roles FOR vertex IN ANY 'users/u1' @@edges RETURN vertex
        $vertices = $this->edges()->getAnyVertices( 'users/u1' , [ Arango::RAW => true ] ) ;
        $this->assertSame( [ 'r1' , 'r2' ] , $this->keys( $vertices ) ) ;
    }

    // ---------------------------------------------------------------- countVertices

    public function testCountOutboundWithCollectWithCountParses() :void
    {
        // WITH roles FOR vertex IN OUTBOUND 'users/u1' @@edges COLLECT WITH COUNT INTO length RETURN length
        $this->assertSame( 2 , $this->edges()->countVertices( Traversal::OUTBOUND , 'users/u1' ) ) ;
    }

    public function testCountInbound() :void
    {
        $this->assertSame( 2 , $this->edges()->countVertices( Traversal::INBOUND , 'roles/r1' ) ) ;
    }

    public function testCountAny() :void
    {
        $this->assertSame( 2 , $this->edges()->countVertices( Traversal::ANY , 'users/u1' ) ) ;
    }
}
