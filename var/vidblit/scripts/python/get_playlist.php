<?php

if(!isset($_GET['id']))
{
    exit();
}

$id = $_GET['id'];
$file = "/var/vidblit/videos/extracts/$id/playlist.m3u8";
if(!file_exists($file))
{
    exit();
}

readfile($file);
?>