<?php

namespace yexeed\thrsql;

use Exception;
use mysqli;
use pmmp\thread\Thread as ThreadAlias;
use pmmp\thread\ThreadSafeArray;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use yexeed\thrsql\utils\PrepareWrap;
use yexeed\thrsql\utils\ResultWrap;

class MysqlNewWorker extends Thread
{
    /** @var ThreadSafeArray */
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
    /** @var ThreadSafeLogger */
    private $logger;

    /** @var bool */
    private $crashed = false;

    public function __construct(string $host, string $user, string $password, string $database, int $port, ThreadSafeLogger $logger)
    {
        $this->hostname = $host;
        $this->username = $user;
        $this->password = $password;
        $this->logger = $logger;
        $this->port = $port;
        $this->database = $database;
        $this->inputs = new ThreadSafeArray();
        $this->outputs = new ThreadSafeArray();
        $this->start(ThreadAlias::INHERIT_INI);
    }

    /**
     * @throws Exception
     */
    public function onRun(): void
    {
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
                try {
                    if (!$mysqli->ping()) {
                        throw new Exception($mysqli->error);
                    }
                    $nextUpdate = $now + 10;
                }catch (Exception $e){
                    $this->logger->error("Can't ping mysql: '" . $e->getMessage() . "' Reconnect in 10 seconds");
                    $mysqli->close();
                    sleep(10);
                    $this->run();
                    return;
                }
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

    public function shutdown(): void {
        $this->shutdown = true;
    }

    /**
     * @return bool
     */
    public function isCrashed(): bool
    {
        return $this->crashed;
    }

    public function quit(): void
    {
        $this->shutdown();
    }
}