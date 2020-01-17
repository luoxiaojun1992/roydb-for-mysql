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

    public function write()
    {
        $data = [];
        $size = $this->calcPacketSize();
        BufferUtil::writeUB3($data, $size);
        $data[] = $this->packetId;
        BufferUtil::writeLength($data, $this->fieldCount);
        if ($this->extra > 0) {
            BufferUtil::writeLength($data, $this->extra);
        }
        return $data;
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL ResultSetHeader Packet';
    }
}
