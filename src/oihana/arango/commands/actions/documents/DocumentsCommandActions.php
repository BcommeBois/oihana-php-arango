<?php

namespace oihana\arango\commands\actions\documents;

trait DocumentsCommandActions
{
    use DocumentsCommandCount    ,
        DocumentsCommandDelete   ,
        DocumentsCommandExist    ,
        DocumentsCommandGet      ,
        DocumentsCommandInsert   ,
        DocumentsCommandLast     ,
        DocumentsCommandList     ,
        DocumentsCommandReplace  ,
        DocumentsCommandTruncate ,
        DocumentsCommandUpdate   ,
        DocumentsCommandUpsert   ;
}