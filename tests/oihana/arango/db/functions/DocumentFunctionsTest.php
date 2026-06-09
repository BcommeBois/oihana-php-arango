<?php

namespace tests\oihana\arango\db\functions;

use ReflectionClass;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\functions\DocumentFunction;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\functions\documents\attributes;
use function oihana\arango\db\functions\documents\count;
use function oihana\arango\db\functions\documents\entries;
use function oihana\arango\db\functions\documents\has;
use function oihana\arango\db\functions\documents\isSameCollection;
use function oihana\arango\db\functions\documents\keep;
use function oihana\arango\db\functions\documents\keepRecursive;
use function oihana\arango\db\functions\documents\keys;
use function oihana\arango\db\functions\documents\length;
use function oihana\arango\db\functions\documents\matches;
use function oihana\arango\db\functions\documents\merge;
use function oihana\arango\db\functions\documents\mergeRecursive;
use function oihana\arango\db\functions\documents\parseCollection;
use function oihana\arango\db\functions\documents\parseIdentifier;
use function oihana\arango\db\functions\documents\parseKey;
use function oihana\arango\db\functions\documents\translate;
use function oihana\arango\db\functions\documents\unsetAttributes;
use function oihana\arango\db\functions\documents\unsetRecursive;
use function oihana\arango\db\functions\documents\value;
use function oihana\arango\db\functions\documents\values;
use function oihana\arango\db\functions\documents\zip;

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

    // ---------- Lot F — document function helpers ----------

    public function testAttributes(): void
    {
        $this->assertSame( 'ATTRIBUTES(doc)' , attributes( 'doc' ) );
        $this->assertSame( 'ATTRIBUTES(doc,true)' , attributes( 'doc' , true ) );
        $this->assertSame( 'ATTRIBUTES(doc,true,true)' , attributes( 'doc' , true , true ) );
        $this->assertSame( 'ATTRIBUTES(doc,false,true)' , attributes( 'doc' , null , true ) );
    }

    public function testKeys(): void
    {
        $this->assertSame( 'KEYS(doc)' , keys( 'doc' ) );
        $this->assertSame( 'KEYS(doc,true,true)' , keys( 'doc' , true , true ) );
        $this->assertSame( 'KEYS(doc,false,true)' , keys( 'doc' , null , true ) );
    }

    public function testValues(): void
    {
        $this->assertSame( 'VALUES(doc)' , values( 'doc' ) );
        $this->assertSame( 'VALUES(doc,true)' , values( 'doc' , true ) );
    }

    public function testCount(): void
    {
        $this->assertSame( 'COUNT(doc)' , count( 'doc' ) );
    }

    public function testLength(): void
    {
        $this->assertSame( 'LENGTH(doc)' , length( 'doc' ) );
    }

    public function testEntries(): void
    {
        $this->assertSame( 'ENTRIES(doc)' , entries( 'doc' ) );
    }

    public function testKeep(): void
    {
        $this->assertSame( 'KEEP(doc,"name","age")' , keep( 'doc' , 'name' , 'age' ) );
    }

    public function testKeepRecursive(): void
    {
        $this->assertSame( 'KEEP_RECURSIVE(doc,"meta")' , keepRecursive( 'doc' , 'meta' ) );
    }

    public function testUnsetAttributes(): void
    {
        $this->assertSame( 'UNSET(doc,"_id","_rev")' , unsetAttributes( 'doc' , '_id' , '_rev' ) );
    }

    public function testUnsetRecursive(): void
    {
        $this->assertSame( 'UNSET_RECURSIVE(doc,"_id")' , unsetRecursive( 'doc' , '_id' ) );
    }

    public function testMergeRecursive(): void
    {
        $this->assertSame( 'MERGE_RECURSIVE(d1,d2)' , mergeRecursive( [ 'd1' , 'd2' ] ) );
    }

    public function testMatches(): void
    {
        $this->assertSame( 'MATCHES(doc,{"age":30})' , matches( 'doc' , [ 'age' => 30 ] ) );
        $this->assertSame( 'MATCHES(doc,[{"age":30},{"age":40}],true)' , matches( 'doc' , [ [ 'age' => 30 ] , [ 'age' => 40 ] ] , true ) );
        $this->assertSame( 'MATCHES(doc,@examples)' , matches( 'doc' , '@examples' ) );
    }

    public function testZip(): void
    {
        $this->assertSame( 'ZIP(["a","b"],[1,2])' , zip( [ 'a' , 'b' ] , [ 1 , 2 ] ) );
        $this->assertSame( 'ZIP(doc.k,doc.v)' , zip( 'doc.k' , 'doc.v' ) );
    }

    public function testIsSameCollection(): void
    {
        $this->assertSame( 'IS_SAME_COLLECTION("products",doc._id)' , isSameCollection( 'products' , 'doc._id' ) );
    }

    public function testParseCollection(): void
    {
        $this->assertSame( 'PARSE_COLLECTION(doc._id)' , parseCollection( 'doc._id' ) );
    }

    public function testParseIdentifier(): void
    {
        $this->assertSame( 'PARSE_IDENTIFIER(doc._id)' , parseIdentifier( 'doc._id' ) );
    }

    public function testParseKey(): void
    {
        $this->assertSame( 'PARSE_KEY(doc._id)' , parseKey( 'doc._id' ) );
    }
}
