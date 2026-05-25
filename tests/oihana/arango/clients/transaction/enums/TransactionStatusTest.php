<?php

namespace tests\oihana\arango\clients\transaction\enums ;

use oihana\arango\clients\transaction\enums\TransactionStatus ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see TransactionStatus} — lifecycle states of a streaming
 * transaction as reported by the ArangoDB server.
 */
#[CoversClass( TransactionStatus::class )]
class TransactionStatusTest extends TestCase
{
    public function testCanonicalValues() :void
    {
        $this->assertSame( 'aborted'   , TransactionStatus::ABORTED   ) ;
        $this->assertSame( 'committed' , TransactionStatus::COMMITTED ) ;
        $this->assertSame( 'running'   , TransactionStatus::RUNNING   ) ;
    }

    public function testIncludesRecognisesKnownStatuses() :void
    {
        $this->assertTrue ( TransactionStatus::includes( 'aborted'   ) ) ;
        $this->assertTrue ( TransactionStatus::includes( 'committed' ) ) ;
        $this->assertTrue ( TransactionStatus::includes( 'running'   ) ) ;
        $this->assertFalse( TransactionStatus::includes( 'unknown'   ) ) ;
    }
}
