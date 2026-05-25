<?php

namespace tests\oihana\arango\clients\aql\helpers ;

use oihana\arango\clients\aql\AqlLiteral ;
use oihana\arango\clients\aql\AqlQuery ;

use PHPUnit\Framework\TestCase ;

use function oihana\arango\clients\aql\helpers\aql ;
use function oihana\arango\clients\aql\helpers\aqlLiteral ;

/**
 * Tests for the {@see aql()} helper — PDO-style template → AqlQuery
 * with safe bind values and optional AqlLiteral inlining.
 */
class AqlFunctionTest extends TestCase
{
    public function testReturnsAqlQueryWithoutPlaceholders() :void
    {
        $query = aql( 'RETURN 1' ) ;

        $this->assertInstanceOf( AqlQuery::class , $query ) ;
        $this->assertSame( 'RETURN 1' , $query->query    ) ;
        $this->assertSame( []         , $query->bindVars ) ;
    }

    public function testSubstitutesSinglePlaceholderWithBindReference() :void
    {
        $query = aql( 'FOR u IN users FILTER u.age > ? RETURN u' , 18 ) ;

        $this->assertSame( 'FOR u IN users FILTER u.age > @value1 RETURN u' , $query->query    ) ;
        $this->assertSame( [ 'value1' => 18 ]                                , $query->bindVars ) ;
    }

    public function testSubstitutesSeveralPlaceholdersInOrder() :void
    {
        $query = aql( 'FOR u IN users FILTER u.age > ? AND u.role == ? RETURN u' , 18 , 'admin' ) ;

        $this->assertSame
        (
            'FOR u IN users FILTER u.age > @value1 AND u.role == @value2 RETURN u' ,
            $query->query ,
        ) ;
        $this->assertSame
        (
            [ 'value1' => 18 , 'value2' => 'admin' ] ,
            $query->bindVars ,
        ) ;
    }

    public function testAqlLiteralIsInlinedVerbatimWithoutBind() :void
    {
        $query = aql( 'FOR u IN users SORT u.name ? RETURN u' , new AqlLiteral( 'DESC' ) ) ;

        $this->assertSame( 'FOR u IN users SORT u.name DESC RETURN u' , $query->query    ) ;
        $this->assertSame( []                                          , $query->bindVars ) ;
    }

    public function testAqlLiteralAndScalarMixedInSameQuery() :void
    {
        $query = aql
        (
            'FOR u IN users FILTER u.age > ? SORT u.name ? LIMIT ? RETURN u' ,
            18 ,
            aqlLiteral( 'DESC' ) ,
            50 ,
        ) ;

        $this->assertSame
        (
            'FOR u IN users FILTER u.age > @value1 SORT u.name DESC LIMIT @value2 RETURN u' ,
            $query->query ,
        ) ;
        $this->assertSame
        (
            [ 'value1' => 18 , 'value2' => 50 ] ,
            $query->bindVars ,
        ) ;
    }

    public function testNullValueIsBoundAsNull() :void
    {
        $query = aql( 'FOR u IN users FILTER u.deleted == ? RETURN u' , null ) ;

        $this->assertSame( 'FOR u IN users FILTER u.deleted == @value1 RETURN u' , $query->query    ) ;
        $this->assertSame( [ 'value1' => null ]                                   , $query->bindVars ) ;
    }

    public function testArrayValueIsBoundAsArray() :void
    {
        $query = aql( 'FOR u IN users FILTER u.role IN ? RETURN u' , [ 'admin' , 'editor' ] ) ;

        $this->assertSame( 'FOR u IN users FILTER u.role IN @value1 RETURN u' , $query->query    ) ;
        $this->assertSame( [ 'value1' => [ 'admin' , 'editor' ] ]              , $query->bindVars ) ;
    }

    public function testMoreValuesThanPlaceholdersAreIgnored() :void
    {
        // Extra trailing values are silently ignored — the resulting query is
        // built from the consumed placeholders only.
        $query = aql( 'RETURN ?' , 'kept' , 'ignored' ) ;

        $this->assertSame( 'RETURN @value1'   , $query->query    ) ;
        $this->assertSame( [ 'value1' => 'kept' ] , $query->bindVars ) ;
    }

    public function testFewerValuesThanPlaceholdersBindNull() :void
    {
        // Missing values bind to null rather than raising an error.
        $query = aql( 'RETURN ?, ?' , 'first' ) ;

        $this->assertSame( 'RETURN @value1, @value2' , $query->query    ) ;
        $this->assertSame
        (
            [ 'value1' => 'first' , 'value2' => null ] ,
            $query->bindVars ,
        ) ;
    }
}
