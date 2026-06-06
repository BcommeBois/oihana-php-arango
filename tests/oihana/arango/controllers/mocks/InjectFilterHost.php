<?php

namespace tests\oihana\arango\controllers\mocks;

use oihana\arango\controllers\traits\inject\InjectFilterTrait;
use oihana\arango\models\enums\filters\FilterComparator;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Stub parent providing the `prepareFilter()` that
 * {@see InjectFilterTrait::prepareFilter()} delegates to via `parent::`.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class InjectFilterParent
{
    /**
     * Canned value returned by the parent `prepareFilter()` (the "URL filter").
     *
     * @var array|null
     */
    public ?array $urlFilterStub = null ;

    /**
     * @param Request|null $request
     * @param array        $args
     * @param array|null   $params
     *
     * @return array|null
     */
    protected function prepareFilter( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        return $this->urlFilterStub ;
    }
}

/**
 * Host composing {@see InjectFilterTrait} on top of {@see InjectFilterParent}
 * so the trait's `prepareFilter()` override (which calls `parent::`) can be
 * exercised, along with public proxies for the protected inject helpers.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class InjectFilterHost extends InjectFilterParent
{
    use InjectFilterTrait ;

    /**
     * The internal injected-filters init key (for assertions).
     */
    public function injectedKey() :string
    {
        return self::INJECTED_FILTERS ;
    }

    /**
     * Public proxy for {@see InjectFilterTrait::injectFilter()}.
     *
     * @param array       $init
     * @param string      $key
     * @param mixed       $value
     * @param string      $op
     * @param string|null $alt
     *
     * @return void
     */
    public function callInjectFilter( array &$init , string $key , mixed $value , string $op = FilterComparator::EQ , ?string $alt = null ) :void
    {
        $this->injectFilter( $init , $key , $value , $op , $alt ) ;
    }

    /**
     * Public proxy for {@see InjectFilterTrait::injectFilters()}.
     *
     * @param array $init
     * @param array $filters
     *
     * @return void
     */
    public function callInjectFilters( array &$init , array $filters ) :void
    {
        $this->injectFilters( $init , $filters ) ;
    }

    /**
     * Public proxy for {@see InjectFilterTrait::prepareFilter()}.
     *
     * @param array $args
     *
     * @return array|null
     */
    public function callPrepareFilter( array $args = [] ) :?array
    {
        return $this->prepareFilter( null , $args ) ;
    }
}
