<?php

include_once('config.php');
include_once('utility.php');
include_once('VidblitDb.php');

openlog('vidblit-php', LOG_PID, LOG_USER);
syslog(LOG_INFO, 'Starting sequence to get request status');

# get input values
$headers = array('HTTP_X_DESCRIPTION' => NULL);
$result = GetHeaders($headers);
if(!$result['ok'])
{
    finish(false, $config['messages']['BadRequest']);
}
$headers = $result['result'];
$gets = array('id' => NULL);
$result = GetGetParams($gets);
if(!$result['ok'])
{
    finish(false, $config['messages']['BadRequest']);
}
$gets = $result['result'];

$token = $headers['HTTP_X_DESCRIPTION'];
$requestid = $gets['id'];

# setup db connection
$db = new VidblitDb($config['database']['vidblit']['host'], $config['database']['vidblit']['user'], $config['database']['vidblit']['pwd'], $config['database']['vidblit']['db']);
$result = $db->connect();
if(!$result['ok'])
{
    finish(false, $config['messages']['TechFail']);
}

# validate user
$result = $db->user_id_bytoken($token);
if(!$result['ok'] || is_null($result['result']))
{
    syslog(LOG_WARNING, 'Db problem or bad user - dying');
    finish(false, $config['messages']['BadRequest']);
}
$userid = $result['result']['id'];

# get request status from  db
$result = $db->request_status($userid, $requestid);
if(!$result['ok'] || is_null($result['result']))
{
    syslog(LOG_WARNING, "A request for user $userid and id $requestid was not found - dying");
    finish(false, $config['messages']['BadRequest']);
}

finish(true, $result['result']);

closelog();
?>