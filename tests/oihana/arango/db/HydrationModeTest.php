<?php

namespace tests\oihana\arango\db;

use ReflectionException;
use ReflectionMethod;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\Hydration;

use tests\oihana\arango\db\mocks\MockHydratedThing;
use tests\oihana\arango\db\mocks\MockNestedAmount;
use tests\oihana\arango\db\mocks\MockPlainAmount;

/**
 * Coverage for the hydration mode of {@see ArangoDB::hydrateDocument()}.
 *
 * A {@see \org\schema\Thing} is built by its constructor, whose assignment is
 * shallow : whatever the schema declares about a nested structure, that structure
 * comes back a raw array. {@see Hydration::REFLECTION} asks for the reflective
 * path instead, the only one honouring the hydration attributes.
 *
 * The suite pins three things : each mode does what it says, an unrecognised mode
 * keeps the historical behaviour rather than reaching the stricter one by
 * accident, and — the assertion that matters — **the mode is not a state of the
 * façade**. A single façade instance is shared by every model of an application,
 * and models read inside one another (a document being served resolves its
 * relations through other models), so a mode remembered here would leak from the
 * model that set it to whichever model reads next.
 *
 * @package tests\oihana\arango\db
 * @author  Marc Alcaraz
 */
#[AllowMockObjectsWithoutExpectations]
final class HydrationModeTest extends ArangoDBTestCase
{
    /**
     * A document whose nested `amount` is what the two modes disagree about.
     *
     * @var array<string,mixed>
     */
    private const array DOCUMENT =
    [
        'name'   => 'A row' ,
        'amount' => [ 'value' => 12.5 , 'currency' => 'EUR' ] ,
    ] ;

    /**
     * Calls the protected hydration seam of the façade.
     *
     * @param ArangoDB    $arangoDB
     * @param string|null $schema
     * @param string|null $hydration Omitted to exercise the default.
     *
     * @return mixed
     *
     * @throws ReflectionException
     */
    private function hydrate( ArangoDB $arangoDB , ?string $schema , ?string $hydration = null ) : mixed
    {
        $method = new ReflectionMethod( ArangoDB::class , 'hydrateDocument' ) ;

        $arguments = [ self::DOCUMENT , $schema ] ;

        if ( $hydration !== null )
        {
            $arguments[] = $hydration ;
        }

        return $method->invokeArgs( $arangoDB , $arguments ) ;
    }

    /**
     * @throws ReflectionException
     */
    public function testTheDefaultModeLeavesANestedStructureRaw() :void
    {
        $document = $this->hydrate( $this->newArangoDB() , MockHydratedThing::class ) ;

        $this->assertInstanceOf( MockHydratedThing::class , $document ) ;
        $this->assertIsArray( $document->amount , 'the constructor assigns the nested payload as it stands' ) ;
    }

    /**
     * @throws ReflectionException
     */
    public function testTheConstructorModeLeavesANestedStructureRaw() :void
    {
        $document = $this->hydrate( $this->newArangoDB() , MockHydratedThing::class , Hydration::CONSTRUCTOR ) ;

        $this->assertInstanceOf( MockHydratedThing::class , $document ) ;
        $this->assertIsArray( $document->amount ) ;
    }

    /**
     * @throws ReflectionException
     */
    public function testTheReflectionModeTypesANestedStructure() :void
    {
        $document = $this->hydrate( $this->newArangoDB() , MockHydratedThing::class , Hydration::REFLECTION ) ;

        $this->assertInstanceOf( MockHydratedThing::class , $document ) ;
        $this->assertInstanceOf( MockNestedAmount::class , $document->amount ) ;
        $this->assertSame( 12.5  , $document->amount->value ) ;
        $this->assertSame( 'EUR' , $document->amount->currency ) ;
    }

    /**
     * A misspelt mode must not reach the stricter path : it drops the nested
     * attributes a schema does not declare and can raise where the constructor
     * accepted, so it is opted into by the exact value or not at all.
     *
     * @throws ReflectionException
     */
    public function testAnUnknownModeKeepsTheHistoricalBehaviour() :void
    {
        $document = $this->hydrate( $this->newArangoDB() , MockHydratedThing::class , 'reflexion' ) ;

        $this->assertIsArray( $document->amount ) ;
    }

    /**
     * 🚨 The assertion that guards the design : the **same** façade, asked twice
     * with two different modes, answers each of them. Were the mode ever kept as
     * a property of the façade, the second read would inherit the first one's —
     * across models, since they all share one instance.
     *
     * @throws ReflectionException
     */
    public function testTwoReadsThroughTheSameFacadeDoNotInfluenceEachOther() :void
    {
        $arangoDB = $this->newArangoDB() ;

        $deep    = $this->hydrate( $arangoDB , MockHydratedThing::class , Hydration::REFLECTION ) ;
        $shallow = $this->hydrate( $arangoDB , MockHydratedThing::class , Hydration::CONSTRUCTOR ) ;
        $again   = $this->hydrate( $arangoDB , MockHydratedThing::class , Hydration::REFLECTION ) ;

        $this->assertInstanceOf( MockNestedAmount::class , $deep->amount    , 'the first read is deep' ) ;
        $this->assertIsArray                             ( $shallow->amount , 'the second read is NOT contaminated by the first' ) ;
        $this->assertInstanceOf( MockNestedAmount::class , $again->amount   , 'nor is the third by the second' ) ;
    }

    /**
     * A class outside the `Thing` lineage has always been hydrated by reflection.
     * The mode must not have changed that.
     *
     * @throws ReflectionException
     */
    public function testAClassOutsideTheThingLineageIsTypedWhateverTheMode() :void
    {
        foreach ( [ null , Hydration::CONSTRUCTOR , Hydration::REFLECTION ] as $mode )
        {
            $document = $this->hydrate( $this->newArangoDB() , MockPlainAmount::class , $mode ) ;

            $this->assertInstanceOf( MockPlainAmount::class  , $document ) ;
            $this->assertInstanceOf( MockNestedAmount::class , $document->amount ) ;
        }
    }

    /**
     * No schema at all : the row is cast to a plain object, mode notwithstanding.
     *
     * @throws ReflectionException
     */
    public function testARowWithoutASchemaIsCastToAPlainObject() :void
    {
        $document = $this->hydrate( $this->newArangoDB() , null , Hydration::REFLECTION ) ;

        $this->assertSame( 'stdClass' , get_debug_type( $document ) ) ;
        $this->assertIsArray( $document->amount ) ;
    }
}
