<?php
namespace tests\oihana\arango\migrations\fixtures\boom ;
use oihana\arango\migrations\Migration ;
class Version20260101000000_Gamma extends Migration
{
    public static array $ran = [] ;
    public function up() : void { self::$ran[] = 'gamma.up' ; }
}
