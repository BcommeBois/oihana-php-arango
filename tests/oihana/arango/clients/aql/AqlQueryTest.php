<?php

namespace tests\oihana\arango\clients\aql ;

use oihana\arango\clients\aql\AqlQuery ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see AqlQuery} — immutable representation of an AQL query
 * and its bind parameters.
 */
#[CoversClass( AqlQuery::class )]
class AqlQueryTest extends TestCase
{
    public function testConstructStoresQueryAndBindVars() :void
    {
        $query = new AqlQuery
        (
            'FOR u IN users FILTER u.age > @minAge RETURN u' ,
            [ 'minAge' => 18 ] ,
        ) ;

        $this->assertSame( 'FOR u IN users FILTER u.age > @minAge RETURN u' , $query->query    ) ;
        $this->assertSame( [ 'minAge' => 18 ]                                , $query->bindVars ) ;
    }

    public function testDefaultBindVarsIsEmptyArray() :void
    {
        $query = new AqlQuery( 'RETURN 1' ) ;

        $this->assertSame( 'RETURN 1' , $query->query    ) ;
        $this->assertSame( []         , $query->bindVars ) ;
    }
}
