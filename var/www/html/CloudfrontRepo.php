<?php

class CloudfrontRepo
{
    private $aws_keypair_id = NULL;
    private $aws_keypem_path = NULL;
    function __construct($aws_keypair_id, $aws_keypem_path)
    {
        syslog(LOG_INFO, "Creating CloudfrontRepo"); 
        $this->aws_keypair_id = $aws_keypair_id;
        $this->aws_keypem_path = $aws_keypem_path;
    }

    function expiry($timeout_s)
    {
        syslog(LOG_INFO, "Calling CloudfrontRepo:expiry with param:timeout=$timeout_s"); 
        return time() + $timeout_s;
    }

    function signed_url($resource, $expires)
    {
        syslog(LOG_INFO, "Calling CloudfrontRepo:signed_url with params: resource=$resource , expires=$expires"); 
        //This comes from key pair you generated for cloudfront
        $keyPairId = $this->aws_keypair_id;
        
        $json = '{"Statement":[{"Resource":"'.$resource.'","Condition":{"DateLessThan":{"AWS:EpochTime":'.$expires.'}}}]}';     
        //Read Cloudfront Private Key Pair
        $fp=fopen($this->aws_keypem_path,"r"); 
        $priv_key=fread($fp,8192); 
        fclose($fp); 

        //Create the private key
        $key = openssl_get_privatekey($priv_key);
        if(!$key)
        {
            syslog(LOG_ERR, "CloudfrontRepo:signed_url  - could not get private key"); 
            return $this->result(false);
        }
        
        //Sign the policy with the private key
        if(!openssl_sign($json, $signed_policy, $key, OPENSSL_ALGO_SHA1))
        {
            syslog(LOG_ERR, "CloudfrontRepo:getSignedURL  - failed to sign policy: ".openssl_error_string()); 
            return $this->result(false);
        }
        
        //Create url safe signed policy
        $base64_signed_policy = base64_encode($signed_policy);
        $signature = str_replace(array('+','=','/'), array('-','_','~'), $base64_signed_policy);

        //Construct the URL
        $url = $resource.'?Expires='.$expires.'&Signature='.$signature.'&Key-Pair-Id='.$keyPairId;
        
        return $this->result(true, $url);
    }

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

// $repo = new CloudfrontRepo("APKAJ4OLBHAH22L5KMLQ", "/pk.pem");

// show("");
// show("Testing expiry");
// $result = $repo->expiry(100);
// var_dump($result);

// show("");
// show("Testing signed_url");
// $result = $repo->signed_url('http://d3oqotq78cv8kq.cloudfront.net/extracts/16/playlist.m3u8', $result);
// var_dump($result);

// $repo = new CloudfrontRepo("APKAJ4OLBHAH22L5KMLQ", "/");
// show("");
// show("Testing signed_url with missing pem");
// $result = $repo->signed_url('http://d3oqotq78cv8kq.cloudfront.net/extracts/16/playlist.m3u8', $result);
// var_dump($result);

?>