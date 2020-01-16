<?php

namespace SMProxy\MysqlPacket;

use SMProxy\MysqlPacket\Util\BufferUtil;

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
            $size += (empty($fieldValue) ? 1 : BufferUtil::getLength($fieldValue));
        }
        return $size;
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL RowData Packet';
    }
}
