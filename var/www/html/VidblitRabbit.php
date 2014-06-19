<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

class VidblitRabbit
{
    private $_host = NULL;
    private $_port = NULL;
    private $_user = NULL;
    private $_pwd = NULL;
    private $_con = NULL;
    private $_queues = NULL;

    function __construct($host, $port, $user, $pwd, $queues)
    {
        $this->_host = $host;
        $this->_port = $port;
        $this->_user = $user;
        $this->_pwd = $pwd;
        $this->_queues = $queues;
    }

    function connect()
    {
        syslog(LOG_INFO, 'VidblitRabbit:connect');
        $this->_con = new AMQPConnection($this->_host, $this->_port, $this->_user, $this->_pwd);
    }

    function publish_extract_msg($id, $url)
    {
        syslog(LOG_INFO, 'VidblitRabbit:publish_extract_msg');
        $msg = json_encode(array('id' => $id, 'url' => $url));
        $this->publish_msg($this->_queues['extractor'], $msg);
    }

    function publish_msg($queue, $msg)
    {
        syslog(LOG_INFO, 'VidblitRabbit:publish_msg');
        $channel = $this->_con->channel();
        $channel->queue_declare($queue, false, false, false, false);
        $message = new AMQPMessage($msg);
        $channel->basic_publish($message, '', $queue);
        $channel->close();
    }

    function __destruct()
    {
        $this->_con->close();
    }
}

// $rabbit = new VidblitRabbit($config['rabbit']['connection']['host'], $config['rabbit']['connection']['port'], $config['rabbit']['connection']['user'], $config['rabbit']['connection']['pwd'], $config['rabbit']['q']);
// $rabbit->connect();

// show("");
// show("Testing publish_extract_msg");
// $result = $rabbit->publish_extract_msg('12', 'blackbottom');
// var_dump($result);


?>