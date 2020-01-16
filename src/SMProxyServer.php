<?php

namespace SMProxy;

use SMProxy\Handler\Frontend\FrontendAuthenticator;
use SMProxy\Handler\Frontend\FrontendConnection;
use SMProxy\MysqlPacket\SMProxyPacket;
use function SMProxy\Helper\array_copy;
use function SMProxy\Helper\getBytes;
use function SMProxy\Helper\getMysqlPackSize;
use function SMProxy\Helper\getPackageLength;
use function SMProxy\Helper\getString;
use function SMProxy\Helper\initConfig;
use SMProxy\Helper\ProcessHelper;
use SMProxy\Log\Log;
use SMProxy\MysqlPacket\AuthPacket;
use SMProxy\MysqlPacket\BinaryPacket;
use SMProxy\MysqlPacket\MySqlPacketDecoder;
use SMProxy\MysqlPacket\MySQLPacket;
use SMProxy\MysqlPacket\OkPacket;
use SMProxy\MysqlPacket\Util\ErrorCode;
use SMProxy\MysqlPacket\Util\RandomUtil;
use SMProxy\MysqlPool\MySQLException;
use SMProxy\MysqlPool\MySQLPool;
use SMProxy\Parser\ServerParse;
use SMProxy\Route\RouteService;
use Swoole\Coroutine;

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
     * @param $server
     * @param $fd
     * @param $reactor_id
     * @param $data
     */
    public function onReceive(\swoole_server $server, int $fd, int $reactor_id, string $data)
    {
        $bin = (new MySqlPacketDecoder())->decode($data);
        if (!$this->source[$fd]->auth) {
            $this->auth($bin, $server, $fd);
        } else {
            if ($data === '!select @@version_comment limit 1') {
                $mysqlPacket = new BinaryPacket();
                $mysqlPacket->packetId = 1;
                $mysqlPacket->packetLength = 1;
                $mysqlPacket->data = 'Number of fields: 1';
                $mysqlPacket->write();
            }
            var_dump($bin);
            var_dump($data);
            return;
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

    /**
     * 语句解析处理
     *
     * @param BinaryPacket $bin
     * @param string $data
     * @param int $fd
     *
     * @throws MySQLException
     */
    private function query(BinaryPacket $bin, string &$data, int $fd)
    {
        $trim_data = rtrim($data);
        $data_len = strlen($trim_data);
        switch ($bin->data[4]) {
            case MySQLPacket::$COM_INIT_DB:
                // just init the frontend
                break;
            case MySQLPacket::$COM_STMT_PREPARE:
            case MySQLPacket::$COM_QUERY:
                $connection = new FrontendConnection();
                $queryType = $connection->query($bin);
                $hintArr = RouteService::route(substr($data, 5, strlen($data) - 5));
                if (isset($hintArr['db_type'])) {
                    switch ($hintArr['db_type']) {
                        case 'read':
                            if ($queryType == ServerParse::DELETE || $queryType == ServerParse::INSERT ||
                                $queryType == ServerParse::REPLACE || $queryType == ServerParse::UPDATE ||
                                $queryType == ServerParse::DDL) {
                                $this->connectReadState[$fd] = false;
                                $system_log = Log::getLogger('system');
                                $system_log->warning("should not use hint 'db_type' to route 'delete', 'insert', 'replace', 'update', 'ddl' to a slave db.");
                            } else {
                                $this->connectReadState[$fd] = true;
                            }
                            break;
                        case 'write':
                            $this->connectReadState[$fd] = false;
                            break;
                        default:
                            $this->connectReadState[$fd] = false;
                            $system_log = Log::getLogger('system');
                            $system_log->warning("use hint 'db_type' value is not found.");
                            break;
                    }
                } elseif (ServerParse::SELECT == $queryType ||
                    ServerParse::SHOW == $queryType ||
                    (ServerParse::SET == $queryType && false === strpos($data, 'autocommit', 4)) ||
                    ServerParse::USE == $queryType
                ) {
                    //处理读操作
                    if (!isset($this->connectHasTransaction[$fd]) ||
                        !$this->connectHasTransaction[$fd]) {
                        if ($data_len > 6 && (('u' == $trim_data[$data_len - 6] || 'U' == $trim_data[$data_len - 6]) &&
                                ServerParse::UPDATE == ServerParse::uCheck($trim_data, $data_len - 6, false))) {
                            //判断悲观锁
                            $this->connectReadState[$fd] = false;
                        } else {
                            $this->connectReadState[$fd] = true;
                        }
                    }
                } elseif (ServerParse::START == $queryType || ServerParse::BEGIN == $queryType
                ) {
                    //处理事务
                    $this->connectHasTransaction[$fd] = true;
                    $this->connectReadState[$fd] = false;
                } elseif (ServerParse::SET == $queryType && false !== strpos($data, 'autocommit', 4) &&
                    0 == $trim_data[$data_len - 1]) {
                    //处理autocommit事务
                    $this->connectHasAutoCommit[$fd] = true;
                    $this->connectHasTransaction[$fd] = true;
                    $this->connectReadState[$fd] = false;
                } elseif (ServerParse::SET == $queryType && false !== strpos($data, 'autocommit', 4) &&
                    1 == $trim_data[$data_len - 1]) {
                    $this->connectHasAutoCommit[$fd] = false;
                    $this->connectReadState[$fd] = false;
                } elseif (ServerParse::COMMIT == $queryType || ServerParse::ROLLBACK == $queryType) {
                    //事务提交
                    $this->connectHasTransaction[$fd] = false;
                } else {
                    $this->connectReadState[$fd] = false;
                }
                break;
            case MySQLPacket::$COM_PING:
                break;
            case MySQLPacket::$COM_QUIT:
                //禁用客户端退出
                $data = '';
                break;
            case MySQLPacket::$COM_PROCESS_KILL:
                break;
            case MySQLPacket::$COM_STMT_EXECUTE:
                break;
            case MySQLPacket::$COM_STMT_CLOSE:
                break;
            case MySQLPacket::$COM_HEARTBEAT:
                break;
            default:
                break;
        }
    }
}
