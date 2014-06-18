<?php

session_start();


require './vendor/autoload.php';

$sql_host = '167.88.34.62';
$sql_user = 'Brun0';
$sql_password = '65UB3b3$';
$sql_db = 'vidblit';

$MIN_USERNAME_LENGTH = 1;
$MAX_USERNAME_LENGTH = 12;
$MIN_PASSWORD_LENGTH = 6;
$MAX_PASSWORD_LENGTH = 12;

openlog('vidblit-php', LOG_PID, LOG_USER);

syslog(LOG_INFO, 'Starting sequence to register user');
#check if we have all correct inputs
if(!isset($_POST['username']) && !isset($_POST['password']) && !isset($_POST['email']))
{
    syslog(LOG_WARNING, 'User error : received bad request with empty userid, url, email');
    $result = array('error' => 'Bad request');
    echo json_encode($result);
    exit();
}

$username = trim($_POST['username']); #username can be any string -> min 1 char, max 12 char
$email = trim($_POST['email']); #email needs to be validated -> remove whitespace on it first
$pwd = $_POST['password']; # password can be any string -> make it min 6 characters

syslog(LOG_INFO, 'Checking username length requirement');
if(strlen($username) < $MIN_USERNAME_LENGTH || strlen($username) > $MAX_USERNAME_LENGTH)
{
    syslog(LOG_WARNING, 'User error : username is too long / short');
    $result = array('error' => 'Username must be ' . $MIN_USERNAME_LENGTH . '-' . $MAX_USERNAME_LENGTH . ' characters');
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Checking password length requirement');
if(strlen($pwd) < $MIN_PASSWORD_LENGTH || strlen($pwd) > $MAX_PASSWORD_LENGTH)
{
    syslog(LOG_WARNING, 'User error : password is too long / short');
    $result = array('error' => 'Password must be ' . $MIN_PASSWORD_LENGTH . '-' . $MAX_PASSWORD_LENGTH . ' characters');
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Checking if email is valid');
if (!filter_var($email,  FILTER_VALIDATE_EMAIL)) {
    syslog(LOG_WARNING, 'User error : invalid email address');
    $result = array('error' => 'Invalid email');
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Connecting to mysql');
$con = mysqli_connect($sql_host,$sql_user,$sql_password,$sql_db);
if (mysqli_connect_errno())
{
    syslog(LOG_ERR, 'Error connecting to mysql db:' . mysqli_connect_error());
    $result =  array('error' => 'Technical difficulties' );
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Checking if email already exist');
$sql_email = $con->real_escape_string($email);
$sql = "select id from user where email='$sql_email'";
$result = $con->query($sql);
if(!$result)
{
    syslog(LOG_ERR, 'Error looking up email in mysql' . mysqli_error());
    $result =  array('error' => 'Technical difficulties' );
    echo json_encode($result);
    exit();
}
$value = $result->fetch_object();
if($value)
{
    syslog(LOG_WARNING, 'Bad request - the email already exists in users');
    $result =  array('error' => 'Email already registered' );
    echo json_encode($result);
    exit();
}

syslog(LOG_INFO, 'Generating salt and hash for password');
$password_hash_length = 22;
$salt = bin2hex(mcrypt_create_iv($password_hash_length, MCRYPT_DEV_URANDOM));
$hash = password_hash($pwd, PASSWORD_BCRYPT, array("salt" => $salt));

syslog(LOG_INFO, 'Creating user in mysql');
$sql_username = $con->real_escape_string($username);
$sql = "CALL create_user('$sql_username', '$salt', '$hash', '$email');";
$result = $con->query($sql);
if(!$result)
{
    syslog(LOG_ERR, 'Error creating user:' . mysqli_error($con));
    $result =  array('error' => 'Technical difficulties' );
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

syslog(LOG_INFO, 'Generating session token');
$time = time();
$sessionid = session_id();
$salt = bin2hex(mcrypt_create_iv($password_hash_length, MCRYPT_DEV_URANDOM));
$sessiontoken = bin2hex(password_hash("$userid/$time/$sessionid", PASSWORD_BCRYPT, array("salt" => $salt)));
if(strlen($sessiontoken) > 600)
{
    $sessiontoken = substr($session, 0, 600);
}

syslog(LOG_INFO, 'Saving session token to mysql');
$sql = "CALL create_session($userid, '$sessiontoken');";
$result = $con->query($sql);
if(!$result)
{
    syslog(LOG_ERR, 'Error saving session token to mysql:' . mysqli_error($con));
    $result =  array('error' => 'Technical difficulties' );
    echo json_encode($result);
    exit();
}
while ($con->more_results())
{
    $con->next_result();
    $result = $con->store_result();
    if ($result instanceof mysqli_result) 
    {
        $result->free();
    }
}
mysqli_close($con);

syslog(LOG_INFO, 'Returning successful user registration');
header("X-Description: $sessiontoken");

$result = array('success' => true);
echo json_encode($result);

closelog();
?>