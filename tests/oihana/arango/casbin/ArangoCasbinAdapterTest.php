<?php

namespace tests\oihana\arango\casbin;

use Casbin\Exceptions\CasbinException;
use Casbin\Exceptions\InvalidFilterTypeException;
use Casbin\Model\Model;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\casbin\ArangoCasbinAdapter;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;
use oihana\models\interfaces\DocumentsModel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use Throwable;

#[CoversClass( ArangoCasbinAdapter::class )]
class ArangoCasbinAdapterTest extends TestCase
{
    private DocumentsModel $model ;
    private ArangoCasbinAdapter $adapter ;

    /**
     * Track all insert/delete/update/list calls to the mock model.
     * @var array<string, array>
     */
    private array $calls = [] ;

    /**
     * Data stored in the mock model (simulates the rbac collection).
     * @var array<int, array>
     */
    private array $store = [] ;

    protected function setUp(): void
    {
        $this->calls = [] ;
        $this->store = [] ;

        $this->model = $this->createStub( DocumentsModel::class ) ;

        $this->model
            ->method( 'insert' )
            ->willReturnCallback( function ( array $init = [] )
            {
                $doc = $init[ Arango::DOC ] ?? [] ;
                // Synthetic _key so adapter code that does "list then delete by _key"
                // can resolve a non-null key on every stored row.
                $doc[ '_key' ] = (string) ( count( $this->store ) + 1 ) ;
                $this->store[] = $doc ;
                $this->calls['insert'][] = $doc ;
                return (object) $doc ;
            }) ;

        $this->model
            ->method( 'list' )
            ->willReturnCallback( function ( array $init = [] )
            {
                $conditions = $init[ Arango::CONDITIONS ] ?? [] ;
                $this->calls['list'][] = $conditions ;

                if ( empty( $conditions ) )
                {
                    return array_map( fn( $d ) => (object) $d , $this->store ) ;
                }

                return array_filter
                (
                    array_map( fn( $d ) => (object) $d , $this->store ) ,
                    function ( $doc ) use ( $conditions )
                    {
                        foreach ( $conditions as $condition )
                        {
                            if ( preg_match( '/doc\.(\w+)\s*==\s*"([^"]*)"/' , $condition , $m ) )
                            {
                                $field = $m[1] ;
                                $value = $m[2] ;

                                if ( ( $doc->$field ?? null ) !== $value )
                                {
                                    return false ;
                                }
                            }
                        }

                        return true ;
                    }
                ) ;
            }) ;

        $this->model
            ->method( 'delete' )
            ->willReturnCallback( function ( array $init = [] )
            {
                $conditions = $init[ Arango::CONDITIONS ] ?? [] ;
                $value      = $init[ Arango::VALUE      ] ?? null ;
                $this->calls['delete'][] = $conditions ?: $value ;

                // The adapter's "list then delete by _key" pattern passes
                // Arango::VALUE (single key or array of keys) instead of
                // conditions. Support both shapes here.
                if( $value !== null )
                {
                    $keys = is_array( $value ) ? $value : [ $value ] ;
                    $keys = array_map( fn( $k ) => (string) $k , $keys ) ;

                    $this->store = array_values( array_filter
                    (
                        $this->store ,
                        fn( $doc ) => !in_array( (string) ( $doc[ '_key' ] ?? '' ) , $keys , true )
                    )) ;

                    return null ;
                }

                $this->store = array_values( array_filter
                (
                    $this->store ,
                    function ( $doc ) use ( $conditions )
                    {
                        foreach ( $conditions as $condition )
                        {
                            if ( preg_match( '/doc\.(\w+)\s*==\s*"([^"]*)"/' , $condition , $m ) )
                            {
                                if ( ( $doc[ $m[1] ] ?? null ) !== $m[2] )
                                {
                                    return true ; // keep
                                }
                            }
                        }

                        return false ; // remove
                    }
                )) ;

                return null ;
            }) ;

        $this->model
            ->method( 'truncate' )
            ->willReturnCallback( function ()
            {
                $this->store = [] ;
                $this->calls['truncate'][] = true ;
                return true ;
            }) ;

        $this->model
            ->method( 'update' )
            ->willReturnCallback( function ( array $init = [] )
            {
                $doc        = $init[ Arango::DOC ]        ?? [] ;
                $conditions = $init[ Arango::CONDITIONS ] ?? [] ;
                $this->calls['update'][] = [ 'doc' => $doc , 'conditions' => $conditions ] ;
                return (object) $doc ;
            }) ;

        $this->adapter = new ArangoCasbinAdapter( $this->model ) ;
    }

    // ========== addPolicy ==========

    /**
     * @return void
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws Error409
     * @throws ArangoException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws Throwable
     */
    public function testAddPolicy(): void
    {
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:admin' , 'commerce-api' , '/products' , 'GET' , 'allow' ] ) ;

        $this->assertCount( 1 , $this->store ) ;
        $this->assertSame( 'p'            , $this->store[0]['ptype'] ) ;
        $this->assertSame( 'role:admin'   , $this->store[0]['v0'] ) ;
        $this->assertSame( 'commerce-api' , $this->store[0]['v1'] ) ;
        $this->assertSame( '/products'    , $this->store[0]['v2'] ) ;
        $this->assertSame( 'GET'          , $this->store[0]['v3'] ) ;
        $this->assertSame( 'allow'        , $this->store[0]['v4'] ) ;
    }

    // ========== addPolicies ==========

    public function testAddPolicies(): void
    {
        $this->adapter->addPolicies( 'p' , 'p' ,
        [
            [ 'role:admin'  , 'api' , '/products' , 'GET'  , 'allow' ] ,
            [ 'role:editor' , 'api' , '/products' , 'POST' , 'allow' ] ,
        ]) ;

        $this->assertCount( 2 , $this->store ) ;
        $this->assertSame( 'role:admin'  , $this->store[0]['v0'] ) ;
        $this->assertSame( 'role:editor' , $this->store[1]['v0'] ) ;
    }

    // ========== removePolicy ==========

    public function testRemovePolicy(): void
    {
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:admin' , 'api' , '/products' , 'GET' , 'allow' ] ) ;
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:editor' , 'api' , '/users' , 'GET' , 'allow' ] ) ;

        $this->assertCount( 2 , $this->store ) ;

        $this->adapter->removePolicy( 'p' , 'p' , [ 'role:admin' , 'api' , '/products' , 'GET' , 'allow' ] ) ;

        $this->assertCount( 1 , $this->store ) ;
        $this->assertSame( 'role:editor' , $this->store[0]['v0'] ) ;
    }

    // ========== removePolicies ==========

    public function testRemovePolicies(): void
    {
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:admin'  , 'api' , '/a' , 'GET' , 'allow' ] ) ;
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:editor' , 'api' , '/b' , 'GET' , 'allow' ] ) ;
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:viewer' , 'api' , '/c' , 'GET' , 'allow' ] ) ;

        $this->adapter->removePolicies( 'p' , 'p' ,
        [
            [ 'role:admin'  , 'api' , '/a' , 'GET' , 'allow' ] ,
            [ 'role:editor' , 'api' , '/b' , 'GET' , 'allow' ] ,
        ]) ;

        $this->assertCount( 1 , $this->store ) ;
        $this->assertSame( 'role:viewer' , $this->store[0]['v0'] ) ;
    }

    // ========== savePolicy ==========

    /**
     * @throws CasbinException
     */
    public function testSavePolicy(): void
    {
        // Pre-fill the store
        $this->store[] = [ 'ptype' => 'p' , 'v0' => 'old' ] ;

        $model = new Model() ;
        $model->loadModelFromText
        (
            "[request_definition]\n" .
            "r = sub, dom, obj, act\n" .
            "[policy_definition]\n" .
            "p = sub, dom, obj, act, eft\n" .
            "[role_definition]\n" .
            "g = _, _, _\n" .
            "[policy_effect]\n" .
            "e = some(where (p.eft == allow))\n" .
            "[matchers]\n" .
            "m = g(r.sub, p.sub, r.dom) && r.dom == p.dom && r.obj == p.obj && r.act == p.act"
        ) ;

        $model->addPolicy( 'p' , 'p' , [ 'role:admin' , 'api' , '/products' , 'GET' , 'allow' ] ) ;
        $model->addPolicy( 'g' , 'g' , [ 'user:123' , 'role:admin' , 'api' ] ) ;

        $this->adapter->savePolicy( $model ) ;

        // Store should have been truncated then refilled
        $this->assertCount( 2 , $this->store ) ;
        $this->assertSame( 'p' , $this->store[0]['ptype'] ) ;
        $this->assertSame( 'g' , $this->store[1]['ptype'] ) ;
    }

    // ========== loadPolicy ==========

    /**
     * @throws CasbinException
     */
    public function testLoadPolicy(): void
    {
        $this->store =
        [
            [ 'ptype' => 'p' , 'v0' => 'role:admin' , 'v1' => 'api' , 'v2' => '/products' , 'v3' => 'GET' , 'v4' => 'allow' ] ,
            [ 'ptype' => 'g' , 'v0' => 'user:123'   , 'v1' => 'role:admin' , 'v2' => 'api' ] ,
        ] ;

        $model = new Model() ;
        $model->loadModelFromText
        (
            "[request_definition]\n" .
            "r = sub, dom, obj, act\n" .
            "[policy_definition]\n" .
            "p = sub, dom, obj, act, eft\n" .
            "[role_definition]\n" .
            "g = _, _, _\n" .
            "[policy_effect]\n" .
            "e = some(where (p.eft == allow))\n" .
            "[matchers]\n" .
            "m = g(r.sub, p.sub, r.dom) && r.dom == p.dom && r.obj == p.obj && r.act == p.act"
        ) ;

        $this->adapter->loadPolicy( $model ) ;

        $this->assertTrue( $model->hasPolicy( 'p' , 'p' , [ 'role:admin' , 'api' , '/products' , 'GET' , 'allow' ] ) ) ;
        $this->assertTrue( $model->hasPolicy( 'g' , 'g' , [ 'user:123' , 'role:admin' , 'api' ] ) ) ;
    }

    // ========== loadFilteredPolicy ==========

    /**
     * @throws CasbinException
     * @throws InvalidFilterTypeException
     */
    public function testLoadFilteredPolicyWithFilter(): void
    {
        $this->store =
        [
            [ 'ptype' => 'p' , 'v0' => 'role:admin'  , 'v1' => 'api-a' , 'v2' => '/products' , 'v3' => 'GET' , 'v4' => 'allow' ] ,
            [ 'ptype' => 'p' , 'v0' => 'role:editor'  , 'v1' => 'api-b' , 'v2' => '/users'    , 'v3' => 'GET' , 'v4' => 'allow' ] ,
        ] ;

        $model = new Model() ;
        $model->loadModelFromText
        (
            "[request_definition]\n" .
            "r = sub, dom, obj, act\n" .
            "[policy_definition]\n" .
            "p = sub, dom, obj, act, eft\n" .
            "[role_definition]\n" .
            "g = _, _, _\n" .
            "[policy_effect]\n" .
            "e = some(where (p.eft == allow))\n" .
            "[matchers]\n" .
            "m = g(r.sub, p.sub, r.dom) && r.dom == p.dom && r.obj == p.obj && r.act == p.act"
        ) ;

        $this->adapter->loadFilteredPolicy( $model , [ 'v1' => 'api-a' ] ) ;

        $this->assertTrue( $model->hasPolicy( 'p' , 'p' , [ 'role:admin' , 'api-a' , '/products' , 'GET' , 'allow' ] ) ) ;
        $this->assertFalse( $model->hasPolicy( 'p' , 'p' , [ 'role:editor' , 'api-b' , '/users' , 'GET' , 'allow' ] ) ) ;
        $this->assertTrue( $this->adapter->isFiltered() ) ;
    }

    /**
     * @throws CasbinException
     * @throws InvalidFilterTypeException
     */
    public function testLoadFilteredPolicyWithClosure(): void
    {
        $this->store =
        [
            [ 'ptype' => 'p' , 'v0' => 'role:admin' , 'v1' => 'api' , 'v2' => '/x' , 'v3' => 'GET' , 'v4' => 'allow' ] ,
        ] ;

        $model = new Model() ;
        $model->loadModelFromText
        (
            "[request_definition]\n" .
            "r = sub, dom, obj, act\n" .
            "[policy_definition]\n" .
            "p = sub, dom, obj, act, eft\n" .
            "[role_definition]\n" .
            "g = _, _, _\n" .
            "[policy_effect]\n" .
            "e = some(where (p.eft == allow))\n" .
            "[matchers]\n" .
            "m = g(r.sub, p.sub, r.dom) && r.dom == p.dom && r.obj == p.obj && r.act == p.act"
        ) ;

        $this->adapter->loadFilteredPolicy( $model , function ( $docModel , $keys , &$rows )
        {
            $rows = $docModel->list([ Arango::RAW => true ]) ;
        }) ;

        $this->assertTrue( $model->hasPolicy( 'p' , 'p' , [ 'role:admin' , 'api' , '/x' , 'GET' , 'allow' ] ) ) ;
    }

    /**
     * @throws CasbinException
     */
    public function testLoadFilteredPolicyWithInvalidFilterThrows(): void
    {
        $model = new Model() ;
        $model->loadModelFromText
        (
            "[request_definition]\n" .
            "r = sub, dom, obj, act\n" .
            "[policy_definition]\n" .
            "p = sub, dom, obj, act, eft\n" .
            "[role_definition]\n" .
            "g = _, _, _\n" .
            "[policy_effect]\n" .
            "e = some(where (p.eft == allow))\n" .
            "[matchers]\n" .
            "m = r.sub == p.sub"
        ) ;

        $this->expectException( InvalidFilterTypeException::class ) ;

        $this->adapter->loadFilteredPolicy( $model , 12345 ) ;
    }

    // ========== updatePolicy ==========

    public function testUpdatePolicy(): void
    {
        $this->adapter->updatePolicy
        (
            'p' , 'p' ,
            [ 'role:admin' , 'api' , '/products' , 'GET' , 'allow' ] ,
            [ 'role:admin' , 'api' , '/products' , 'GET|POST' , 'allow' ]
        ) ;

        $this->assertCount( 1 , $this->calls['update'] ) ;

        $updateCall = $this->calls['update'][0] ;

        $this->assertSame( 'p'             , $updateCall['doc']['ptype'] ) ;
        $this->assertSame( 'GET|POST'      , $updateCall['doc']['v3'] ) ;
        $this->assertStringContainsString( 'doc.ptype == "p"'           , $updateCall['conditions'][0] ) ;
        $this->assertStringContainsString( 'doc.v0 == "role:admin"'     , $updateCall['conditions'][1] ) ;
    }

    // ========== updatePolicies ==========

    public function testUpdatePolicies(): void
    {
        $this->adapter->updatePolicies
        (
            'p' , 'p' ,
            [
                [ 'role:admin'  , 'api' , '/a' , 'GET' , 'allow' ] ,
                [ 'role:editor' , 'api' , '/b' , 'POST' , 'allow' ] ,
            ] ,
            [
                [ 'role:admin'  , 'api' , '/a' , 'GET|PATCH' , 'allow' ] ,
                [ 'role:editor' , 'api' , '/b' , 'POST|PUT'  , 'allow' ] ,
            ]
        ) ;

        $this->assertCount( 2 , $this->calls['update'] ) ;
    }

    // ========== removeFilteredPolicy ==========

    public function testRemoveFilteredPolicy(): void
    {
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:admin'  , 'api' , '/a' , 'GET' , 'allow' ] ) ;
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:admin'  , 'api' , '/b' , 'GET' , 'allow' ] ) ;
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:editor' , 'api' , '/c' , 'GET' , 'allow' ] ) ;

        $this->adapter->removeFilteredPolicy( 'p' , 'p' , 0 , 'role:admin' ) ;

        $this->assertCount( 1 , $this->store ) ;
        $this->assertSame( 'role:editor' , $this->store[0]['v0'] ) ;
    }

    // ========== updateFilteredPolicies ==========

    /**
     * @throws DateInvalidTimeZoneException
     * @throws Error409
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DateMalformedStringException
     * @throws Throwable
     * @throws UnsupportedOperationException
     * @throws NotFoundExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws BindException
     */
    public function testUpdateFilteredPolicies(): void
    {
        $this->adapter->addPolicy( 'p' , 'p' , [ 'role:old' , 'api' , '/x' , 'GET' , 'allow' ] ) ;

        $result = $this->adapter->updateFilteredPolicies
        (
            'p' , 'p' ,
            [
                [ 'role:new' , 'api' , '/x' , 'GET' , 'allow' ] ,
            ] ,
            0 , 'role:old'
        ) ;

        $this->assertCount( 1 , $result ) ;
        $this->assertSame( [ 'role:old' , 'api' , '/x' , 'GET' , 'allow' ] , $result[0] ) ;

        // Old removed, new added
        $this->assertCount( 1 , $this->store ) ;
        $this->assertSame( 'role:new' , $this->store[0]['v0'] ) ;
    }

    // ========== filterRule ==========

    public function testFilterRule(): void
    {
        $this->assertSame
        (
            [ 'role:admin' , 'api' , '/x' ] ,
            $this->adapter->filterRule( [ 'role:admin' , 'api' , '/x' , '' , null , '' ] )
        ) ;
    }

    public function testFilterRuleKeepsAllNonEmpty(): void
    {
        $this->assertSame
        (
            [ 'a' , 'b' , 'c' ] ,
            $this->adapter->filterRule( [ 'a' , 'b' , 'c' ] )
        ) ;
    }

    // ========== constructor ==========

    public function testConstructorAcceptsLogger(): void
    {
        $logger  = $this->createStub( LoggerInterface::class ) ;
        $adapter = new ArangoCasbinAdapter( $this->model , $logger ) ;

        $this->assertInstanceOf( ArangoCasbinAdapter::class , $adapter ) ;
    }

    // ========== isFiltered ==========

    public function testIsFilteredDefaultFalse(): void
    {
        $this->assertFalse( $this->adapter->isFiltered() ) ;
    }

    public function testSetFiltered(): void
    {
        $this->adapter->setFiltered( true ) ;
        $this->assertTrue( $this->adapter->isFiltered() ) ;

        $this->adapter->setFiltered( false ) ;
        $this->assertFalse( $this->adapter->isFiltered() ) ;
    }
}
