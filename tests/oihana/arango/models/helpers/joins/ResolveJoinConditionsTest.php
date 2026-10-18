<?php

namespace tests\oihana\arango\models\helpers\joins;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\joins\resolveJoinConditions;

/**
 * The extra predicates of a join definition — {@see resolveJoinConditions()}.
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class ResolveJoinConditionsTest extends TestCase
{
    public function testNothingDeclaredIsNoPredicate() :void
    {
        $this->assertSame( [] , resolveJoinConditions( [] , 'j' , 'doc' ) ) ;
    }

    public function testAnArrayIsTakenAsItStands() :void
    {
        $this->assertSame( [ 'doc.active == true' ] , resolveJoinConditions( [ AQL::CONDITIONS => [ 'doc.active == true' ] ] , 'j' , 'doc' ) ) ;
    }

    /**
     * The callable receives the join variable, the parent and the init — always.
     */
    public function testACallableReceivesTheJoinTheParentAndTheInit() :void
    {
        $received = [] ;

        $conditions = resolveJoinConditions
        (
            [ AQL::CONDITIONS => function( string $join , string $parent , array $init ) use ( &$received ) :array
            {
                $received = [ $join , $parent , $init ] ;

                return [ $join . '.owner == ' . $parent . '.owner' ] ;
            } ] ,
            'j' ,
            'doc' ,
            [ 'x' => 1 ]
        ) ;

        $this->assertSame( [ 'j' , 'doc' , [ 'x' => 1 ] ] , $received ) ;
        $this->assertSame( [ 'j.owner == doc.owner' ] , $conditions ) ;
    }

    /**
     * A one-parameter closure keeps working : PHP discards the surplus.
     */
    public function testAOneParameterClosureKeepsWorking() :void
    {
        $this->assertSame( [ 'j.kind == "audio"' ] , resolveJoinConditions( [ AQL::CONDITIONS => fn( string $join ) => [ $join . '.kind == "audio"' ] ] , 'j' , 'doc' ) ) ;
    }

    public function testANonArrayIsRefused() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        resolveJoinConditions( [ AQL::CONDITIONS => fn() => 'doc.active == true' ] , 'j' , 'doc' ) ;
    }
}
