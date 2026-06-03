<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\FacetTrait;

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
    use BindTrait ,
        FacetTrait ;

    public mixed    $logger     = null ;
    public ?string  $collection = 'mycol' ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ; // deterministic: bind names are always explicit, so the id is unused
    }

    public function __toString() :string
    {
        return '[stub]' ;
    }

    public function callPrepareFacets( ?array $init , ?array &$binds = null , string $docRef = AQL::DOC , string $op = Logic::AND ) :?string
    {
        return $this->prepareFacets( $init , $binds , $docRef , $op ) ;
    }

    public function callField( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetField( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callListField( string $key , mixed $value , array &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        return $this->prepareFacetListField( $key , $value , $binds , $facet , $doc , $sortable ) ;
    }

    public function callListFieldSorted( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetListFieldSorted( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callEdge( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetEdge( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callEdgeComplex( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetEdgeComplex( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callArrayComplex( string $key , mixed $value , array &$binds , string $doc = AQL::DOC ) :string
    {
        return $this->prepareFacetArrayComplex( $key , $value , $binds , $doc ) ;
    }

    public function callList( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetList( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callIn( string $key , mixed $value , array &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        return $this->prepareFacetIn( $key , $value , $binds , $facet , $doc , $sortable ) ;
    }

    public function callThesaurus( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetThesaurus( $key , $value , $binds , $facet , $doc ) ;
    }
}
