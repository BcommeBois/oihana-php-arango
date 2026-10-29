<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\functions\documents\translate;
use function oihana\arango\db\helpers\aqlValue;

/**
 * Live validation of what the third argument of `translate()` changes.
 *
 * The emitted strings differ by one argument ; only the server tells what that
 * argument is worth. Without a default, `TRANSLATE()` answers the value ITSELF when
 * it is not in the lookup ; with `AQL::NULL`, it answers `null`. A PHP `null` omits
 * the argument, so it cannot ask for the second answer.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class TranslateDefaultIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_translate_default_it' ;

    private const string LOOKUP = '{a:1}' ;

    /**
     * @throws ArangoException
     */
    private function evaluate( string $expression ) :mixed
    {
        $rows = iterator_to_array( self::$db->query( 'RETURN ' . $expression ) , false ) ;
        $this->assertCount( 1 , $rows , 'RETURN answers one row.' ) ;
        return $rows[ 0 ] ; // may be null : that is the answer under test
    }

    /**
     * @throws ArangoException
     */
    public function testWithoutDefaultAMissingValueComesBackItself() :void
    {
        $this->assertSame( 'z' , $this->evaluate( translate( aqlValue( 'z' ) , self::LOOKUP ) ) ) ;
        $this->assertSame( 'z' , $this->evaluate( translate( aqlValue( 'z' ) , self::LOOKUP , null ) ) ) ;
    }

    /**
     * @throws ArangoException
     */
    public function testAqlNullDefaultAnswersNull() :void
    {
        $this->assertNull( $this->evaluate( translate( aqlValue( 'z' ) , self::LOOKUP , AQL::NULL ) ) ) ;
    }

    /**
     * Counter-proof : a value found in the lookup ignores the default.
     *
     * @throws ArangoException
     */
    public function testAFoundValueIgnoresTheDefault() :void
    {
        $this->assertSame( 1 , $this->evaluate( translate( aqlValue( 'a' ) , self::LOOKUP ) ) ) ;
        $this->assertSame( 1 , $this->evaluate( translate( aqlValue( 'a' ) , self::LOOKUP , AQL::NULL ) ) ) ;
    }
}
