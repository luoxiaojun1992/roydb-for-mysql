<?php

namespace SMProxy;

use Roydb\SelectRequest;
use SMProxy\Handler\Frontend\FrontendAuthenticator;
use SMProxy\MysqlPacket\CommandPacket;
use SMProxy\MysqlPacket\EOFPacket;
use SMProxy\MysqlPacket\FieldPacket;
use SMProxy\MysqlPacket\ResultSetHeaderPacket;
use SMProxy\MysqlPacket\RowDataPacket;
use SMProxy\Roydb\QueryClient;
use function SMProxy\Helper\array_copy;
use function SMProxy\Helper\getBytes;
use function SMProxy\Helper\getMysqlPackSize;
use function SMProxy\Helper\getString;
use SMProxy\MysqlPacket\AuthPacket;
use SMProxy\MysqlPacket\BinaryPacket;
use SMProxy\MysqlPacket\MySqlPacketDecoder;
use SMProxy\MysqlPacket\MySQLPacket;
use SMProxy\MysqlPacket\OkPacket;
use SMProxy\MysqlPacket\Util\ErrorCode;
use SMProxy\MysqlPacket\Util\RandomUtil;
use SMProxy\MysqlPool\MySQLException;

/**
 * Author: Louis Livi <574747417@qq.com>
 * Date: 2018/10/26
 * Time: 下午6:32.
 */
class SMProxyServer extends BaseServer
{
    public $source;
    private $mysqlServer;
    protected $dbConfig;

    /**
     * SMProxyServer constructor.
     */
    public function __construct()
    {
        $size = array_sum(array_map(function ($v) {
                return eval('return ' . $v . ';');
        }, array_column(CONFIG['database']['databases'], 'maxConns'))) * 100;
        $this->mysqlServer = new \Swoole\Table($size, 1);
        $this->mysqlServer->column('threadId', \Swoole\Table::TYPE_INT, 8);
        $this->mysqlServer->column('serverVersion', \Swoole\Table::TYPE_STRING, 128);
        $this->mysqlServer->column('pluginName', \Swoole\Table::TYPE_STRING, 128);
        $this->mysqlServer->column('serverStatus', \Swoole\Table::TYPE_INT, 8);
        $this->mysqlServer->create();
        parent::__construct();
    }

    /**
     * 连接.
     *
     * @param $server
     * @param $fd
     */
    public function onConnect(\swoole_server $server, int $fd)
    {
        // 生成认证数据
        $Authenticator = new FrontendAuthenticator();
        $this->source[$fd] = $Authenticator;
        if ($server->exist($fd)) {
            $server->send($fd, $Authenticator->getHandshakePacket($fd));
        }
    }

    /**
     * 接收消息.
     *
     * @param \swoole_server $server
     * @param int $fd
     * @param int $reactor_id
     * @param string $data
     * @throws MySQLException
     * @throws SMProxyException
     */
    public function onReceive(\swoole_server $server, int $fd, int $reactor_id, string $data)
    {
        $bin = (new MySqlPacketDecoder())->decode($data);
        if (!$this->source[$fd]->auth) {
            $this->auth($bin, $server, $fd);
        } else {
            $command = new CommandPacket();
            $command->read($bin);
            if ($command->command === MySQLPacket::$COM_QUERY) {
                if (getString($command->arg) === 'select @@version_comment limit 1') {
                    $resultSetHeader = new ResultSetHeaderPacket();
                    $resultSetHeader->packetId = 1;
                    $resultSetHeader->fieldCount = 1;

                    $field = new FieldPacket();
                    $field->packetId = 2;
                    $field->db = getBytes('');
                    $field->table = getBytes('');
                    $field->orgTable = getBytes('');
                    $field->catalog = getBytes(FieldPacket::DEFAULT_CATALOG);
                    $field->name = getBytes('@@version_comment');
                    $field->orgName = getBytes('');
                    $field->charsetIndex = 0x21;
                    $field->length = 0x54;
                    $field->type = 0xFD;
                    $field->flags = 0x0000;
                    $field->decimals = 31;

                    $eof1 = new EOFPacket();
                    $eof1->packetId = 3;
                    $eof1->warningCount = 0;
                    $eof1->status = 0x0002;

                    $rowData = new RowDataPacket();
                    $rowData->packetId = 4;
                    $rowData->fieldCount = 1;
                    $rowData->fieldValues[] = 'MySQL Community Server (GPL)';

                    $eof2 = new EOFPacket();
                    $eof2->packetId = 5;
                    $eof2->warningCount = 0;
                    $eof2->status = 0x0002;
                    if ($server->exist($fd)) {
                        $server->send($fd, getString(
                            array_merge(
                                $resultSetHeader->write(),
                                $field->write(),
                                $eof1->write(),
                                $rowData->write(),
                                $eof2->write()
                            )
                        ));
                    }
                } else {
                    $sql = getString($command->arg);

                    try {
                        $selectResponse = (new QueryClient())->Select(
                            (new SelectRequest())->setSql($sql)
                        );
                        if (!$selectResponse) {
                            throw new \RuntimeException('SMProxy@empty response from roydb');
                        }
                    } catch (\Throwable $e) {
                        $message = 'SMProxy@unknown error from roydb';
                        $errMessage = self::writeErrMessage(
                            1,
                            $message,
                            ErrorCode::ER_UNKNOWN_ERROR
                        );
                        if ($server->exist($fd)) {
                            $server->send($fd, getString($errMessage));
                        }
                        throw new MySQLException($e->getMessage(), $e->getCode(), $e);
                    }

                    $resultSet = [];

                    $rows = $selectResponse->getRowData();
                    foreach ($rows as $row) {
                        $rowData = [];
                        foreach ($row->getField() as $field) {
                            $key = $field->getKey();
                            $valueType = $field->getValueType();
                            if ($valueType === 'integer') {
                                $rowData[$key] = $field->getIntValue();
                            } elseif ($valueType === 'double') {
                                $rowData[$key] = $field->getDoubleValue();
                            } elseif ($valueType === 'string') {
                                $rowData[$key] = $field->getStrValue();
                            }
                        }
                        $resultSet[] = $rowData;
                    }

                    if (count($resultSet) <= 0) {
                        if ($server->exist($fd)) {
                            $server->send($fd, getString(OkPacket::$OK));
                            return;
                        }
                    }

                    $buffer = [];

                    $packetId = 1;

                    $fieldCount = count($resultSet) > 0 ? count($resultSet[0]) : 0;

                    $resultSetHeader = new ResultSetHeaderPacket();
                    $resultSetHeader->packetId = $packetId++;
                    $resultSetHeader->fieldCount = $fieldCount;
                    $buffer = array_merge($buffer, $resultSetHeader->write());

                    if (count($resultSet) > 0) {
                        foreach ($resultSet[0] as $key => $value) {
                            $field = new FieldPacket();
                            $field->packetId = $packetId++;
                            $field->db = getBytes('');
                            $field->table = getBytes('');
                            $field->orgTable = getBytes('');
                            $field->catalog = getBytes(FieldPacket::DEFAULT_CATALOG);
                            $field->name = getBytes($key);
                            $field->orgName = getBytes('');
                            $field->charsetIndex = 0x21;
                            $field->length = 0x00;
                            $field->type = 0xFD;
                            $field->flags = 0x0000;
                            $field->decimals = 0;
                            $buffer = array_merge($buffer, $field->write());
                        }
                    }

                    $eof1 = new EOFPacket();
                    $eof1->packetId = $packetId++;
                    $eof1->warningCount = 0;
                    $eof1->status = 0x0002;
                    $buffer = array_merge($buffer, $eof1->write());

                    foreach ($resultSet as $row) {
                        $rowData = new RowDataPacket();
                        $rowData->packetId = $packetId++;
                        $rowData->fieldCount = $fieldCount;
                        foreach ($row as $value) {
                            $rowData->fieldValues[] = $value;
                        }
                        $buffer = array_merge($buffer, $rowData->write());
                    }

                    $eof2 = new EOFPacket();
                    $eof2->packetId = $packetId;
                    $eof2->warningCount = 0;
                    $eof2->status = 0x0002;
                    $buffer = array_merge($buffer, $eof2->write());

                    if ($server->exist($fd)) {
                        $server->send($fd, getString($buffer));
                    }
                }
            }
        }
    }

    /**
     * 客户端断开连接.
     *
     * @param \swoole_server $server
     * @param int $fd
     *
     */
    public function onClose(\swoole_server $server, int $fd)
    {
        parent::onClose($server, $fd);
    }

    /**
     * WorkerStart.
     *
     * @param \swoole_server $server
     * @param int $worker_id
     */
    public function onWorkerStart(\swoole_server $server, int $worker_id)
    {
        //
    }

    /**
     * 验证账号
     *
     * @param \swoole_server $server
     * @param int $fd
     * @param string $user
     * @param string $password
     *
     * @return bool
     */
    private function checkAccount(\swoole_server $server, int $fd, string $user, array $password)
    {
        $checkPassword = $this->source[$fd]
            ->checkPassword($password, CONFIG['server']['password']);
        return CONFIG['server']['user'] == $user && $checkPassword;
    }

    /**
     * 验证账号失败
     *
     * @param \swoole_server $server
     * @param int $fd
     * @param int $serverId
     *
     * @throws MySQLException
     */
    private function accessDenied(\swoole_server $server, int $fd, int $serverId)
    {
        $message = 'SMProxy@access denied for user \'' . $this->source[$fd]->user . '\'@\'' .
            $server->getClientInfo($fd)['remote_ip'] . '\' (using password: YES)';
        $errMessage = self::writeErrMessage($serverId, $message, ErrorCode::ER_ACCESS_DENIED_ERROR, 28000);
        if ($server->exist($fd)) {
            $server->send($fd, getString($errMessage));
        }
        throw new MySQLException($message);
    }

    /**
     * 判断model
     *
     * @param string $model
     * @param \swoole_server $server
     * @param int $fd
     *
     * @return string
     * @throws MySQLException
     */
    private function compareModel(string $model, \swoole_server $server, int $fd)
    {
        /**
         * 拼接数据库键名
         *
         * @param int $fd
         * @param string $model
         *
         * @return string
         */
        $spliceKey = function (int $fd, string $model) {
            return $this->source[$fd]->database ? $model . DB_DELIMITER . $this->source[$fd]->database : $model;
        };
        switch ($model) {
            case 'read':
                $key = $spliceKey($fd, $model);
                //如果没有读库 默认用写库
                if (!isset($this->dbConfig[$key])) {
                    $model = 'write';
                    $key = $spliceKey($fd, $model);
                    //如果没有写库
                    $this->existsDBKey($server, $fd, $model, $key);
                }
                break;
            case 'write':
                $key = $spliceKey($fd, $model);
                //如果没有写库
                $this->existsDBKey($server, $fd, $model, $key);
                break;
            default:
                $key = 'write' . DB_DELIMITER . $this->source[$fd]->database;
                break;
        }
        return $key;
    }


    /**
     * 判断配置文件键是否存在
     *
     * @param \swoole_server $server
     * @param int $fd
     * @param string $model
     * @param string $key
     *
     * @throws MySQLException
     */
    private function existsDBKey(\swoole_server $server, int $fd, string $model, string $key)
    {
        //如果没有写库则抛出异常
        if (!isset($this->dbConfig[$key])) {
            $message = 'SMProxy@Database config ' . ($this->source[$fd]->database ?: '') . ' ' . $model .
                ' is not exists!';
            $errMessage = self::writeErrMessage(1, $message, ErrorCode::ER_SYNTAX_ERROR, 42000);
            if ($server->exist($fd)) {
                $server->send($fd, getString($errMessage));
            }
            throw new MySQLException($message);
        }
    }

    /**
     * 验证
     *
     * @param BinaryPacket $bin
     * @param \swoole_server $server
     * @param int $fd
     *
     * @throws MySQLException
     */
    private function auth(BinaryPacket $bin, \swoole_server $server, int $fd)
    {
        if ($bin->data[0] == 20) {
            $checkAccount = $this->checkAccount($server, $fd, $this->source[$fd]->user, array_copy($bin->data, 4, 20));
            if (!$checkAccount) {
                $this->accessDenied($server, $fd, 4);
            } else {
                if ($server->exist($fd)) {
                    $server->send($fd, getString(OkPacket::$SWITCH_AUTH_OK));
                }
                $this->source[$fd]->auth = true;
            }
        } elseif ($bin->data[4] == 14) {
            if ($server->exist($fd)) {
                $server->send($fd, getString(OkPacket::$OK));
            }
        } else {
            $authPacket = new AuthPacket();
            $authPacket->read($bin);
            $checkAccount = $this->checkAccount($server, $fd, $authPacket->user ?? '', $authPacket->password ?? []);
            if (!$checkAccount) {
                if ($authPacket->pluginName == 'mysql_native_password') {
                    $this->accessDenied($server, $fd, 2);
                } else {
                    $this->source[$fd]->user = $authPacket->user;
                    $this->source[$fd]->database = $authPacket->database;
                    $this->source[$fd]->seed = RandomUtil::randomBytes(20);
                    $authSwitchRequest = array_merge(
                        [254],
                        getBytes('mysql_native_password'),
                        [0],
                        $this->source[$fd]->seed,
                        [0]
                    );
                    if ($server->exist($fd)) {
                        $server->send($fd, getString(array_merge(getMysqlPackSize(count($authSwitchRequest)), [2], $authSwitchRequest)));
                    }
                }
            } else {
                if ($server->exist($fd)) {
                    $server->send($fd, getString(OkPacket::$AUTH_OK));
                }
                $this->source[$fd]->auth = true;
                $this->source[$fd]->database = $authPacket->database;
            }
        }
    }
}
