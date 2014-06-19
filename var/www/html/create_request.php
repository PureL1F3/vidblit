<?php
include_once('config.php');
include_once('utility.php');
include_once('VidblitDb.php');
include_once('VidblitRabbit.php');

openlog('vidblit-php', LOG_PID, LOG_USER);
syslog(LOG_INFO, 'Starting sequence to create new request');

# get input values
$headers = array('HTTP_X_DESCRIPTION' => NULL);
$result = GetHeaders($headers);
if(!$result['ok'])
{
    finish(false, $config['messages']['BadRequest']);
}
$headers = $result['result'];
$posts = array('url' => NULL);
$result = GetPostParams($posts);
if(!$result['ok'])
{
    finish(false, $config['messages']['BadRequest']);
}
$posts = $result['result'];

$token = $headers['HTTP_X_DESCRIPTION'];
$url = $posts['url'];

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
    finish(false, $config['messages']['TechFail']);
}
$userid = $result['result']['id'];

# create request in db
$result = $db->request_create($userid, $url);
if(!$result['ok'])
{
    syslog(LOG_WARNING, 'Failed to create request - dying');
    finish(false, $config['messages']['TechFail']);
}
$requestid = $result['result']['id'];

# create request to extract in rabbit
$rabbit = new VidblitRabbit($config['rabbit']['connection']['host'], $config['rabbit']['connection']['port'], $config['rabbit']['connection']['user'], $config['rabbit']['connection']['pwd'], $config['rabbit']['q']);
$rabbit->connect();
$rabbit->publish_extract_msg($requestid, $url);

finish(true, array('id' => $requestid));

closelog();
?>