<?php

namespace oihana\arango\commands\actions;

/**
 * The ArangoDB CLI subcommands database.
 */
trait ArangoActions
{
    use ArangoDumpAction ,
        ArangoListCollectionsAction ,
        ArangoListDumpsAction ,
        ArangoRestoreAction ;
}