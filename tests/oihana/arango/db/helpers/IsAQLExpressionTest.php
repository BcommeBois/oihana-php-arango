<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\isAQLExpression;

class IsAQLExpressionTest extends TestCase
{
    public function testNullReturnsFalse()
    {
        $this->assertFalse(isAQLExpression(null));
    }

    public function testBooleanReturnsFalse()
    {
        $this->assertFalse(isAQLExpression(true));
        $this->assertFalse(isAQLExpression(false));
    }

    public function testNumbersReturnFalse()
    {
        $this->assertFalse(isAQLExpression(0));
        $this->assertFalse(isAQLExpression(42));
        $this->assertFalse(isAQLExpression(-3.14));
    }

    public function testArraysAndObjectsReturnFalse()
    {
        $this->assertFalse(isAQLExpression([1,2,3]));
        $this->assertFalse(isAQLExpression(['key' => 'value']));
        $this->assertFalse(isAQLExpression((object)['name' => 'Alice']));
    }

    public function testEmptyStringReturnsFalse()
    {
        $this->assertFalse(isAQLExpression(''));
        $this->assertFalse(isAQLExpression('   '));
    }

    public function testAqlFunctionReturnsTrue()
    {
        // On suppose que StringFunction::CONCAT("a","b") est reconnu
        $this->assertTrue(isAQLExpression('CONCAT("a","b")'));
        $this->assertFalse(isAQLExpression('concat("a","b")'));
        $this->assertFalse(isAQLExpression('TEST("a","b")'));
    }

    public function testDocumentReferences()
    {
        $this->assertTrue(isAQLExpression('doc.field'));
        $this->assertTrue(isAQLExpression('user.profile.name'));
        $this->assertFalse(isAQLExpression('doc..field')); // double point invalide
        $this->assertFalse(isAQLExpression('.doc.field')); // début invalide
    }

    public function testBindParameters()
    {
        $this->assertTrue(isAQLExpression('@param'));
        $this->assertTrue(isAQLExpression('@user.name'));
        $this->assertFalse(isAQLExpression('@'));
        $this->assertFalse(isAQLExpression('@.field'));
    }

    public function testCollectionDocumentPaths()
    {
        $this->assertTrue(isAQLExpression('collection/key'));
        $this->assertTrue(isAQLExpression('users/1234'));
        $this->assertFalse(isAQLExpression('collection/'));
        $this->assertFalse(isAQLExpression('/key'));
    }

    public function testRandomQuotedStringReturnsTrue()
    {
        $this->assertTrue(isAQLExpression("'hello world'"));
    }

    /**
     * Compound expressions are never detected : a value that merely looks like
     * code stays a quoted string. Declare them raw instead (RAW_KEYS / RAW_VALUES).
     */
    public function testCompoundExpressionsAreNotDetected()
    {
        $this->assertFalse(isAQLExpression('(FOR x IN things RETURN x)'));
        $this->assertFalse(isAQLExpression('(for x in things return x)'));
        $this->assertFalse(isAQLExpression('(LET a = 1 RETURN a)'));
        $this->assertFalse(isAQLExpression('(1 + 2)'));
        $this->assertFalse(isAQLExpression('x.y == 1'));
        $this->assertFalse(isAQLExpression('i + 1'));
    }

    /**
     * A numeric string is code only when it is an AQL number. The server refuses a
     * leading zero before a digit and a trailing dot : those are text.
     */
    public function testNumericStringsTheServerRefusesAreNotDetected()
    {
        foreach ( [ '00' , '007' , '-007' , '+05' , '00.5' , '01e3' , '01000' , '0612345678' , '5.' , '-5.' , ' 007 ' ] as $value )
        {
            $this->assertFalse( isAQLExpression( $value ) , var_export( $value , true ) ) ;
        }
    }

    /**
     * Every numeric string the server reads as a number stays detected.
     */
    public function testAqlNumbersStayDetected()
    {
        foreach ( [ '0' , '0.5' , '-0' , '.5' , '-.5' , '5.0' , '0e5' , '+5' , '1e3' , '1E3' , '1e+3' , '1e-3' , '1.5e3' , '123' , ' 42 ' , '9007199254740993' ] as $value )
        {
            $this->assertTrue( isAQLExpression( $value ) , var_export( $value , true ) ) ;
        }
    }

    public function testRandomStringReturnsFalse()
    {
        $this->assertFalse(isAQLExpression('hello world'));
        $this->assertFalse(isAQLExpression('"hello world"'));
        $this->assertFalse(isAQLExpression('not_a_function()'));
    }
}