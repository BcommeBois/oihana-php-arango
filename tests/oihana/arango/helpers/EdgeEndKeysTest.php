<?php

namespace tests\oihana\arango\helpers;

use ArrayIterator;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

use org\schema\constants\Schema;

use function oihana\arango\helpers\edgeEndKeys;

/**
 * Unit coverage for {@see edgeEndKeys()} : the distinct `_key`s of one end of a list of edges.
 */
#[CoversFunction( 'oihana\arango\helpers\edgeEndKeys' )]
class EdgeEndKeysTest extends TestCase
{
    public function testAnEmptyListGivesNoKey() :void
    {
        $this->assertSame( [] , edgeEndKeys( [] , Schema::_FROM ) ) ;
    }

    public function testAnIteratorIsRead() :void
    {
        $this->assertSame( [ 'p1' ] , edgeEndKeys( new ArrayIterator([ [ Schema::_TO => 'policies/p1' ] ]) , Schema::_TO ) ) ;
    }

    public function testTheKeysAreDistinctInTheirFirstOrder() :void
    {
        $edges =
        [
            [ Schema::_FROM => 'roles/b' , Schema::_TO => 'policies/p1' ] ,
            [ Schema::_FROM => 'roles/a' , Schema::_TO => 'policies/p1' ] ,
            [ Schema::_FROM => 'roles/b' , Schema::_TO => 'policies/p2' ] ,
        ] ;

        $this->assertSame( [ 'b' , 'a' ]   , edgeEndKeys( $edges , Schema::_FROM ) ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , edgeEndKeys( $edges , Schema::_TO   ) ) ;
    }

    public function testAnEdgeWithoutTheEndIsSkipped() :void
    {
        $this->assertSame( [ 'a' ] , edgeEndKeys( [ [ Schema::_TO => 'policies/p1' ] , [ Schema::_FROM => 'roles/a' ] , null ] , Schema::_FROM ) ) ;
    }

    public function testNumericKeysStayStrings() :void
    {
        $this->assertSame( [ '1234567890' , '42' ] , edgeEndKeys( [ [ Schema::_FROM => 'services/1234567890' ] , [ Schema::_FROM => 'services/42' ] ] , Schema::_FROM ) ) ;
    }
}
