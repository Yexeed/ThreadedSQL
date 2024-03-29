<?php


namespace yexeed\thrsql\utils;


use mysqli_stmt;

class ResultWrap
{
    /** @var array */
    private $rows;
    /** @var string */
    private $error;
    /** @var bool */
    private $timedOut;
    /** @var int */
    private $insertId;

    public function __construct(array $rows, ?string $error = null, bool $timedOut = false, int $insertId = -1)
    {
        $this->rows = $rows;
        $this->error = $error;
        $this->timedOut = $timedOut;
        $this->insertId = $insertId;
    }

    public static function executeAndWrapStmt(mysqli_stmt $executedStmt): ResultWrap{
        //todo: упростить логику этой функции
        try {
            if (!$executedStmt->execute()) {
                throw new \Exception($executedStmt->error);
            } else {
                $result = $executedStmt->get_result();
                $rows = [];
                if ($result === false) {
                    if ($executedStmt->errno !== 0) {
                        //ERROR!
                        $wrap = new ResultWrap([], $executedStmt->error);
                    } else {
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
        }catch (\Exception $e){
            $wrap = new ResultWrap([], $e->getMessage());
        } finally {
            $executedStmt->close();
        }
        return $wrap;
    }

    /**
     * @return array should be json-encoded
     */
    public function arraySerialize(): array
    {
        return [
            'rows' => $this->rows,
            'error' => $this->error,
            'insertId' => $this->insertId,
            'timedOut' => $this->timedOut
        ];
    }

    /**
     * @param array $data serialized array using the method above
     * @return ResultWrap
     */
    public static function unserialize(array $data): ResultWrap{
        return new ResultWrap($data['rows'], $data['error'], $data['timedOut'], $data['insertId']);
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
     * @return string|null
     */
    public function getError(): ?string
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
        return empty($this->rows);
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