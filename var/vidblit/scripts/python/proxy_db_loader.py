import syslog
import threading
import mysql.connector
import pika
import json


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

def GetRawProxies():
    log(syslog.LOG_INFO, 'Getting raw proxy table settings')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    cursor.execute("select id, type, ip, port, source from proxy_raw where valid=1")
    results = cursor.fetchall()

    proxies = None
    maxid = None
    for row in results:
        _id = row[0]
        _protocol = row[1]
        _ip = row[2]
        _port = row[3]
        _source = row[4]

        if proxies is None:
            proxies = {}
        if _source not in proxies.keys():
            proxies[_source] = {}
        if _protocol not in proxies[_source].keys():
            proxies[_source][_protocol] = []

        proxies[_source][_protocol].append({'ip' : _ip, 'port' : _port})
        if maxid is None:
            maxid = _id
        if _id > maxid:
            maxid = _id
    cnx.close()
    return (proxies, maxid)

def InsertProxiesToDb(proxies, source):
    log(syslog.LOG_INFO, 'Upserting new proxies')

    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()

    NewProxies = None
    for proxy_type in proxies.keys():
        for proxy in proxies[proxy_type]:
            ip = proxy['ip']
            port = proxy['port']
            args = (source, proxy_type, ip, port)
            cursor.callproc('create_proxy', args)
            proxy_id = None
            for result_cursor in cursor.stored_results():
                for row in result_cursor:
                    proxy_id = row[0]
                    break;
                break;
            if proxy_id is not None:
                log(syslog.LOG_INFO, 'Added new proxy {0}://{1}:{2}'.format(proxy_type, ip, port))
                if NewProxies is None:
                    NewProxies = {}
                if proxy_type not in NewProxies.keys():
                    NewProxies[proxy_type] = []
                NewProxies[proxy_type].append({'id': proxy_id, 'ip' : ip, 'port' : port})
    cnx.close()
    return NewProxies

def ClearRawProxiesUpToId(maxid):
    log(syslog.LOG_INFO, 'Upserting new proxies')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    args = (maxid,)
    cursor.callproc('clear_raw_proxy', args)
    cnx.close()

def AddNewProxiesToRabbit(proxies):
    log(syslog.LOG_INFO, 'Adding new proxies to rabbit')
    log(syslog.LOG_INFO, 'Connecting to rabbit')
    rabbit_credentials = pika.PlainCredentials(rabbit_user, rabbit_pwd)
    rabbit_parameters = pika.ConnectionParameters(rabbit_host, rabbit_port, '/', rabbit_credentials)
    rabbit_connection = pika.BlockingConnection(rabbit_parameters)
    for proxy_type in proxies:
        queue = rabbit_q_http_proxy
        if proxy_type == 'https':
            queue = rabbit_q_https_proxy
        channel = rabbit_connection.channel()
        channel.queue_declare(queue=queue)
        for proxy in proxies[proxy_type]:
            log(syslog.LOG_INFO, 'Publishing new proxy to rabbit')
            msg = json.dumps(proxy)
            channel.basic_publish(exchange='', routing_key=queue, body=msg)
        channel.close()

syslog.openlog('sslproxy.org_loader', syslog.LOG_PID, syslog.LOG_USER)

rabbit_user = 'guest'
rabbit_pwd = 'guest'
rabbit_host = '107.170.154.102'
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


proxies, maxid = GetRawProxies()
if proxies is not None:
    for source in proxies.keys():
        new_proxies = InsertProxiesToDb(proxies[source], source)
        if new_proxies is not None:
            AddNewProxiesToRabbit(new_proxies)
if maxid is not None:
    ClearRawProxiesUpToId(maxid)