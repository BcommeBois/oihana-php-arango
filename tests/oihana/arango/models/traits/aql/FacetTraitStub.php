<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\FacetTrait;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use ReflectionException;

/**
 * Bare host exposing {@see FacetTrait} (and the {@see BindTrait} it relies on
 * for `$this->bind()`) for isolated testing. Public proxies forward to the
 * protected facet builders while preserving the `&$binds` reference.
 *
 * Shared by the unit characterization suite ({@see FacetTraitTest}) and the
 * live integration suite ({@see \tests\oihana\arango\integration\FacetIntegrationTest}).
 */
class FacetTraitStub
{
    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ; // deterministic: bind names are always explicit, so the id is unused
    }

    use BindTrait ,
        FacetTrait ;

    public mixed        $logger     = null ;
    public ?string      $collection = 'mycol' ;
    public ?array       $fields     = null ; // projection map — powers the inherited permission gate
    public ?\DI\Container $container = null ; // resolves an aggregate facet's AQL::MODEL for the Levier 2 gate

    public function __toString() :string
    {
        return '[stub]' ;
    }

    public function callPrepareFacets( ?array $init , ?array &$binds = null , string $docRef = AQL::DOC , string $op = Logic::AND ) :?string
    {
        return $this->prepareFacets( $init , $binds , $docRef , $op ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    public function callField( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetField( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @param bool $sortable
     * @return string
     * @throws BindException
     */
    public function callListField( string $key , mixed $value , array &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        return $this->prepareFacetListField( $key , $value , $binds , $facet , $doc , $sortable ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     */
    public function callListFieldSorted( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetListFieldSorted( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     * @throws ReflectionException
     */
    public function callEdge( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetEdge( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function callEdgeComplex( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetEdgeComplex( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function callEdgeAggregate( string $key , mixed $value , array &$binds , array $facet , string $doc , array $init = [] ) :string
    {
        return $this->prepareFacetEdgeAggregate( $key , $value , $binds , $facet , $doc , $init ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     * @throws ValidationException
     */
    public function callJoinAggregate( string $key , mixed $value , array &$binds , array $facet , string $doc , array $init = [] ) :string
    {
        return $this->prepareFacetJoinAggregate( $key , $value , $binds , $facet , $doc , $init ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ReflectionException
     * @throws BindException
     */
    public function callJoinComplex( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetJoinComplex( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ReflectionException
     * @throws BindException
     */
    public function callArrayComplex( string $key , mixed $value , array &$binds , array $facet = [] , string $doc = AQL::DOC ) :string
    {
        return $this->prepareFacetArrayComplex( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     */
    public function callJoin( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetJoin( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     */
    public function callList( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetList( $key , $value , $binds , $facet , $doc ) ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @param bool $sortable
     * @return string
     * @throws BindException
     */
    public function callIn( string $key , mixed $value , array &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        return $this->prepareFacetIn( $key , $value , $binds , $facet , $doc , $sortable ) ;
    }

}
