<?php

namespace tests\oihana\arango\models\traits;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Hydration;
use oihana\arango\models\traits\HydrationTrait;

/**
 * Coverage for {@see HydrationTrait} — the per-model hydration mode and, above
 * all, how it normalizes what a definition hands it.
 *
 * The reflective mode is the strict one : it drops the nested attributes a schema
 * does not declare and can raise where the constructor silently accepted. It must
 * therefore be reached by its **exact** value and by nothing else — a typo in a
 * model definition has to keep the historical behaviour, not opt the whole
 * collection into the strict path.
 *
 * @package tests\oihana\arango\models\traits
 * @author  Marc Alcaraz
 */
final class HydrationTraitTest extends TestCase
{
    /**
     * A bare consumer of the trait — the model wiring is irrelevant here.
     *
     * @return object
     */
    private function consumer() : object
    {
        return new class { use HydrationTrait ; } ;
    }

    public function testTheModeDefaultsToTheConstructorBeforeAnyInitialization() :void
    {
        $this->assertSame( Hydration::CONSTRUCTOR , $this->consumer()->hydration ) ;
    }

    public function testAnAbsentOptionResolvesToTheConstructor() :void
    {
        $model = $this->consumer()->initializeHydration( [] ) ;

        $this->assertSame( Hydration::CONSTRUCTOR , $model->hydration ) ;
    }

    public function testTheReflectionModeIsHonoured() :void
    {
        $model = $this->consumer()->initializeHydration( [ AQL::HYDRATION => Hydration::REFLECTION ] ) ;

        $this->assertSame( Hydration::REFLECTION , $model->hydration ) ;
    }

    public function testTheConstructorModeIsHonoured() :void
    {
        $model = $this->consumer()->initializeHydration( [ AQL::HYDRATION => Hydration::CONSTRUCTOR ] ) ;

        $this->assertSame( Hydration::CONSTRUCTOR , $model->hydration ) ;
    }

    /**
     * The guard that matters : anything that is not the exact reflective value —
     * a typo, a null, a number, a list — falls back to the constructor instead of
     * reaching the strict path by accident.
     */
    public function testAnythingElseFallsBackToTheConstructor() :void
    {
        foreach ( [ 'reflexion' , 'Reflection' , 'REFLECTION' , '' , null , 0 , 1 , true , [ Hydration::REFLECTION ] ] as $value )
        {
            $model = $this->consumer()->initializeHydration( [ AQL::HYDRATION => $value ] ) ;

            $this->assertSame
            (
                Hydration::CONSTRUCTOR ,
                $model->hydration ,
                'an unrecognised mode must keep the historical behaviour: ' . var_export( $value , true )
            ) ;
        }
    }

    /**
     * A model already set to the reflective mode must be able to come back — the
     * initializer states the mode, it does not accumulate it.
     */
    public function testAnUnknownModeResetsAModelPreviouslySetToReflection() :void
    {
        $model = $this->consumer()->initializeHydration( [ AQL::HYDRATION => Hydration::REFLECTION ] ) ;

        $this->assertSame( Hydration::REFLECTION , $model->hydration ) ;

        $model->initializeHydration( [ AQL::HYDRATION => 'nonsense' ] ) ;

        $this->assertSame( Hydration::CONSTRUCTOR , $model->hydration ) ;
    }

    public function testTheInitializerIsFluent() :void
    {
        $model = $this->consumer() ;

        $this->assertSame( $model , $model->initializeHydration( [] ) ) ;
    }

    /**
     * Two models, two modes, no interference — the property belongs to each
     * instance, which is the whole reason it is not held by the shared façade.
     */
    public function testTwoModelsKeepTheirOwnMode() :void
    {
        $deep    = $this->consumer()->initializeHydration( [ AQL::HYDRATION => Hydration::REFLECTION  ] ) ;
        $shallow = $this->consumer()->initializeHydration( [ AQL::HYDRATION => Hydration::CONSTRUCTOR ] ) ;

        $this->assertSame( Hydration::REFLECTION  , $deep->hydration ) ;
        $this->assertSame( Hydration::CONSTRUCTOR , $shallow->hydration ) ;
    }
}
