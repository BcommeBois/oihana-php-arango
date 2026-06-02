<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsExistTrait::exist()}.
 *
 * Focuses on the orchestration the build tests can't see: the empty short-circuit,
 * and the ALL vs ANY interpretation of the COUNT returned by getFirstResult().
 */
final class DocumentsExistTraitTest extends TestCase
{
    private function model( mixed $count ) :MockDocuments
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = $count ;
        return $model ;
    }

    public function testEmptyValuesReturnFalseWithoutQuery() :void
    {
        $model = $this->model( 99 ) ;
        $this->assertFalse( $model->exist( [] ) ) ;
        $this->assertSame( '' , $model->lastQuery , 'no query should be executed for empty values' ) ;
    }

    public function testSingleValueAllStrategyTrueWhenFound() :void
    {
        $this->assertTrue( $this->model( 1 )->exist( [ Arango::VALUE => 'a' ] ) ) ;
    }

    public function testSingleValueFalseWhenNotFound() :void
    {
        $this->assertFalse( $this->model( 0 )->exist( [ Arango::VALUE => 'a' ] ) ) ;
    }

    public function testAllStrategyRequiresEveryValueToMatch() :void
    {
        // ALL (default): result must equal the number of distinct values.
        $this->assertFalse( $this->model( 1 )->exist( [ Arango::VALUE => [ 'a' , 'b' ] ] ) ) ;
        $this->assertTrue ( $this->model( 2 )->exist( [ Arango::VALUE => [ 'a' , 'b' ] ] ) ) ;
    }

    public function testAnyStrategyMatchesWhenAtLeastOneFound() :void
    {
        $init = [ Arango::VALUE => [ 'a' , 'b' ] , Arango::MATCH => ArrayComparator::ANY ] ;

        $this->assertTrue ( $this->model( 1 )->exist( $init ) ) ;
        $this->assertFalse( $this->model( 0 )->exist( $init ) ) ;
    }

    public function testDuplicateValuesAreDeduplicatedForTheAllCount() :void
    {
        // 'a','a','b' => 2 distinct values, so a count of 2 satisfies ALL.
        $this->assertTrue( $this->model( 2 )->exist( [ Arango::VALUE => [ 'a' , 'a' , 'b' ] ] ) ) ;
    }

    public function testDebugWithoutMockStillQueriesAndReturnsResult() :void
    {
        // debug=true but mock=false: the debug branch runs (debugQuery) yet the
        // real getFirstResult path is still taken.
        $model = $this->model( 1 ) ;
        $this->assertTrue( $model->exist( [ Arango::VALUE => 'a' , Arango::DEBUG => true ] ) ) ;
        $this->assertNotSame( '' , $model->lastQuery ) ;
    }

    public function testDebugMockShortCircuitsToFalse() :void
    {
        $model = $this->model( 99 ) ; // would be true if evaluated
        $this->assertFalse( $model->exist( [ Arango::VALUE => 'a' , Arango::DEBUG => true , Arango::MOCK => true ] ) ) ;
    }

    public function testExistByKeyTargetsTheGivenKeyAttribute() :void
    {
        $model = $this->model( 1 ) ;
        $this->assertTrue( $model->existByKey( 'email' , [ Arango::VALUE => 'john@doe.com' ] ) ) ;
        $this->assertStringContainsString( 'doc.email IN' , $model->lastQuery ) ;
    }

    public function testExistInForwardsValuesAndMatchStrategy() :void
    {
        $model = $this->model( 2 ) ;
        $this->assertTrue( $model->existIn( [ 'a' , 'b' ] , ArrayComparator::ALL ) ) ;

        $missing = $this->model( 0 ) ;
        $this->assertFalse( $missing->existIn( [ 'a' , 'b' ] , ArrayComparator::ALL ) ) ;
    }
}
