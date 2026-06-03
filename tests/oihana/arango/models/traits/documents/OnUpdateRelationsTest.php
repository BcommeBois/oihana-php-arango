<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\models\enums\NoticeType;
use oihana\models\notices\AfterInsert;
use oihana\models\notices\AfterReplace;
use oihana\models\notices\AfterUpdate;
use oihana\signals\notices\Payload;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for the {@see \oihana\arango\models\traits\documents\callbacks\OnUpdateRelations}
 * trait: onUpdateRelations() (delegation to updateRelations) and the
 * register/unregister wiring onto the afterInsert/Replace/Update signals.
 */
final class OnUpdateRelationsTest extends TestCase
{
    /**
     * MockDocuments that records every onUpdateRelations() invocation so the
     * signal wiring can be asserted.
     */
    private function spy() :MockDocuments
    {
        $spy = new class( 'users' ) extends MockDocuments
        {
            public int $calls = 0 ;

            public function onUpdateRelations( Payload $payload ) :void
            {
                $this->calls++ ;
            }
        } ;

        $spy->initializeInsertSignals() ;
        $spy->initializeReplaceSignals() ;
        $spy->initializeUpdateSignals() ;

        return $spy ;
    }

    public function testOnUpdateRelationsWithoutRelationsIsANoOp() :void
    {
        $model = new MockDocuments( 'users' ) ;

        // Real method (no relations in context) → updateRelations early-returns.
        $model->onUpdateRelations
        (
            new Payload( type: NoticeType::AFTER_INSERT , data: (object) [ '_key' => '1' ] , target: $model , context: [] ) ,
        ) ;

        $this->expectNotToPerformAssertions() ;
    }

    public function testRegisterConnectsToTheThreeAfterSignals() :void
    {
        $spy = $this->spy() ;
        $this->assertSame( $spy , $spy->registerUpdateRelations() ) ;

        $spy->afterInsert->emit ( new AfterInsert ( data: null , target: $spy ) ) ;
        $spy->afterReplace->emit( new AfterReplace( data: null , target: $spy ) ) ;
        $spy->afterUpdate->emit ( new AfterUpdate ( data: null , target: $spy ) ) ;

        $this->assertSame( 3 , $spy->calls , 'onUpdateRelations must fire on insert, replace and update' ) ;
    }

    public function testUnregisterDisconnectsFromTheSignals() :void
    {
        $spy = $this->spy() ;
        $spy->registerUpdateRelations() ;
        $this->assertSame( $spy , $spy->unregisterUpdateRelations() ) ;

        $spy->afterInsert->emit ( new AfterInsert ( data: null , target: $spy ) ) ;
        $spy->afterReplace->emit( new AfterReplace( data: null , target: $spy ) ) ;
        $spy->afterUpdate->emit ( new AfterUpdate ( data: null , target: $spy ) ) ;

        $this->assertSame( 0 , $spy->calls ) ;
    }
}
