import syslog
import urllib2
import threading
import mysql.connector
import pika
import json
import mechanize
import cookielib
from bs4 import BeautifulSoup

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

def GetLetUsHideProxies(site):
    response = urllib2.urlopen(site)
    page = response.read()
    page_proxies = json.loads(page)

    proxies = None
    for proxy in page_proxies:
        ip = proxy['host']
        port = proxy['port']
        protocol = proxy['protocol'].lower()
        if protocol in ('http', 'https'):
            if proxies is None:
                proxies = {}
            if protocol not in proxies.keys():
                proxies[protocol] = []
            proxies[protocol].append({'ip' : ip, 'port' : port})
    return proxies


def GetSSLProxies(site):
    log(syslog.LOG_INFO, 'Getting ssl proxies')

    response = urllib2.urlopen(site)
    page = response.read()
    soup = BeautifulSoup(page)

    proxylisttable = soup.find('table', id='proxylisttable')
    proxylistheader = proxylisttable.find('thead')
    headers = proxylistheader.findAll('th')

    proxylistbody = proxylisttable.find('tbody')
    proxyrows = proxylistbody.findAll('tr')

    expected_nheaders = 8
    nheaders = len(headers)
    if nheaders != expected_nheaders:
        print "Bad header ", headers[i], " expected ", expected_headers[i]
        return None

    expected_headers = ['IP Address', 'Port', 'Code', 'Country', 'Anonymity', 'Google', 'Https', 'Last Checked']
    for i in range(expected_nheaders):
        if headers[i].text != expected_headers[i]:
            print "Bad header ", headers[i], " expected ", expected_headers[i]
            return None

    proxylistbody = proxylisttable.find('tbody')
    proxyrows = proxylistbody.findAll('tr')

    proxies = None
    for row in proxyrows:
        fields = row.findAll('td')
        code = fields[2].text
        https = fields[6].text
        if code != 'US' or https != 'yes':
            continue
        ip = fields[0].text
        port = fields[1].text
        if proxies is None:
            proxies = {}
        if 'https' not in proxies.keys():
            proxies['https'] = []
        proxies['https'].append({'ip' : ip, 'port' : port})
    return proxies

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

def GetPageWithMechanize(site):
    br = mechanize.Browser()
    cj = cookielib.LWPCookieJar()
    br.set_cookiejar(cj)
    br.set_handle_equiv(True)
    br.set_handle_gzip(True)
    br.set_handle_redirect(True)
    br.set_handle_referer(True)
    br.set_handle_robots(False)
    br.set_handle_refresh(mechanize._http.HTTPRefreshProcessor(), max_time=1)
    br.addheaders = [('User-agent', 'Mozilla/5.0 (X11; U; Linux i686; en-US; rv:1.9.0.1) Gecko/2008071615 Fedora/3.0.1-1.fc9 Firefox/3.0.1')]
    response = br.open(site)
    page = response.read()
    return page

# site = 'http://www.freeproxylists.net/?c=US&pt=&pr=&a%5B%5D=1&a%5B%5D=2&u=60'
# proxies = GetFreeProxyListProxies(site)
# def GetFreeProxyListProxies(site):
#     page = GetPageWithMechanize(site)
#     soup = BeautifulSoup(page)

#     proxylisttable = soup.find('table', class_='DataGrid')
#     proxylistheader = proxylisttable.find('tr', class_='Caption')
#     headers = proxylistheader.findAll('td')
#     expected_headers= ['IP Address', 'Port', 'Protocol', 'Anonymity', 'Country', 'Region', 'City', 'Uptime', 'Response', 'Transfer']
#     expected_nheaders = len(expected_fields)
#     nheaders = len(headers)
#     if nheaders != expected_nheaders:
#         print "Expected {0} headers but have {1} instead".format(expected_nheaders, nheaders)
#         return
#     for i in range(expected_nheaders):
#         field = h[i].find('a').text;
#         if field != expected_headers[i]:
#             print "Expected {0} header in position {1} but have {2} instead".format(expected_headers[i], i, field)
#             return

#     proxies = proxylisttable.findAll('tr')
#     for p in proxies:
#         if p['class'] == 'Caption':
#             continue #header
#         if p.td['colspan'] == 10:
#             continue #ad

#         ip = td[0].script.text
#         port = td[1].text
#         protocol = td[2].text

#         print '{0}, {1}, {2}'.format(ip, port, protocol)

import sys
sys.exit(0)

site = 'http://www.sslproxies.org'
proxies = GetSSLProxies(site)
if proxies is not None:
    new_proxies = InsertProxiesToDb(proxies, site)
    if new_proxies is not None:
        AddNewProxiesToRabbit(new_proxies)

site = 'http://letushide.com/export/json/all,ntp,us/'
proxies = GetLetUsHideProxies(site)
if proxies is not None:
    new_proxies = InsertProxiesToDb(proxies, site)
    if new_proxies is not None:
        AddNewProxiesToRabbit(new_proxies)
syslog.closelog()

