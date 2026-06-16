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
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\enums\Scope;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\models\helpers\edges\buildEdgeVariable;
use function oihana\init\initConfig;

/**
 * Live validation of the `Field::SCOPE => Scope::EDGE` projection: inside an
 * edge sub-traversal, a flagged field is read from the **edge** variable
 * (relationship metadata) instead of the target **vertex**.
 *
 * The real {@see buildEdgeVariable()} is driven against a seeded, disposable
 * database, wrapped in a minimal `FOR doc IN users ... RETURN` query. A correct
 * result proves the generated `RETURN { _key: vertex.<...>, level: TO_NUMBER(edge.<...>) }`
 * actually parses AND runs on a real server — which the unit suite (frozen AQL
 * string only) cannot prove.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class EdgeScopeProjectionIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_edge_scope_it' ;

    private const string USERS = 'users' ;
    private const string ROLES = 'roles' ;
    private const string EDGES = 'user_has_role' ;

    /**
     * Seeds u1 → {r1, r2}, with a `level` metadata carried by each edge.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::USERS )->create() ;
        $db->collection( self::ROLES )->create() ;
        $db->edgeCollection( self::EDGES )->create() ;

        $db->collection( self::USERS )->insert( [ '_key' => 'u1' ] ) ;
        $db->collection( self::ROLES )->insert( [ '_key' => 'r1' , 'name' => 'admin'  ] ) ;
        $db->collection( self::ROLES )->insert( [ '_key' => 'r2' , 'name' => 'editor' ] ) ;

        $db->edgeCollection( self::EDGES )->insert( [ '_from' => 'users/u1' , '_to' => 'roles/r1' , 'level' => 10 ] ) ;
        $db->edgeCollection( self::EDGES )->insert( [ '_from' => 'users/u1' , '_to' => 'roles/r2' , 'level' => 20 ] ) ;
    }

    /**
     * A live `Edges` model wired to the disposable database, with its `_from`
     * (`users`) and `_to` (`roles`) vertex models set.
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

    public function testEdgeScopedFieldIsProjectedFromTheEdge() :void
    {
        // roles[] projects `name`/`_key` from the target vertex and `level`
        // (Field::SCOPE => edge) from the relationship edge itself.
        $let = buildEdgeVariable( 'roles' ,
        [
            AQL::MODEL  => $this->edges() ,
            AQL::FIELDS =>
            [
                '_key'  => [] ,
                'name'  => [] ,
                'level' => [ Field::FILTER => Filter::NUMBER , Field::SCOPE => Scope::EDGE ] ,
            ] ,
        ] ) ;

        $query = "FOR doc IN " . self::USERS . " FILTER doc._key == 'u1' " . $let . " RETURN { roles: roles }" ;

        $rows = [] ;
        foreach ( self::$db->query( $query ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }

        $this->assertCount( 1 , $rows ) ;

        $roles = $rows[ 0 ][ 'roles' ] ;
        usort( $roles , fn( $a , $b ) => strcmp( $a[ '_key' ] , $b[ '_key' ] ) ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'r1' , 'name' => 'admin'  , 'level' => 10 ] ,
                [ '_key' => 'r2' , 'name' => 'editor' , 'level' => 20 ] ,
            ] ,
            $roles
        ) ;
    }
}
