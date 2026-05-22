<?php

namespace Czdb\Entity;

class DataBlock {
    private $region;
    private $dataPtr;

    public function __construct($region, $dataPtr) {
        $this->region = $region;
        $this->dataPtr = $dataPtr;
    }

    public function getRegion($geoMapData, $columnSelection) {
        return $this->unpack($geoMapData, $columnSelection);
    }

    public function setRegion($region) {
        $this->region = $region;
        return $this;
    }

    public function getDataPtr() {
        return $this->dataPtr;
    }

    public function setDataPtr($dataPtr) {
        $this->dataPtr = $dataPtr;
        return $this;
    }

    private function unpackInt(&$buffer, &$offset) {
        if (!isset($buffer[$offset])) {
            throw new \RuntimeException('Unexpected end of messagepack int');
        }

        $b = ord($buffer[$offset++]);
        if ($b <= 0x7f) {
            return $b;
        }
        if ($b >= 0xe0) {
            return $b - 0x100;
        }

        switch ($b) {
            case 0xcc:
                return $this->unpackUint8($buffer, $offset);
            case 0xcd:
                return $this->unpackUint16($buffer, $offset);
            case 0xce:
                return $this->unpackUint32($buffer, $offset);
            case 0xd0:
                return $this->unpackInt8($buffer, $offset);
            case 0xd1:
                return $this->unpackInt16($buffer, $offset);
            case 0xd2:
                return $this->unpackInt32($buffer, $offset);
            case 0xcf:
                return $this->unpackUint64($buffer, $offset);
            case 0xd3:
                return $this->unpackInt64($buffer, $offset);
            default:
                throw new \RuntimeException('Unsupported int format in messagepack: 0x' . dechex($b));
        }
    }

    private function unpackStr(&$buffer, &$offset) {
        if (!isset($buffer[$offset])) {
            throw new \RuntimeException('Unexpected end of messagepack string');
        }

        $b = ord($buffer[$offset]);
        $len = 0;
        if (($b & 0xe0) === 0xa0) {
            $len = $b & 0x1f;
            $offset++;
        } elseif ($b === 0xd9) {
            $len = ord($buffer[$offset + 1]);
            $offset += 2;
        } elseif ($b === 0xda) {
            $len = unpack('n', substr($buffer, $offset + 1, 2))[1];
            $offset += 3;
        } elseif ($b === 0xdb) {
            $len = unpack('N', substr($buffer, $offset + 1, 4))[1];
            $offset += 5;
        } else {
            throw new \RuntimeException('Not a string in messagepack: 0x' . dechex($b));
        }

        $str = substr($buffer, $offset, $len);
        $offset += $len;
        return $str;
    }

    private function unpackArrayHeader(&$buffer, &$offset) {
        if (!isset($buffer[$offset])) {
            throw new \RuntimeException('Unexpected end of messagepack array');
        }

        $b = ord($buffer[$offset]);
        if (($b & 0xf0) === 0x90) {
            $offset++;
            return $b & 0x0f;
        }
        if ($b === 0xdc) {
            $len = unpack('n', substr($buffer, $offset + 1, 2))[1];
            $offset += 3;
            return $len;
        }
        if ($b === 0xdd) {
            $len = unpack('N', substr($buffer, $offset + 1, 4))[1];
            $offset += 5;
            return $len;
        }
        throw new \RuntimeException('Not an array in messagepack: 0x' . dechex($b));
    }

    private function unpack($geoMapData, $columnSelection) {
        $offset = 0;
        $geoPosMixSize = $this->unpackInt($this->region, $offset);
        $otherData = $this->unpackStr($this->region, $offset);

        if ($geoPosMixSize == 0) {
            return $otherData;
        }

        $dataLen = ($geoPosMixSize >> 24) & 0xFF;
        $dataPtr = $geoPosMixSize & 0x00FFFFFF;

        $regionData = substr($geoMapData, $dataPtr, $dataLen);
        $sb = "";

        $regionOffset = 0;
        $columnNumber = $this->unpackArrayHeader($regionData, $regionOffset);

        for ($i = 0; $i < $columnNumber; $i++) {
            $columnSelected = ($columnSelection >> ($i + 1) & 1) == 1;
            $value = $this->unpackStr($regionData, $regionOffset);
            $value = ($value === "") ? "null" : $value;

            if ($columnSelected) {
                $sb .= $value . "\t";
            }
        }

        return $sb . $otherData;
    }

    private function unpackUint8(string $buffer, int &$offset): int {
        if (!isset($buffer[$offset])) {
            throw new \RuntimeException('Unexpected end of messagepack uint8');
        }

        return ord($buffer[$offset++]);
    }

    private function unpackUint16(string $buffer, int &$offset): int {
        if (!isset($buffer[$offset + 1])) {
            throw new \RuntimeException('Unexpected end of messagepack uint16');
        }

        return ord($buffer[$offset++]) << 8
            | ord($buffer[$offset++]);
    }

    private function unpackUint32(string $buffer, int &$offset): int {
        if (!isset($buffer[$offset + 3])) {
            throw new \RuntimeException('Unexpected end of messagepack uint32');
        }

        return ord($buffer[$offset++]) << 24
            | ord($buffer[$offset++]) << 16
            | ord($buffer[$offset++]) << 8
            | ord($buffer[$offset++]);
    }

    private function unpackUint64(string $buffer, int &$offset): int {
        if (!isset($buffer[$offset + 7])) {
            throw new \RuntimeException('Unexpected end of messagepack uint64');
        }

        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('64-bit integers are not supported on this platform');
        }

        $high = $this->unpackUint32($buffer, $offset);
        $low = $this->unpackUint32($buffer, $offset);

        return ($high << 32) | $low;
    }

    private function unpackInt8(string $buffer, int &$offset): int {
        $value = $this->unpackUint8($buffer, $offset);
        return $value > 0x7f ? $value - 0x100 : $value;
    }

    private function unpackInt16(string $buffer, int &$offset): int {
        $value = $this->unpackUint16($buffer, $offset);
        return $value > 0x7fff ? $value - 0x10000 : $value;
    }

    private function unpackInt32(string $buffer, int &$offset): int {
        $value = $this->unpackUint32($buffer, $offset);
        return $value > 0x7fffffff ? $value - 0x100000000 : $value;
    }

    private function unpackInt64(string $buffer, int &$offset): int {
        $value = $this->unpackUint64($buffer, $offset);
        return $value;
    }
}
