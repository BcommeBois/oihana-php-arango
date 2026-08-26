<?php

namespace tests\oihana\arango\traits;

use oihana\arango\enums\Arango;
use oihana\arango\traits\DefaultLangTrait;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerInterface;

use tests\oihana\arango\traits\mocks\DefaultLangHost;

/**
 * Test suite for {@see DefaultLangTrait}.
 *
 * The declaration sites are swept as **combinations**, not as lines: the init
 * definition, the pre-set property and the container each answer or stay silent,
 * and what matters is which one wins when several do.
 */
#[CoversTrait( DefaultLangTrait::class )]
#[AllowMockObjectsWithoutExpectations]
final class DefaultLangTraitTest extends TestCase
{
    /**
     * Builds a container serving the given id → value map.
     *
     * @param array<string,mixed> $services
     */
    private function makeContainer( array $services ) : ContainerInterface
    {
        $container = $this->createMock( ContainerInterface::class ) ;

        $container->method( 'has' )->willReturnCallback(
            fn( string $id ) => array_key_exists( $id , $services )
        ) ;

        $container->method( 'get' )->willReturnCallback(
            fn( string $id ) => $services[ $id ]
        ) ;

        return $container ;
    }

    // =========================================================================
    // Nothing declared
    // =========================================================================

    #[Test]
    public function noDeclarationAnywhereLeavesTheLanguageNull() : void
    {
        $host = new DefaultLangHost() ;

        $host->initializeDefaultLang() ;

        $this->assertNull( $host->defaultLang ) ;
    }

    #[Test]
    public function anEmptyContainerLeavesTheLanguageNull() : void
    {
        $host = new DefaultLangHost() ;

        $host->initializeDefaultLang( [] , $this->makeContainer( [] ) ) ;

        $this->assertNull( $host->defaultLang ) ;
    }

    // =========================================================================
    // One site answers
    // =========================================================================

    #[Test]
    public function theInitDefinitionIsRead() : void
    {
        $host = new DefaultLangHost() ;

        $host->initializeDefaultLang( [ Arango::DEFAULT_LANG => 'fr' ] ) ;

        $this->assertSame( 'fr' , $host->defaultLang ) ;
    }

    #[Test]
    public function thePropertyDefaultSurvivesAnEmptyInit() : void
    {
        $host = new DefaultLangHost() ;
        $host->defaultLang = 'en' ;

        $host->initializeDefaultLang() ;

        $this->assertSame( 'en' , $host->defaultLang ) ;
    }

    #[Test]
    public function theContainerAnswersWhenNothingElseDoes() : void
    {
        $host = new DefaultLangHost() ;

        $host->initializeDefaultLang( [] , $this->makeContainer( [ 'defaultLang' => 'es' ] ) ) ;

        $this->assertSame( 'es' , $host->defaultLang ) ;
    }

    // =========================================================================
    // Several sites answer — who wins
    // =========================================================================

    #[Test]
    public function theInitDefinitionWinsOverTheProperty() : void
    {
        $host = new DefaultLangHost() ;
        $host->defaultLang = 'en' ;

        $host->initializeDefaultLang( [ Arango::DEFAULT_LANG => 'fr' ] ) ;

        $this->assertSame( 'fr' , $host->defaultLang ) ;
    }

    #[Test]
    public function theInitDefinitionWinsOverTheContainer() : void
    {
        $host = new DefaultLangHost() ;

        $host->initializeDefaultLang
        (
            [ Arango::DEFAULT_LANG => 'fr' ] ,
            $this->makeContainer( [ 'defaultLang' => 'es' ] )
        ) ;

        $this->assertSame( 'fr' , $host->defaultLang ) ;
    }

    #[Test]
    public function thePropertyWinsOverTheContainer() : void
    {
        $host = new DefaultLangHost() ;
        $host->defaultLang = 'en' ;

        $host->initializeDefaultLang( [] , $this->makeContainer( [ 'defaultLang' => 'es' ] ) ) ;

        $this->assertSame( 'en' , $host->defaultLang ) ;
    }

    // =========================================================================
    // Normalisation and refusal
    // =========================================================================

    #[Test]
    #[DataProvider('provideSpellings')]
    public function theTagIsLowercasedSoASingleSpellingReachesTheQuery( string $declared , string $expected ) : void
    {
        $host = new DefaultLangHost() ;

        $host->initializeDefaultLang( [ Arango::DEFAULT_LANG => $declared ] ) ;

        $this->assertSame( $expected , $host->defaultLang ) ;
    }

    public static function provideSpellings() : array
    {
        return
        [
            'already lowercase' => [ 'fr'    , 'fr'    ] ,
            'uppercase'         => [ 'FR'    , 'fr'    ] ,
            'mixed case region' => [ 'pt-br' , 'pt-br' ] ,
        ] ;
    }

    #[Test]
    public function anInvalidTagIsRefusedRatherThanIgnored() : void
    {
        $host = new DefaultLangHost() ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid language code: "fr fr"' ) ;

        $host->initializeDefaultLang( [ Arango::DEFAULT_LANG => 'fr fr' ] ) ;
    }

    #[Test]
    public function aNonStringDeclarationIsRefused() : void
    {
        $host = new DefaultLangHost() ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid language code: "int"' ) ;

        $host->initializeDefaultLang( [ Arango::DEFAULT_LANG => 42 ] ) ;
    }

    #[Test]
    public function anInvalidContainerEntryIsRefusedToo() : void
    {
        $host = new DefaultLangHost() ;

        $this->expectException( ValidationException::class ) ;

        $host->initializeDefaultLang( [] , $this->makeContainer( [ 'defaultLang' => 'fr_FR' ] ) ) ;
    }
}
