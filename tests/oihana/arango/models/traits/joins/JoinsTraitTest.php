<?php

namespace tests\oihana\arango\models\traits\joins;

use oihana\arango\models\traits\joins\JoinsTrait;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Minimal host composing {@see JoinsTrait}.
 */
class JoinsTraitHost
{
    use JoinsTrait ;
}

/**
 * Unit coverage for {@see JoinsTrait}.
 */
#[CoversTrait(JoinsTrait::class)]
class JoinsTraitTest extends TestCase
{
    public function testJoinsDefaultsToNull() :void
    {
        $this->assertNull( new JoinsTraitHost()->joins ) ;
    }

    public function testInitializeJoinsSetsTheDefinitionsFromInit() :void
    {
        $host = new JoinsTraitHost() ;

        $returned = $host->initializeJoins( [ JoinsTraitHost::JOINS => [ 'additionalType' => [ 'model' => 'placeTypes' ] ] ] ) ;

        $this->assertSame( [ 'additionalType' => [ 'model' => 'placeTypes' ] ] , $host->joins ) ;
        $this->assertSame( $host , $returned ) ;
    }

    public function testInitializeJoinsKeepsTheCurrentValueWhenAbsent() :void
    {
        $host = new JoinsTraitHost() ;
        $host->joins = [ 'kept' => true ] ;

        $host->initializeJoins() ;

        $this->assertSame( [ 'kept' => true ] , $host->joins ) ;
    }

    public function testReleasesJoinsResetsToNull() :void
    {
        $host = new JoinsTraitHost() ;
        $host->joins = [ 'x' => 1 ] ;

        $returned = $host->releasesJoins() ;

        $this->assertNull( $host->joins ) ;
        $this->assertSame( $host , $returned ) ;
    }
}
