<?php

namespace tests\oihana\arango\commands;

use InvalidArgumentException;

use oihana\arango\clients\aql\AqlQuery;
use oihana\arango\commands\PreserveFilter;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see PreserveFilter} : the compilation of a preserve rule
 * through the `?filter=` compiler, outside any model and any container, and the
 * queries the `preserve` / `reinject` actions run with it.
 */
#[CoversClass(PreserveFilter::class)]
class PreserveFilterTest extends TestCase
{
    private function filter( array $filter , array $filters = [ '_key' => FilterType::STRING ] ) :PreserveFilter
    {
        return new PreserveFilter( 'articles' , $filters , $filter ) ;
    }

    /**
     * The bind variables a query uses, `@@collection` and `@value` alike, keyed as
     * the server expects them in `bindVars` (`@collection`, `value`).
     */
    private function usedBinds( AqlQuery $query ) :array
    {
        preg_match_all( '/@(@?\w+)/' , $query->query , $matches ) ;
        $names = array_values( array_unique( $matches[ 1 ] ) ) ;
        sort( $names ) ;
        return $names ;
    }

    private function givenBinds( AqlQuery $query ) :array
    {
        $names = array_map( 'strval' , array_keys( $query->bindVars ) ) ;
        sort( $names ) ;
        return $names ;
    }

    // ---------------------------------------------------------------- the backup

    public function testTheBackupIsNamedAfterTheCollection() :void
    {
        $filter = $this->filter( [ 'key' => '_key' , 'op' => 'nsw' , 'val' => 'src:' ] ) ;

        $this->assertSame( 'articles' , $filter->collection ) ;
        $this->assertSame( 'articles__preserved' , $filter->backup ) ;
    }

    // ---------------------------------------------------------------- the compilation

    public function testNotStartsWithCompilesToANegatedStartsWithOverABoundValue() :void
    {
        $filter = $this->filter( [ 'key' => '_key' , 'op' => 'nsw' , 'val' => 'src:' ] ) ;

        $this->assertMatchesRegularExpression( '/^!\(STARTS_WITH\(doc\._key,@\w+\)\)$/' , $filter->condition ) ;
        $this->assertSame( [ 'src:' ] , array_values( $filter->binds ) ) ;
    }

    public function testAValueIsNeverWrittenIntoTheQuery() :void
    {
        $value  = "x') || true || ('" ;
        $filter = $this->filter( [ 'key' => '_key' , 'val' => $value ] ) ;

        $this->assertStringNotContainsString( $value , $filter->condition ) ;
        $this->assertStringNotContainsString( 'true' , $filter->condition ) ;
        $this->assertSame( [ $value ] , array_values( $filter->binds ) ) ;
    }

    public function testGroupsCompile() :void
    {
        $filters = [ '_key' => FilterType::STRING , 'price' => FilterType::NUMBER ] ;

        $and = $this->filter( [ 'and' , [ 'key' => '_key' , 'op' => 'sw' , 'val' => 'x' ] , [ 'key' => 'price' , 'op' => 'gt' , 'val' => 3 ] ] , $filters ) ;
        $or  = $this->filter( [ 'or'  , [ 'key' => '_key' , 'val' => 'a' ] , [ 'key' => '_key' , 'val' => 'b' ] ] , $filters ) ;
        $not = $this->filter( [ 'not' , [ 'key' => '_key' , 'val' => 'a' ] ] , $filters ) ;

        $this->assertStringContainsString( '&&' , $and->condition ) ;
        $this->assertStringContainsString( '||' , $or->condition ) ;
        $this->assertStringStartsWith( '!' , $not->condition ) ;
        $this->assertCount( 2 , $and->binds ) ;
    }

    public function testAnUndeclaredKeyIsRefusedAndTheMessageNamesTheCollection() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessageMatches( '/articles.*"name" is not filterable/' ) ;

        $this->filter( [ 'key' => 'name' , 'val' => 'x' ] ) ;
    }

    public function testAnUnknownOperatorIsRefused() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessageMatches( '/articles.*Unsupported filter operator "xx"/' ) ;

        $this->filter( [ 'key' => '_key' , 'op' => 'xx' , 'val' => 'x' ] ) ;
    }

    public function testAFilterThatConstrainsNothingIsRefused() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessageMatches( '/articles.*constrains nothing/' ) ;

        $this->filter( [ 'key' => 'price' , 'op' => 'between' ] , [ 'price' => FilterType::NUMBER ] ) ;
    }

    // ---------------------------------------------------------------- the queries

    public function testEveryQueryBindsExactlyTheVariablesItUses() :void
    {
        $filter = $this->filter( [ 'key' => '_key' , 'op' => 'nsw' , 'val' => 'src:' ] ) ;

        foreach( [ $filter->countQuery() , $filter->copyQuery() , $filter->removeQuery() , $filter->putBackQuery() , $filter->backupCountQuery() ] as $query )
        {
            $this->assertSame( $this->usedBinds( $query ) , $this->givenBinds( $query ) , $query->query ) ;
        }
    }

    public function testTheQueriesTargetTheRightCollections() :void
    {
        $filter = $this->filter( [ 'key' => '_key' , 'op' => 'nsw' , 'val' => 'src:' ] ) ;

        $this->assertSame( 'articles'            , $filter->countQuery()->bindVars[ '@collection' ] ) ;
        $this->assertSame( 'articles'            , $filter->removeQuery()->bindVars[ '@collection' ] ) ;
        $this->assertSame( 'articles__preserved' , $filter->backupCountQuery()->bindVars[ '@backup' ] ) ;

        $this->assertMatchesRegularExpression( '/FOR doc IN @@collection FILTER .+ INSERT .+ INTO @@backup/'  , $filter->copyQuery()->query ) ;
        $this->assertMatchesRegularExpression( '/FOR doc IN @@backup INSERT .+ INTO @@collection/'          , $filter->putBackQuery()->query ) ;
        $this->assertMatchesRegularExpression( '/FOR doc IN @@collection FILTER .+ REMOVE doc IN @@collection/' , $filter->removeQuery()->query ) ;
    }

    public function testTheRuleFiltersTheCopyTheCountAndTheRemoval() :void
    {
        $filter = $this->filter( [ 'key' => '_key' , 'op' => 'nsw' , 'val' => 'src:' ] ) ;

        foreach( [ $filter->countQuery() , $filter->copyQuery() , $filter->removeQuery() ] as $query )
        {
            $this->assertStringContainsString( 'FILTER ' . $filter->condition , $query->query ) ;
        }

        // The put back copies the whole backup : everything in it was named by the rule.
        $this->assertStringNotContainsString( 'FILTER' , $filter->putBackQuery()->query ) ;
    }

    public function testTheCopiesLeaveTheSystemAttributesOfTheSourceBehind() :void
    {
        $filter = $this->filter( [ 'key' => '_key' , 'op' => 'nsw' , 'val' => 'src:' ] ) ;

        $this->assertStringContainsString( "UNSET( doc , '_id' , '_rev' )" , $filter->copyQuery()->query ) ;
        $this->assertStringContainsString( "UNSET( doc , '_id' , '_rev' )" , $filter->putBackQuery()->query ) ;
    }
}
