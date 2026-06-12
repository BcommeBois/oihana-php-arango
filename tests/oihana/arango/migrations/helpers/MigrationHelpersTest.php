<?php

namespace tests\oihana\arango\migrations\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\migrations\helpers\dropFieldQuery;
use function oihana\arango\migrations\helpers\renameFieldQuery;
use function oihana\arango\migrations\helpers\setDefaultQuery;

/**
 * Unit coverage for the migration toolbox query builders — pure AQL
 * construction, no server.
 *
 * @package tests\oihana\arango\migrations\helpers
 * @author  Marc Alcaraz
 */
class MigrationHelpersTest extends TestCase
{
    public function testRenameFieldQuery() :void
    {
        $this->assertSame
        (
            'FOR doc IN places FILTER HAS( doc , "tel" ) UPDATE doc WITH { phone: doc.tel, tel: null } IN places OPTIONS { keepNull: false }' ,
            renameFieldQuery( 'places' , 'tel' , 'phone' )
        ) ;
    }

    public function testDropFieldQuery() :void
    {
        $this->assertSame
        (
            'FOR doc IN places FILTER HAS( doc , "legacy" ) UPDATE doc WITH { legacy: null } IN places OPTIONS { keepNull: false }' ,
            dropFieldQuery( 'places' , 'legacy' )
        ) ;
    }

    public function testSetDefaultQueryWithAStringValue() :void
    {
        $this->assertSame
        (
            'FOR doc IN orders FILTER doc.status == null UPDATE doc WITH { status: "pending" } IN orders' ,
            setDefaultQuery( 'orders' , 'status' , 'pending' )
        ) ;
    }

    public function testSetDefaultQueryEncodesNonScalarValues() :void
    {
        $this->assertSame
        (
            'FOR doc IN orders FILTER doc.tags == null UPDATE doc WITH { tags: [] } IN orders' ,
            setDefaultQuery( 'orders' , 'tags' , [] )
        ) ;
    }
}
