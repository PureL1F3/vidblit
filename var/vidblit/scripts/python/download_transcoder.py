import logging
import os
import shutil
import subprocess
import time
import sys
import json
import mysql.connector
import syslog
import pika
import threading
import traceback
import socket

FFMPEG_CREATE_WAIT = 0.2 # wait from when create request to get m3u8 playlist file
FFMPEG_MAX_WAIT_FOR_PLAYLIST = 20

#logging utility
def log(log_type, log_msg):
    syslog.syslog(log_type, '{0}:{1}'.format(threading.currentThread().getName(), log_msg))
def log_long(log_type, log_msg):
    msg_max = 400
    msg_len = len(log_msg)
    chunks = int(msg_len / msg_max)
    for i in range(chunks):
        startindex = i * msg_max
        remaining_len = msg_len - startindex
        endindex = (i + 1) * msg_max if remaining_len > 0 else msg_len
        syslog.syslog(log_type, '- {0}:{1}'.format(threading.currentThread().getName(), msg[startindex : endindex]))

def get_ip_addr():
    log(syslog.LOG_INFO, 'Getting local ip address')
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.connect(('google.com', 0))
    ip = s.getsockname()[0]
    return ip

def get_request_params(msg):
    log(syslog.LOG_INFO, 'Getting request params from msg')
    request = json.loads(msg)
    request_type = request['type']
    request_id = request['id']
    request_url = request['url']
    request_proxy = request['proxy']
    return (request_type, request_id, request_url, request_proxy)

def FinishWithError(requestid, error):
    log(syslog.LOG_INFO, 'Updating database of failed download transcoding')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    args = (requestid, 'ERROR', error)
    cursor.callproc('request_error', args)
    cnx.close()
def UpdateRequestPlaylistCreated(requestid, url):
    log(syslog.LOG_INFO, 'Updating database of successful extraction playlist creation')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    hostname = get_ip_addr()
    args = (hostname, requestid, url)
    cursor.callproc('update_extract_location', args)
    cnx.close()

def CreateDirectoryForVideo(id):
    directory = '{0}/extracts/{1}'.format(video_base_directory, id)
    log(syslog.LOG_INFO, 'Creating directory for video at {0}'.format(directory))
    if os.path.exists(directory):
        log(syslog.LOG_INFO, 'Removing existing')
        shutil.rmtree(directory)
    os.makedirs(directory)
    return directory
def TryFFMPEG(requestid, request_url, directory, request_proxy):
    os.chdir(directory)

    env = os.environ
    env['http_proxy'] = '{0}:{1}'.format(request_proxy['ip'], request_proxy['port'])

    dest_playlist_path = 'playlist.m3u8'
    dest_segmentfmt_path = 'out%03d.ts'
    ffmpeg_cmd = r'ffmpeg -i "{0}" -map 0 -codec:v libx264 -codec:a libfaac -f ssegment -segment_list {1} -segment_list_flags +cache -segment_time 10 {2}'.format(request_url, dest_playlist_path, dest_segmentfmt_path)
    log(syslog.LOG_INFO, 'Running: {0}'.format(ffmpeg_cmd))
    p = subprocess.Popen(ffmpeg_cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, shell=True, env=env)

    dest_playlist_path = '{0}/{1}'.format(directory, dest_playlist_path)
    playlistAvailable = False
    total_wait = 0
    while not playlistAvailable:
        time.sleep(FFMPEG_CREATE_WAIT)
        if os.path.exists(dest_playlist_path):
            UpdateRequestPlaylistCreated(requestid, dest_playlist_path)
            playlistAvailable = True
            break;

        total_wait += FFMPEG_CREATE_WAIT
        if total_wait >= FFMPEG_MAX_WAIT_FOR_PLAYLIST:
            p.kill()
            FinishWithError(requestid, "Could not process video via ffmpeg")
            return False

    log(syslog.LOG_INFO, 'Allowing ffmpeg to finish but relieving this thread')
    return True

def OnDownloadTranscoderMessage(ch, method, properties, body):
    try:
        log(syslog.LOG_INFO, 'Received extract request')
        request_type, request_id, request_url, request_proxy = get_request_params(body)
        directory = CreateDirectoryForVideo(request_id)
        if TryFFMPEG(request_id, request_url, directory, request_proxy):
            ch.basic_ack(delivery_tag = method.delivery_tag)
        else:
            ch.basic_nack(delivery_tag = method.delivery_tag)
    except:
        log(syslog.LOG_WARNING, 'Oops something bad happened with download transcoding:')
        log_long(syslog.LOG_WARNING, '{0}'.format(traceback.print_exc()))
        ch.basic_nack(delivery_tag = method.delivery_tag)

def StartRabbitConsumer():
    rabbit_credentials = pika.PlainCredentials(rabbit_user, rabbit_pwd)
    rabbit_parameters = pika.ConnectionParameters(rabbit_host, rabbit_port, '/', rabbit_credentials)
    rabbit_connection = pika.BlockingConnection(rabbit_parameters)
    rabbit_extractor_channel = rabbit_connection.channel()
    rabbit_extractor_channel.basic_qos(prefetch_count=1)
    rabbit_extractor_channel.basic_consume(OnDownloadTranscoderMessage, queue=rabbit_q_dl_transcoder)
    rabbit_extractor_channel.start_consuming()

syslog.openlog('extractor', syslog.LOG_PID, syslog.LOG_USER)

rabbit_user = 'guest'
rabbit_pwd = 'guest'
rabbit_host = 'localhost'
rabbit_port = 5672
rabbit_url = 'amqp://{0}:{1}@{2}:{3}/'.format(rabbit_user, rabbit_pwd, rabbit_host, rabbit_port)
rabbit_q_extractor = 'extractor'
rabbit_q_dl_transcoder = 'downloadtranscoder'

# setup mysql connection
db_host = '167.88.34.62'
db_user = 'Brun0'
db_pwd = '65UB3b3$'
db_name = 'vidblit'

video_base_directory = '/var/vidblit/videos'
max_threads = 10
for i in range(max_threads):
    t = threading.Thread(name='T[{0}]'.format(i + 1), target=StartRabbitConsumer)
    log(syslog.LOG_INFO, 'Starting consumer thread {0}'.format(t.getName()))
    t.start()