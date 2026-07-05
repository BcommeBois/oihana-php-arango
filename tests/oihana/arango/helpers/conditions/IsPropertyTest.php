<?php

namespace tests\oihana\arango\helpers\conditions;

use InvalidArgumentException;
use oihana\exceptions\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\helpers\conditions\isProperty;

class IsPropertyTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testScalarValueBuildsEqualCondition() :void
    {
        $this->assertSame( [ "doc.status == 'active'" ] , isProperty( 'status' , 'active' ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testArrayValueBuildsInCondition() :void
    {
        $this->assertSame
        (
            [ "doc.status IN ['active','pending']" ] ,
            isProperty( 'status' , [ 'active' , 'pending' ] )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testBindParameterIsKeptRaw() :void
    {
        $this->assertSame( [ 'doc.tags == @tags' ] , isProperty( 'tags' , '@tags' ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testDocumentReferenceIsKeptRaw() :void
    {
        $this->assertSame( [ 'doc.name == doc2.name' ] , isProperty( 'name' , 'doc2.name' ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testCustomDocRefIsUsed() :void
    {
        $this->assertSame( [ "other.status == 'active'" ] , isProperty( 'status' , 'active' , 'other' ) ) ;
    }

    public function testNullValueThrowsInvalidArgumentException() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        isProperty( 'status' , null ) ;
    }

    public function testEmptyArrayValueThrowsInvalidArgumentException() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        isProperty( 'status' , [] ) ;
    }

    public function testBlankStringValueThrowsInvalidArgumentException() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        isProperty( 'status' , '   ' ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testUnsupportedValueTypeThrowsUnsupportedOperationException() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        isProperty( 'status' , [ tmpfile() ] ) ;
    }
}
