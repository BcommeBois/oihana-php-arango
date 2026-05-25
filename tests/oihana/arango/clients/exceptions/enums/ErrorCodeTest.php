<?php

namespace tests\oihana\arango\clients\exceptions\enums ;

use oihana\arango\clients\exceptions\enums\ErrorCode ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ErrorCode} — the catalogue of ArangoDB internal error
 * numbers that the client maps to dedicated exception subclasses.
 *
 * Values are stable across ArangoDB versions and must match the canonical
 * codes returned by the server.
 *
 * @see https://docs.arangodb.com/stable/develop/error-codes-and-meanings/
 */
#[CoversClass( ErrorCode::class )]
class ErrorCodeTest extends TestCase
{
    public function testCanonicalArangoCodes() :void
    {
        $this->assertSame( 1203 , ErrorCode::ARANGO_COLLECTION_NOT_FOUND       ) ;
        $this->assertSame( 1200 , ErrorCode::ARANGO_CONFLICT                   ) ;
        $this->assertSame( 1229 , ErrorCode::ARANGO_DATABASE_NAME_INVALID      ) ;
        $this->assertSame( 1228 , ErrorCode::ARANGO_DATABASE_NOT_FOUND         ) ;
        $this->assertSame( 1202 , ErrorCode::ARANGO_DOCUMENT_NOT_FOUND         ) ;
        $this->assertSame( 1218 , ErrorCode::ARANGO_DOCUMENT_REV_BAD           ) ;
        $this->assertSame( 1207 , ErrorCode::ARANGO_DUPLICATE_NAME             ) ;
        $this->assertSame( 1208 , ErrorCode::ARANGO_ILLEGAL_NAME               ) ;
        $this->assertSame( 1221 , ErrorCode::ARANGO_INDEX_NOT_FOUND            ) ;
        $this->assertSame( 1210 , ErrorCode::ARANGO_UNIQUE_CONSTRAINT_VIOLATED ) ;
        $this->assertSame( 3002 , ErrorCode::CLUSTER_BACKEND_UNAVAILABLE       ) ;
    }

    public function testCanonicalTransactionCodes() :void
    {
        $this->assertSame( 1652 , ErrorCode::TRANSACTION_DISALLOWED_OPERATION ) ;
        $this->assertSame( 1654 , ErrorCode::TRANSACTION_OPERATION_TIMEOUT    ) ;
        $this->assertSame( 1655 , ErrorCode::TRANSACTION_NOT_FOUND            ) ;
        $this->assertSame( 1656 , ErrorCode::TRANSACTION_ABORTED              ) ;
        $this->assertSame( 1657 , ErrorCode::TRANSACTION_ALREADY_COMMITTED    ) ;
        $this->assertSame( 1658 , ErrorCode::TRANSACTION_ALREADY_ABORTED      ) ;
    }

    public function testEnumsAreIntegers() :void
    {
        foreach ( ErrorCode::enums() as $value )
        {
            $this->assertIsInt( $value ) ;
        }
    }

    public function testReverseLookupReturnsConstantName() :void
    {
        $this->assertSame( 'ARANGO_CONFLICT'             , ErrorCode::getConstant( '1200' ) ) ;
        $this->assertSame( 'CLUSTER_BACKEND_UNAVAILABLE' , ErrorCode::getConstant( '3002' ) ) ;
    }

    public function testIncludesRecognisesKnownCodes() :void
    {
        $this->assertTrue ( ErrorCode::includes( 1200 ) ) ;
        $this->assertTrue ( ErrorCode::includes( 3002 ) ) ;
        $this->assertFalse( ErrorCode::includes( 9999 ) ) ;
    }
}
