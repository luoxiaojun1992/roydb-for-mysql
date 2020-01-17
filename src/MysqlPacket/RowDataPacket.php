<?php

namespace SMProxy\MysqlPacket;

use SMProxy\MysqlPacket\Util\BufferUtil;
use function SMProxy\Helper\getBytes;

class RowDataPacket extends MySQLPacket
{
    const NULL_MARK = 251;
    const EMPTY_MARK = 0;

    public $value;
	public $fieldCount;
	public $fieldValues;

    /**
     * @inheritDoc
     */
    public function calcPacketSize()
    {
        $size = 0;
        for ($i = 0; $i < $this->fieldCount; ++$i) {
            $fieldValue = $this->fieldValues[$i] ?? null;
            $size += (empty($fieldValue) ? 1 : BufferUtil::getLength(getBytes($fieldValue)));
        }
        return $size;
    }

    public function write()
    {
        $data = [];
        //todo bugfix
        BufferUtil::writeUB3($data, $this->calcPacketSize());
//        $data[] = 0x1d;
//        $data[] = 0x00;
//        $data[] = 0x00;
        $data[] = $this->packetId;
        for ($i = 0; $i < $this->fieldCount; ++$i) {
            $fv = $this->fieldValues[$i] ?? null;
			if (is_null($fv)) {
                $data[] = self::NULL_MARK;
            } elseif (strlen($fv) == 0) {
                $data[] = self::EMPTY_MARK;
            } else {
                BufferUtil::writeLength($data, strlen($fv));
                $data = array_merge($data, getBytes($fv));
            }
		}
		return $data;
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL RowData Packet';
    }
}
