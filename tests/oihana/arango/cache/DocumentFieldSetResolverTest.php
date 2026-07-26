<?php

namespace tests\oihana\arango\cache;

use Memcached;
use RuntimeException;
use stdClass;

use oihana\arango\cache\DocumentFieldSetResolver;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;

#[CoversClass( DocumentFieldSetResolver::class )]
#[AllowMockObjectsWithoutExpectations]
final class DocumentFieldSetResolverTest extends TestCase
{
    private const string KEY = 'test.field.set' ;

    /**
     * Builds a document object carrying the given properties.
     */
    private function makeDocument( array $properties ) : stdClass
    {
        $document = new stdClass() ;

        foreach ( $properties as $name => $value )
        {
            $document->{ $name } = $value ;
        }

        return $document ;
    }

    /**
     * Builds a Documents mock whose `list()` returns the given documents.
     */
    private function makeModel( array $documents = [] ) : Documents
    {
        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willReturn( $documents ) ;

        return $model ;
    }

    /**
     * Builds a Documents mock counting its `list()` calls and recording the
     * init it received.
     *
     * @param array    $documents The documents to return on every call.
     * @param int|null $calls     Receives the call count.
     * @param array    $inits     Receives every init passed to `list()`.
     */
    private function makeCountingModel( array $documents , ?int &$calls , array &$inits = [] ) : Documents
    {
        $calls = 0 ;

        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willReturnCallback
        (
            function( array $init = [] ) use ( $documents , &$calls , &$inits )
            {
                $calls ++ ;
                $inits[] = $init ;
                return $documents ;
            }
        ) ;

        return $model ;
    }

    /**
     * An in-memory Memcached stub — same approach as PermissionSubjectResolverTest:
     * going through PHPUnit avoids having to match the extension's native
     * signatures, which differ slightly across versions.
     */
    private function makeCacheStub() : Memcached
    {
        $store = new stdClass() ;
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

    // =========================================================================
    // Reading
    // =========================================================================

    public function testValuesReturnsTheFieldValuesOfTheMatchingDocuments() : void
    {
        $model = $this->makeModel
        ([
            $this->makeDocument([ 'id' => '10' ]) ,
            $this->makeDocument([ 'id' => '20' ]) ,
        ]) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $this->assertSame( [ '10' , '20' ] , $resolver->values() ) ;
    }

    public function testValuesReturnsAnEmptyArrayWhenTheCollectionIsEmpty() : void
    {
        $resolver = new DocumentFieldSetResolver( $this->makeModel() , $this->makeCacheStub() , self::KEY ) ;

        $this->assertSame( [] , $resolver->values() ) ;
    }

    // =========================================================================
    // Cache lifecycle
    // =========================================================================

    public function testTheSecondCallIsServedByTheCache() : void
    {
        $model = $this->makeCountingModel( [ $this->makeDocument([ 'id' => '10' ]) ] , $calls ) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $resolver->values() ;
        $resolver->values() ;
        $resolver->values() ;

        $this->assertSame( 1 , $calls , 'Expected exactly one read across three calls when the cache is enabled.' ) ;
    }

    public function testTtlZeroBypassesTheCache() : void
    {
        $model = $this->makeCountingModel( [ $this->makeDocument([ 'id' => '10' ]) ] , $calls ) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY , ttl: 0 ) ;

        $resolver->values() ;
        $resolver->values() ;

        $this->assertSame( 2 , $calls , 'Expected ttl=0 to bypass the cache and read on every call.' ) ;
    }

    public function testInvalidateForcesAReload() : void
    {
        $model = $this->makeCountingModel( [ $this->makeDocument([ 'id' => '10' ]) ] , $calls ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'debug' ) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY , logger: $logger ) ;

        $resolver->values() ;    // cold  → 1
        $resolver->values() ;    // warm  → still 1
        $resolver->invalidate() ;
        $resolver->values() ;    // cold again → 2

        $this->assertSame( 2 , $calls , 'Expected the invalidation to force a fresh read on the next call.' ) ;
    }

    // =========================================================================
    // Fail-open
    // =========================================================================

    public function testAReadFailureFailsOpenWithAnEmptySet() : void
    {
        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willThrowException( new RuntimeException( 'arango down' ) ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'error' ) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY , logger: $logger ) ;

        $this->assertSame( [] , $resolver->values() ) ;
    }

    // =========================================================================
    // Value collection
    // =========================================================================

    public function testTheValuesAreDeduplicatedAndReindexed() : void
    {
        $model = $this->makeModel
        ([
            $this->makeDocument([ 'id' => '10' ]) ,
            $this->makeDocument([ 'id' => '20' ]) ,
            $this->makeDocument([ 'id' => '10' ]) ,
        ]) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $values = $resolver->values() ;

        $this->assertSame( [ '10' , '20' ] , $values ) ;
        $this->assertSame( [ 0 , 1 ] , array_keys( $values ) , 'Expected the set to be re-indexed, so AQL sees a list and not an object.' ) ;
    }

    public function testIntegerValuesArePreserved() : void
    {
        $model = $this->makeModel([ $this->makeDocument([ 'id' => 10 ]) ]) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $this->assertSame( [ 10 ] , $resolver->values() , 'Expected the native int, since AQL does not coerce across types.' ) ;
    }

    public function testMixedTypesArePreserved() : void
    {
        $model = $this->makeModel
        ([
            $this->makeDocument([ 'id' => 5 ]) ,
            $this->makeDocument([ 'id' => '0608' ]) ,
        ]) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $this->assertSame( [ 5 , '0608' ] , $resolver->values() , 'Expected the leading zero to survive — casting to int would lose it.' ) ;
    }

    public function testDocumentsWithoutTheFieldOrWithAnEmptyOneAreIgnored() : void
    {
        $model = $this->makeModel
        ([
            $this->makeDocument([ 'name' => 'no id here' ]) ,
            $this->makeDocument([ 'id' => '' ]) ,
            $this->makeDocument([ 'id' => null ]) ,
            $this->makeDocument([ 'id' => 1.5 ]) ,
            $this->makeDocument([ 'id' => [ '10' ] ]) ,
            'not a document at all' ,
            $this->makeDocument([ 'id' => '10' ]) ,
        ]) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $this->assertSame( [ '10' ] , $resolver->values() ) ;
    }

    public function testDocumentsGivenAsAssociativeArraysAreSupported() : void
    {
        $model = $this->makeModel
        ([
            [ 'id' => '10' ] ,
            [ 'id' => '20' ] ,
            [ 'name' => 'no id here' ] ,
        ]) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $this->assertSame( [ '10' , '20' ] , $resolver->values() ) ;
    }

    public function testTheCollectedFieldIsConfigurable() : void
    {
        $model = $this->makeModel
        ([
            $this->makeDocument([ 'id' => '10' , 'termCode' => 'AAA' ]) ,
            $this->makeDocument([ 'id' => '20' , 'termCode' => 'BBB' ]) ,
        ]) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY , field: 'termCode' ) ;

        $this->assertSame( [ 'AAA' , 'BBB' ] , $resolver->values() ) ;
    }

    // =========================================================================
    // Cache partitioning
    // =========================================================================

    public function testTwoCacheKeysHoldTwoDistinctSets() : void
    {
        $cache = $this->makeCacheStub() ;

        $first = new DocumentFieldSetResolver
        (
            $this->makeModel([ $this->makeDocument([ 'id' => '10' ]) ]) ,
            $cache ,
            'set.one'
        ) ;

        $second = new DocumentFieldSetResolver
        (
            $this->makeModel([ $this->makeDocument([ 'id' => '99' ]) ]) ,
            $cache ,
            'set.two'
        ) ;

        $this->assertSame( [ '10' ] , $first->values() ) ;
        $this->assertSame( [ '99' ] , $second->values() ) ;

        // Re-read both from the warm cache: neither must have served the other's set.
        $this->assertSame( [ '10' ] , $first->values() ) ;
        $this->assertSame( [ '99' ] , $second->values() ) ;
    }

    // =========================================================================
    // Filter forwarding
    // =========================================================================

    public function testTheFilterIsForwardedToTheModelWhenGiven() : void
    {
        $filter = [ 'status' => 'disabled' ] ;

        $inits = [] ;
        $model = $this->makeCountingModel( [] , $calls , $inits ) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY , filter: $filter ) ;

        $resolver->values() ;

        $this->assertSame
        (
            [ Arango::LIMIT => 0 , Arango::FILTER => $filter ] ,
            $inits[ 0 ]
        ) ;
    }

    public function testTheFilterIsOmittedWhenNull() : void
    {
        $inits = [] ;
        $model = $this->makeCountingModel( [] , $calls , $inits ) ;

        $resolver = new DocumentFieldSetResolver( $model , $this->makeCacheStub() , self::KEY ) ;

        $resolver->values() ;

        $this->assertSame( [ Arango::LIMIT => 0 ] , $inits[ 0 ] , 'Expected no FILTER clause at all, so the whole collection is read.' ) ;
    }
}
