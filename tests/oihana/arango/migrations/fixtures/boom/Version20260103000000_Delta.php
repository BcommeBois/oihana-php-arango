<?php
namespace tests\oihana\arango\migrations\fixtures\boom ;
use oihana\arango\migrations\Migration ;
class Version20260103000000_Delta extends Migration
{
    public static array $ran = [] ;
    public function up() : void { self::$ran[] = 'delta.up' ; }
}
