<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\isAQLFunction;

class IsAQLFunctionTest extends TestCase
{
    public function testReturnsFalseForUnknownFunctions()
    {
        $expression = 'UNKNOWN_FUNC(doc)';
        $this->assertFalse( isAQLFunction( $expression ) ) ;
    }

    public function testReturnsTrueForMultipleFunctionTypes()
    {
        $expression = 'LENGTH(doc.name)';
        $this->assertTrue( isAQLFunction( $expression ) ); // NumericFunction::isFunctionCall
    }

    public function testReturnsFalseWithCaseSensitivityProblem()
    {
        $expression = 'length(doc.name)';
        $this->assertFalse( isAQLFunction( $expression ) );
    }
}