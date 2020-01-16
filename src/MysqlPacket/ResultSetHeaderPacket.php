<?php

namespace SMProxy\MysqlPacket;

use SMProxy\MysqlPacket\Util\BufferUtil;

class ResultSetHeaderPacket extends MySQLPacket
{
    public $fieldCount;
    public $extra;

    /**
     * @inheritDoc
     */
    public function calcPacketSize()
    {
        $size = BufferUtil::getLength($this->fieldCount);
        if ($this->extra > 0) {
            $size += BufferUtil::getLength($this->extra);
        }
        return $size;
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL ResultSetHeader Packet';
    }
}
