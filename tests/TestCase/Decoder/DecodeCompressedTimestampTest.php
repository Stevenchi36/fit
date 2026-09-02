<?php
declare(strict_types=1);

/**
 * Sportlog (https://sportlog.at)
 *
 * @license MIT License
 */

namespace Sportlog\FIT\Test\TestCase\Decoder;

use PHPUnit\Framework\TestCase;
use Sportlog\FIT\Decoder;
use Sportlog\FIT\Profile\Messages\RecordMessage;
use Sportlog\FIT\Profile\Types\MesgNum;
use Sportlog\FIT\Test\Support\FitFixtureBuilder;

/**
 * Verifies that the decoder correctly handles compressed-timestamp record headers
 * (FIT protocol section 4.5). Garmin smart recording uses this format to encode
 * the lower 5 bits of the timestamp in the record header rather than in a full
 * UINT32 field, saving 4 bytes per record.
 */
final class DecodeCompressedTimestampTest extends TestCase
{
    private const FIT_EPOCH = 631065600;

    // FIT timestamp 1074247200 is divisible by 32, making time_offset arithmetic
    // straightforward: offsets of 5 and 10 land cleanly in the same 32-s window.
    private const BASE_FIT_TS = 1074247200;

    public function testCompressedTimestampRecordsAreDecoded(): void
    {
        $baseUnixTs = self::BASE_FIT_TS + self::FIT_EPOCH;

        $path = (new FitFixtureBuilder)
            ->withRecord(51.500, -0.100, self::BASE_FIT_TS)
            ->withCompressedRecord(51.501, -0.101, 5)
            ->withCompressedRecord(51.502, -0.102, 10)
            ->toTempFile('fit_compressed_ts_');

        try {
            $records = $this->decodeRecords($path);

            $this->assertCount(3, $records);
            $this->assertEquals($baseUnixTs,      $records[0]->getTimestamp()->getTimestamp());
            $this->assertEquals($baseUnixTs + 5,  $records[1]->getTimestamp()->getTimestamp());
            $this->assertEquals($baseUnixTs + 15, $records[2]->getTimestamp()->getTimestamp());
        } finally {
            @unlink($path);
        }
    }

    public function testCompressedTimestampPositionFieldsArePreserved(): void
    {
        $path = (new FitFixtureBuilder)
            ->withRecord(51.500, -0.100, self::BASE_FIT_TS)
            ->withCompressedRecord(51.501, -0.101, 5)
            ->withCompressedRecord(51.502, -0.102, 10)
            ->toTempFile('fit_compressed_ts_');

        try {
            $records = $this->decodeRecords($path);

            $this->assertEqualsWithDelta(51.501, FitFixtureBuilder::toDegrees($records[1]->getPositionLat()), 0.0001);
            $this->assertEqualsWithDelta(-0.101, FitFixtureBuilder::toDegrees($records[1]->getPositionLong()), 0.0001);
            $this->assertEqualsWithDelta(51.502, FitFixtureBuilder::toDegrees($records[2]->getPositionLat()), 0.0001);
            $this->assertEqualsWithDelta(-0.102, FitFixtureBuilder::toDegrees($records[2]->getPositionLong()), 0.0001);
        } finally {
            @unlink($path);
        }
    }

    /** @return RecordMessage[] */
    private function decodeRecords(string $path): array
    {
        return (new Decoder)->read($path)->getMessages(MesgNum::RECORD);
    }
}
