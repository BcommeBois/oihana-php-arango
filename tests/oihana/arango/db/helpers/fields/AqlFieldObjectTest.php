<?php

namespace tests\oihana\arango\db\helpers\fields;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\aqlFieldObject;

/**
 * Coverage for {@see aqlFieldObject()} — the `IS_OBJECT ? : IS_ARRAY ? FIRST : null`
 * projection that normalizes a field to a single object (taking the first element
 * when the source is an array).
 */
final class AqlFieldObjectTest extends TestCase
{
    public function testReturnsObjectOrFirstArrayElement() :void
    {
        $this->assertSame
        (
            'author:IS_OBJECT(doc.author) ? doc.author : IS_ARRAY(doc.author) ? FIRST(doc.author) : null' ,
            aqlFieldObject( 'author' , 'doc.author' ) ,
        ) ;
    }

    public function testWorksWithAPlainVariableReference() :void
    {
        $this->assertSame
        (
            'main:IS_OBJECT(tagsList) ? tagsList : IS_ARRAY(tagsList) ? FIRST(tagsList) : null' ,
            aqlFieldObject( 'main' , 'tagsList' ) ,
        ) ;
    }
}
