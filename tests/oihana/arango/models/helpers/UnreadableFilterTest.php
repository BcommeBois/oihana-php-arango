<?php

namespace tests\oihana\arango\models\helpers;

use oihana\arango\exceptions\RequestValidationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\unreadableFilter;

/**
 * {@see unreadableFilter()} — the sentence a filter no filter reads is refused with.
 */
final class UnreadableFilterTest extends TestCase
{
    private const string GRAMMAR = ' speaks no filter grammar : name the field with "key" — {"key":…,"op":…,"val":…} — or write a group as a list — ["and"|"or"|"not", …].' ;

    public function testAScalarIsNamedByItsType() :void
    {
        $this->assertInstanceOf( RequestValidationException::class , unreadableFilter( 'x' ) ) ;
        $this->assertSame( 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : string given.' , unreadableFilter( 'x' )->getMessage() ) ;
        $this->assertSame( 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : bool given.'   , unreadableFilter( true )->getMessage() ) ;
        $this->assertSame( 'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : null given.'   , unreadableFilter( null )->getMessage() ) ;
    }

    public function testAnEmptyMemberHoldsNoCondition() :void
    {
        $this->assertSame( 'A filter group holds no condition.' , unreadableFilter( [] )->getMessage() ) ;
    }

    public function testAnObjectIsNamedByItsKeys() :void
    {
        $this->assertSame( 'The filter {"logic","conditions"}' . self::GRAMMAR , unreadableFilter( [ 'logic' => 'or' , 'conditions' => [] ] )->getMessage() ) ;
    }

    /**
     * The keys come from the request : the first five are enough to recognise the mistake.
     */
    public function testOnlyTheFirstFiveKeysAreNamed() :void
    {
        $filter = [ 'a' => 1 , 'b' => 1 , 'c' => 1 , 'd' => 1 , 'e' => 1 , 'f' => 1 , 'g' => 1 ] ;

        $this->assertSame( 'The filter {"a","b","c","d","e",…}' . self::GRAMMAR , unreadableFilter( $filter )->getMessage() ) ;
        $this->assertSame( 'The filter {"a","b","c","d","e"}' . self::GRAMMAR , unreadableFilter( array_slice( $filter , 0 , 5 ) )->getMessage() ) ;
    }
}
