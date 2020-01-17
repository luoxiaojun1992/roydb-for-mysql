<?php

namespace SMProxy\MysqlPacket;

use SMProxy\MysqlPacket\Util\BufferUtil;

class FieldPacket extends MySQLPacket
{
    const DEFAULT_CATALOG = "def";
	const FILLER = [0, 0];

	public $catalog = self::DEFAULT_CATALOG;
	public $db;
	public $table;
	public $orgTable;
	public $name;
	public $orgName;
	public $charsetIndex;
	public $length;
	public $type;
	public $flags;
	public $decimals;
	public $definition;

    /**
     * @inheritDoc
     */
    public function calcPacketSize()
    {
        $size = (is_null($this->catalog) ? 1 : BufferUtil::getLength($this->catalog));
		$size += (is_null($this->db) ? 1 : BufferUtil::getLength($this->db));
		$size += (is_null($this->table) ? 1 : BufferUtil::getLength($this->table));
		$size += (is_null($this->orgTable) ? 1 : BufferUtil::getLength($this->orgTable));
		$size += (is_null($this->name) ? 1 : BufferUtil::getLength($this->name));
		$size += (is_null($this->orgName) ? 1 : BufferUtil::getLength($this->orgName));
		$size += 13;// 1+2+4+1+2+1+2
		if (!is_null($this->definition)) {
            $size += BufferUtil::getLength($this->definition);
        }
		return $size;
    }

    public function write()
    {
        $data = [];
        $size = $this->calcPacketSize();
		BufferUtil::writeUB3($data, $size);
		$data[] = $this->packetId;
		$this->writeBody($data);
		return $data;
    }

    private function writeBody(&$data)
    {
        $nullVal = 0;
        BufferUtil::writeWithLength($data, $this->catalog, $nullVal);
        BufferUtil::writeWithLength($data, $this->db, $nullVal);
        BufferUtil::writeWithLength($data, $this->table, $nullVal);
        BufferUtil::writeWithLength($data, $this->orgTable, $nullVal);
        BufferUtil::writeWithLength($data, $this->name, $nullVal);
        BufferUtil::writeWithLength($data, $this->orgName, $nullVal);
        $data[] = 0x0C;
		BufferUtil::writeUB2($data, $this->charsetIndex);
		BufferUtil::writeUB4($data, $this->length);
		$data[] = $this->type & 0xff;
		BufferUtil::writeUB2($data, $this->flags);
		$data[] = $this->decimals;
        $data[] = 0x00;
        $data[] = 0x00;
		if (!is_null($this->definition)) {
            BufferUtil::writeWithLength($data, $this->definition);
        }
    }

    /**
     * @inheritDoc
     */
    protected function getPacketInfo()
    {
        return 'MySQL Field Packet';
    }
}
