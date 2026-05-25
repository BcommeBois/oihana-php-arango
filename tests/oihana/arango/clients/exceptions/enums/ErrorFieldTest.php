<?php

namespace tests\oihana\arango\clients\exceptions\enums ;

use oihana\arango\clients\exceptions\enums\ErrorField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ErrorField} — JSON field names returned by ArangoDB in
 * error responses. Values must match the canonical server spelling.
 */
#[CoversClass( ErrorField::class )]
class ErrorFieldTest extends TestCase
{
    public function testCanonicalFieldNames() :void
    {
        $this->assertSame( 'code'         , ErrorField::CODE          ) ;
        $this->assertSame( 'error'        , ErrorField::ERROR         ) ;
        $this->assertSame( 'errorMessage' , ErrorField::ERROR_MESSAGE ) ;
        $this->assertSame( 'errorNum'     , ErrorField::ERROR_NUM     ) ;
    }

    public function testIncludesRecognisesAllFields() :void
    {
        $this->assertTrue ( ErrorField::includes( 'code'         ) ) ;
        $this->assertTrue ( ErrorField::includes( 'error'        ) ) ;
        $this->assertTrue ( ErrorField::includes( 'errorMessage' ) ) ;
        $this->assertTrue ( ErrorField::includes( 'errorNum'     ) ) ;
        $this->assertFalse( ErrorField::includes( 'unknown'      ) ) ;
    }
}
