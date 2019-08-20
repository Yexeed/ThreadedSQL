<?php


namespace yexeed\thrsql\utils;


class ResultWrap
{
    /** @var bool */
    private $empty;
    /** @var array */
    private $rows;
    /** @var mixed */
    private $error;
    /** @var bool */
    private $timedOut;
    /** @var int */
    private $insertId = -1;

    public function __construct(array $rows, $error = null, $timedOut = false)
    {
        $this->rows = $rows;
        $this->empty = empty($rows);
        $this->error = $error;
        $this->timedOut = $timedOut;
    }

    public static function executeAndWrapStmt(\mysqli_stmt $executedStmt){
        //todo: упростить логику этой функции
        if(!$executedStmt->execute()){
            $wrap = new ResultWrap([], $executedStmt->error);
        }else{
            $result = $executedStmt->get_result();
            $rows = [];
            if($result === false){
                if($executedStmt->errno !== 0) {
                    //ERROR!
                    $wrap = new ResultWrap([], $executedStmt->error);
                }else{
                    $wrap = new ResultWrap([]); //no error, just empty result.
                    $wrap->insertId = $executedStmt->insert_id;
                }
            } else {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $result->free();
                $wrap = new ResultWrap($rows);
                $wrap->insertId = $executedStmt->insert_id;
            }
        }
        $executedStmt->close();
        return $wrap;
    }

    /**
     * @return array to be json-encod
     */
    public function arraySerialize(): array
    {
        return [
            'rows' => $this->rows,
            'error' => $this->error
        ];
    }

    /**
     * @return array
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @return int
     */
    public function getInsertId(): int
    {
        return $this->insertId;
    }

    /**
     * @param int $insertId
     */
    public function setInsertId(int $insertId): void
    {
        $this->insertId = $insertId;
    }

    /**
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return bool
     */
    public function isTimedOut(): bool
    {
        return $this->timedOut;
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->empty;
    }

    /**
     * @return bool
     */
    public function wasSuccessful(): bool{
        return $this->error === null && !$this->timedOut;
    }

    public function first(): ?array{
        if($this->isEmpty() || !$this->wasSuccessful()){
            return null;
        }
        return $this->rows[0];
    }
}