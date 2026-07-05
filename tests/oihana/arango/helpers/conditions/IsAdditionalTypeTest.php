<?php

namespace tests\oihana\arango\helpers\conditions;

use oihana\exceptions\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\helpers\conditions\isAdditionalType;

class IsAdditionalTypeTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testStringSchemaTypeBuildsEqualCondition() :void
    {
        $this->assertSame( [ "doc.additionalType == 'Person'" ] , isAdditionalType( 'Person' ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testArraySchemaTypeBuildsInCondition() :void
    {
        $this->assertSame
        (
            [ "doc.additionalType IN ['Person','Organization']" ] ,
            isAdditionalType( [ 'Person' , 'Organization' ] )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testBindParameterIsKeptRaw() :void
    {
        $this->assertSame( [ 'doc.additionalType == @types' ] , isAdditionalType( '@types' ) ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testCustomDocRefIsUsed() :void
    {
        $this->assertSame
        (
            [ "other.additionalType == 'Person'" ] ,
            isAdditionalType( 'Person' , 'other' )
        ) ;
    }
}
