<?php

namespace SMProxy\MysqlPacket;

class EOFPacket extends MySQLPacket
{
    /**
     * @inheritDoc
     */
    public function calcPacketSize()
    {
        return 5;// 1+2+2;
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL EOF Packet';
    }
}
