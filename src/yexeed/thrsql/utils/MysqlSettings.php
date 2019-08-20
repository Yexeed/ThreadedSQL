<?php


namespace yexeed\thrsql\utils;


class MysqlSettings
{
    /** @var MysqlSettings */
    private static $cached = null;
    /** @var string */
    private $hostname;
    /** @var int */
    private $port;
    /** @var string */
    private $username;
    /** @var string */
    private $password;
    /** @var string */
    private $database;

    public function __construct(string $hostname, int $port, string $username, string $password, string $database)
    {
        $this->hostname = $hostname;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
    }

    public static function get(){
        return self::$cached;
    }

    public static function init(array $cfg): void{
        self::$cached = new MysqlSettings(
            $cfg['host'] ?? $cfg['hostname'],
            $cfg['port'] ?? 3306,
            $cfg['username'] ?? $cfg['user'],
            $cfg['password'] ?? $cfg['pass'],
            $cfg['database'] ?? $cfg['db']
        );
    }

    /**
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @return string
     */
    public function getDatabase(): string
    {
        return $this->database;
    }
}