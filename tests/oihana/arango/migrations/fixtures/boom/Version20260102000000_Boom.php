<?php
namespace tests\oihana\arango\migrations\fixtures\boom ;
use oihana\arango\migrations\Migration ;
class Version20260102000000_Boom extends Migration
{
    public static array $ran = [] ;
    public function up() : void { self::$ran[] = 'boom.up' ; throw new \RuntimeException( 'kaboom' ) ; }
}
