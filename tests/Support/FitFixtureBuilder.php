<?php
declare(strict_types=1);

/**
 * Sportlog (https://sportlog.at)
 *
 * @license MIT License
 */

namespace Sportlog\FIT\Test\Support;

/**
 * Builds minimal binary FIT files for use in decoder tests.
 *
 * Supports normal timestamp records, compressed-timestamp records
 * (Garmin smart recording), and FLOAT32 position records (non-spec devices).
 *
 * FIT protocol: https://developer.garmin.com/fit/protocol/
 */
class FitFixtureBuilder
{
    /** FIT base types. */
    private const BASE_ENUM    = 0x00;
    private const BASE_SINT32  = 0x85;
    private const BASE_UINT32  = 0x86;
    private const BASE_FLOAT32 = 0x88;

    /** FIT global message numbers. */
    private const MSG_FILE_ID = 0;
    private const MSG_RECORD  = 20;

    /** FIT field definition numbers for the RECORD message. */
    private const FIELD_TIMESTAMP    = 253;
    private const FIELD_POSITION_LAT = 0;
    private const FIELD_POSITION_LNG = 1;

    private array $records = [];
    private int $lastFitTimestamp = 0;

    /** Append a normal record with a full UINT32 timestamp. */
    public function withRecord(float $latDeg, float $lngDeg, int $fitTimestamp): static
    {
        $this->records[] = [
            'type'  => 'normal',
            'lat'   => $this->toSemicircles($latDeg),
            'lng'   => $this->toSemicircles($lngDeg),
            'fitTs' => $fitTimestamp,
        ];

        $this->lastFitTimestamp = $fitTimestamp;

        return $this;
    }

    /**
     * Append a compressed-timestamp record (Garmin smart recording format).
     * The timestamp is encoded in the record header; no timestamp field is written.
     *
     * @param int $secondsAfterPrevious Seconds after the preceding record (0–31).
     */
    public function withCompressedRecord(float $latDeg, float $lngDeg, int $secondsAfterPrevious): static
    {
        $fitTs      = $this->lastFitTimestamp + $secondsAfterPrevious;
        $timeOffset = $fitTs & 0x1F;

        $this->records[] = [
            'type'       => 'compressed',
            'lat'        => $this->toSemicircles($latDeg),
            'lng'        => $this->toSemicircles($lngDeg),
            'fitTs'      => $fitTs,
            'timeOffset' => $timeOffset,
        ];

        $this->lastFitTimestamp = $fitTs;

        return $this;
    }

    /**
     * Append a record where position fields use FLOAT32 degrees rather than SINT32 semicircles.
     * Some non-spec devices emit position this way.
     */
    public function withFloat32Record(float $latDeg, float $lngDeg, int $fitTimestamp): static
    {
        $this->records[] = [
            'type'   => 'float32',
            'latDeg' => $latDeg,
            'lngDeg' => $lngDeg,
            'fitTs'  => $fitTimestamp,
        ];

        $this->lastFitTimestamp = $fitTimestamp;

        return $this;
    }

    /** Write the FIT file to a temp path and return that path. */
    public function toTempFile(string $prefix = 'fit_test_'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix) . '.fit';
        file_put_contents($path, $this->toBytes());

        return $path;
    }

    /** Return the raw FIT binary. */
    public function toBytes(): string
    {
        $types = array_column($this->records, 'type');

        $data  = $this->fileIdDefinition();
        $data .= $this->fileIdData();
        $data .= $this->recordDefinition();

        if (in_array('float32', $types, true)) {
            $data .= $this->float32RecordDefinition();
        }

        foreach ($this->records as $record) {
            $data .= match ($record['type']) {
                'normal'     => $this->normalRecord($record['lat'], $record['lng'], $record['fitTs']),
                'compressed' => $this->compressedRecord($record['lat'], $record['lng'], $record['timeOffset']),
                'float32'    => $this->float32RecordData($record['latDeg'], $record['lngDeg'], $record['fitTs']),
            };
        }

        $header  = $this->header(strlen($data));
        $fileCrc = $this->crc16($data);

        return $header . $data . pack('v', $fileCrc);
    }

    // -------------------------------------------------------------------------
    // Binary builders
    // -------------------------------------------------------------------------

    private function header(int $dataSize): string
    {
        $bytes = pack('CCvV', 14, 0x10, 2114, $dataSize) . '.FIT';

        return $bytes . pack('v', $this->crc16($bytes));
    }

    private function fileIdDefinition(): string
    {
        return pack('C', 0x40) . pack('C', 0) . pack('C', 0) . pack('v', self::MSG_FILE_ID) . pack('C', 1)
            . pack('CCC', self::FIELD_POSITION_LAT, 1, self::BASE_ENUM);
    }

    private function fileIdData(): string
    {
        return pack('C', 0x00) . pack('C', 4);
    }

    /** RECORD definition: local type 1, fields timestamp(253) + lat(0) + lng(1) as SINT32. */
    private function recordDefinition(): string
    {
        return pack('C', 0x41) . pack('C', 0) . pack('C', 0) . pack('v', self::MSG_RECORD) . pack('C', 3)
            . pack('CCC', self::FIELD_TIMESTAMP,    4, self::BASE_UINT32)
            . pack('CCC', self::FIELD_POSITION_LAT, 4, self::BASE_SINT32)
            . pack('CCC', self::FIELD_POSITION_LNG, 4, self::BASE_SINT32);
    }

    /** RECORD definition: local type 2, position fields as FLOAT32. */
    private function float32RecordDefinition(): string
    {
        return pack('C', 0x42) . pack('C', 0) . pack('C', 0) . pack('v', self::MSG_RECORD) . pack('C', 3)
            . pack('CCC', self::FIELD_TIMESTAMP,    4, self::BASE_UINT32)
            . pack('CCC', self::FIELD_POSITION_LAT, 4, self::BASE_FLOAT32)
            . pack('CCC', self::FIELD_POSITION_LNG, 4, self::BASE_FLOAT32);
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
     * Bit-7=1, bits-5-6=01 (local type 1), bits-0-4=timeOffset.
     * Timestamp field is absent from the data stream.
     */
    private function compressedRecord(int $lat, int $lng, int $timeOffset): string
    {
        return pack('C', 0xA0 | ($timeOffset & 0x1F))
            . pack('V', $lat & 0xFFFFFFFF)
            . pack('V', $lng & 0xFFFFFFFF);
    }

    private function float32RecordData(float $latDeg, float $lngDeg, int $fitTs): string
    {
        return pack('C', 0x02)
            . pack('V', $fitTs)
            . pack('g', $latDeg)
            . pack('g', $lngDeg);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function toSemicircles(float $degrees): int
    {
        return (int) round($degrees * 2_147_483_648 / 180);
    }

    public static function toDegrees(int $semicircles): float
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
