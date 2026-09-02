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

/**
 * Verifies that the decoder correctly handles compressed-timestamp record headers
 * (FIT protocol section 4.5). Garmin smart recording uses this format to encode
 * the lower 5 bits of the timestamp in the record header rather than in a full
 * UINT32 field, saving 4 bytes per record.
 */
final class DecodeCompressedTimestampTest extends TestCase
{
    private const FIT_EPOCH = 631065600;

    public function testCompressedTimestampRecordsAreDecoded(): void
    {
        // FIT timestamp 1074247200 is divisible by 32, making time_offset arithmetic
        // straightforward: offsets of 5 and 10 land cleanly in the same 32-s window.
        $baseFitTs  = 1074247200;
        $baseUnixTs = $baseFitTs + self::FIT_EPOCH;

        $path = $this->buildFitFile($baseFitTs);

        try {
            $decoder  = new Decoder();
            $messages = $decoder->read($path);

            /** @var RecordMessage[] $records */
            $records = $messages->getMessages(MesgNum::RECORD);

            $this->assertCount(3, $records);

            $this->assertEquals(
                (new \DateTime())->setTimestamp($baseUnixTs)->format('U'),
                $records[0]->getTimestamp()->format('U')
            );
            $this->assertEquals(
                (new \DateTime())->setTimestamp($baseUnixTs + 5)->format('U'),
                $records[1]->getTimestamp()->format('U')
            );
            $this->assertEquals(
                (new \DateTime())->setTimestamp($baseUnixTs + 15)->format('U'),
                $records[2]->getTimestamp()->format('U')
            );
        } finally {
            @unlink($path);
        }
    }

    public function testCompressedTimestampPositionFieldsArePreserved(): void
    {
        $baseFitTs = 1074247200;
        $path      = $this->buildFitFile($baseFitTs);

        try {
            $decoder  = new Decoder();
            $messages = $decoder->read($path);

            /** @var RecordMessage[] $records */
            $records = $messages->getMessages(MesgNum::RECORD);

            // Compressed records at indices 1 and 2 should carry the lat/lng baked
            // into the fixture, not zeroes or values leaked from the reference record.
            $this->assertEqualsWithDelta(51.501, $this->semicirclesToDegrees($records[1]->getPositionLat()), 0.0001);
            $this->assertEqualsWithDelta(-0.101, $this->semicirclesToDegrees($records[1]->getPositionLong()), 0.0001);
            $this->assertEqualsWithDelta(51.502, $this->semicirclesToDegrees($records[2]->getPositionLat()), 0.0001);
            $this->assertEqualsWithDelta(-0.102, $this->semicirclesToDegrees($records[2]->getPositionLong()), 0.0001);
        } finally {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // Fixture builder
    // -------------------------------------------------------------------------

    /**
     * Builds a minimal FIT binary containing:
     *   - one normal RECORD at $baseFitTs          (lat 51.500, lng -0.100)
     *   - one compressed-timestamp RECORD at +5 s  (lat 51.501, lng -0.101)
     *   - one compressed-timestamp RECORD at +10 s (lat 51.502, lng -0.102)
     */
    private function buildFitFile(int $baseFitTs): string
    {
        $data  = $this->fileIdDefinition();
        $data .= $this->fileIdData();
        $data .= $this->recordDefinition();
        $data .= $this->normalRecord($this->degreesToSemicircles(51.500), $this->degreesToSemicircles(-0.100), $baseFitTs);
        $data .= $this->compressedRecord($this->degreesToSemicircles(51.501), $this->degreesToSemicircles(-0.101), ($baseFitTs + 5) & 0x1F);
        $data .= $this->compressedRecord($this->degreesToSemicircles(51.502), $this->degreesToSemicircles(-0.102), ($baseFitTs + 15) & 0x1F);

        $header  = $this->header(strlen($data));
        $fileCrc = $this->crc16($data);

        $bytes = $header . $data . pack('v', $fileCrc);

        $path = tempnam(sys_get_temp_dir(), 'fit_compressed_ts_') . '.fit';
        file_put_contents($path, $bytes);

        return $path;
    }

    private function header(int $dataSize): string
    {
        $bytes = pack('CCvV', 14, 0x10, 2114, $dataSize) . '.FIT';

        return $bytes . pack('v', $this->crc16($bytes));
    }

    private function fileIdDefinition(): string
    {
        return pack('C', 0x40) . pack('C', 0) . pack('C', 0) . pack('v', 0) . pack('C', 1)
            . pack('CCC', 0, 1, 0x00);
    }

    private function fileIdData(): string
    {
        return pack('C', 0x00) . pack('C', 4);
    }

    /** RECORD definition: local type 1, fields: timestamp(253), lat(0), lng(1). */
    private function recordDefinition(): string
    {
        return pack('C', 0x41) . pack('C', 0) . pack('C', 0) . pack('v', 20) . pack('C', 3)
            . pack('CCC', 253, 4, 0x86)
            . pack('CCC', 0,   4, 0x85)
            . pack('CCC', 1,   4, 0x85);
    }

    private function normalRecord(int $lat, int $lng, int $fitTs): string
    {
        return pack('C', 0x01)
            . pack('V', $fitTs)
            . pack('V', $lat & 0xFFFFFFFF)
            . pack('V', $lng & 0xFFFFFFFF);
    }

    /**
     * Compressed-timestamp record for local message type 1.
     * Header: bit-7=1, bits-5-6=01 (local type 1), bits-0-4=timeOffset.
     * The timestamp field is absent from the data stream.
     */
    private function compressedRecord(int $lat, int $lng, int $timeOffset): string
    {
        return pack('C', 0xA0 | ($timeOffset & 0x1F))
            . pack('V', $lat & 0xFFFFFFFF)
            . pack('V', $lng & 0xFFFFFFFF);
    }

    private function degreesToSemicircles(float $degrees): int
    {
        return (int) round($degrees * 2_147_483_648 / 180);
    }

    private function semicirclesToDegrees(int $semicircles): float
    {
        return $semicircles * 180 / 2_147_483_648;
    }

    private function crc16(string $data): int
    {
        static $table = [
            0x0000, 0xCC01, 0xD801, 0x1400, 0xF001, 0x3C00, 0x2800, 0xE401,
            0xA001, 0x6C00, 0x7800, 0xB401, 0x5000, 0x9C01, 0x8801, 0x4400,
        ];

        $crc = 0;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($data[$i]);
            $tmp  = $table[$crc & 0xF];
            $crc  = ($crc >> 4) & 0x0FFF;
            $crc ^= $tmp ^ $table[$byte & 0xF];
            $tmp  = $table[$crc & 0xF];
            $crc  = ($crc >> 4) & 0x0FFF;
            $crc ^= $tmp ^ $table[($byte >> 4) & 0xF];
        }

        return $crc;
    }
}
