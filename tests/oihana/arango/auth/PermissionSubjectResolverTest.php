<?php

namespace tests\oihana\arango\auth;

use Memcached;

use oihana\arango\auth\PermissionSubjectResolver;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use stdClass;

#[CoversClass( PermissionSubjectResolver::class )]
#[AllowMockObjectsWithoutExpectations]
final class PermissionSubjectResolverTest extends TestCase
{
    private function makePermission( string $subject , string $object , string $action ) : stdClass
    {
        $p = new stdClass() ;
        $p->subject = $subject ;
        $p->object  = $object ;
        $p->action  = $action ;
        return $p ;
    }

    private function makeModel( array $permissions = [] ) : Documents
    {
        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willReturn( $permissions ) ;

        return $model ;
    }

    private function makeCacheStub() : Memcached
    {
        // In-memory stub built on top of PHPUnit's mock — declaring a child
        // class of Memcached would require matching its native signatures
        // exactly, which differ slightly between PHP / Memcached extension
        // versions. Going through PHPUnit gives us control over the three
        // methods we actually use.
        $store = new \stdClass() ;
        $store->data = [] ;

        $cache = $this->getMockBuilder( Memcached::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' , 'set' , 'delete' ])
            ->getMock() ;

        $cache->method( 'get' )->willReturnCallback( fn( $key ) => $store->data[ $key ] ?? false ) ;
        $cache->method( 'set' )->willReturnCallback( function( $key , $value ) use ( $store )
        {
            $store->data[ $key ] = $value ;
            return true ;
        } ) ;
        $cache->method( 'delete' )->willReturnCallback( function( $key ) use ( $store )
        {
            unset( $store->data[ $key ] ) ;
            return true ;
        } ) ;

        return $cache ;
    }

    public function testResolveReturnsCoupleForKnownSubject() : void
    {
        $model = $this->makeModel
        ([
            $this->makePermission( 'roles.permissions:list' , '/roles/:id/permissions' , 'GET' ) ,
            $this->makePermission( 'roles:create' , '/roles' , 'POST' ) ,
        ]) ;

        $resolver = new PermissionSubjectResolver( $model , $this->makeCacheStub() ) ;

        $couple = $resolver->resolve( 'roles.permissions:list' ) ;

        $this->assertSame
        (
            [ 'object' => '/roles/:id/permissions' , 'action' => 'GET' ] ,
            $couple
        ) ;
    }

    public function testResolveReturnsNullForUnknownSubject() : void
    {
        $model = $this->makeModel
        ([
            $this->makePermission( 'roles.permissions:list' , '/roles/:id/permissions' , 'GET' ) ,
        ]) ;

        $resolver = new PermissionSubjectResolver( $model , $this->makeCacheStub() ) ;

        $this->assertNull( $resolver->resolve( 'this.subject:does.not.exist' ) ) ;
    }

    public function testGetMapHitsDatabaseOnceWhenCacheIsCold() : void
    {
        $listCalls = 0 ;

        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willReturnCallback( function () use ( &$listCalls )
        {
            $listCalls++ ;
            return
            [
                $this->makePermission( 'a:b' , '/a' , 'GET' ) ,
            ] ;
        } ) ;

        $resolver = new PermissionSubjectResolver( $model , $this->makeCacheStub() ) ;

        // First call — cache cold, hits the database.
        $resolver->getMap() ;
        // Second call — cache warm, must not hit the database again.
        $resolver->getMap() ;
        // Third call to be extra sure.
        $resolver->resolve( 'a:b' ) ;

        $this->assertSame( 1 , $listCalls , 'Expected exactly one ArangoDB call across 3 lookups when the cache is enabled.' ) ;
    }

    public function testInvalidateForcesNextLookupToReloadFromDatabase() : void
    {
        $listCalls = 0 ;

        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willReturnCallback( function () use ( &$listCalls )
        {
            $listCalls++ ;
            return
            [
                $this->makePermission( 'a:b' , '/a' , 'GET' ) ,
            ] ;
        } ) ;

        $resolver = new PermissionSubjectResolver( $model , $this->makeCacheStub() ) ;

        $resolver->getMap() ;       // cold → 1
        $resolver->getMap() ;       // warm → still 1
        $resolver->invalidate() ;
        $resolver->getMap() ;       // cold again → 2

        $this->assertSame( 2 , $listCalls , 'Expected the invalidation to force a fresh database read on the next lookup.' ) ;
    }

    public function testTtlZeroBypassesTheCache() : void
    {
        $listCalls = 0 ;

        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willReturnCallback( function () use ( &$listCalls )
        {
            $listCalls++ ;
            return
            [
                $this->makePermission( 'a:b' , '/a' , 'GET' ) ,
            ] ;
        } ) ;

        $resolver = new PermissionSubjectResolver( $model , $this->makeCacheStub() , ttl: 0 ) ;

        $resolver->getMap() ;
        $resolver->getMap() ;
        $resolver->getMap() ;

        $this->assertSame( 3 , $listCalls , 'Expected ttl=0 to bypass the cache and hit the database every call (debugging mode).' ) ;
    }

    public function testLoadFailureReturnsEmptyMap() : void
    {
        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willThrowException( new \RuntimeException( 'arango down' ) ) ;

        $resolver = new PermissionSubjectResolver( $model , $this->makeCacheStub() ) ;

        $this->assertSame( [] , $resolver->getMap() ) ;
        $this->assertNull( $resolver->resolve( 'anything:list' ) ) ;
    }

    public function testNonStringSubjectsAreSkipped() : void
    {
        $bogus = new stdClass() ;
        $bogus->subject = 12345 ; // numeric, not a string
        $bogus->object  = '/whatever' ;
        $bogus->action  = 'GET' ;

        $blank = new stdClass() ;
        $blank->subject = '' ;
        $blank->object  = '/whatever' ;
        $blank->action  = 'GET' ;

        $valid = $this->makePermission( 'roles:get' , '/roles/:id' , 'GET' ) ;

        $model = $this->makeModel([ $bogus , $blank , $valid ]) ;

        $resolver = new PermissionSubjectResolver( $model , $this->makeCacheStub() ) ;

        $map = $resolver->getMap() ;

        $this->assertCount( 1 , $map ) ;
        $this->assertArrayHasKey( 'roles:get' , $map ) ;
    }
}
