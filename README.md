# ThreadedSQL
PMMP Plugin Library to create  MySQL queries.
# Использование
#### Создание запроса
```php
$query = "
    CREATE TABLE test 
    (
        `id` INT NOT NULL AUTO_INCREMENT,
        `data` TEXT NOT NULL,
        PRIMARY KEY (`id`)
    )
"; //сам запрос
$prepare = new PrepareWrap($query); //PrepareWrap служит оберткой для препарированных запросов

ThreadedSQL::query($prepare);

//вы также можете использовать старый синтаксис (2.0),
//но только если уверены, что препарирование
//в этом случае не требуется
ThreadedSQL::query($query);
```
---
#### Обработка запроса
```php
//создание препарированного запроса
$prepare = new PrepareWrap("SELECT * FROM test WHERE data = ?", "some test (possibly injected by malicious user) data");

// 2-м аргументом вы можете указывать функцию,
// которая вызовется, как только запрос будет выполнен
// (не всегда успешно, это нужно проверять)
ThreadedSQL::query($prepare, function(ResultWrap $wrap){
    //ResultWrap служит оберткой для результата запроса
    if(!$wrap->wasSuccessful(){
        //запрос не был успешен!
        print "Ошибка запроса: " . $wrap->getError() . "\n";
    }else{
        if($wrap->isEmpty()){
            print "Ответ пустой!";
        }else{
          //если вы ожидаете только одну строчку из таблицы, вы можете использовать first();
          $firstRow = $wrap->first();
          var_dump($firstRow);
          //иначе, используйте getRows()
          foreach($wrap->getRows() as $row){
              var_dump($row);
          }
        }
    }
});
```
---
#### Тайм-аут
Этот функционал создан для случаев, когда вы ожидаете что запрос должен прийти незамедлительно,
в рамках какого-либо срока
```php
//$prepare используется из предыдущего примера

ThreadedSQL::query($prepare, function(ResultWrap $wrap){
    //wasSuccessful() проверяет на наличие ошибки а также тайм-аута,
    //поэтому если вам нужно разделить эти проверки - сначала проверяйте
    //на тайм-аут
    if($wrap->isTimedOut()){
        print "Запрос выполнялся слишком долго!";
    }elseif(!$wrap->wasSucessful()){
        print "Ошибка запроса: " . $wrap->getError() . "\n";
    }else{
        //запрос успешен
    }
}, 5); //тайм-аут запроса: 5 секунд
```
 
