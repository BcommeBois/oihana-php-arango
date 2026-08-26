<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\traits\aql\SortTrait;

use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\normalizeSortable;

/**
 * Bare host exposing {@see SortTrait} with a projection map, so the multilingual
 * detection can inherit a field declared `Filter::TRANSLATE`.
 */
class MultilingualSortStub
{
    use SortTrait ;

    public ?array $fields = null ;
}

/**
 * Unit coverage of the **multilingual** `?sort=` entry: ordering on one locale of
 * a translations object, with a fallback locale and a last-resort field.
 *
 * The declaration sites are swept as combinations rather than as lines — the
 * requested locale, the three fallback sites and `Field::ELSE` each answer or stay
 * silent, and what the expression becomes depends on which ones did.
 */
final class MultilingualSortTest extends TestCase
{
    private function stub( array $sortable , ?array $fields = null , ?string $defaultLang = null ) : MultilingualSortStub
    {
        $stub = new MultilingualSortStub() ;

        $stub->sortable    = $sortable ;
        $stub->fields      = $fields ;
        $stub->defaultLang = $defaultLang ;

        return $stub ;
    }

    /**
     * The declaration used by most cases: a translated field, a fallback field, and
     * the projection that makes the entry multilingual by inheritance.
     */
    private function model( ?string $defaultLang = null , array $extra = [] ) : MultilingualSortStub
    {
        return $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' , Field::ELSE => 'name' ] + $extra ] ,
            [ 'alternateName' => Filter::TRANSLATE , 'name' => Filter::STRING ] ,
            $defaultLang ,
        ) ;
    }

    // =========================================================================
    // What marks an entry as multilingual
    // =========================================================================

    #[Test]
    public function theProjectionDeclarationIsInherited() : void
    {
        $sort = $this->model( 'fr' )->prepareSort( [ Arango::SORT => 'label' ] ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["fr"],doc.name) ASC' , $sort ) ;
    }

    #[Test]
    public function theProjectionDeclarationIsInheritedFromItsArrayForm() : void
    {
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' , Field::ELSE => 'name' ] ] ,
            [ 'alternateName' => [ Field::FILTER => Filter::TRANSLATE ] ] ,
            'fr' ,
        ) ;

        $this->assertSame
        (
            'NOT_NULL(doc.alternateName["fr"],doc.name) ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'label' ] ) ,
        ) ;
    }

    #[Test]
    public function theEntryCanDeclareItItselfWhenTheFieldIsNotProjected() : void
    {
        $stub = $this->stub
        (
            [ 'label' =>
            [
                Field::FILTER => Filter::TRANSLATE ,
                Field::PATH   => 'internal.labels' ,
                Field::ELSE   => 'name' ,
            ] ] ,
            [ 'name' => Filter::STRING ] ,
            'fr' ,
        ) ;

        $this->assertSame
        (
            'NOT_NULL(doc.internal.labels["fr"],doc.name) ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'label' ] ) ,
        ) ;
    }

    #[Test]
    public function anUndeclaredFieldStaysTheStoredPathItAlwaysWas() : void
    {
        $stub = $this->stub( [ 'label' => 'alternateName' ] , [ 'alternateName' => Filter::STRING ] , 'fr' ) ;

        $this->assertSame( 'doc.alternateName ASC' , $stub->prepareSort( [ Arango::SORT => 'label' ] ) ) ;
    }

    #[Test]
    public function aNestedPathIsNotWalkedIntoByInheritance() : void
    {
        // Documented limit: inheritance reads a ROOT field. The entry may still
        // declare Field::FILTER itself — here it does not, so nothing changes.
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'meta.alternateName' ] ] ,
            [ 'meta' => [ Field::FILTER => Filter::DOCUMENT , Field::FIELDS => [ 'alternateName' => Filter::TRANSLATE ] ] ] ,
            'fr' ,
        ) ;

        $this->assertSame( 'doc.meta.alternateName ASC' , $stub->prepareSort( [ Arango::SORT => 'label' ] ) ) ;
    }

    // =========================================================================
    // The chain, and what shortens it
    // =========================================================================

    #[Test]
    public function theRequestedLocaleComesFirstThenTheFallbackThenTheElseField() : void
    {
        $sort = $this->model( 'fr' )->prepareSort( [ Arango::SORT => 'label' , Arango::LANG => 'en' ] ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["en"],doc.alternateName["fr"],doc.name) ASC' , $sort ) ;
    }

    #[Test]
    public function aRequestedLocaleEqualToTheFallbackIsNotEmittedTwice() : void
    {
        $sort = $this->model( 'fr' )->prepareSort( [ Arango::SORT => 'label' , Arango::LANG => 'fr' ] ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["fr"],doc.name) ASC' , $sort ) ;
    }

    #[Test]
    public function anExplicitlyNullLanguageOrdersOnTheFallback() : void
    {
        // `?lang=all` resolves to null upstream: it widens the payload, it does
        // not unplug the sort.
        $sort = $this->model( 'fr' )->prepareSort( [ Arango::SORT => 'label' , Arango::LANG => null ] ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["fr"],doc.name) ASC' , $sort ) ;
    }

    #[Test]
    public function withoutAnElseFieldTheChainStopsAtTheLocales() : void
    {
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' ] ] ,
            [ 'alternateName' => Filter::TRANSLATE ] ,
            'fr' ,
        ) ;

        $this->assertSame
        (
            'NOT_NULL(doc.alternateName["en"],doc.alternateName["fr"]) ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'label' , Arango::LANG => 'en' ] ) ,
        ) ;
    }

    #[Test]
    public function aSingleLinkNeedsNoNotNullAtAll() : void
    {
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' ] ] ,
            [ 'alternateName' => Filter::TRANSLATE ] ,
        ) ;

        $this->assertSame
        (
            'doc.alternateName["en"] ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'label' , Arango::LANG => 'en' ] ) ,
        ) ;
    }

    #[Test]
    public function anEmptyChainDegradesToTheStoredPath() : void
    {
        // No requested locale, no fallback declared anywhere, no Field::ELSE.
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' ] ] ,
            [ 'alternateName' => Filter::TRANSLATE ] ,
        ) ;

        $this->assertSame( 'doc.alternateName ASC' , $stub->prepareSort( [ Arango::SORT => 'label' ] ) ) ;
    }

    // =========================================================================
    // The fallback cascade — entry > model > host
    // =========================================================================

    #[Test]
    public function theHostFallbackIsUsedWhenNothingElseDeclaresOne() : void
    {
        $sort = $this->model()->prepareSort( [ Arango::SORT => 'label' , Arango::DEFAULT_LANG => 'es' ] ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["es"],doc.name) ASC' , $sort ) ;
    }

    #[Test]
    public function theModelOutranksTheHost() : void
    {
        // A default must never override an explicit declaration.
        $sort = $this->model( 'fr' )->prepareSort( [ Arango::SORT => 'label' , Arango::DEFAULT_LANG => 'es' ] ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["fr"],doc.name) ASC' , $sort ) ;
    }

    #[Test]
    public function theEntryOutranksTheModel() : void
    {
        $stub = $this->model( 'fr' , [ Field::DEFAULT_LANG => 'de' ] ) ;

        $this->assertSame
        (
            'NOT_NULL(doc.alternateName["de"],doc.name) ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'label' ] ) ,
        ) ;
    }

    #[Test]
    public function theEntryOutranksTheHostToo() : void
    {
        $stub = $this->model( null , [ Field::DEFAULT_LANG => 'de' ] ) ;

        $this->assertSame
        (
            'NOT_NULL(doc.alternateName["de"],doc.name) ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'label' , Arango::DEFAULT_LANG => 'es' ] ) ,
        ) ;
    }

    #[Test]
    public function theRequestedLocaleOutranksEveryFallback() : void
    {
        $stub = $this->model( 'fr' , [ Field::DEFAULT_LANG => 'de' ] ) ;

        $sort = $stub->prepareSort
        (
            [ Arango::SORT => 'label' , Arango::LANG => 'en' , Arango::DEFAULT_LANG => 'es' ] ,
        ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["en"],doc.alternateName["de"],doc.name) ASC' , $sort ) ;
    }

    // =========================================================================
    // Guards
    // =========================================================================

    #[Test]
    public function aRequestedTagThatIsNotALanguageBlamesTheRequest() : void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid language code: "fr" || 1==1"' ) ;

        $this->model( 'fr' )->prepareSort( [ Arango::SORT => 'label' , Arango::LANG => 'fr" || 1==1' ] ) ;
    }

    #[Test]
    public function aDeclaredTagThatIsNotALanguageBlamesTheCode() : void
    {
        $stub = $this->model( 'fr' , [ Field::DEFAULT_LANG => 'fr_FR' ] ) ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid language code: "fr_fr"' ) ;

        $stub->prepareSort( [ Arango::SORT => 'label' ] ) ;
    }

    #[Test]
    public function anElsePathThatIsNotAnAttributeIsRefused() : void
    {
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' , Field::ELSE => 'name || 1==1' ] ] ,
            [ 'alternateName' => Filter::TRANSLATE ] ,
            'fr' ,
        ) ;

        $this->expectException( ValidationException::class ) ;

        $stub->prepareSort( [ Arango::SORT => 'label' ] ) ;
    }

    #[Test]
    public function aDashedTagIsReachedThroughTheBracketAccessor() : void
    {
        $sort = $this->model()->prepareSort( [ Arango::SORT => 'label' , Arango::LANG => 'pt-BR' ] ) ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["pt-br"],doc.name) ASC' , $sort ) ;
    }

    // =========================================================================
    // Permission
    // =========================================================================

    #[Test]
    public function anUnreadableElseFieldIsDroppedFromTheChain() : void
    {
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' , Field::ELSE => 'secret' ] ] ,
            [ 'alternateName' => Filter::TRANSLATE , 'secret' => [ Field::REQUIRES => 'staff:read' ] ] ,
            'fr' ,
        ) ;

        $sort = $stub->prepareSort
        (
            [ Arango::SORT => 'label' , Arango::AUTHORIZER => fn() => false ] ,
        ) ;

        $this->assertSame( 'doc.alternateName["fr"] ASC' , $sort ) ;
    }

    #[Test]
    public function anUnreadableTranslatedFieldDropsTheWholeCriterion() : void
    {
        $stub = $this->stub
        (
            [ 'label' => [ Field::PATH => 'alternateName' , Field::ELSE => 'name' ] ] ,
            [ 'alternateName' => [ Field::FILTER => Filter::TRANSLATE , Field::REQUIRES => 'staff:read' ] ] ,
            'fr' ,
        ) ;

        $sort = $stub->prepareSort
        (
            [ Arango::SORT => 'label' , Arango::AUTHORIZER => fn() => false ] ,
        ) ;

        $this->assertSame( '' , $sort ) ;
    }

    // =========================================================================
    // Composition with the rest of the grammar
    // =========================================================================

    #[Test]
    public function aMultilingualCriterionDescendsAndMixesWithAStoredOne() : void
    {
        $stub = $this->stub
        (
            [
                'label'   => [ Field::PATH => 'alternateName' , Field::ELSE => 'name' ] ,
                'created' => 'created' ,
            ] ,
            [ 'alternateName' => Filter::TRANSLATE ] ,
            'fr' ,
        ) ;

        $sort = $stub->prepareSort( [ Arango::SORT => '-label,created' , Arango::LANG => 'en' ] ) ;

        $this->assertSame
        (
            'NOT_NULL(doc.alternateName["en"],doc.alternateName["fr"],doc.name) DESC, doc.created ASC' ,
            $sort ,
        ) ;
    }

    #[Test]
    public function theDefaultSortReachesItToo() : void
    {
        $stub = $this->model( 'fr' ) ;
        $stub->sortDefault = 'label' ;

        $this->assertSame( 'NOT_NULL(doc.alternateName["fr"],doc.name) ASC' , $stub->prepareSort() ) ;
    }

    // =========================================================================
    // 🚨 The behaviour that moves without a declaration changing
    // =========================================================================

    /**
     * The indexed shorthand over a field the projection already declares
     * `Filter::TRANSLATE` is detected on its own — no `Field::PATH`, no
     * `Field::FILTER`, nothing added to the model. It used to order on the whole
     * translations object; under a language it orders on that locale.
     *
     * Both halves are pinned here because the frontier between them is what
     * `UPGRADING.md` tells consumers to grep for: with no language in play, the
     * expression is the stored path byte for byte.
     */
    #[Test]
    public function theIndexedShorthandOnATranslatedFieldFollowsTheLanguage() : void
    {
        $stub = $this->stub
        (
            normalizeSortable( [ 'alternateName' ] ) ,
            [ 'alternateName' => Filter::TRANSLATE ] ,
        ) ;

        $this->assertSame
        (
            'doc.alternateName ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'alternateName' ] ) ,
            'No language anywhere: unchanged.' ,
        ) ;

        $this->assertSame
        (
            'doc.alternateName["fr"] ASC' ,
            $stub->prepareSort( [ Arango::SORT => 'alternateName' , Arango::LANG => 'fr' ] ) ,
            'A requested language moves it — the breaking change.' ,
        ) ;
    }

    #[Test]
    public function anUnknownKeyKeepsDroppingInSilence() : void
    {
        $this->assertSame( '' , $this->model( 'fr' )->prepareSort( [ Arango::SORT => 'nope' ] ) ) ;
    }

    #[Test]
    public function theWhitelistIsStillTheDoorkeeper() : void
    {
        $stub = $this->stub( [] , [ 'alternateName' => Filter::TRANSLATE ] , 'fr' ) ;

        $this->assertSame( '' , $stub->prepareSort( [ Arango::SORT => 'alternateName' ] ) ) ;
    }
}
