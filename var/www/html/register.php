<?php

include_once('config.php');
include_once('utility.php');
include_once('VidblitDb.php');

session_start();

openlog('vidblit-php', LOG_PID, LOG_USER);
syslog(LOG_INFO, 'Starting sequence to register user');

$posts = array('user' => NULL, 'pwd' => NULL, 'email' => NULL);
$result = GetPostParams($posts);
if(!$result['ok'])
{
    finish(false, $config['messages']['BadRequest']);
}
$posts = $result['result'];

$user = reg_validated_user($posts['user']);
$pwd = reg_validated_password($posts['pwd']);
$email = reg_validated_email($posts['email']);
if(is_null($user))
{
    finish(false, $config['messages']['BadUser']);
}
if(is_null($pwd))
{
    finish(false, $config['messages']['BadPwd']);
}
if(is_null($email))
{
    finish(false, $config['messages']['BadEmail']);
}

# setup db connection
$db = new VidblitDb($config['database']['vidblit']['host'], $config['database']['vidblit']['user'], $config['database']['vidblit']['pwd'], $config['database']['vidblit']['db']);
$result = $db->connect();
if(!$result['ok'])
{
    finish(false, $config['messages']['TechFail']);
}

# validate user
$result = $db->user_id_byemail($email);
if(!$result['ok'])
{
    syslog(LOG_WARNING, 'Db problem - dying');
    finish(false, $config['messages']['TechFail']);
}
if(!is_null($result['result']))
{
    syslog(LOG_WARNING, 'Duplicate user email - dying');
    finish(false, $config['messages']['DuplicateEmail']);
}

$result = reg_saltnhash($pwd);
$salt = $result['result']['salt'];
$hash = $result['result']['hash'];

$result = $db->user_create($user, $salt, $hash, $email);
if(!$result['ok'])
{
    syslog(LOG_WARNING, 'Db problem - dying');
    finish(false, $config['messages']['TechFail']);
}
$userid = $result['result']['id'];
$token = session_token($userid);
$result = $db->session_create($userid, $token);
if(!$result['ok'])
{
    syslog(LOG_WARNING, 'Db problem - dying');
    finish(false, $config['messages']['TechFail']);
}

header("X-Description: $token");
finish(true);
closelog();
?>