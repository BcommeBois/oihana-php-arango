<?php

namespace tests\oihana\arango\clients\aql\helpers ;

use oihana\arango\clients\aql\AqlLiteral ;
use oihana\arango\clients\aql\AqlQuery ;

use PHPUnit\Framework\TestCase ;

use function oihana\arango\clients\aql\helpers\aql ;
use function oihana\arango\clients\aql\helpers\aqlLiteral ;
use function oihana\arango\clients\aql\helpers\join ;

/**
 * Tests for the {@see join()} helper — assembles a list of AQL fragments
 * into a single {@see AqlQuery}, with bind-collision rewriting.
 */
class JoinFunctionTest extends TestCase
{
    public function testEmptyInputReturnsEmptyAqlQuery() :void
    {
        $result = join( [] ) ;

        $this->assertInstanceOf( AqlQuery::class , $result ) ;
        $this->assertSame( ''  , $result->query    ) ;
        $this->assertSame( [] , $result->bindVars ) ;
    }

    public function testJoinsAqlLiteralsVerbatim() :void
    {
        $result = join
        (
            [ aqlLiteral( 'FILTER a' ) , aqlLiteral( 'FILTER b' ) ] ,
            ' AND ' ,
        ) ;

        $this->assertSame( 'FILTER a AND FILTER b' , $result->query    ) ;
        $this->assertSame( []                       , $result->bindVars ) ;
    }

    public function testBindsScalarFragmentsByPosition() :void
    {
        $result = join( [ 1 , 'two' , true ] , ', ' ) ;

        $this->assertSame( '@j0, @j1, @j2' , $result->query ) ;
        $this->assertSame
        (
            [ 'j0' => 1 , 'j1' => 'two' , 'j2' => true ] ,
            $result->bindVars ,
        ) ;
    }

    public function testMergesAqlQueryFragmentsWithoutCollision() :void
    {
        $f1 = aql( 'FILTER u.role == ?'   , 'admin' ) ; // bindVars: { value1: 'admin' }
        $f2 = aql( 'FILTER u.active == ?' , true    ) ; // bindVars: { value1: true } ← collision

        $result = join( [ $f1 , $f2 ] , ' AND ' ) ;

        $this->assertSame
        (
            'FILTER u.role == @value1 AND FILTER u.active == @j1_value1' ,
            $result->query ,
        ) ;
        $this->assertSame
        (
            [ 'value1' => 'admin' , 'j1_value1' => true ] ,
            $result->bindVars ,
        ) ;
    }

    public function testRenamesCollectionBindOnCollision() :void
    {
        $f1 = new AqlQuery( 'FOR u IN @@col RETURN u' , [ '@col' => 'users'  ] ) ;
        $f2 = new AqlQuery( 'FOR p IN @@col RETURN p' , [ '@col' => 'people' ] ) ;

        $result = join( [ $f1 , $f2 ] , ' UNION ' ) ;

        $this->assertSame
        (
            'FOR u IN @@col RETURN u UNION FOR p IN @@j1_col RETURN p' ,
            $result->query ,
        ) ;
        $this->assertSame
        (
            [ '@col' => 'users' , '@j1_col' => 'people' ] ,
            $result->bindVars ,
        ) ;
    }

    public function testKeepsNonCollidingBindsAsIs() :void
    {
        $f1 = new AqlQuery( 'FILTER u.minAge >= @minAge' , [ 'minAge' => 18    ] ) ;
        $f2 = new AqlQuery( 'FILTER u.role   == @role'   , [ 'role'   => 'ops' ] ) ;

        $result = join( [ $f1 , $f2 ] , ' AND ' ) ;

        $this->assertSame
        (
            'FILTER u.minAge >= @minAge AND FILTER u.role   == @role' ,
            $result->query ,
        ) ;
        $this->assertSame
        (
            [ 'minAge' => 18 , 'role' => 'ops' ] ,
            $result->bindVars ,
        ) ;
    }

    public function testMixesQueryAndLiteralAndScalarFragments() :void
    {
        $result = join
        (
            [
                aql( 'FILTER u.role == ?' , 'admin' ) ,
                aqlLiteral( 'SORT u.name' ) ,
                42 ,
            ] ,
            ' ' ,
        ) ;

        $this->assertSame
        (
            'FILTER u.role == @value1 SORT u.name @j2' ,
            $result->query ,
        ) ;
        $this->assertSame
        (
            [ 'value1' => 'admin' , 'j2' => 42 ] ,
            $result->bindVars ,
        ) ;
    }

    public function testDefaultSeparatorIsSingleSpace() :void
    {
        $result = join
        (
            [ aqlLiteral( 'A' ) , aqlLiteral( 'B' ) , aqlLiteral( 'C' ) ] ,
        ) ;

        $this->assertSame( 'A B C' , $result->query ) ;
    }

    public function testSingleFragmentRoundTripsCleanly() :void
    {
        $f      = aql( 'FILTER u.age > ?' , 18 ) ;
        $result = join( [ $f ] ) ;

        $this->assertSame( $f->query    , $result->query    ) ;
        $this->assertSame( $f->bindVars , $result->bindVars ) ;
    }

    public function testRewritingDoesNotTouchUnrelatedBindReferences() :void
    {
        // f1 binds `value1`; f2 also binds `value1` so j1_value1 is created,
        // but f2 also mentions an unrelated `@somethingElse` that must NOT be touched.
        $f1 = new AqlQuery( 'FILTER u.x == @value1' , [ 'value1' => 'a' ] ) ;
        $f2 = new AqlQuery
        (
            'FILTER u.y == @value1 AND u.z == @somethingElse' ,
            [ 'value1' => 'b' , 'somethingElse' => 'c' ] ,
        ) ;

        $result = join( [ $f1 , $f2 ] , ' AND ' ) ;

        $this->assertSame
        (
            'FILTER u.x == @value1 AND FILTER u.y == @j1_value1 AND u.z == @somethingElse' ,
            $result->query ,
        ) ;
        $this->assertSame
        (
            [ 'value1' => 'a' , 'j1_value1' => 'b' , 'somethingElse' => 'c' ] ,
            $result->bindVars ,
        ) ;
    }

    public function testValueBindCollisionDoesNotCorruptCollectionBind() :void
    {
        // f1 holds a value bind `col` (NOT a collection bind).
        // f2 holds a collection bind `@col` — different keys, no collision.
        $f1 = new AqlQuery( 'LET col = @col'      , [ 'col'  => 'literal'        ] ) ;
        $f2 = new AqlQuery( 'FOR u IN @@col RETURN u' , [ '@col' => 'realCollection' ] ) ;

        $result = join( [ $f1 , $f2 ] , ' ' ) ;

        // No rename — they are different bind names (col vs @col).
        $this->assertSame
        (
            'LET col = @col FOR u IN @@col RETURN u' ,
            $result->query ,
        ) ;
        $this->assertSame
        (
            [ 'col' => 'literal' , '@col' => 'realCollection' ] ,
            $result->bindVars ,
        ) ;
    }
}
