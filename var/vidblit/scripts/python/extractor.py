#http://ubuntuforums.org/showthread.php?t=1932389
import os
os.environ['http_proxy']=''

import youtube_dl
import logging
import sys
import os
import traceback
import mysql.connector
import json
import syslog
import boto
import pika
import threading
import urllib2
import subprocess

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

#request utility
def get_ydl(params):
    log(syslog.LOG_INFO, 'Setting up ydl')
    ydl = youtube_dl.YoutubeDL(params)
    ydl.add_default_info_extractors()
    return ydl
def get_request_params(msg):
    log(syslog.LOG_INFO, 'Getting request params from msg')
    request = json.loads(msg)
    requestid = request['id']
    url = request['url']
    return (requestid, url)
def get_suitable_extractor(ydl, url):
    log(syslog.LOG_INFO, 'Looking for a suitable extractor')
    suitable_extractors = ('youtube', 'vine', 'vimeo', 'liveleak')
    iekey = None
    for ie in ydl._ies:
        iekey = ie.ie_key()
        if ie.suitable(url) and iekey.lower() in suitable_extractors:
            log(syslog.LOG_INFO, 'Found suitable extractor: {0}'.format(iekey))
            break;
    return iekey
def get_extraction_result(ydl, extractor_key, url):
    log(syslog.LOG_INFO, 'Performing info extraction')
    result = ydl.extract_info(url, download=False, ie_key=extractor_key)
    return result
def GetSmallestUrl(extractor, result):
    log(syslog.LOG_INFO, 'Looking for smallest video url')
    extractor = extractor.lower()
    url = None
    if extractor == 'vine':
        url = GetVineSmallestUrl(result)
    elif extractor == 'youtube':
        url = GetYoutubeSmallestUrl(result)
    elif extractor == 'vimeo':
        url = GetVimeoSmallestUrl(result)

    if url is None:
        url = result['url']
    return url
def GetYoutubeSmallestUrl(result):
    smallest_url = None
    try:
        good_format_ids = ("36", "5", "18")
        good_formats = {}
        result_formats = result['formats']
        for f in result_formats:
            if f['format_id'] in good_format_ids:
                good_formats[f['format_id']] = f['url']
        for f in good_format_ids:
            if f in good_formats.keys():
                smallest_url = good_formats[f]
                log(syslog.LOG_INFO, 'Found smallest youtube url - fmt {0}'.format(f))
                break
    except:
        log(syslog.LOG_WARNING, 'Error looking for smallest youtube url:')
        log_long(syslog.LOG_WARNING, '{0}'.format(traceback.print_exc()))
    return smallest_url    
def GetVineSmallestUrl(result):
    smallest_url = None
    try:
        result_formats = result['formats']
        smallest_url = None
        for f in result_formats:
            if f['format_id'] == 'low':
                smallest_url = f['url']
                log(syslog.LOG_INFO, 'Found smallest vine url - fmt {0}'.format(f))           
                break
    except:
        log(syslog.LOG_WARNING, 'Error looking for smallest youtube url:')
        log_long(syslog.LOG_WARNING, '{0}'.format(traceback.print_exc()))
    return smallest_url
def GetVimeoSmallestUrl(result):
    smallest_url = None
    try:
        result_formats = result["formats"]
        smallest_format = None
        smallest_area = 1000000000
        smallest_url = None
        for f in result_formats:
            area = f['height'] * f['width']
            if area < smallest_area:
                smallest_format = f
                smallest_area = area
                smallest_url = f['url']
                log(syslog.LOG_INFO, 'Found smallest vimeo url - fmt {0}'.format(f))
    except:
        log(syslog.LOG_WARNING, 'Error looking for smallest vimeo url:')
        log_long(syslog.LOG_WARNING, '{0}'.format(traceback.print_exc()))
    return smallest_url

def GetVideoLength(proxy_ip, proxy_port, url):
    env = os.environ
    env['http_proxy'] = '{0}:{1}'.format(proxy_ip, proxy_port)
    ffprobe_cmd = 'ffprobe "{0}" -show_format -v quiet | sed -n \'s/duration=//p\''.format(url)
    log(syslog.LOG_INFO, 'Getting video length: {0}'.format(ffprobe_cmd))
    p = subprocess.Popen(ffprobe_cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, shell=True, env=env)
    out, err = p.communicate()
    length = int(float(out))
    return length

def FinishWithError(requestid, error):
    log(syslog.LOG_INFO, 'Updating database of failed extraction')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    args = (requestid, error)
    cursor.callproc('update_request_error', args)
    cnx.close()

def PublishTranscodeMessage(extractid, type, url, proxytype, proxyip, proxyport):
    log(syslog.LOG_INFO, 'Publishing transcode message')
    proxy = {'type': proxytype, 'ip': proxyip, 'port': proxyport}
    transcode_msg = {'id' : extractid, 'type' : type, 'url' : url, 'proxy': proxy}
    transcode_msg = json.dumps(transcode_msg)
    rabbit_credentials = pika.PlainCredentials(rabbit_user, rabbit_pwd)
    rabbit_parameters = pika.ConnectionParameters(rabbit_host, rabbit_port, '/', rabbit_credentials)
    rabbit_connection = pika.BlockingConnection(rabbit_parameters)
    rabbit_dl_transcoder_channel = rabbit_connection.channel()
    rabbit_dl_transcoder_channel.queue_declare(queue=rabbit_q_dl_transcoder)
    rabbit_dl_transcoder_channel.basic_publish(exchange='', routing_key=rabbit_q_dl_transcoder, body=transcode_msg)
    rabbit_dl_transcoder_channel.close()

def OnExtractorMessage(ch, method, properties, body):
    try:
        log(syslog.LOG_INFO, 'Received extract request')
        requestid, url = get_request_params(body)
        log(syslog.LOG_INFO, 'Processing extract for requestid {0}'.format(requestid))        
        ydl_params = {}
        ydl = get_ydl(ydl_params)
        extractor_key = get_suitable_extractor(ydl, url)
        if extractor_key is None:
            log(syslog.LOG_WARNING, 'Failed to find suitable extractor for url')
            FinishWithError(requestid, 'Sorry this url is not supported')
            ch.basic_ack(delivery_tag = method.delivery_tag)
            return
        proxy_id = None;
        proxy_ip = None;
        proxy_port = None;
        proxy_type = None;
        proxy_id, proxy_ip, proxy_port, proxy_type = GetProxy(extractor_key.lower());
        ydl_params['proxy'] = '{0}://{1}:{2}'.format(proxy_type, proxy_ip, proxy_port)
        ydl = get_ydl(ydl_params)
        result = None
        try:
            result = get_extraction_result(ydl, extractor_key, url)
            if ('playlist' in result.keys() and result['playlist'] is not None) or ('_type' in result.keys() and result['_type'] == 'playlist'):
                log(syslog.LOG_WARNING, 'Failed to get video - the url links to a playlist')
                FinishWithError(requestid, 'Sorry the video cannot be a playlist')
                ch.basic_ack(delivery_tag = method.delivery_tag)
                return           
        except:
            log(syslog.LOG_WARNING, 'Failed to get video info')
            log_long(syslog.LOG_WARNING, '{0}'.format(traceback.print_exc()))
            FinishWithError(requestid, 'Sorry this video is not available')
            ch.basic_ack(delivery_tag = method.delivery_tag)
            return
        request_type = result['extractor'].lower()
        request_typeid = result['id']
        request_url = None
        extractid = GetExistingExtractId(request_type, request_typeid)
        if extractid is None:
            log(syslog.LOG_INFO, 'This request doesn\'t have any existing extract so we are creating one')
            request_url = GetSmallestUrl(request_type, result)
            request_title = result['title']
            request_length = GetVideoLength(proxy_ip, proxy_port, request_url)
            extractid = CreateExtract(requestid, result, request_url, request_type, request_typeid, request_title, request_length, proxy_id)
            PublishTranscodeMessage(extractid, request_type, request_url, proxy_ip, proxy_port, proxy_type )
        UpdateRequestWithExtractId(requestid, extractid)
        ch.basic_ack(delivery_tag = method.delivery_tag)
    except:
        log(syslog.LOG_WARNING, 'Oops something bad happened with extraction:')
        log_long(syslog.LOG_WARNING, '{0}'.format(traceback.print_exc()))
        ch.basic_nack(delivery_tag = method.delivery_tag)

def GetProxy(extractor_key):
    log(syslog.LOG_INFO, 'Getting proxy for extractor {0}'.format(extractor_key))
    proxy_type = 'https'
    if extractor_key in ('vimeo', 'liveleak'):
        proxy_type = 'http'
    while True:
        proxy_id, proxy_ip, proxy_port = GetProxyFromRabbit(proxy_type);

        if not ProxyIsValid(proxy_ip, proxy_port, extractor_key, proxy_type):
            DeleteProxy(proxy_id)
        else:
            ReleaseProxy(proxy_id, proxy_ip, proxy_port, proxy_type)
            break;
    return proxy_id, proxy_ip, proxy_port, proxy_type

def DeleteProxy(proxyid):
    log(syslog.LOG_INFO, 'Killing proxy {0} - not valid'.format(proxyid))
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    args = (proxyid, )
    cursor.callproc('kill_proxy', args)
    cnx.close()

def ReleaseProxy(proxyid, proxyip, proxyport, proxy_type):
    log(syslog.LOG_INFO, 'Releasing proxy')
    queue = rabbit_q_http_proxy
    if proxy_type == 'https':
        queue = rabbit_q_https_proxy
    log(syslog.LOG_INFO, 'Publishing {0} proxy message to return proxy back to queue'.format(proxy_type))
    transcode_msg = {'id': proxyid, 'ip' : proxyip, 'port' : proxyport }
    transcode_msg = json.dumps(transcode_msg)
    rabbit_credentials = pika.PlainCredentials(rabbit_user, rabbit_pwd)
    rabbit_parameters = pika.ConnectionParameters(rabbit_host, rabbit_port, '/', rabbit_credentials)
    rabbit_connection = pika.BlockingConnection(rabbit_parameters)
    rabbit_dl_transcoder_channel = rabbit_connection.channel()
    rabbit_dl_transcoder_channel.queue_declare(queue=queue)
    rabbit_dl_transcoder_channel.basic_publish(exchange='', routing_key=queue, body=transcode_msg)
    rabbit_dl_transcoder_channel.close()


def ProxyIsValid(ip, port, type, proxy_type):
    MAX_TIMEOUT = 5
    print type
    test_site = ''
    if type == 'vine':
        test_site = 'https://vine.co'
    elif type == 'youtube':
        test_site = 'https://www.youtube.com'
    elif type == 'vimeo':
        test_site = 'http://vimeo.com/dmca' # cannot do homepage - vimeo homepage is https
    elif type == 'liveleak':
        test_site = 'http://www.liveleak.com'
    valid=True
    try:
        proxies = {proxy_type : '{0}:{1}'.format(ip, port)}
        h = urllib2.ProxyHandler(proxies)
        o = urllib2.build_opener(h)
        urllib2.install_opener(o)
        r = urllib2.urlopen(test_site, timeout=MAX_TIMEOUT)
    except:
        valid = False
        log_long(syslog.LOG_WARNING, '{0}'.format(traceback.print_exc()))
    return valid

def GetProxyFromRabbit(type):
    log(syslog.LOG_INFO, 'Getting {0} proxy from rabbit'.format(type))
    id = None
    ip = None
    port = None

    rabbit_credentials = pika.PlainCredentials(rabbit_user, rabbit_pwd)
    rabbit_parameters = pika.ConnectionParameters(rabbit_host, rabbit_port, '/', rabbit_credentials)
    connection = pika.BlockingConnection(rabbit_parameters)
    channel = connection.channel()
    queue = rabbit_q_http_proxy
    if type == 'https':
        queue = rabbit_q_https_proxy
    method_frame, header_frame, body = channel.basic_get(queue=queue)
    if method_frame:
        proxy = json.loads(body)
        id = proxy['id']
        ip = proxy['ip']
        port = proxy['port']
        channel.basic_ack(method_frame.delivery_tag)
        log(syslog.LOG_INFO, 'Found proxy {0}://{1}:{2}'.format(type, ip, port))
    log(syslog.LOG_INFO, '{0}://{1}:{2} = {3}'.format(type, ip, port, id))
    return (id, ip, port)

def UpdateRequestWithExtractId(requestid, extractid):
    log(syslog.LOG_INFO, 'Updating the request with the extract id')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    args = (requestid, extractid)
    cursor.callproc('update_request_extract', args)
    cnx.close()

def CreateExtract(requestid, result, request_url, request_type, request_typeid, request_title, request_length, proxy_id):
    log(syslog.LOG_INFO, 'Creating extract in mysql')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    args = (requestid, json.dumps(result), request_url, request_type, request_typeid, request_title, request_length, proxy_id)
    cursor.callproc('create_extract', args)
    result = None
    for result_cursor in cursor.stored_results():
        for row in result_cursor:
            result = row[0]
            break;
        break;
    cnx.close()
    return result

def GetExistingExtractId(type, typeid):
    log(syslog.LOG_INFO, 'Looking for existing extract in mysql for video type and typeid')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    cursor.execute("select id from extract where type=%s and typeid=%s", (type, typeid))
    result = cursor.fetchone()
    cnx.close()
    return None if result is None else result[0]

def StartRabbitConsumer():
    rabbit_credentials = pika.PlainCredentials(rabbit_user, rabbit_pwd)
    rabbit_parameters = pika.ConnectionParameters(rabbit_host, rabbit_port, '/', rabbit_credentials)
    rabbit_connection = pika.BlockingConnection(rabbit_parameters)
    rabbit_extractor_channel = rabbit_connection.channel()
    rabbit_extractor_channel.basic_qos(prefetch_count=1)
    rabbit_extractor_channel.basic_consume(OnExtractorMessage, queue=rabbit_q_extractor)
    rabbit_extractor_channel.start_consuming()

syslog.openlog('extractor', syslog.LOG_PID, syslog.LOG_USER)

rabbit_user = 'guest'
rabbit_pwd = 'guest'
rabbit_host = 'localhost'
rabbit_port = 5672
rabbit_url = 'amqp://{0}:{1}@{2}:{3}/'.format(rabbit_user, rabbit_pwd, rabbit_host, rabbit_port)
rabbit_q_extractor = 'extractor'
rabbit_q_dl_transcoder = 'downloadtranscoder'
rabbit_q_http_proxy = 'http_proxy'
rabbit_q_https_proxy = 'https_proxy'


# setup mysql connection
db_host = '167.88.34.62'
db_user = 'Brun0'
db_pwd = '65UB3b3$'
db_name = 'vidblit'


max_threads = 10
for i in range(max_threads):
    t = threading.Thread(name='T[{0}]'.format(i + 1), target=StartRabbitConsumer)
    log(syslog.LOG_INFO, 'Starting consumer thread {0}'.format(t.getName()))
    t.start()

