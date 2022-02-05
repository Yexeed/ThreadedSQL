<?php
/**
 * Created by PhpStorm.
 * User: yexee
 * Date: 08.02.2019
 * Time: 17:44
 */

namespace yexeed\thrsql;

use Exception;
use mysqli;
use pocketmine\Thread;
use pocketmine\ThreadManager;
use Threaded;
use ThreadedLogger;
use yexeed\thrsql\utils\PrepareWrap;
use yexeed\thrsql\utils\ResultWrap;

/*
 * this class uses a separate thread to work on mysql queries
 */
class MysqlOldWorker extends Thread
{
    /** @var Threaded */
    public $inputs, $outputs;
    /** @var string */
    private $hostname;
    /** @var string */
    private $username;
    /** @var string */
    private $password;
    /** @var int */
    private $port;
    /** @var string */
    private $database;
    /** @var bool */
    private $shutdown;
    /** @var ThreadedLogger */
    private $logger;

    /** @var bool */
    private $crashed = false;

    public function __construct(string $host, string $user, string $password, string $database, int $port, ThreadedLogger $logger)
    {
        $this->hostname = $host;
        $this->username = $user;
        $this->password = $password;
        $this->logger = $logger;
        $this->port = $port;
        $this->database = $database;
        $this->inputs = new Threaded();
        $this->outputs = new Threaded();
        $this->start();
    }

    /**
     * @throws Exception
     */
    public function run()
    {
        $this->registerClassLoader();
        try {
            $mysqli = @new mysqli($this->hostname, $this->username, $this->password, $this->database, $this->port);
            if($mysqli->connect_errno){
                throw new Exception($mysqli->connect_error, $mysqli->connect_errno);
            }
            if(!$mysqli->set_charset("utf8mb4")){
                throw new Exception($mysqli->error);
            }
        }catch (Exception $e){
            $this->logger->error("Can't connect to Mysql: " . ($e->getMessage()));
            $this->crashed = true;
            return;
        }
        $this->shutdown = false;
        $this->outputs[] = "enabled";
        $now = time();
        $nextUpdate = $now + 10;
        while(!$this->shutdown){
            $now = time();
            if($nextUpdate < $now) {
                if(!$mysqli->ping()){
                    $this->logger->error("Can't ping mysql: '" . $mysqli->error . "' Reconnect in 10 seconds");
                    $mysqli->close();
                    sleep(10);
                    $this->run();
                    return;
                }
                $nextUpdate = $now + 10;
            }
            while($line = $this->inputs->shift()){
                $prepare = PrepareWrap::fromJson($line);
                $mysqlStmt = $mysqli->prepare($prepare->getQuery());
                if($mysqlStmt === false){
                    $resultWrap = new ResultWrap([], $mysqli->error);
                    $this->outputs[] = json_encode([
                        'id' => $prepare->getQueryId(),
                        'result' => $resultWrap->arraySerialize()
                    ]);
                    continue;
                }

                $bindType = "";
                $bindValues = [];
                foreach ($prepare->getBindParameters() as $bindParameter){
                    $bindType .= $bindParameter[0];
                    $bindValues[] = $bindParameter[1];
                }
                if(!empty($bindValues)) {
                    $mysqlStmt->bind_param($bindType, ...$bindValues);
                }

                $wrap = ResultWrap::executeAndWrapStmt($mysqlStmt);
                $this->outputs[] = json_encode([
                    'id' => $prepare->getQueryId(),
                    'result' => $wrap->arraySerialize()
                ]);
            }
            usleep(10000);
        }
        $mysqli->close();
    }

    public function shutdown(){
        $this->shutdown = true;
    }

    /**
     * @return bool
     */
    public function isCrashed(): bool
    {
        return $this->crashed;
    }

    public function quit()
    {
        $this->shutdown();
        ThreadManager::getInstance()->remove($this);
    }

    public function setGarbage(){}
}