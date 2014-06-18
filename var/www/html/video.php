<?php

function getIp()
{
    if (isset($_SERVER)) {

        if (isset($_SERVER["HTTP_X_FORWARDED_FOR"]))
            return $_SERVER["HTTP_X_FORWARDED_FOR"];

        if (isset($_SERVER["HTTP_CLIENT_IP"]))
            return $_SERVER["HTTP_CLIENT_IP"];

        return $_SERVER["REMOTE_ADDR"];
    }

    else return '0.0.0.0';
}

function getSignedURL($resource, $timeout)
{
    //This comes from key pair you generated for cloudfront
    $keyPairId = "APKAJ4OLBHAH22L5KMLQ";

    $expires = time() + $timeout; //Time out in seconds
    $json = '{"Statement":[{"Resource":"'.$resource.'","Condition":{"DateLessThan":{"AWS:EpochTime":'.$expires.'}}}]}';     
    
    //Read Cloudfront Private Key Pair
    $fp=fopen("/pk.pem","r"); 
    $priv_key=fread($fp,8192); 
    fclose($fp); 

    //Create the private key
    $key = openssl_get_privatekey($priv_key);
    if(!$key)
    {
        echo "<p>Failed to load private key!</p>";
        return;
    }
    
    //Sign the policy with the private key
    if(!openssl_sign($json, $signed_policy, $key, OPENSSL_ALGO_SHA1))
    {
        echo '<p>Failed to sign policy: '.openssl_error_string().'</p>';
        return;
    }
    
    //Create url safe signed policy
    $base64_signed_policy = base64_encode($signed_policy);
    $signature = str_replace(array('+','=','/'), array('-','_','~'), $base64_signed_policy);

    //Construct the URL
    $url = $resource.'?Expires='.$expires.'&Signature='.$signature.'&Key-Pair-Id='.$keyPairId;
    
    return $url;
}
// function getSignedURL($ip, $file, $timeout)
// {
//     //This comes from key pair you generated for cloudfront
//     $keyPairId = "APKAJ4OLBHAH22L5KMLQ";

//     $distribution = 'http://dizrdglcercvd.cloudfront.net';
//     $resource = $distribution . '/*';

//     echo "Resource: $resource \n";

//     $expires = time() + $timeout; //Time out in seconds

//     $policy = array('Statement' => array(array(
//             'Resource' => $resource
//             //,
//             // 'Condition' => array(
//             //     'DateLessThan' => array(
//             //             'AWS:EpochTime' => $expires
//             //         ),
//             //     'IpAddress' => array(
//             //             'aws:SourceIp' => '5.5.6.7'
//             //         )
//             //     )
//         )));
//     $json = json_encode($policy);

//     echo "Policy: $policy \n";
//     echo "Json: $json \n";
    
//     //Read Cloudfront Private Key Pair
//     $fp=fopen("/pk.pem","r"); 
//     $priv_key=fread($fp,8192); 
//     fclose($fp); 

//     //Create the private key
//     $key = openssl_get_privatekey($priv_key);
//     if(!$key)
//     {
//         echo "<p>Failed to load private key!</p>";
//         return;
//     }
    
//     //Sign the policy with the private key
//     if(!openssl_sign($json, $signed_policy, $key, OPENSSL_ALGO_SHA1))
//     {
//         echo '<p>Failed to sign policy: '.openssl_error_string().'</p>';
//         return;
//     }
    
//     //Create url safe signed policy
//     $base64_signed_policy = base64_encode($signed_policy);
//     $signature = str_replace(array('+','=','/'), array('-','_','~'), $base64_signed_policy);

//     //Construct the URL
//     $url = $distribution . $file .'?Expires='.$expires.'&Signature='.$signature.'&Key-Pair-Id='.$keyPairId;
    
//     return $url;
// }

// $ip = getIp();
$url = getSignedURL('http://d3oqotq78cv8kq.cloudfront.net/extracts/16/out001.ts', 1000);
echo $url;

?>