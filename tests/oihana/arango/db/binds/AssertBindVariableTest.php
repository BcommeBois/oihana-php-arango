<?php

namespace tests\oihana\arango\db\binds;

use oihana\exceptions\BindException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\binds\assertBindVariable;

final class AssertBindVariableTest extends TestCase
{
    public function testValidBindVariables(): void
    {
        $validVariables = [
            'foo',
            '_bar',
            'bar123',
            '@userId',
            '@_temp',
        ];

        foreach ($validVariables as $var)
        {
            try
            {
                assertBindVariable($var);
                $this->addToAssertionCount(1); // assure qu'une assertion est comptée
            }
            catch ( BindException $e )
            {
                $this->fail("Valid bind variable '$var' threw an exception: " . $e->getMessage());
            }
        }
    }

    public function testNullIsAllowed(): void
    {
        try
        {
            assertBindVariable(null);
            $this->addToAssertionCount(1);
        }
        catch (BindException $e)
        {
            $this->fail("Null should be allowed as a bind variable.");
        }
    }

    public function testInvalidBindVariables(): void
    {
        $invalidVariables =
        [
            '123abc',      // starts with digit
            '@!invalid',   // invalid character
            'user-id',     // hyphen not allowed
            'foo bar',     // space not allowed
            '',            // empty string
            '@',           // just @
        ];

        foreach ($invalidVariables as $var)
        {
            $this->expectException(BindException::class);
            $this->expectExceptionMessageMatches('/Invalid bind variable/');
            assertBindVariable($var);
        }
    }
}