<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\stripArrayExpansion;

/**
 * Test suite for the stripArrayExpansion() helper function.
 */
#[CoversFunction('oihana\arango\db\helpers\stripArrayExpansion')]
class StripArrayExpansionTest extends TestCase
{
    public static function providePaths() : array
    {
        return
        [
            'plain field'              => [ 'name'                              , 'name' ] ,
            'dotted object path'       => [ 'description.fr'                    , 'description.fr' ] ,
            'single array sub-field'   => [ 'contactPoints[*].email'           , 'contactPoints.email' ] ,
            'array leaf only'          => [ 'tags[*]'                           , 'tags' ] ,
            'multi-level expansion'    => [ 'employee[*].contactPoint[*].email' , 'employee.contactPoint.email' ] ,
            'no marker at all'         => [ 'a.b.c'                             , 'a.b.c' ] ,
            'empty string'             => [ ''                                  , '' ] ,
        ] ;
    }

    #[Test]
    #[DataProvider('providePaths')]
    public function stripsEveryArrayExpansionMarker( string $path , string $expected ) : void
    {
        $this->assertSame( $expected , stripArrayExpansion( $path ) ) ;
    }
}
