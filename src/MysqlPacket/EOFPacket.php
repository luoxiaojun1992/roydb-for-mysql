<?php

namespace SMProxy\MysqlPacket;

use SMProxy\MysqlPacket\Util\BufferUtil;

class EOFPacket extends MySQLPacket
{
    const FIELD_COUNT = 0xfe;

    public $fieldCount = self::FIELD_COUNT;
    public $warningCount;
    public $status = 2;

    /**
     * @inheritDoc
     */
    public function calcPacketSize()
    {
        return 5;// 1+2+2;
    }

    public function write()
    {
        $data = [];
        $size = $this->calcPacketSize();
        BufferUtil::writeUB3($data, $size);
        $data[] = $this->packetId;
        $data[] = $this->fieldCount;
        BufferUtil::writeUB2($data, $this->warningCount);
        BufferUtil::writeUB2($data, $this->status);
        return $data;
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL EOF Packet';
    }
}
