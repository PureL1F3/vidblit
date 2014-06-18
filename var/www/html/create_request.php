<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

$rabbit_host = 'localhost';
$rabbit_port = 5672;
$rabbit_user = 'guest';
$rabbit_pwd = 'guest';
$rabbit_q_extractor = 'extractor';

$sql_host = '167.88.34.62';
$sql_user = 'Brun0';
$sql_password = '65UB3b3$';
$sql_db = 'vidblit';

openlog('vidblit-php', LOG_PID, LOG_USER);

syslog(LOG_INFO, 'Starting sequence to create new request');

#check that our user is valid
syslog(LOG_INFO, 'Getting userid for session token in mysql');
if(!isset($_SERVER['HTTP_X_DESCRIPTION']))
{
    syslog(LOG_WARNING, 'User error : missing session token');
    $result = array('error' => 'Bad request');
    echo json_encode($result);
    exit();
}

#check if user is valid
syslog(LOG_INFO, 'Connecting to mysql');
$con = mysqli_connect($sql_host,$sql_user,$sql_password,$sql_db);
if (mysqli_connect_errno())
{
    syslog(LOG_ERR, 'Error connecting to mysql db:' . mysqli_connect_error());
    $result =  array('error' => 'Technical difficulties' );
    echo json_encode($result);
    exit();
}

$token = $_SERVER['HTTP_X_DESCRIPTION'];
$sql_token = $con->real_escape_string($token);
if(strlen($sql_token) > 600)
{
    syslog(LOG_WARNING, 'User error : bad session token - more than 600 characters');
    $result = array('error' => 'Bad request');
    echo json_encode($result);
    exit();
}


$sql = "CALL get_session_userid('$sql_token');";
$result = $con->query($sql);
if(!$result)
{
    syslog(LOG_ERR, 'User error : token does not exist for active session' . mysqli_error($con));
    $result =  array('error' => 'Bad request' );
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Receiving user id');
$userid = $result->fetch_object()->userid;
while ($con->more_results())
{
    $con->next_result();
    $result = $con->store_result();
    if ($result instanceof mysqli_result) 
    {
        $result->free();
    }
}

syslog(LOG_INFO, 'Checking if we have a url request');
if(!isset($_POST['url']))
{
    syslog(LOG_WARNING, 'User error : received bad request with empty userid, url');
    $result = array('error' => 'Bad request');
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Creating request in mysql');
$sql_userid = 0;
$sql_url = $con->real_escape_string($_POST['url']);
$sql = "CALL create_request('$sql_userid', '$sql_url');";
$result = $con->query($sql);
if(!$result)
{
    syslog(LOG_ERR, 'Error creating request:' . mysqli_error($con));
    $result =  array('error' => 'Technical difficulties' );
    echo json_encode($result);
    exit();
}

#create db request
syslog(LOG_INFO, 'Receiving request id');
$requestid = $result->fetch_object()->requestid;
mysqli_close($con);

#add request to extractor queue
syslog(LOG_INFO, 'Connecting to rabbit');
$connection = new AMQPConnection($rabbit_host, $rabbit_port, $rabbit_user, $rabbit_pwd);
$channel = $connection->channel();
$channel->queue_declare($rabbit_q_extractor, false, false, false, false);

syslog(LOG_INFO, 'Publishing extractor message to rabbit');
$extractor_msg = array('id' => $requestid, 'url' => $_POST['url']);
$extractor_msg = json_encode($extractor_msg);
$msg = new AMQPMessage($extractor_msg);
$channel->basic_publish($msg, '', $rabbit_q_extractor);
$channel->close();
$connection->close();

syslog(LOG_INFO, 'Returning successful create request result');
$result =  array('id' => $requestid);
echo json_encode($result);

closelog();
?>