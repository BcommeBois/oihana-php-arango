<?php

namespace tests\oihana\arango\helpers\conditions;

use InvalidArgumentException;
use oihana\exceptions\UnsupportedOperationException;
use org\schema\Thing;
use PHPUnit\Framework\TestCase;
use stdClass;
use xyz\oihana\schema\organizations\Customer;
use xyz\oihana\schema\places\CustomerSite;
use xyz\oihana\schema\places\Warehouse;

use function oihana\arango\helpers\conditions\isSchemaType;

class IsSchemaTypeTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testSingleClassBuildsEqualCondition() :void
    {
        $this->assertSame
        (
            [ "doc.additionalType == 'https://schema.oihana.xyz/Customer'" ] ,
            isSchemaType( Customer::class )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testMultipleClassesBuildInCondition() :void
    {
        $this->assertSame
        (
            [ "doc.additionalType IN ['https://schema.oihana.xyz/CustomerSite','https://schema.oihana.xyz/Warehouse']" ] ,
            isSchemaType( [ CustomerSite::class , Warehouse::class ] )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testSingleElementArrayCollapsesToEqualCondition() :void
    {
        $this->assertSame
        (
            [ "doc.additionalType == 'https://schema.oihana.xyz/Customer'" ] ,
            isSchemaType( [ Customer::class ] )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testCustomDocRefIsUsed() :void
    {
        $this->assertSame
        (
            [ "other.additionalType == 'https://schema.oihana.xyz/Customer'" ] ,
            isSchemaType( Customer::class , 'other' )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testNonThingClassThrowsInvalidArgumentException() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( stdClass::class ) ;
        isSchemaType( stdClass::class ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testThingBaseClassItselfBuildsEqualCondition() :void
    {
        $this->assertSame
        (
            [ "doc.additionalType == 'https://schema.org/Thing'" ] ,
            isSchemaType( Thing::class )
        ) ;
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testOneInvalidClassAmongValidOnesThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        isSchemaType( [ Customer::class , stdClass::class ] ) ;
    }
}
