<?php

$sql_host = '167.88.34.62';
$sql_user = 'Brun0';
$sql_password = '65UB3b3$';
$sql_db = 'vidblit';

openlog('vidblit-php', LOG_PID, LOG_USER);
syslog(LOG_INFO, 'Starting sequence to get request status');
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

syslog(LOG_INFO, 'Checking if we received a request id');
if(!isset($_POST['id']))
{
    syslog(LOG_WARNING, 'User error : received bad request with empty requestid');
    $result = array('error' => 'Bad request');
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Looking up request in mysql');
echo'looking up stuff';
exit();
$sql_userid = $userid;
$sql_requestid = $con->real_escape_string($_POST['id']);
$sql = 'select r.requestid as rid, ' .
        ' r.error as error,  ' .
        ' e.title as title, ' .
        ' e.type as type, ' .
        ' l.src_url as src_url, ' .
        ' l.dest_url as dest_url ' .
        ' from request r ' .
        ' left join request_locations l on r.requestid=l.requestid ' .
        ' left join request_extractresults e on r.requestid=e.requestid' .
        ' where r.requestid=' . $sql_requestid .
        ' and r.userid=' . $sql_userid;
$result = $con->query($sql);
if(!$result)
{
    syslog(LOG_ERR, 'Error looking up request in mysql' . mysqli_error($con));
    $result =  array('error' => 'Technical difficulties' );
    echo json_encode($result);
    exit();
}

$value = $result->fetch_object();
if(!$value)
{
    syslog(LOG_WARNING, 'Bad request - the requestid, userid have no result');
    $result =  array('error' => 'Bad request' );
    echo json_encode($result);
    exit();
}

$error = $value->error;
$title = $value->title;
$type = $value->type;
$src_url = $value->src_url;
$dest_url = $value->dest_url;

$result = array();
if(!is_null($error))
{
    $result['error'] = $error;
}
else 
{
    if(!is_null($title))
    {
        $result['info'] = array();
        $result['info']['title'] = $title;
        $result['info']['type'] = $type;      
    }
    if(!is_null($src_url))
    {
        $result['src'] = $src_url;
    }
    if(!is_null($dest_url))
    {
        $result['dest'] = $src_url;
    }
}
syslog(LOG_INFO, 'Returning successful request status result');
echo json_encode($result);

closelog();
?>