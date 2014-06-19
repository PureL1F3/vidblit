<?php

include_once('config.php');
include_once('utility.php');
include_once('VidblitDb.php');

openlog('vidblit-php', LOG_PID, LOG_USER);
syslog(LOG_INFO, 'Starting sequence to log out user');

# get input values
$headers = array('HTTP_X_DESCRIPTION' => NULL);
$result = GetHeaders($headers);
if(!$result['ok'])
{
    finish(false, $config['messages']['BadRequest']);
}
$headers = $result['result'];

$token = $headers['HTTP_X_DESCRIPTION'];

# setup db connection
$db = new VidblitDb($config['database']['vidblit']['host'], $config['database']['vidblit']['user'], $config['database']['vidblit']['pwd'], $config['database']['vidblit']['db']);
$result = $db->connect();
if(!$result['ok'])
{
    finish(false, $config['messages']['TechFail']);
}

# validate user
$result = $db->session_kill($token);
if(!$result['ok'])
{
    syslog(LOG_WARNING, 'Db problem or bad user - dying');
    finish(false, $config['messages']['BadRequest']);
}

finish(true, $result['result']);

closelog();
?>