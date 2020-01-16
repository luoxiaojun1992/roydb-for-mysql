<?php

namespace SMProxy\MysqlPacket;

class ResultSetHeaderPacket extends MySQLPacket
{
    /**
     * @inheritDoc
     */
    public function calcPacketSize()
    {
        // TODO: Implement calcPacketSize() method.
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL Result Set Packet';
    }
}
