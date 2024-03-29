<?php
class VidblitDB
{
    private $_host = NULL;
    private $_user = NULL;
    private $_pwd = NULL;
    private $_db = NULL;
    private $_con = NULL;

    function __construct($host, $user, $pwd, $db)
    {
        syslog(LOG_INFO, 'Creating VidblitDb');
        $this->_host = $host;
        $this->_user = $user;
        $this->_pwd = $pwd;
        $this->_db = $db;
    }

    # ------------------ connection ------------------
    function connect()
    {
        syslog(LOG_INFO, 'Connecting VidblitDb');
        $this->_con = mysqli_connect($this->_host, $this->_user, $this->_pwd, $this->_db);
        if(mysqli_connect_errno())
        {
            return $this->mysql_connect_error();
        }
        return $this->result(true);
    }

    # ------------------ functionalities ------------------
    function request_create($id, $url)
    {
        syslog(LOG_INFO, "Calling VidblitDb:request_create with param:id=$id, url=$url");
        $sql_url = $this->_con->real_escape_string($url);
        $sql = "CALL create_request('$id', '$sql_url');";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $object = $result->fetch_object();
        $this->mysql_clean_buffer();
        syslog(LOG_INFO, 'Got result:'.$object->requestid); 
        $value = array('id' => $object->requestid);
        return $this->result(true, $value);
    }

    function request_status($userid, $requestid)
    {
        syslog(LOG_INFO, "Calling VidblitDb:request_status with param:userid=$userid, requestid=$requestid");
        $sql_requestid = $this->_con->real_escape_string($requestid);
        $sql_userid = $this->_con->real_escape_string($userid);
        $sql = "SELECT e.type, e.title, e.length, e.error, e.location from request r left join extract e on r.extractid=e.id where r.id=$sql_requestid and r.userid=$sql_userid";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $object = $result->fetch_object();
        $value = NULL;
        if($object)
        {
            syslog(LOG_INFO, 'Got result'); 
            $value = array('type' => $object->type, 'title' => $object->title, 'length' => $object->length, 'error' => $object->error, 'available' => !is_null($object->location));
        }
        return $this->result(true, $value);
    }

    function session_create($userid, $token)
    {
        syslog(LOG_INFO, "Calling VidblitDb:session_create with param: userid=$userid, token=$token"); 
        $sql_userid = $this->_con->real_escape_string($userid);
        $sql_token = $this->_con->real_escape_string($token);
        $sql = "CALL create_session('$sql_userid', '$sql_token');";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $this->mysql_clean_buffer();
        return $this->result(true);
    }

    function session_kill($token)
    {
        syslog(LOG_INFO, "Calling VidblitDb:session_kill with param:token=$token"); 
        $sql_token = $this->_con->real_escape_string($token);
        $sql = "CALL kill_session('$sql_token');";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $this->mysql_clean_buffer();
        return $this->result(true);
    }

    function user_create($user, $salt, $hash, $email)
    {
        syslog(LOG_INFO, "Calling VidblitDb:user_create with param: user=$user, salt=$salt, hash=$hash, email=$email"); 
        $sql_user = $this->_con->real_escape_string($user);
        $sql_salt = $this->_con->real_escape_string($salt);
        $sql_hash = $this->_con->real_escape_string($hash);
        $sql_email = $this->_con->real_escape_string($email);
        $sql = "CALL create_user('$sql_user', '$sql_salt', '$sql_hash', '$sql_email');";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $object = $result->fetch_object();
        $this->mysql_clean_buffer();
        $value = array('id' => $object->userid);
        return $this->result(true, $value);
    }

    function user_id_bytoken($token)
    {
        syslog(LOG_INFO, "Calling VidblitDb:user_id_bytoken with param:token=$token"); 
        $sql_token = $this->_con->real_escape_string($token);
        $sql = "CALL get_session_userid('$sql_token');";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $object = $result->fetch_object();
        $this->mysql_clean_buffer();

        $value = NULL;
        if($object)
        {
            syslog(LOG_INFO, 'Got result:'.$object->userid); 
            $value = array('id' => $object->userid);
        }
        return $this->result(true, $value);
    }

    function user_id_byemail($email)
    {
        syslog(LOG_INFO, "Calling VidblitDb:user_id_byemail with param: email=$email"); 
        $sql_email = $this->_con->real_escape_string($email);
        $sql = "select id from user where email='$sql_email';";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $object = $result->fetch_object();
        $value = NULL;
        if($object)
        {
            syslog(LOG_INFO, 'Got result:'.$object->id); 
            $value = array('id' => $object->id);
        }
        return $this->result(true, $value);
    }
    
    function extract_hostnlen_byid($id)
    {
        syslog(LOG_INFO, "Calling VidblitDb:extract_hostnlen_byid with param:id=$id"); 
        $sql_id = $this->_con->real_escape_string($id);
        $sql = "select length, locationhost from extract where id='$sql_id';";
        $result = $this->_con->query($sql);
        if(!$result)
        {
            return $this->mysql_error();
        }
        $object = $result->fetch_object();
        $this->mysql_clean_buffer();

        $value = NULL;
        if($object)
        {
            syslog(LOG_INFO, 'Got result: '.$object->length .','.$object->locationhost); 
            $value = array(
                    'length' => $object->length,
                    'host' => $object->locationhost);
        }
        return $this->result(true, $value);
    }

    # ------------------ utilities ------------------
    function mysql_clean_buffer()
    {
        syslog(LOG_INFO, 'Calling VidblitDb:mysql_clean_buffer'); 
        while ($this->_con->more_results())
        {
            $this->_con->next_result();
            $result = $this->_con->store_result();
            if ($result instanceof mysqli_result) 
            {
                $result->free();
            }
        }
    }

    # ------------------ error ------------------
    function mysql_connect_error()
    {
        syslog(LOG_ERR, 'Vidblit SQL Connection Error: ' . mysqli_connect_error());
        return $this->result(false);
    }

    function mysql_error()
    {
        syslog(LOG_ERR, 'Vidblit SQL Error: ' . mysqli_error($this->_con));
        return $this->result(false);
    }

    # ------------------ result ------------------
    function result($ok, $value=NULL)
    {
        syslog(LOG_INFO, 'Calling VidblitDb:result'); 
        $result = array('ok' => $ok, 'result' => $value);
        return $result;
    }
}

// function show($line)
// {
//     echo "<br/>$line<br/>";
// }

// $sql_host = '167.88.34.62';
// $sql_user = 'Brun0';
// $sql_password = '65UB3b3$';
// $sql_db = 'vidblit';

// $db = new VidblitDB($sql_host, $sql_user, $sql_password, $sql_db);
// $db->connect();

// # -------------------------------------- user_id_bytoken
// show("");
// show("Testing user_id_bytoken with bad token");
// $result = $db->user_id_bytoken('badtoken');
// var_dump($result);

// show("Testing user_id_bytoken with valid token");
// $result = $db->user_id_bytoken('243279243130243232363766313336353335396364343335663731317575316f456c43344b6c695249573357646976554855326a7951676c4c544132');
// var_dump($result);

// # -------------------------------------- extract_hostnlen_byid
// show("");
// show("Testing extract_hostnlen_byid with invalid id");
// $result = $db->extract_hostnlen_byid(111111);
// var_dump($result);

// show("Testing extract_hostnlen_byid with valid id and null host");
// $result = $db->extract_hostnlen_byid(11);
// var_dump($result);

// show("Testing extract_hostnlen_byid with valid id and host");
// $result = $db->extract_hostnlen_byid(12);
// var_dump($result);

?>