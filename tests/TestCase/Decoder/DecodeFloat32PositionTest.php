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
 * Verifies that the decoder handles RECORD messages where position_lat and
 * position_long are encoded as FLOAT32 degrees rather than the spec-compliant
 * SINT32 semicircles. Some non-spec devices emit position this way.
 */
final class DecodeFloat32PositionTest extends TestCase
{
    public function testFloat32PositionFieldsReturnDegrees(): void
    {
        $path = $this->buildFitFile(51.501, -0.101);

        try {
            $decoder  = new Decoder();
            $messages = $decoder->read($path);

            /** @var RecordMessage[] $records */
            $records = $messages->getMessages(MesgNum::RECORD);

            $this->assertCount(1, $records);

            $lat = $records[0]->getPositionLat();
            $lng = $records[0]->getPositionLong();

            $this->assertIsFloat($lat);
            $this->assertIsFloat($lng);
            $this->assertEqualsWithDelta(51.501, $lat, 0.0001);
            $this->assertEqualsWithDelta(-0.101, $lng, 0.0001);
        } finally {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // Fixture builder
    // -------------------------------------------------------------------------

    /**
     * Builds a minimal FIT binary with one RECORD where position_lat and
     * position_long use base type FLOAT32 (0x88) rather than SINT32 (0x85).
     */
    private function buildFitFile(float $latDeg, float $lngDeg): string
    {
        $fitTs = 1074247200; // arbitrary valid FIT timestamp

        $data  = $this->fileIdDefinition();
        $data .= $this->fileIdData();
        $data .= $this->float32RecordDefinition();
        $data .= $this->float32RecordData($latDeg, $lngDeg, $fitTs);

        $header  = $this->header(strlen($data));
        $fileCrc = $this->crc16($data);

        $bytes = $header . $data . pack('v', $fileCrc);

        $path = tempnam(sys_get_temp_dir(), 'fit_float32_pos_') . '.fit';
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

    /**
     * RECORD definition using FLOAT32 (0x88) for position fields.
     * Local message type 1, global message 20.
     */
    private function float32RecordDefinition(): string
    {
        return pack('C', 0x41) . pack('C', 0) . pack('C', 0) . pack('v', 20) . pack('C', 3)
            . pack('CCC', 253, 4, 0x86)   // timestamp: UINT32
            . pack('CCC', 0,   4, 0x88)   // position_lat: FLOAT32
            . pack('CCC', 1,   4, 0x88);  // position_long: FLOAT32
    }

    private function float32RecordData(float $latDeg, float $lngDeg, int $fitTs): string
    {
        return pack('C', 0x01)
            . pack('V', $fitTs)
            . pack('g', $latDeg)   // FLOAT32 LE degrees
            . pack('g', $lngDeg);  // FLOAT32 LE degrees
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
