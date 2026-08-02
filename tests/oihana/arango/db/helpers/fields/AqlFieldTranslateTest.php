<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\aqlFieldTranslate;

/**
 * Coverage for {@see aqlFieldTranslate()} — emits a guarded TRANSLATE("lang", doc.key, "")
 * expression when a language is supplied, otherwise falls back to the plain field.
 *
 * The signature is (key, doc, keyName, lang): the language is the FOURTH argument.
 * The live dispatcher calls it as aqlFieldTranslate($key, $docRef, $keyName, $init)
 * and the function pulls Arango::LANG out of the $init array.
 */
final class AqlFieldTranslateTest extends TestCase
{
    public function testLanguageAsStringEmitsTranslate() :void
    {
        $this->assertSame
        (
            'label:IS_OBJECT(doc.name) ? TRANSLATE("en",doc.name,"") : null' ,
            aqlFieldTranslate( 'label' , 'doc' , 'name' , 'en' ) ,
        ) ;
    }

    public function testLanguageAsArrayUsesTheLangKey() :void
    {
        $this->assertSame
        (
            'title:IS_OBJECT(doc.title) ? TRANSLATE("de",doc.title,"") : null' ,
            aqlFieldTranslate( 'title' , 'doc' , null , [ Arango::LANG => 'de' ] ) ,
        ) ;
    }

    /**
     * ⭐ TRANSLATE() looks the language up IN A DOCUMENT : handed anything else — an absent
     * attribute, or a plain string left over from a pre-i18n record — it returns null AND
     * raises an AQL warning (1542), once per row concerned. A query run with the
     * failOnWarning cursor option then fails outright, so one un-translated document could
     * break a whole listing. The guard asks first and yields the very same null.
     */
    public function testTheGuardIsOnTheSourceBeingADocument() :void
    {
        $result = aqlFieldTranslate( 'title' , 'doc' , null , 'fr' ) ;

        $this->assertStringStartsWith( 'title:IS_OBJECT(doc.title) ?' , $result ) ;
        $this->assertStringEndsWith  ( ': null' , $result ) ;
    }

    /**
     * The guard follows the aliased source and the given reference — the same attribute is
     * tested and translated, never two different ones.
     */
    public function testTheGuardFollowsTheAliasedSourceAndReference() :void
    {
        $this->assertSame
        (
            'label:IS_OBJECT(v_1.name) ? TRANSLATE("en",v_1.name,"") : null' ,
            aqlFieldTranslate( 'label' , 'v_1' , 'name' , 'en' ) ,
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

    /**
     * Without a language there is no TRANSLATE() to protect : the plain projection stays
     * unguarded, byte for byte as before.
     */
    public function testTheFallbackProjectionIsNotGuarded() :void
    {
        $this->assertStringNotContainsString( 'IS_OBJECT' , aqlFieldTranslate( 'description' , 'doc' ) ) ;
    }
}
