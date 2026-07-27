<?php

namespace tests\oihana\arango\cache;

use Closure;
use Memcached;
use RuntimeException;
use stdClass;

use oihana\arango\cache\InheritedFieldSetResolver;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;

#[CoversClass( InheritedFieldSetResolver::class )]
#[AllowMockObjectsWithoutExpectations]
final class InheritedFieldSetResolverTest extends TestCase
{
    private const string KEY = 'test.inherited.field.set' ;

    /**
     * The filter used whenever its content does not matter — it only has to be
     * non-null, so the seed read is told apart from the expansion one.
     */
    private const array FILTER = [ 'status' => 'disabled' ] ;

    /**
     * The parentage rule of the fixtures: an identifier encodes its hierarchy,
     * two characters per level, and three characters or less is a root.
     */
    private function chunkedParent() : Closure
    {
        return function( int|string $id ) : int|string|null
        {
            $id = (string) $id ;
            return strlen( $id ) > 3 ? substr( $id , 0 , -2 ) : null ;
        } ;
    }

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
     * Builds a Documents mock whose `list()` is the given callback.
     *
     * @param Closure  $onList Receives the init, returns the documents.
     * @param int|null $calls  Receives the call count.
     * @param array    $inits  Receives every init passed to `list()`.
     */
    private function makeModel( Closure $onList , ?int &$calls = null , array &$inits = [] ) : Documents
    {
        $calls = 0 ;

        $model = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $model->method( 'list' )->willReturnCallback
        (
            function( array $init = [] ) use ( $onList , &$calls , &$inits )
            {
                $calls ++ ;
                $inits[] = $init ;
                return $onList( $init ) ;
            }
        ) ;

        return $model ;
    }

    /**
     * Builds a Documents mock answering the two reads apart: the seed read carries
     * a filter, the expansion one does not.
     *
     * @param array    $seed  The documents matching the filter.
     * @param array    $all   The documents of the whole collection.
     * @param int|null $calls Receives the call count.
     * @param array    $inits Receives every init passed to `list()`.
     */
    private function makeTreeModel( array $seed , array $all , ?int &$calls = null , array &$inits = [] ) : Documents
    {
        return $this->makeModel
        (
            fn( array $init ) => isset( $init[ Arango::FILTER ] ) ? $seed : $all ,
            $calls ,
            $inits
        ) ;
    }

    /**
     * Builds the documents of a collection from a plain list of identifiers.
     */
    private function makeTerms( array $identifiers ) : array
    {
        return array_map( fn( $id ) => $this->makeDocument([ 'id' => $id ]) , $identifiers ) ;
    }

    /**
     * An in-memory Memcached stub — same approach as DocumentFieldSetResolverTest:
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
    // The nominal case: nothing seeds the inheritance
    // =========================================================================

    public function testAnEmptySeedSetCostsASingleRead() : void
    {
        $model = $this->makeTreeModel( [] , $this->makeTerms([ '410' , '41006' ]) , $calls ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $this->assertSame( [] , $resolver->values() ) ;

        $this->assertSame( 1 , $calls , 'Expected the empty seed set to short-circuit: no second read at all.' ) ;
    }

    // =========================================================================
    // Inheritance
    // =========================================================================

    public function testInheritanceReachesEveryLevel() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' , '41007' , '4100604' , '420' , '42008' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $this->assertSame
        (
            [ '410' , '41006' , '41007' , '4100604' ] ,
            $resolver->values() ,
            'Expected the whole descent of 410, and nothing from the 420 branch.'
        ) ;
    }

    public function testAGapInTheChainIsWalkedThrough() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '4100604' ]) // 41006 does not exist as a document
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $this->assertSame
        (
            [ '410' , '4100604' ] ,
            $resolver->values() ,
            'Expected the walk to cross a missing level rather than stop at it — the identifiers are computed, not read.'
        ) ;
    }

    public function testASiblingIsNotMasked() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '41006' ]) ,
            $this->makeTerms([ '410' , '41006' , '41007' , '4100604' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $values = $resolver->values() ;

        $this->assertSame( [ '41006' , '4100604' ] , $values ) ;
        $this->assertNotContains( '41007' , $values , 'A sibling must not inherit.' ) ;
        $this->assertNotContains( '410'   , $values , 'A seeded descendant must not mask its own ancestor.' ) ;
    }

    public function testARootInheritsFromNothing() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '420' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $this->assertSame( [ '410' ] , $resolver->values() , 'A root has no ancestor to inherit from.' ) ;
    }

    public function testAClosureReturningNullStraightAwayAddsNothing() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' , '4100604' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , fn( int|string $id ) => null
        ) ;

        $this->assertSame( [ '410' ] , $resolver->values() , 'A closure declaring every document a root leaves the seed set alone.' ) ;
    }

    public function testAClosureReturningAnUnusableValueEndsTheWalk() : void
    {
        $unusable = [ '1' => 1.5 , '2' => '' , '3' => [ '410' ] , '4' => null ] ;

        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '1' , '2' , '3' , '4' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER ,
            fn( int|string $id ) => $unusable[ $id ] ?? null
        ) ;

        $this->assertSame
        (
            [ '410' ] ,
            $resolver->values() ,
            'A float, an empty string or an array is held to the same standard as a collected value: the walk ends there.'
        ) ;
    }

    public function testTheResultIsDeduplicatedAndReindexed() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' , '41006' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $values = $resolver->values() ;

        $this->assertSame( [ '410' , '41006' ] , $values ) ;
        $this->assertSame( [ 0 , 1 ] , array_keys( $values ) , 'Expected the set to be re-indexed, so AQL sees a list and not an object.' ) ;
    }

    // =========================================================================
    // Native types
    // =========================================================================

    public function testIntegerIdentifiersStayIntegers() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ 410 ]) ,
            $this->makeTerms([ 410 , 41006 , 4100604 , 420 ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER ,
            fn( int|string $id ) => $id > 999 ? (int) ( $id / 100 ) : null
        ) ;

        $this->assertSame
        (
            [ 410 , 41006 , 4100604 ] ,
            $resolver->values() ,
            'Expected native ints, since AQL does not coerce across types — a set of strings would filter nothing.'
        ) ;
    }

    public function testLeadingZeroIdentifiersAreNeverConverted() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '0410' ]) ,
            $this->makeTerms([ '0410' , '041006' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $this->assertSame
        (
            [ '0410' , '041006' ] ,
            $resolver->values() ,
            'Expected the leading zero to survive on both sides — casting to int would lose it.'
        ) ;
    }

    public function testAnIntegerSeedDoesNotMatchAStringDescendant() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ 410 ]) ,
            $this->makeTerms([ 410 , '41006' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER ,
            fn( int|string $id ) => '410' // a closure whose type disagrees with the seed
        ) ;

        $this->assertSame
        (
            [ 410 ] ,
            $resolver->values() ,
            'Membership is strict: a string ancestor does not match an integer seed, and the lib converts neither.'
        ) ;
    }

    // =========================================================================
    // Bounded walk
    // =========================================================================

    public function testACyclicClosureIsAbandoned() : void
    {
        $cycle = [ 'A' => 'B' , 'B' => 'A' ] ;

        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , 'A' ])
        ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'warning' ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER ,
            fn( int|string $id ) => $cycle[ $id ] ?? null ,
            logger: $logger
        ) ;

        $this->assertSame( [ '410' ] , $resolver->values() , 'A cycle is logged and abandoned, never raised.' ) ;
    }

    public function testAClosureReturningItsOwnInputIsAbandoned() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , 'X' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , fn( int|string $id ) => $id
        ) ;

        $this->assertSame( [ '410' ] , $resolver->values() ) ;
    }

    public function testTheMaximumDepthIsEnforced() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , 'X' ])
        ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'warning' ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER ,
            fn( int|string $id ) => $id . '0' , // an endless chain of fresh values
            logger: $logger
        ) ;

        $this->assertSame( [ '410' ] , $resolver->values() , 'An unbounded ancestry is capped, logged, and yields nothing.' ) ;
    }

    // =========================================================================
    // Graduated fail-open
    // =========================================================================

    public function testASeedReadFailureYieldsAnEmptySet() : void
    {
        $model = $this->makeModel( fn( array $init ) => throw new RuntimeException( 'arango down' ) , $calls ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'error' ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent() , logger: $logger
        ) ;

        $this->assertSame( [] , $resolver->values() , 'A failing seed read must not be mistaken for "everything is excluded".' ) ;
        $this->assertSame( 1  , $calls ) ;
    }

    public function testAnExpansionReadFailureFallsBackOnTheSeedSet() : void
    {
        $seed = $this->makeTerms([ '410' ]) ;

        $model = $this->makeModel
        (
            fn( array $init ) => isset( $init[ Arango::FILTER ] ) ? $seed : throw new RuntimeException( 'arango down' )
        ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'error' ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent() , logger: $logger
        ) ;

        $this->assertSame
        (
            [ '410' ] ,
            $resolver->values() ,
            'A failing expansion must not shrink the set back to nothing — a read failure never widens what the caller keeps.'
        ) ;
    }

    public function testAThrowingClosureFallsBackOnTheSeedSet() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' ])
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER ,
            fn( int|string $id ) => throw new RuntimeException( 'faulty parentage rule' )
        ) ;

        $this->assertSame( [ '410' ] , $resolver->values() , 'A consumer closure raising must not propagate.' ) ;
    }

    // =========================================================================
    // Cache lifecycle
    // =========================================================================

    public function testTheSecondCallIsServedByTheCache() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' ]) ,
            $calls
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $resolver->values() ;
        $resolver->values() ;
        $resolver->values() ;

        $this->assertSame( 2 , $calls , 'Expected the two reads of the cold build only, then the cache.' ) ;
    }

    public function testTtlZeroBypassesTheCache() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' ]) ,
            $calls
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent() , ttl: 0
        ) ;

        $resolver->values() ;
        $resolver->values() ;

        $this->assertSame( 4 , $calls , 'Expected ttl=0 to bypass the cache and rebuild on every call.' ) ;
    }

    public function testInvalidateForcesAReload() : void
    {
        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' ]) ,
            $calls
        ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'debug' ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent() , logger: $logger
        ) ;

        $resolver->values() ;    // cold  → 2 reads
        $resolver->values() ;    // warm  → still 2
        $resolver->invalidate() ;
        $resolver->values() ;    // cold again → 4

        $this->assertSame( 4 , $calls , 'Expected the invalidation to force a fresh build on the next call.' ) ;
    }

    // =========================================================================
    // Narrowed reads
    // =========================================================================

    public function testBothReadsAreNarrowedToTheCollectedField() : void
    {
        $inits = [] ;

        $model = $this->makeTreeModel
        (
            $this->makeTerms([ '410' ]) ,
            $this->makeTerms([ '410' , '41006' ]) ,
            $calls ,
            $inits
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent()
        ) ;

        $resolver->values() ;

        $this->assertSame
        (
            [
                Arango::LIMIT        => 0 ,
                Arango::FILTER       => self::FILTER ,
                Arango::QUERY_FIELDS => [] ,
                Arango::FIELDS       => [ 'id' ] ,
            ] ,
            $inits[ 0 ] ,
            'The seed read is projected too, and the empty queryFields is what stops a model-declared projection from ignoring the fields key.'
        ) ;

        $this->assertSame
        (
            [
                Arango::LIMIT        => 0 ,
                Arango::QUERY_FIELDS => [] ,
                Arango::FIELDS       => [ 'id' ] ,
            ] ,
            $inits[ 1 ] ,
            'The expansion reads the whole collection: without the projection it would return every document in full, plus every declared relation.'
        ) ;
    }

    public function testTheCollectedFieldIsConfigurable() : void
    {
        $inits = [] ;

        $model = $this->makeModel
        (
            fn( array $init ) => isset( $init[ Arango::FILTER ] )
                               ? [ $this->makeDocument([ 'termCode' => '410' ]) ]
                               : [ $this->makeDocument([ 'termCode' => '410' ]) , $this->makeDocument([ 'termCode' => '41006' ]) ] ,
            $calls ,
            $inits
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent() , field: 'termCode'
        ) ;

        $this->assertSame( [ '410' , '41006' ] , $resolver->values() ) ;
        $this->assertSame( [ 'termCode' ] , $inits[ 0 ][ Arango::FIELDS ] ) ;
    }

    public function testADottedFieldIsLeftUnprojected() : void
    {
        $inits = [] ;

        $model = $this->makeModel
        (
            fn( array $init ) => isset( $init[ Arango::FILTER ] )
                               ? [ $this->makeDocument([ 'code.value' => '410' ]) ]
                               : [ $this->makeDocument([ 'code.value' => '410' ]) , $this->makeDocument([ 'code.value' => '41006' ]) ] ,
            $calls ,
            $inits
        ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , self::FILTER , $this->chunkedParent() , field: 'code.value'
        ) ;

        $this->assertSame( [ '410' , '41006' ] , $resolver->values() ) ;

        $this->assertSame
        (
            [ Arango::LIMIT => 0 , Arango::FILTER => self::FILTER ] ,
            $inits[ 0 ] ,
            'A dotted field would render as an unquoted a.b object key: the wide read is slower, never wrong.'
        ) ;
    }

    // =========================================================================
    // Null filter
    // =========================================================================

    public function testANullFilterSeedsWithTheWholeCollection() : void
    {
        $model = $this->makeModel( fn( array $init ) => $this->makeTerms([ '410' , '41006' ]) , $calls ) ;

        $resolver = new InheritedFieldSetResolver
        (
            $model , $this->makeCacheStub() , self::KEY , null , $this->chunkedParent()
        ) ;

        $this->assertSame( [ '410' , '41006' ] , $resolver->values() ) ;
        $this->assertSame( 2 , $calls , 'A null filter still seeds — it just seeds with everything.' ) ;
    }
}
