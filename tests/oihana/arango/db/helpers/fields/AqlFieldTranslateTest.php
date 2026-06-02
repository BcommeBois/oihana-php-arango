<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\aqlFieldTranslate;

/**
 * Coverage for {@see aqlFieldTranslate()} — emits a TRANSLATE("lang", doc.key, "")
 * expression when a language is supplied, otherwise falls back to the plain field.
 *
 * The signature is (key, doc, keyName, lang): the language is the FOURTH argument.
 * The live dispatcher calls it as aqlFieldTranslate($key, $docRef, $keyName, $init)
 * and the function pulls Arango::LANG out of the $init array.
 *
 * NOTE: the function's own docblock examples are misleading — they pass the
 * language as the 3rd argument (the keyName slot), e.g. claiming
 * `aqlFieldTranslate('title','doc','fr')` yields `TRANSLATE("fr", doc.title, "")`.
 * It does not (see testThirdArgumentIsKeyNameNotLang). Flagged to the maintainer;
 * the function behaviour itself is correct, only the doc examples are wrong.
 */
final class AqlFieldTranslateTest extends TestCase
{
    public function testLanguageAsStringEmitsTranslate() :void
    {
        $this->assertSame
        (
            'label:TRANSLATE("en",doc.name,"")' ,
            aqlFieldTranslate( 'label' , 'doc' , 'name' , 'en' ) ,
        ) ;
    }

    public function testLanguageAsArrayUsesTheLangKey() :void
    {
        $this->assertSame
        (
            'title:TRANSLATE("de",doc.title,"")' ,
            aqlFieldTranslate( 'title' , 'doc' , null , [ Arango::LANG => 'de' ] ) ,
        ) ;
    }

    public function testNullLanguageFallsBackToPlainField() :void
    {
        $this->assertSame
        (
            'description:doc.description' ,
            aqlFieldTranslate( 'description' , 'doc' , null ) ,
        ) ;
    }

    public function testArrayLanguageWithoutLangKeyFallsBackToPlainField() :void
    {
        $this->assertSame
        (
            'title:doc.title' ,
            aqlFieldTranslate( 'title' , 'doc' , null , [ 'other' => 'x' ] ) ,
        ) ;
    }

    public function testDefaultsToPlainFieldOnDocRef() :void
    {
        $this->assertSame( 'title:doc.title' , aqlFieldTranslate( 'title' ) ) ;
    }

    /**
     * Freezes the keyName/lang argument order: the 3rd argument is the property
     * name, not the language, so no translation happens here.
     */
    public function testThirdArgumentIsKeyNameNotLang() :void
    {
        $this->assertSame( 'title:doc.fr' , aqlFieldTranslate( 'title' , 'doc' , 'fr' ) ) ;
    }
}
