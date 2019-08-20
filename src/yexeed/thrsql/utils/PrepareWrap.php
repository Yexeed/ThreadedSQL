<?php


namespace yexeed\thrsql\utils;

/*
 * this class creates json wrap which used between threads
 */

use Exception;
use JsonSerializable;
use pocketmine\utils\MainLogger;

class PrepareWrap implements JsonSerializable
{
    /**
     * @param string $json
     * @return PrepareWrap
     * @throws Exception
     */
    public static function fromJson(string $json): PrepareWrap{
        $json = json_decode($json, true);
//        MainLogger::getLogger()->logException(new Exception("A"));

        $prep = new PrepareWrap($json['query'], ...$json['parameters']);
        $prep->queryId = $json['id'];
        return $prep;
    }

    /**
     * @var array
     * an array of arrays containing ParamType => ParamValue
     */
    private $bindParameters = [];

    /**
     * @var string
     * the original query
     */
    private $query;

    /**
     * @var int
     * only for Threaded usage of MysqlWorker
     */
    private $queryId;

    /**
     * PrepareWrap constructor.
     * @param string $query
     * @param string|int|float ...$forcedParameters
     * @throws Exception
     */
    public function __construct(string $query, ...$forcedParameters)
    {
        $this->query = $query;
        $this->bindParameter(...$forcedParameters);
    }

    /**
     * Fluent adder of parameters, why not?
     *
     * @param string|int|float ...$parameterList
     * @return PrepareWrap
     * @throws Exception
     */
    public function bindParameter(...$parameterList){
        foreach ($parameterList as $parameter){
            $type = null;
            if(is_string($parameter)){
                $type = "s";
            } elseif(is_int($parameter)){
                $type = "i";
            }elseif(is_float($parameter)){
                $type = "d";
            }elseif(is_array($parameter)){
                //already passed parameter expected
                //array: [type, parameter]
                $type = $parameter[0];
                $parameter = $parameter[1];
            }else{
                throw new Exception("Can't bind parameter $parameter");
            }
            $this->bindParameters[] = [
                $type,
                $parameter
            ];
        }
        return $this;
    }

    /**
     * @return array
     */
    public function getBindParameters(): array
    {
        return $this->bindParameters;
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @return string json-encoded string of this wrap
     */
    public function jsonSerialize(): string{
        return json_encode([
            'id' => $this->queryId,
            'query' => $this->query,
            'parameters' => $this->bindParameters
        ]);
    }

    /**
     * @return int
     */
    public function getQueryId(): int
    {
        return $this->queryId;
    }

    /**
     * @param int $queryId
     */
    public function setQueryId(int $queryId): void
    {
        $this->queryId = $queryId;
    }
}