<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\exceptions\RequestValidationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use stdClass;

use function oihana\arango\db\helpers\assertLanguageCode;
use function oihana\arango\db\helpers\isLanguageCode;

/**
 * Test suite for the isLanguageCode() / assertLanguageCode() helpers.
 *
 * A language tag names an attribute of the stored translations object, so it is
 * written verbatim into the query and can never be bound — these helpers are
 * what makes that interpolation safe.
 */
#[CoversFunction('oihana\arango\db\helpers\isLanguageCode')]
#[CoversFunction('oihana\arango\db\helpers\assertLanguageCode')]
final class LanguageCodeTest extends TestCase
{
    public static function provideValidCodes() : array
    {
        return
        [
            'two letters'    => [ 'fr'         ] ,
            'three letters'  => [ 'ast'        ] ,
            'region'         => [ 'pt-BR'      ] ,
            'script and region' => [ 'zh-Hant-TW' ] ,
            'numeric region' => [ 'es-419'     ] ,
        ] ;
    }

    public static function provideInvalidCodes() : array
    {
        return
        [
            'empty'            => [ ''            ] ,
            'single letter'    => [ 'f'           ] ,
            'four letters'     => [ 'fran'        ] ,
            'uppercase'        => [ 'FR'          ] ,
            'space'            => [ 'fr fr'       ] ,
            'quote'            => [ 'fr"'         ] ,
            'injection'        => [ 'fr" || 1==1' ] ,
            'dot path'         => [ 'fr.name'     ] ,
            'trailing dash'    => [ 'fr-'         ] ,
            'leading dash'     => [ '-fr'         ] ,
            'underscore'       => [ 'fr_FR'       ] ,
            'digits'           => [ '42'          ] ,
        ] ;
    }

    public static function provideInvalidTypes() : array
    {
        return
        [
            'null'   => [ null          ] ,
            'int'    => [ 42            ] ,
            'float'  => [ 1.5           ] ,
            'bool'   => [ true          ] ,
            'array'  => [ [ 'fr' ]      ] ,
            'object' => [ new stdClass() ] ,
        ] ;
    }

    #[Test]
    #[DataProvider('provideValidCodes')]
    public function isLanguageCodeAcceptsValidTags( string $value ) : void
    {
        $this->assertTrue( isLanguageCode( $value ) ) ;
    }

    #[Test]
    #[DataProvider('provideInvalidCodes')]
    public function isLanguageCodeRejectsUnsafeTags( string $value ) : void
    {
        $this->assertFalse( isLanguageCode( $value ) ) ;
    }

    #[Test]
    #[DataProvider('provideInvalidTypes')]
    public function isLanguageCodeRejectsNonStrings( mixed $value ) : void
    {
        $this->assertFalse( isLanguageCode( $value ) ) ;
    }

    #[Test]
    #[DataProvider('provideValidCodes')]
    public function assertLanguageCodePassesValidTags( string $value ) : void
    {
        assertLanguageCode( $value ) ;
        $this->assertTrue( true ) ; // no exception is the assertion
    }

    #[Test]
    public function assertLanguageCodeBlamesTheCodeByDefault() : void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid language code: "fr fr"' ) ;

        assertLanguageCode( 'fr fr' ) ;
    }

    #[Test]
    public function assertLanguageCodeBlamesTheRequestWhenItCameFromTheWire() : void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid language code: "fr fr"' ) ;

        assertLanguageCode( 'fr fr' , fromRequest: true ) ;
    }

    #[Test]
    public function assertLanguageCodeNamesTheTypeOfANonString() : void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid language code: "int"' ) ;

        assertLanguageCode( 42 ) ;
    }
}
