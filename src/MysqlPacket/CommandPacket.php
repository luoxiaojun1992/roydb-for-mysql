<?php

namespace SMProxy\MysqlPacket;

class CommandPacket extends MySQLPacket
{
    public $command;
    public $arg;

    /**
     * @inheritDoc
     */
    public function calcPacketSize()
    {
        return 1 + count($this->arg);
    }

    public function read(BinaryPacket $bin)
    {
        $mm = new MySQLMessage($bin->data);
        $this->packetLength = $mm->readUB3();
        $this->packetId = $mm->read();
        $this->command = $mm->read();
        $this->arg = $mm->readBytes();
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL Command Packet';
    }
}
