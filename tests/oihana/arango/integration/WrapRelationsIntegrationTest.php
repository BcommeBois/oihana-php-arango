<?php

namespace tests\oihana\arango\integration;

use Devium\Toml\TomlError;
use DI\DependencyException;
use DI\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
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
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use PHPUnit\Framework\Attributes\Group;

use ReflectionException;
use Throwable;
use function oihana\arango\models\helpers\edges\buildEdgeVariable;
use function oihana\init\initConfig;

/**
 * Live validation of a `Filter::WRAP` field that carries the wrapped vertex's
 * own relations: a sub-edge (and a join) declared under `Field::EDGES` /
 * `Field::JOINS` is traversed/resolved **from the wrapped vertex** and nested
 * **inside** the wrapped object, in a single query.
 *
 * Neutral graph:
 *
 * ```
 * account --[account_has_identity]--> person   (the projected link)
 * person  <--[org_has_member]-- organization   (the person's organization, INBOUND)
 * person.role --(join)--> role                 (a stored reference on the vertex)
 * ```
 *
 * The real {@see buildEdgeVariable()} is driven against a seeded, disposable
 * database, wrapped in a minimal `FOR doc IN accounts ... RETURN` query. A
 * correct result proves the generated nested `LET` — emitted inside the
 * `FOR vertex` scope and traversing **from** that vertex — actually parses AND
 * runs on a real server, which the unit suite (frozen AQL string only) cannot.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class WrapRelationsIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_wrap_relations_it' ;

    private const string ACCOUNTS      = 'accounts' ;
    private const string PERSONS       = 'persons' ;
    private const string ORGANIZATIONS = 'organizations' ;
    private const string ROLES         = 'roles' ;

    private const string ACCOUNT_HAS_IDENTITY = 'account_has_identity' ;
    private const string ORG_HAS_MEMBER       = 'org_has_member' ;

    /**
     * Seeds a1 → p1 (identity), o1 → p1 (membership, reached INBOUND from the
     * person) and p1.role → 'admin' (a stored reference joined to `roles`).
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::ACCOUNTS      )->create() ;
        $db->collection( self::PERSONS       )->create() ;
        $db->collection( self::ORGANIZATIONS )->create() ;
        $db->collection( self::ROLES         )->create() ;
        $db->edgeCollection( self::ACCOUNT_HAS_IDENTITY )->create() ;
        $db->edgeCollection( self::ORG_HAS_MEMBER       )->create() ;

        $db->collection( self::ACCOUNTS      )->insert( [ '_key' => 'a1' ] ) ;
        $db->collection( self::PERSONS       )->insert( [ '_key' => 'p1' , 'name' => 'Ada' , 'role' => 'admin' ] ) ;
        $db->collection( self::ORGANIZATIONS )->insert( [ '_key' => 'o1' , 'name' => 'Acme' ] ) ;
        $db->collection( self::ROLES         )->insert( [ '_key' => 'admin' , 'label' => 'Administrator' ] ) ;

        $db->edgeCollection( self::ACCOUNT_HAS_IDENTITY )->insert( [ '_from' => 'accounts/a1'      , '_to' => 'persons/p1' ] ) ;
        $db->edgeCollection( self::ORG_HAS_MEMBER       )->insert( [ '_from' => 'organizations/o1' , '_to' => 'persons/p1' ] ) ;
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
     * @param ArangoDB $arangodb
     * @param Container $container
     * @param string $collection
     * @return Documents
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function documents( ArangoDB $arangodb , Container $container , string $collection ) :Documents
    {
        return new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => $collection , AQL::LAZY => false ] ) ;
    }

    /**
     * The `account_has_identity` edge model (accounts → persons).
     * @param ArangoDB $arangodb
     * @param Container $container
     * @return Edges
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function accountHasIdentity( ArangoDB $arangodb , Container $container ) :Edges
    {
        return new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ACCOUNT_HAS_IDENTITY ,
            AQL::FROM        => $this->documents( $arangodb , $container , self::ACCOUNTS ) ,
            AQL::TO          => $this->documents( $arangodb , $container , self::PERSONS  ) ,
            AQL::LAZY        => false ,
        ]) ;
    }

    /**
     * The `org_has_member` edge model (organizations → persons), reached INBOUND
     * from a person so the projected vertex is the organization (`from`).
     */
    private function orgHasMember( ArangoDB $arangodb , Container $container ) :Edges
    {
        return new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ORG_HAS_MEMBER ,
            AQL::FROM        => $this->documents( $arangodb , $container , self::ORGANIZATIONS ) ,
            AQL::TO          => $this->documents( $arangodb , $container , self::PERSONS       ) ,
            AQL::LAZY        => false ,
        ]) ;
    }

    /**
     * The wrapped person exposes its organization (`worksFor`) — reached by an
     * INBOUND sub-edge from the wrapped vertex — nested beside its scalar fields.
     */
    public function testWrapNestsAnInboundSubEdge() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $let = buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $this->accountHasIdentity( $arangodb , $container ) ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        '_key'     => [] ,
                        'name'     => [] ,
                        'worksFor' => [ Field::FILTER => Filter::EDGE ] ,
                    ] ,
                    Field::EDGES =>
                    [
                        'worksFor' =>
                        [
                            AQL::MODEL     => $this->orgHasMember( $arangodb , $container ) ,
                            AQL::DIRECTION => Traversal::INBOUND ,
                            AQL::FIELDS    => [ '_key' => [] , 'name' => [] ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ] , container: $container ) ;

        $query = "FOR doc IN " . self::ACCOUNTS . " FILTER doc._key == 'a1' " . $let . " RETURN { identities: identities }" ;

        $rows = [] ;
        foreach ( self::$db->query( $query ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }

        $this->assertCount( 1 , $rows ) ;
        $this->assertSame
        (
            [
                [ 'subject' => [ '_key' => 'p1' , 'name' => 'Ada' , 'worksFor' => [ '_key' => 'o1' , 'name' => 'Acme' ] ] ] ,
            ] ,
            $rows[ 0 ][ 'identities' ]
        ) ;
    }

    /**
     * The wrapped person exposes its `role` — a stored reference resolved by a
     * join from the wrapped vertex (`vertex.role`) — nested beside its name.
     */
    public function testWrapNestsAJoin() :void
    {
        [ $arangodb , $container ] = $this->context() ;

        $let = buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $this->accountHasIdentity( $arangodb , $container ) ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'name' => [] ,
                        'role' => [ Field::FILTER => Filter::JOIN ] ,
                    ] ,
                    Field::JOINS =>
                    [
                        'role' =>
                        [
                            AQL::MODEL  => $this->documents( $arangodb , $container , self::ROLES ) ,
                            AQL::FIELDS => [ '_key' => [] , 'label' => [] ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ] , container: $container ) ;

        $query = "FOR doc IN " . self::ACCOUNTS . " FILTER doc._key == 'a1' " . $let . " RETURN { identities: identities }" ;

        $rows = [] ;
        foreach ( self::$db->query( $query ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }

        $this->assertCount( 1 , $rows ) ;
        $this->assertSame
        (
            [
                [ 'subject' => [ 'name' => 'Ada' , 'role' => [ '_key' => 'admin' , 'label' => 'Administrator' ] ] ] ,
            ] ,
            $rows[ 0 ][ 'identities' ]
        ) ;
    }
}
