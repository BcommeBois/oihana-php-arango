<?php

namespace tests\oihana\arango\db\options;

use oihana\arango\db\enums\OverwriteMode;
use oihana\arango\db\options\InsertOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see InsertOptions}, and in particular for the `set` hook
 * of `$overwriteMode`: the value is not stored as given but normalized through
 * {@see OverwriteMode::get()}, so anything outside the enumeration collapses to
 * `null` instead of reaching ArangoDB as an invalid `OPTIONS { overwriteMode }`.
 */
#[CoversClass(InsertOptions::class)]
class InsertOptionsTest extends TestCase
{
    public function testOverwriteModeDefaultsToNull() :void
    {
        $this->assertNull( new InsertOptions()->overwriteMode ) ;
    }

    public function testOverwriteModeAcceptsEveryEnumeratedValue() :void
    {
        $options = new InsertOptions() ;

        foreach( [ OverwriteMode::CONFLICT , OverwriteMode::IGNORE , OverwriteMode::REPLACE , OverwriteMode::UPDATE ] as $mode )
        {
            $options->overwriteMode = $mode ;
            $this->assertSame( $mode , $options->overwriteMode ) ;
        }
    }

    public function testOverwriteModeIsPopulatedFromTheConstructorInit() :void
    {
        $options = new InsertOptions( [ 'overwriteMode' => OverwriteMode::UPDATE ] ) ;

        $this->assertSame( OverwriteMode::UPDATE , $options->overwriteMode ) ;
    }

    public function testOverwriteModeRejectsAnUnknownValue() :void
    {
        $options = new InsertOptions( [ 'overwriteMode' => 'nonsense' ] ) ;

        $this->assertNull( $options->overwriteMode ) ;
    }

    public function testOverwriteModeCanBeClearedBackToNull() :void
    {
        $options = new InsertOptions() ;

        $options->overwriteMode = OverwriteMode::REPLACE ;
        $options->overwriteMode = null ;

        $this->assertNull( $options->overwriteMode ) ;
    }

    public function testOtherOptionsArePopulatedFromTheInit() :void
    {
        $options = new InsertOptions
        ([
            'exclusive'        => true ,
            'ignoreErrors'     => true ,
            'overwriteMode'    => OverwriteMode::IGNORE ,
            'versionAttribute' => 'externalVersion' ,
            'waitForSync'      => false ,
        ]) ;

        $this->assertTrue ( $options->exclusive ) ;
        $this->assertTrue ( $options->ignoreErrors ) ;
        $this->assertFalse( $options->waitForSync ) ;
        $this->assertSame ( 'externalVersion'      , $options->versionAttribute ) ;
        $this->assertSame ( OverwriteMode::IGNORE  , $options->overwriteMode ) ;
    }
}
