<?php

namespace tests\oihana\arango\clients\http ;

use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\ConflictException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\exceptions\MaintenanceException ;
use oihana\arango\clients\http\RetryPolicy ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see RetryPolicy} — capped exponential back-off + retry gating.
 */
#[CoversClass( RetryPolicy::class )]
class RetryPolicyTest extends TestCase
{
    // =========================================================================
    // Defaults
    // =========================================================================

    public function testDefaultsMatchClassConstants() :void
    {
        $policy = new RetryPolicy() ;

        $this->assertSame( RetryPolicy::DEFAULT_MAX_ATTEMPTS  , $policy->maxAttempts ) ;
        $this->assertSame( RetryPolicy::DEFAULT_BASE_DELAY_MS , $policy->baseDelayMs ) ;
        $this->assertSame( RetryPolicy::DEFAULT_MAX_DELAY_MS  , $policy->maxDelayMs  ) ;
    }

    // =========================================================================
    // shouldRetry()
    // =========================================================================

    public function testShouldRetryTrueWhenExceptionSafeAndBudgetAllows() :void
    {
        $policy = new RetryPolicy( maxAttempts : 3 ) ;

        $this->assertTrue( $policy->shouldRetry( new ConflictException()    , 1 ) ) ;
        $this->assertTrue( $policy->shouldRetry( new MaintenanceException() , 2 ) ) ;
    }

    public function testShouldRetryFalseWhenExceptionNotSafeToRetry() :void
    {
        $policy = new RetryPolicy( maxAttempts : 3 ) ;

        $this->assertFalse( $policy->shouldRetry( new ArangoException() , 1 ) ) ;
        $this->assertFalse( $policy->shouldRetry( new HttpException()   , 1 ) ) ;
    }

    public function testShouldRetryFalseWhenBudgetExhausted() :void
    {
        $policy = new RetryPolicy( maxAttempts : 3 ) ;

        $this->assertFalse( $policy->shouldRetry( new ConflictException() , 3 ) ) ;
        $this->assertFalse( $policy->shouldRetry( new ConflictException() , 4 ) ) ;
    }

    public function testShouldRetryFalseWhenMaxAttemptsIsOne() :void
    {
        // maxAttempts: 1 means "no retry allowed".
        $policy = new RetryPolicy( maxAttempts : 1 ) ;

        $this->assertFalse( $policy->shouldRetry( new ConflictException() , 1 ) ) ;
    }

    // =========================================================================
    // delayMs()
    // =========================================================================

    public function testDelayMsIsExponential() :void
    {
        $policy = new RetryPolicy( baseDelayMs : 100 , maxDelayMs : 10000 ) ;

        $this->assertSame( 100  , $policy->delayMs( 1 ) ) ;
        $this->assertSame( 200  , $policy->delayMs( 2 ) ) ;
        $this->assertSame( 400  , $policy->delayMs( 3 ) ) ;
        $this->assertSame( 800  , $policy->delayMs( 4 ) ) ;
        $this->assertSame( 1600 , $policy->delayMs( 5 ) ) ;
    }

    public function testDelayMsIsCappedAtMaxDelayMs() :void
    {
        $policy = new RetryPolicy( baseDelayMs : 100 , maxDelayMs : 500 ) ;

        $this->assertSame( 100 , $policy->delayMs( 1 ) ) ;
        $this->assertSame( 200 , $policy->delayMs( 2 ) ) ;
        $this->assertSame( 400 , $policy->delayMs( 3 ) ) ;
        $this->assertSame( 500 , $policy->delayMs( 4 ) ) ; // would be 800, capped at 500
        $this->assertSame( 500 , $policy->delayMs( 10 ) ) ;
    }

    public function testDelayMsIsZeroForAttemptLessThanOne() :void
    {
        $policy = new RetryPolicy() ;

        $this->assertSame( 0 , $policy->delayMs( 0  ) ) ;
        $this->assertSame( 0 , $policy->delayMs( -3 ) ) ;
    }

    public function testDelayMsHonoursZeroBaseDelay() :void
    {
        // Useful in test contexts to avoid blocking on usleep().
        $policy = new RetryPolicy( baseDelayMs : 0 ) ;

        $this->assertSame( 0 , $policy->delayMs( 1 ) ) ;
        $this->assertSame( 0 , $policy->delayMs( 5 ) ) ;
    }
}
