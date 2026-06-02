<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\ActiveTrait;
use oihana\arango\models\traits\aql\BindTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see ActiveTrait} (and the BindTrait it uses for
 * `$this->bind()`) for isolated testing. The bind name is explicit, so the
 * emitted AQL and `$binds` are deterministic.
 */
class ActiveTraitStub
{
    use ActiveTrait ,
        BindTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
    }
}

/**
 * Characterization coverage for {@see ActiveTrait}: initializeActivable and
 * prepareActive (the `doc.active == @active` predicate, gated by the
 * `$activable` flag and the presence of an `active` request value).
 */
class ActiveTraitTest extends TestCase
{
    private function stub( bool $activable = true ) :ActiveTraitStub
    {
        $stub = new ActiveTraitStub() ;
        $stub->activable = $activable ;
        return $stub ;
    }

    // ---------------------------------------------------------------- initializeActivable

    public function testInitializeActivableSetsFlagAndReturnsSelf() :void
    {
        $stub = new ActiveTraitStub() ;
        $result = $stub->initializeActivable( [ Arango::ACTIVABLE => true ] ) ;

        $this->assertSame( $stub , $result ) ;
        $this->assertTrue( $stub->activable ) ;
    }

    public function testInitializeActivableDefaultsToFalseWhenKeyMissing() :void
    {
        $stub = new ActiveTraitStub() ;
        $stub->activable = true ;
        $stub->initializeActivable( [] ) ;

        $this->assertFalse( $stub->activable ) ;
    }

    // ---------------------------------------------------------------- prepareActive

    public function testReturnsEmptyWhenNotActivable() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub( false )->prepareActive( [ Arango::ACTIVE => true ] , $binds ) ) ;
    }

    public function testReturnsEmptyWhenNoActiveValueProvided() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->prepareActive( [] , $binds ) ) ;
    }

    public function testActiveTrueBindsOne() :void
    {
        $binds = [] ;
        $this->assertSame( 'doc.active == @active' , $this->stub()->prepareActive( [ Arango::ACTIVE => true ] , $binds ) ) ;
        $this->assertSame( [ 'active' => 1 ] , $binds ) ;
    }

    public function testActiveFalseBindsZero() :void
    {
        $binds = [] ;
        $this->assertSame( 'doc.active == @active' , $this->stub()->prepareActive( [ Arango::ACTIVE => false ] , $binds ) ) ;
        $this->assertSame( [ 'active' => 0 ] , $binds ) ;
    }

    public function testCustomDocumentReferenceIsUsed() :void
    {
        $binds = [] ;
        $this->assertSame( 'x.active == @active' , $this->stub()->prepareActive( [ Arango::ACTIVE => true ] , $binds , 'x' ) ) ;
    }
}
