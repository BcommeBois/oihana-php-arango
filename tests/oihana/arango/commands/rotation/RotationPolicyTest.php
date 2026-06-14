<?php

namespace tests\oihana\arango\commands\rotation;

use DateTimeImmutable;

use oihana\arango\commands\rotation\RotationPolicy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the {@see RotationPolicy} value object.
 */
#[CoversClass(RotationPolicy::class)]
class RotationPolicyTest extends TestCase
{
    public function testDefaultsWhenNoInit() :void
    {
        $policy = new RotationPolicy() ;

        $this->assertNull( $policy->keep ) ;
        $this->assertSame( [] , $policy->buckets ) ;
        $this->assertNull( $policy->cutoff ) ;
        $this->assertNull( $policy->maxTotalBytes ) ;
    }

    public function testHydratesEveryField() :void
    {
        $cutoff = new DateTimeImmutable( '-30 days' ) ;

        $policy = new RotationPolicy
        ([
            RotationPolicy::KEEP            => 7 ,
            RotationPolicy::BUCKETS         => [ 'mydb' => 3 ] ,
            RotationPolicy::CUTOFF          => $cutoff ,
            RotationPolicy::MAX_TOTAL_BYTES => 5_000 ,
        ]) ;

        $this->assertSame( 7 , $policy->keep ) ;
        $this->assertSame( [ 'mydb' => 3 ] , $policy->buckets ) ;
        $this->assertSame( $cutoff , $policy->cutoff ) ;
        $this->assertSame( 5_000 , $policy->maxTotalBytes ) ;
    }

    public function testKeepZeroIsKept() :void
    {
        // 0 is a valid value (prune all but the floor) and must not fall back to null.
        $this->assertSame( 0 , new RotationPolicy( [ RotationPolicy::KEEP => 0 ] )->keep ) ;
    }

    public function testCastsAndIgnoresNonArrayBuckets() :void
    {
        $policy = new RotationPolicy
        ([
            RotationPolicy::KEEP            => '4' ,        // cast to int
            RotationPolicy::MAX_TOTAL_BYTES => '1024' ,     // cast to int
            RotationPolicy::BUCKETS         => 'not-an-array' ,
        ]) ;

        $this->assertSame( 4 , $policy->keep ) ;
        $this->assertSame( 1024 , $policy->maxTotalBytes ) ;
        $this->assertSame( [] , $policy->buckets ) ; // non-array ignored
    }

    public function testHydratesFromObject() :void
    {
        $policy = new RotationPolicy( (object) [ RotationPolicy::KEEP => 2 ] ) ;
        $this->assertSame( 2 , $policy->keep ) ;
    }

    public function testConstantsAreThePropertyNames() :void
    {
        $this->assertSame( 'keep' , RotationPolicy::KEEP ) ;
        $this->assertSame( 'buckets' , RotationPolicy::BUCKETS ) ;
        $this->assertSame( 'cutoff' , RotationPolicy::CUTOFF ) ;
        $this->assertSame( 'maxTotalBytes' , RotationPolicy::MAX_TOTAL_BYTES ) ;
    }
}
