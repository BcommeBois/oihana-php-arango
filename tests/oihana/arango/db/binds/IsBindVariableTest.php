<?php

namespace tests\oihana\arango\db\binds;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\binds\isBindVariable;

class IsBindVariableTest extends TestCase
{
    public function testisBindVariable(): void
    {
        $this->assertTrue  ( isBindVariable('my_var'  ) ) ;
        $this->assertTrue  ( isBindVariable('@my_var' ) ) ;
        $this->assertFalse ( isBindVariable('my-var'  ) ) ;
        $this->assertFalse ( isBindVariable('1var'    ) ) ;
        $this->assertTrue  ( isBindVariable('var1'    ) ) ;
        $this->assertTrue  ( isBindVariable('_var'    ) ) ;
        $this->assertTrue  ( isBindVariable('var_'    ) ) ;
    }
}