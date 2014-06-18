<?php

$sql_host = '167.88.34.62';
$sql_user = 'Brun0';
$sql_password = '65UB3b3$';
$sql_db = 'vidblit';

openlog('vidblit-php', LOG_PID, LOG_USER);

syslog(LOG_INFO, 'Starting sequence to log out user');
syslog(LOG_INFO, 'Connecting to mysql');
$con = mysqli_connect($sql_host,$sql_user,$sql_password,$sql_db);
if (mysqli_connect_errno())
{
    syslog(LOG_ERR, 'Error connecting to mysql db:' . mysqli_connect_error());
    $result =  array('error' => 'Technical difficulties' );
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Killing session token in mysql');
$token = $_SERVER['HTTP_X_DESCRIPTION'];
$sql_token = $con->real_escape_string($token);
if(strlen($sql_token_ <= 600))
{
    $sql = "CALL kill_session('$sql_token');";
    $result = $con->query($sql);
    if(!$result)
    {
        syslog(LOG_ERR, 'Error killing session token' . mysqli_error($con));
        $result =  array('error' => 'Technical difficulties' );
        echo json_encode($result);
        exit();
    }
}

syslog(LOG_INFO, 'Reporting successful kill of session');
$result = array('success' => true);
echo json_encode($result);

closelog();
?>