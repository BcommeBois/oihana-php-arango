<?php

namespace tests\oihana\arango\integration;

use Throwable;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\helpers\isAQLExpression;
use function oihana\core\strings\betweenQuotes;

/**
 * Live validation that `isAQLExpression()` calls a numeric string a number exactly
 * when the server does.
 *
 * PHP and AQL disagree on what a number is : PHP accepts `007` and `5.`, the server
 * refuses both with a syntax error that fails the whole query. Only the server can
 * tell where the line is, so the detection is checked against it on every numeric
 * string a small grammar generates — signs, leading zeros, dots, exponents, spaces.
 * A future ArangoDB that moved the line would show here.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class NumericStringIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_numeric_string_it' ;

    private const string COLLECTION = 'items' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $items = $db->collection( self::COLLECTION ) ;
        $items->create() ;
        $items->insert( [ '_key' => 'a' , 'zip' => '01000' ] ) ;
    }

    /**
     * Every string PHP calls numeric, built from signs, integer parts, fractions,
     * exponents, plus a few spaced and oversized ones.
     *
     * @return array<int, string>
     */
    private static function numericStrings() :array
    {
        $values = [] ;

        foreach ( [ '' , '-' , '+' ] as $sign )
        foreach ( [ '' , '0' , '00' , '5' , '05' , '10' , '007' , '123' ] as $integer )
        foreach ( [ '' , '.' , '.0' , '.5' , '.50' ] as $fraction )
        foreach ( [ '' , 'e3' , 'E3' , 'e+3' , 'e-3' , 'e' , 'e03' ] as $exponent )
        {
            $value = $sign . $integer . $fraction . $exponent ;
            if ( is_numeric( $value ) )
            {
                $values[] = $value ;
            }
        }

        foreach ( [ ' 42' , '42 ' , "\t7" , ' 007 ' , '9223372036854775807' , '12345678901234567890' ] as $value )
        {
            $values[] = $value ;
        }

        return array_values( array_unique( $values ) ) ;
    }

    private function serverAccepts( string $value ) :bool
    {
        try
        {
            self::$db->query( 'RETURN ' . $value ) ;
            return true ;
        }
        catch ( Throwable )
        {
            return false ;
        }
    }

    public function testTheDetectionAgreesWithTheServerOnEveryNumericString() :void
    {
        $values    = self::numericStrings() ;
        $disagrees = [] ;
        $accepted  = 0 ;

        foreach ( $values as $value )
        {
            $server = $this->serverAccepts( $value ) ;
            $accepted += $server ? 1 : 0 ;

            if ( $server !== isAQLExpression( $value ) )
            {
                $disagrees[] = var_export( $value , true ) . ( $server ? ' (server: number)' : ' (server: refused)' ) ;
            }
        }

        $this->assertSame( [] , $disagrees , 'The detection must call a string a number exactly when the server does.' ) ;

        // the sweep really covers both sides of the line
        $this->assertGreaterThan( 100 , $accepted ) ;
        $this->assertGreaterThan( 100 , count( $values ) - $accepted ) ;
    }

    /**
     * @throws ArangoException
     */
    public function testARefusedNumericFormRunsAsAString() :void
    {
        foreach ( [ '007' , '01000' , '0612345678' , '5.' , '-007' ] as $value )
        {
            $rows = iterator_to_array( self::$db->query( 'RETURN ' . aqlValue( $value ) ) , false ) ;
            $this->assertSame( [ $value ] , $rows , var_export( $value , true ) ) ;
        }
    }

    /**
     * @throws ArangoException
     */
    public function testAZeroPaddedValueFindsItsDocument() :void
    {
        $aql  = 'FOR doc IN ' . self::COLLECTION . ' FILTER doc.zip == ' . aqlValue( '01000' ) . ' RETURN doc._key' ;
        $rows = iterator_to_array( self::$db->query( $aql ) , false ) ;

        $this->assertSame( [ 'a' ] , $rows ) ;
    }

    /**
     * The trap that stays, and its way out : `'123'` is an AQL number, so it is written
     * `_key:123` and refused as a key ; `betweenQuotes()` keeps it a string.
     *
     * @throws ArangoException
     */
    public function testANumericKeyMustBeDeclaredAString() :void
    {
        try
        {
            self::$db->query( 'INSERT ' . aqlDocument( [ '_key' => '123' ] ) . ' INTO ' . self::COLLECTION ) ;
            $this->fail( 'A number is not a document key.' ) ;
        }
        catch ( ArangoException $exception )
        {
            $this->assertStringContainsString( 'illegal document key' , $exception->getMessage() ) ;
        }

        $rows = iterator_to_array( self::$db->query( 'INSERT ' . aqlDocument( [ '_key' => betweenQuotes( '123' ) ] ) . ' INTO ' . self::COLLECTION . ' RETURN NEW._key' ) , false ) ;
        $this->assertSame( [ '123' ] , $rows ) ;
    }

    /**
     * Counter-proof : an AQL number is still emitted raw and read as the same number.
     *
     * @throws ArangoException
     */
    public function testAnAqlNumberIsStillANumber() :void
    {
        foreach ( [ '123' => 123 , '+5' => 5 , '0.5' => 0.5 , '1e3' => 1000 , '-.5' => -0.5 ] as $value => $expected )
        {
            $rows = iterator_to_array( self::$db->query( 'RETURN ' . aqlValue( (string) $value ) ) , false ) ;
            $this->assertEquals( [ $expected ] , $rows , var_export( $value , true ) ) ;
        }
    }
}
