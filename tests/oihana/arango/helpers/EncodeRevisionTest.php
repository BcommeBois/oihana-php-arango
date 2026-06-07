<?php

namespace tests\oihana\arango\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\helpers\decodeRevision;
use function oihana\arango\helpers\encodeRevision;

final class EncodeRevisionTest extends TestCase
{
    public function testEncodeRevision():void
    {
        $tests =
        [
            [
                "_rev"  => "_jGPSg12---",
                "date"  => "2025-01-20T15:28:40.830Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kSxG8ii---",
                "date"  => "2025-09-15T09:51:03.753Z",
                "count" => 0
            ],
            [
                "_rev"  => "_jGPSg12---",
                "date"  => "2025-01-20T15:28:40.830Z",
                "count" => 0
            ],
            [
                "_rev"  => "_jLYcfSK---",
                "date"  => "2025-02-05T14:58:20.611Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kS3s2Ke---",
                "date"  => "2025-09-15T17:31:53.416Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kZ1uVZi---",
                "date"  => "2025-10-07T09:11:10.521Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kczrpSy---",
                "date"  => "2025-10-16T14:30:12.045Z",
                "count" => 0
            ],
            [
                "_rev"  => "_keF82f6---",
                "date"  => "2025-10-20T14:21:12.607Z",
                "count" => 0
            ],
        ];

        foreach ( $tests as $test )
        {
            $encoded = encodeRevision($test['date'], $test['count']);
            $this->assertSame($test['_rev'], $encoded, "encodeRevision failed for date {$test['date']}");

            $decoded = decodeRevision($encoded);
            $this->assertNotNull($decoded, "decodeRevision returned null for $encoded");
            $this->assertSame($test['count'], $decoded['count'], "Count mismatch for $encoded");
            $this->assertSame($test['date'], $decoded['date'], "Date mismatch for $encoded");
        }
    }

    public function testEncodeRevisionAutomaticCount(): void
    {
        $date = "2025-10-25T12:00:00.000Z";

        $rev1 = encodeRevision($date);
        $rev2 = encodeRevision($date);
        $rev3 = encodeRevision($date);

        $this->assertNotSame($rev1, $rev2, 'Automatic count did not increment');
        $this->assertNotSame($rev2, $rev3, 'Automatic count did not increment');

        $decoded1 = decodeRevision($rev1);
        $decoded2 = decodeRevision($rev2);
        $decoded3 = decodeRevision($rev3);

        $this->assertSame($date, $decoded1['date']);
        $this->assertSame($date, $decoded2['date']);
        $this->assertSame($date, $decoded3['date']);

        $this->assertSame($decoded1['count'] + 1, $decoded2['count']);
        $this->assertSame($decoded2['count'] + 1, $decoded3['count']);
    }

    public function testThrowsInvalidArgumentOnUnparseableDateWhenThrowable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('failed to parse date');
        encodeRevision('not-a-date', null, true);
    }

    public function testReturnsEmptyStringOnUnparseableDateWhenNotThrowable(): void
    {
        $this->assertSame('', encodeRevision('not-a-date'));
    }

    public function testThrowsInvalidArgumentWhenCountNegativeAndThrowable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('count must be between');
        encodeRevision('2025-10-25T12:00:00.000Z', -1, true);
    }

    public function testReturnsEmptyStringWhenCountOutOfRangeAndNotThrowable(): void
    {
        $this->assertSame('', encodeRevision('2025-10-25T12:00:00.000Z', 1048576));
    }
}