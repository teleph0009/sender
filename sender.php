<?php
require 'vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

define('RABBITMQ_HOST', '10.10.10.2');
define('RABBITMQ_PORT', 5672);
define('RABBITMQ_USER', 'myuser');
define('RABBITMQ_PASS', 'mypassword');
define('RABBITMQ_QUEUE', 'calls');

date_default_timezone_set("Europe/Kyiv");

// --------------------- ЛОГ ---------------------
function writeLog($message) {
    $log = "Время: " . date('Y-m-d H:i:s') . "\n" . $message . "\n" . str_repeat("-", 50) . "\n";
    file_put_contents('consumer_log.txt', $log, FILE_APPEND);
}

// --------------------- RETRY QUEUE ---------------------
function addToRetryQueue($queryData, $src, $dst, $callId) {
    $queueFile = 'retry_queue.json';
    $queue = file_exists($queueFile) ? json_decode(file_get_contents($queueFile), true) : [];
    if (!is_array($queue)) $queue = [];

    // сохраняем как есть (без двойного encode/decode)
    $queue[] = [
        'queryData' => $queryData,
        'aa'        => $src,
        'as'        => $dst,
        'callid1'   => $callId,
        'time'      => date('Y-m-d H:i:s')
    ];

    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --------------------- HTTP CALL ---------------------
function httpPost($url, $data) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_POST           => 1,
        CURLOPT_HEADER         => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_URL            => $url,
        CURLOPT_POSTFIELDS     => $data,
    ]);

    $result    = curl_exec($curl);
    $curlErr   = curl_error($curl);
    $curlErrNo = curl_errno($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    return [
        'ok'       => $curlErrNo === 0,
        'err'      => $curlErr,
        'code'     => $httpCode,
        'body'     => $result
    ];
}

// --------------------- MAIN CALL PROCESS ---------------------
function processCall($data) {
    writeLog("Начало обработки: " . json_encode($data, JSON_UNESCAPED_UNICODE));

    $status_map = [
        'ANSWERED'    => '200',
        'BUSY'        => '304',
        'NOANSWER'    => '304',
        'CHANUNAVAIL' => '480',
        'HANGUP'      => '404',
        'CANCEL'      => '603',
        'CONGESTION'  => '403',
        'FAILED'      => '603'
    ];

    // обязательные поля
    $src = $data['src'] ?? '';
    $dst = $data['dst'] ?? '';
    if (!$src || !$dst) {
        writeLog("Ошибка: нет src или dst");
        return false;
    }

    $status_code = $status_map[$data['dial_status'] ?? ''] ?? '200';
    $call_type   = $data['call_type'] ?? '';
    $duration    = $data['duration'] ?? '0';
    $fname       = $data['fname'] ?? '';
    $record_url  = $data['record_url'] ?? '';
    $line_number = $data['line_number'] ?? '380670000000';
    $lead1       = empty($data['lead']) ? '1' : '0';

    // ---------- ШАГ 1: REGISTER ----------
    writeLog("ШАГ 1: register");

    $queryData = http_build_query([
        'USER_ID'         => $dst,
        'PHONE_NUMBER'    => $src,
        'TYPE'            => $call_type,
        'CALL_START_DATE' => date("Y-m-d H:i:s"),
        'LINE_NUMBER'     => $line_number,
        'CRM_CREATE'      => $lead1
    ]);

    $res = httpPost('https://serena.uno/rest/169210/1b4uu2i8mg/telephony.externalcall.register.json', $queryData);

    if (!$res['ok']) {
        writeLog("Ошибка curl REGISTER: {$res['err']}");
        return false;
    }

    if ($res['code'] !== 200) {
        writeLog("REGISTER HT-TP {$res['code']}: {$res['body']}");
        return false;
    }

    $arr = json_decode($res['body'], true);
    $callId = $arr['result']['CALL_ID'] ?? null;

    if (!$callId) {
        writeLog("REGISTER: нет CALL_ID. Ответ: {$res['body']}");
        return false;
    }

    writeLog("CALL_ID = $callId");
    sleep(6);

    // ---------- ШАГ 2: FINISH ----------
    writeLog("ШАГ 2: finish");

    $queryDataFinish = http_build_query([
        'CALL_ID'    => $callId,
        'STATUS_CODE'=> $status_code,
        'DURATION'   => $duration,
        'CALL_TYPE'  => $call_type,
        'USER_ID'    => $dst
    ]);

    $res2 = httpPost('https://serena.uno/rest/169210/83xahqf/telephony.externalcall.finish.json', $queryDataFinish);

    if (!$res2['ok']) {
        writeLog("Ошибка curl FINISH: {$res2['err']}");
        return false;
    }

    writeLog("FINISH HTTP {$res2['code']} BODY: {$res2['body']}");

    // ---------- ШАГ 3: ATTACH RECORD ----------
    writeLog("ШАГ 3: attachrecord");

    $queryDataRecord = http_build_query([
        'CALL_ID'    => $callId,
        'FILENAME'   => $fname,
        'RECORD_URL' => $record_url
    ]);

    $res3 = httpPost('https://serena.uno/rest/169210/op41t/telephony.externalcall.attachrecord.json', $queryDataRecord);

    if (!$res3['ok']) {
        writeLog("Ошибка curl ATTACH: {$res3['err']}");
        return false;
    }

    file_put_contents(
        'api_log_00.txt',
        "Время: " . date('Y-m-d H:i:s') . "\nHTTP: {$res3['code']}\nBODY: {$res3['body']}\n-\n",
        FILE_APPEND
    );

    if ($res3['code'] == 400) {
        writeLog("ATTACH HTTP 400 — в очередь повтора");
        addToRetryQueue($queryDataRecord, $src, $dst, $callId);
    }

    writeLog("Обработка звонка завершена");
    return true;
}

// --------------------- CALLBACK ---------------------
$callback = function ($msg) {
    echo " [x] Получено сообщение\n";

    $data = json_decode($msg->body, true);

    if (!is_array($data)) {
        writeLog("Ошибка JSON: " . json_last_error_msg());
        $msg->ack();
        return;
    }

    echo " [*] Обработка: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";

    try {
        $ok = processCall($data);
        if ($ok) {
            echo " [✓] Успех\n";
            $msg->ack();
        } else {
            echo " [!] Ошибка обработки — nack\n";
            $msg->nack(false, true); // оставляем
        }
    } catch (Throwable $e) {
        writeLog("Исключения нет: " . $e->getMessage());
        $msg->nack(false, true);
    }

    echo str_repeat("-", 50) . "\n";
};

// --------------------- MAIN ---------------------
try {
    echo "Подключение к RabbitMQ...\n";
    $connection = new AMQPStreamConnection(RABBITMQ_HOST, RABBITMQ_PORT, RABBITMQ_USER, RABBITMQ_PASS);
    $channel = $connection->channel();
    $channel->queue_declare(RABBITMQ_QUEUE, false, true, false, false);
    $channel->basic_qos(null, 1, null);

    echo "Ожидание сообщений...\n";

    $channel->basic_consume(RABBITMQ_QUEUE, '', false, false, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

} catch (Throwable $e) {
    writeLog("Критическая ошибка: " . $e->getMessage());
}
?>
