<?php
/**
 * Created by PhpStorm.
 * User: yexee
 * Date: 08.02.2019
 * Time: 17:44
 */

namespace yexeed\thrsql;

use Exception;
use pocketmine\Thread;
use Threaded;
use ThreadedLogger;
use yexeed\thrsql\utils\PrepareWrap;
use yexeed\thrsql\utils\ResultWrap;

/*
 * this class uses a separate thread to work on mysql queries
 */
class MysqlWorker extends Thread
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
            $my = @new \mysqli($this->hostname, $this->username, $this->password, $this->database, $this->port);
            if($my->connect_errno){
                $icv = iconv("CP1251", "UTF-8", $my->connect_error);
                throw new Exception($my->connect_error, $my->connect_errno);
            }
            if(!$my->set_charset("utf8")){
                throw new Exception($my->error);
            }
            $my->query(/** @lang MySQL */ "SET NAMES 'utf8'");
        }catch (Exception $e){
            $this->logger->error("Can't connect to Mysql: " . ($e->getMessage()));
            $this->crashed = true;
            return;
        }
        $this->shutdown = false;
        $this->outputs[] = "enabled";
        while(!$this->shutdown){
            while($line = $this->inputs->shift()){
                $prepare = PrepareWrap::fromJson($line);
                $mysqlStmt = $my->prepare($prepare->getQuery());
                if($mysqlStmt === false){
                    $this->outputs[] = [
                        'id' => $prepare->getQueryId(),
                        'result' => new ResultWrap([],  $my->error)
                    ];
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
        $my->close();
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
        parent::quit();
    }

    public function setGarbage(){}
}