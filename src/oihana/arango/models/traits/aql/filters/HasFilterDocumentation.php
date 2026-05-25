<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Filter ;

trait HasFilterDocumentation
{
    /**
     * Generate documentation of all supported filter paths
     *
     * @param bool $includeTypes     Include filter types in output
     * @param bool $includeRelations Include relation references
     *
     * @return array Array of supported filter paths with metadata
     *
     * @example
     * ```php
     * $customersModel = $container->get(Models::CUSTOMERS);
     *
     * $filterPaths = $customersModel->documentFilterPaths();
     *
     * foreach ( $filterPaths as $path )
     * {
     *     echo $path['path'] . "\n";
     * }
     * ```
     *
     * **Output :**
     * ```
     * name
     * id
     * status
     * created
     * modified
     * address
     * address.email
     * address.street
     * address.city
     * address.postalCode
     * additionalProperty[*]
     * additionalProperty[*].propertyID
     * additionalProperty[*].value
     * contactPoint[*]
     * contactPoint[*].email
     * contactPoint[*].telephone
     * employee[*]
     * employee[*].givenName
     * employee[*].familyName
     * employee[*].contactPoint[*]
     * employee[*].contactPoint[*].email
     * employee[*].workLocation
     * employee[*].workLocation.name
     * employee[*].workLocation.address
     * employee[*].workLocation.address.email
     * assignedSeller
     * assignedSeller.name
     * assignedSeller.givenName
     * category[*]
     * category[*].id
     * category[*].name
     * location[*]
     * location[*].name
     * location[*].address
     * location[*].address.email
     * ```
     */
    public function documentFilterPaths
    (
        bool $includeTypes     = true,
        bool $includeRelations = false
    )
    : array
    {
        $paths = [];

        foreach ( $this->filters ?? [] as $key => $config )
        {
            $this->buildFilterPathsRecursive
            (
                $key ,
                $config ,
                [] ,
                $paths,
                $includeTypes ,
                $includeRelations
            );
        }

        return $paths;
    }

    /**
     * Recursively build filter paths documentation
     *
     * @param string $key Current segment key
     * @param mixed $config Current segment configuration
     * @param array $parentPath Accumulated parent path
     * @param array &$paths Reference to paths accumulator
     * @param bool $includeTypes Include type information
     * @param bool $includeRelations Include relation references
     */
    private function buildFilterPathsRecursive
    (
        string $key  ,
        mixed  $config ,
        array  $parentPath ,
        array  &$paths ,
        bool   $includeTypes ,
        bool   $includeRelations
    )
    : void
    {
        $fullPath = [ ...$parentPath , $key ] ;
        $pathStr  = implode('.' , $fullPath ) ;

        // Simple type (leaf node)
        if ( is_string( $config ) )
        {
            $pathInfo = [ AQL::PATH => $pathStr ] ;

            if ( $includeTypes )
            {
                $pathInfo[ AQL::TYPE ] = $config ;
                $pathInfo[ AQL::LEAF ] = true ;
            }

            $paths[] = $pathInfo ;
            return ;
        }

        // Complex configuration (branch node)
        if ( !is_array( $config ) )
        {
            return ;
        }

        $type = $config[ AQL::TYPE ] ?? null ;
        $nestedFilters = $config[ AQL::FILTERS ] ?? null ;

        if (!$type)
        {
            return ;
        }

        // Add array notation for appropriate types
        $needsArray = in_array( $type ,
        [
            Filter::ARRAY_EXPANSION,
            Filter::EDGES,
            Filter::JOINS
        ]) ;

        if ( $needsArray )
        {
            $pathStr .= '[*]' ;
        }

        // Document this level
        $pathInfo = [ AQL::PATH => $pathStr ] ;

        if ( $includeTypes )
        {
            $pathInfo[ AQL::TYPE ] = $type ;
            $pathInfo[ AQL::LEAF ] = empty( $nestedFilters ) ;
        }

        if ( $includeRelations && isset( $config[ AQL::RELATION ] ) )
        {
            $pathInfo[ AQL::RELATION ] = $config[ AQL::RELATION ] ;
        }

        $paths[] = $pathInfo ;

        // Recurse into nested filters
        if ( !empty( $nestedFilters ) && is_array( $nestedFilters ) )
        {
            foreach ( $nestedFilters as $nestedKey => $nestedConfig )
            {
                // For nested paths, we keep the clean key (without [*])
                $cleanPath = [ ...$parentPath , $key ] ;
                $this->buildFilterPathsRecursive
                (
                    $nestedKey,
                    $nestedConfig,
                    $cleanPath,
                    $paths,
                    $includeTypes,
                    $includeRelations
                );
            }
        }
    }
}