<?php
require_once __DIR__ . '/vendor/autoload.php';

function GetHeaders($headers)
{
    foreach($headers as $name => $value)
    {
        if(!isset($_SERVER[$name]))
        {
            syslog(LOG_WARNING, "Bad request - missing required header $name");
            return result(false);
        }
        $headers[$name] =  $_SERVER[$name];
    }
    return result(true, $headers);
}

function GetPostParams($posts)
{
    foreach($posts as $name => $value)
    {
        if(!isset($_POST[$name]))
        {
            syslog(LOG_WARNING, "Bad request - missing required post parameter $name");
            return result(false);
        }
        $posts[$name] =  $_POST[$name];
    }
    return result(true, $posts);
}

function GetGetParams($gets)
{
    foreach($gets as $name => $value)
    {
        if(!isset($_GET[$name]))
        {
            syslog(LOG_WARNING, "Bad request - missing required get parameter $name");
            return result(false);
        }
        $gets[$name] =  $_GET[$name];
    }
    return result(true, $gets);
}

function result($ok, $value=NULL)
{
    $result = array('ok' => $ok, 'result' => $value);
    return $result;
}

function finish($ok=false, $value=NULL)
{
    $result = array('ok' => $ok, 'result' => $value);
    echo json_encode($result);
    exit();
}

function show($line)
{
    echo "<br/>$line<br/>";
}

function reg_validated_user($user)
{
    $user = trim($user);
    $len = strlen($user);
    if($len < REGISTRATION_USER_MINCHAR || $len > REGISTRATION_USER_MAXCHAR)
    {
        $user = NULL;
    }
    return $user;
}

function reg_validated_email($email)
{
    $email = trim($email);
    if(!filter_var($email,  FILTER_VALIDATE_EMAIL))
    {
        $email = NULL;
    }
    return $email;
}

function reg_validated_password($pwd)
{
    $len = strlen($pwd);
    if($len < REGISTRATION_PWD_MINCHAR || $len > REGISTRATION_PWD_MAXCHAR)
    {
        $pwd = NULL;
    }

    return $pwd;
}

function reg_saltnhash($pwd)
{
    syslog(LOG_INFO, 'utility->reg_saltnhash: Generating salt and hash for password');
    $salt = bin2hex(mcrypt_create_iv(PWD_HASH_LENGTH, MCRYPT_DEV_URANDOM));
    $hash = password_hash($pwd, PASSWORD_BCRYPT, array("salt" => $salt));

    return result(true, array('salt' => $salt, 'hash' => $hash));
}

function session_token($userid)
{
    syslog(LOG_INFO, 'utility->session_token: Generating session token');
    $time = time();
    $sessionid = session_id();
    $salt = bin2hex(mcrypt_create_iv(PWD_HASH_LENGTH, MCRYPT_DEV_URANDOM));
    $sessiontoken = bin2hex(password_hash("$userid/$time/$sessionid", PASSWORD_BCRYPT, array("salt" => $salt)));
    if(strlen($sessiontoken) > 600)
    {
        $sessiontoken = substr($session, 0, 600);
    }
    return $sessiontoken;
}

?>