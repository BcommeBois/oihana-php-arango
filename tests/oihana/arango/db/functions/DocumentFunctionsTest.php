<?php

namespace tests\oihana\arango\db\functions;

use ReflectionClass;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\functions\DocumentFunction;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\functions\documents\has;
use function oihana\arango\db\functions\documents\merge;
use function oihana\arango\db\functions\documents\translate;
use function oihana\arango\db\functions\documents\value;

use function oihana\arango\db\helpers\aqlDocument;

use function oihana\core\strings\func;

class DocumentFunctionsTest extends TestCase
{
    public function testHas(): void
    {
        $result = has("doc", "attr");
        $expected = DocumentFunction::HAS . "(doc,attr)";
        $this->assertSame($expected, $result);
    }

    public function testMergeWithString(): void
    {
        $result = merge("doc1");
        $expected = DocumentFunction::MERGE . "(doc1)";
        $this->assertSame($expected, $result);
    }

    public function testMergeWithArray(): void
    {
        $docs = ['doc1', 'doc2'];
        $result = merge($docs);
        $expected = DocumentFunction::MERGE . "(doc1,doc2)";
        $this->assertSame($expected, $result);
    }

    public function testMergeWithNull(): void
    {
        $result = merge(null);
        $expected = DocumentFunction::MERGE . "()";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testMergeSingleObject()
    {
        $obj = aqlDocument(['name' => 'Eka', 'age' => 47]);
        $expected = "MERGE({name:'Eka',age:47})";
        $this->assertSame($expected, merge($obj));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testMergeArrayOfObjects()
    {
        $obj1 = aqlDocument((object)['name' => 'Eka']);
        $obj2 = aqlDocument(['age' => 47]);
        $expected = "MERGE({name:'Eka'},{age:47})";
        $this->assertSame($expected, merge([$obj1, $obj2]));
    }

    public function testTranslateWithDefault(): void
    {
        $result = translate("value", "{foo:1}", "bar");
        $expected = "TRANSLATE(value,{foo:1},bar)";
        $this->assertSame($expected, $result);
    }

    public function testValue(): void
    {
        $path = ["foo", "bar", 2];
        $result = value("doc", $path);
        $expected = DocumentFunction::VALUE . "(doc," . json_encode($path) . ")";
        $this->assertSame($expected, $result);
    }

    public function testFuncStandaloneWithString(): void
    {
        $result = func("FOO", "bar");
        $this->assertSame("FOO(bar)", $result);
    }

    public function testFuncStandaloneWithArray(): void
    {
        $result = func("FOO", ["bar", "baz"]);
        $this->assertSame("FOO(bar,baz)", $result);
    }

    public function testFuncStandaloneWithNull(): void
    {
        $result = func("FOO", null);
        $this->assertSame("FOO()", $result);
    }

    public function testAllDocumentFunctionConstants(): void
    {
        $reflection = new ReflectionClass(DocumentFunction::class);
        $constants = $reflection->getConstants();

        // Check all constants
        $expected =
        [
            'ATTRIBUTES', 'COUNT', 'ENTRIES', 'HAS', 'IS_SAME_COLLECTION',
            'KEEP', 'KEEP_RECURSIVE', 'KEYS', 'LENGTH', 'MATCHES', 'MERGE',
            'MERGE_RECURSIVE', 'PARSE_COLLECTION', 'PARSE_IDENTIFIER', 'PARSE_KEY',
            'TRANSLATE', 'UNSET', 'UNSET_RECURSIVE', 'VALUE', 'VALUES', 'ZIP'
        ];

        $this->assertSame($expected, array_keys($constants));

        // On vérifie que chaque constante renvoie bien son nom (cohérence interne)
        foreach ($constants as $name => $value) {
            $this->assertSame($name, $value, "Constante incorrecte pour $name");
        }
    }

    public function testFuncWithCustomSeparator(): void
    {
        $result = func("FOO", ["a", "b", "c"], ";");
        $this->assertSame("FOO(a;b;c)", $result);
    }
}
