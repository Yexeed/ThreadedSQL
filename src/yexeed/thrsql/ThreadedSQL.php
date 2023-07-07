<?php

namespace yexeed\thrsql;


use Closure;
use Exception;
use mysqli;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use pocketmine\scheduler\TaskScheduler;
use yexeed\thrsql\task\MysqlNewChecker;
use yexeed\thrsql\utils\MysqlSettings;
use yexeed\thrsql\utils\PrepareWrap;
use yexeed\thrsql\utils\ResultWrap;

class ThreadedSQL extends PluginBase
{
    private MysqlNewWorker $thread;
    private static ?self $instance = null;
    private int $id = 0;
    private array $callbacks = [];
    private array $timeout = [];
    private ?TaskHandler $checkerTaskHandler = null;
    private bool $working = true;

    public function onEnable(): void
    {
        self::$instance = $this;
        $this->loadMysqlSettings();
        $settings = MysqlSettings::get();

        $params = [$settings->getHostname(),
            $settings->getUsername(),
            $settings->getPassword(),
            $settings->getDatabase(),
            $settings->getPort(),
            $this->getServer()->getLogger()];
        $this->thread = new MysqlNewWorker(...$params);
        $this->startChecker();
    }

    private function loadMysqlSettings(): void {
        $this->saveDefaultConfig();
        $array = $this->getConfig()->get("mysql");
        MysqlSettings::init($array);
    }

    public function check(): void {
        if($this->thread->isCrashed()){
            //wow?
            foreach ($this->callbacks as $callback){
                $callback(new ResultWrap([], "MySQL crashed"));
            }
            $this->timeout = [];

            $this->checkerTaskHandler->cancel();
            $this->working = false;
            $this->getLogger()->emergency("MysqlWorker CRASH!!!");
            return;
        }
        while($line = $this->thread->outputs->shift()){
            if($line === "enabled"){
                $this->getLogger()->notice("MysqlWorker enabled!");
                continue;
            }
            $json = json_decode($line, true);
            $id = $json['id'];
            $result = $json['result'];

            unset($this->timeout[$id]);

            if (isset($this->callbacks[$id])) {
                $c = $this->callbacks[$id];
                $c(ResultWrap::unserialize($result));
                unset($this->callbacks[$id]);
            }
        }
        foreach ($this->timeout as $id => &$timeout){
            if($timeout <= 0){
                $c = $this->callbacks[$id];
                $c(new ResultWrap([], "Timed-Out", true));

                unset($this->thread->inputs[$id]);
                unset($this->callbacks[$id]);
                unset($this->timeout[$id]);
            }
            $timeout--;
        }
    }

    public function startChecker(): void {
        $task = new MysqlNewChecker($this);
        $handler = $this->getScheduler()->scheduleRepeatingTask($task, 1);
        $this->checkerTaskHandler = $handler;
    }

    /**
     * @param string|PrepareWrap $wrap
     * @param Closure|null $func
     * @param int|null $timeout
     * @throws Exception
     */
    public static function query(PrepareWrap|string $wrap, ?Closure $func = null, ?int $timeout = null): void {
        if(!$wrap instanceof PrepareWrap){
            $wrap = new PrepareWrap($wrap); //deprecated API support :O
        }
        $id = self::$instance->id;
        $wrap->setQueryId($id);
        self::$instance->thread->inputs[] = $wrap->jsonSerialize();
        if($func !== null){
            if($timeout !== null){
                self::$instance->timeout[$id] = $timeout * 20; // second = 20 ticks (task run every tick)
            }
            self::$instance->callbacks[$id] = $func;
        }
        self::$instance->id++;
        if(self::$instance->id === PHP_INT_MAX){ //hz, на всякий случай
            self::$instance->id = 0;
        }
    }

    /**
     * @param string|PrepareWrap $wrap
     * @return array|null
     * @throws Exception
     */
    public static function forceQuery(PrepareWrap|string $wrap): ?array{
        if(!$wrap instanceof PrepareWrap){
            $wrap = new PrepareWrap($wrap);
        }
        $settings = MysqlSettings::get();
        $mysqli = new mysqli($settings->getHostname(), $settings->getUsername(), $settings->getPassword(), $settings->getDatabase(), $settings->getPort());
        if($mysqli->connect_errno){
            self::$instance->getLogger()->error("Can't connect to forced mysql: " . $mysqli->connect_error);
            return null;
        }

        $mysqlStmt = $mysqli->prepare($wrap->getQuery());
        foreach ($wrap->getBindParameters() as $bindParameter){
            $mysqlStmt->bind_param($bindParameter[0], $bindParameter[1]);
        }

        if(!$mysqlStmt->execute()){
            self::$instance->getLogger()->error("Can't run forced mysql stmt: " . $mysqlStmt->error);
            $rows = null;
        }else{
            $result = $mysqlStmt->get_result();
            $rows = [];
            if($result === false){
                if($mysqlStmt->errno !== 0) {
                    //ERROR!
                    $rows = null;
                }else{
                    $rows = [$mysqlStmt->insert_id]; //no error, just empty result.
                }
            }else {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $result->free();
                $mysqlStmt->close();
            }
        }
        $mysqlStmt->close();
        $mysqli->close();
        return $rows;
    }

    /**
     * @return bool
     */
    public static function isWorking(): bool
    {
        return self::$instance->working;
    }

    public function onDisable(): void
    {
        if($this->thread->isRunning()) {
            $this->thread->quit();
        }
    }
}