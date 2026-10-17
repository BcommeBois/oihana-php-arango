<?php

namespace tests\oihana\arango\db\enums\traits;

use ReflectionClass;
use ReflectionProperty;

use oihana\arango\db\enums\traits\IndexOptionsTrait;
use oihana\arango\db\enums\traits\QueryOptionsTrait;
use oihana\arango\db\options\CollectOptions;
use oihana\arango\db\options\ForOptions;
use oihana\arango\db\options\indexes\GeoIndexOptions;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\MDIIndexOptions;
use oihana\arango\db\options\indexes\PersistentIndexOptions;
use oihana\arango\db\options\indexes\TTLIndexOptions;
use oihana\arango\db\options\indexes\VectorIndexOptions;
use oihana\arango\db\options\InsertOptions;
use oihana\arango\db\options\QueryOptions;
use oihana\arango\db\options\RemoveOptions;
use oihana\arango\db\options\ReplaceOptions;
use oihana\arango\db\options\SearchOptions;
use oihana\arango\db\options\TraversalOptions;
use oihana\arango\db\options\UpdateOptions;
use oihana\arango\db\options\UpsertOptions;

use PHPUnit\Framework\TestCase;

/**
 * Every key constant of the options names a property, and every property has its constant.
 *
 * 🚨 **A constant naming no property fills nothing, in silence.** The options take
 * their values through a constructor that keeps only the keys that are properties :
 * `STORED_VALUE = 'storedValue'` beside a `$storedValues` property dropped every
 * stored value it was handed, and `LEGACY_POLYGONS` had no property at all. Both
 * answered `200` and created an index without the option. This essay is the one
 * that would have caught them.
 */
final class OptionsConstantsTest extends TestCase
{
    /**
     * The index options classes.
     */
    private const array INDEX_OPTIONS =
    [
        IndexOptions::class ,
        GeoIndexOptions::class ,
        MDIIndexOptions::class ,
        PersistentIndexOptions::class ,
        TTLIndexOptions::class ,
        VectorIndexOptions::class ,
    ] ;

    /**
     * The query options classes.
     */
    private const array QUERY_OPTIONS =
    [
        CollectOptions::class ,
        ForOptions::class ,
        InsertOptions::class ,
        QueryOptions::class ,
        RemoveOptions::class ,
        ReplaceOptions::class ,
        SearchOptions::class ,
        TraversalOptions::class ,
        UpdateOptions::class ,
        UpsertOptions::class ,
    ] ;

    /**
     * The public properties of some classes.
     *
     * @param array<int,class-string> $classes
     *
     * @return array<int,string>
     */
    private function properties( array $classes ) :array
    {
        $names = [] ;

        foreach ( $classes as $class )
        {
            foreach ( new ReflectionClass( $class )->getProperties( ReflectionProperty::IS_PUBLIC ) as $property )
            {
                $names[] = $property->getName() ;
            }
        }

        return array_values( array_unique( $names ) ) ;
    }

    /**
     * The values of the constants of a trait.
     *
     * @param class-string $trait
     *
     * @return array<string,string>
     */
    private function constants( string $trait ) :array
    {
        return new ReflectionClass( $trait )->getConstants() ;
    }

    public function testEveryIndexOptionsConstantNamesAProperty() :void
    {
        $properties = $this->properties( self::INDEX_OPTIONS ) ;

        foreach ( $this->constants( IndexOptionsTrait::class ) as $name => $value )
        {
            $this->assertContains( $value , $properties , sprintf( 'IndexOptionsTrait::%s = "%s" names no property : it fills nothing.' , $name , $value ) ) ;
        }
    }

    public function testEveryIndexOptionsPropertyHasItsConstant() :void
    {
        $constants = $this->constants( IndexOptionsTrait::class ) ;

        foreach ( $this->properties( self::INDEX_OPTIONS ) as $property )
        {
            $this->assertContains( $property , $constants , sprintf( 'the index option "%s" has no constant in IndexOptionsTrait.' , $property ) ) ;
        }
    }

    /**
     * The query options trait also names the index options : a query declares them
     * too, through the same constructor.
     */
    public function testEveryQueryOptionsConstantNamesAProperty() :void
    {
        $properties = $this->properties( [ ...self::QUERY_OPTIONS , ...self::INDEX_OPTIONS ] ) ;

        foreach ( $this->constants( QueryOptionsTrait::class ) as $name => $value )
        {
            $this->assertContains( $value , $properties , sprintf( 'QueryOptionsTrait::%s = "%s" names no property : it fills nothing.' , $name , $value ) ) ;
        }
    }

    public function testEveryQueryOptionsPropertyHasItsConstant() :void
    {
        $constants = $this->constants( QueryOptionsTrait::class ) ;

        foreach ( $this->properties( self::QUERY_OPTIONS ) as $property )
        {
            $this->assertContains( $property , $constants , sprintf( 'the query option "%s" has no constant in QueryOptionsTrait.' , $property ) ) ;
        }
    }

    /**
     * The two constants that filled nothing are gone, and their right spelling is
     * there.
     */
    public function testTheStoredValuesAreSpelledAsTheServerSpellsThem() :void
    {
        $this->assertSame( 'storedValues' , IndexOptions::STORED_VALUES ) ;
        $this->assertArrayNotHasKey( 'STORED_VALUE' , $this->constants( IndexOptionsTrait::class ) ) ;
        $this->assertArrayNotHasKey( 'STORED_VALUE' , $this->constants( QueryOptionsTrait::class ) ) ;

        $options = new PersistentIndexOptions( [ IndexOptions::FIELDS => [ 'a' ] , IndexOptions::STORED_VALUES => [ 'b' ] ] ) ;

        $this->assertSame( [ 'b' ] , $options->jsonSerialize()[ 'storedValues' ] ) ;
    }
}
