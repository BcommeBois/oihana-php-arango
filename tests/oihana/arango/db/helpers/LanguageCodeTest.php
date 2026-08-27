<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\exceptions\RequestValidationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\assertLanguageCode;

/**
 * Test suite for the assertLanguageCode() helper.
 *
 * A language tag names an attribute of the stored translations object, so it is
 * written verbatim into the query and can never be bound. **What** makes a tag
 * safe is decided by `oihana\controllers\helpers\isLanguageCode()`, and covered
 * by that package; what is covered here is the only part that is an ArangoDB
 * concern — **who gets blamed** when a tag is refused.
 *
 * The distinction is not cosmetic. A tag typed into a `?lang=` is the caller's
 * mistake and answers `400` with a message written for them; a tag declared in a
 * model or a configuration is the consumer's own code, no request will ever fix
 * it, and it answers `500`.
 */
#[CoversFunction('oihana\arango\db\helpers\assertLanguageCode')]
final class LanguageCodeTest extends TestCase
{
    public static function provideValidCodes() : array
    {
        return
        [
            'two letters'       => [ 'fr'         ] ,
            'three letters'     => [ 'ast'        ] ,
            'region'            => [ 'pt-BR'      ] ,
            'script and region' => [ 'zh-Hant-TW' ] ,
            'numeric region'    => [ 'es-419'     ] ,
        ] ;
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

    /**
     * The refusal a request gets is still a refusal the code path recognises —
     * `RequestValidationException` extends `ValidationException`, so a consumer
     * catching the general type keeps catching both.
     */
    #[Test]
    public function theRequestRefusalIsStillAValidationException() : void
    {
        $this->expectException( ValidationException::class ) ;

        assertLanguageCode( 'fr_FR' , fromRequest: true ) ;
    }
}
