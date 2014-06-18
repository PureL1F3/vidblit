import socket
import os
import syslog
import threading
import mysql.connector
import time

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
        syslo

def get_ip_addr():
    log(syslog.LOG_INFO, 'Getting ip address')
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.connect(('google.com', 0))
    ip = s.getsockname()[0]
    return ip

def GetHostedPlayists():
    log(syslog.LOG_INFO, 'Getting list of supposedly hosted playists')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    hostname = get_ip_addr()
    cursor.execute("select id, location from extract where locationhost=%s", (hostname, ))
    results = cursor.fetchall()
    cnx.close()
    return results

def FilterHostedPlaylistsToNix(results):
    log(syslog.LOG_INFO, 'Filtering hosted playlists to those we need to nix')
    playlists_to_nix = None
    now = time.time()
    for row in results:
        _extractid = row[0]
        _playlistpath = row[1]
        log(syslog.LOG_INFO, 'Found hosted plalist  for extract {0} at {1}'.format(_extractid, _playlistpath))
        if not os.path.isfile(_playlistpath):
            log(syslog.LOG_INFO, '{0} is not a file'.format(_playlistpath))
            continue
        last_mod_time = os.stat(_playlistpath).st_mtime
        if now - last_mod_time > MAX_MODIFIED_MIN * 60:
            log(syslog.LOG_INFO, 'Adding extract {0} to nix playlist'.format(_extractid))
            if playlists_to_nix is None:
                playlists_to_nix = []
            playlists_to_nix.append(_extractid)
    return playlists_to_nix

def NixHostedPlaylists(playlists_to_nix):
    log(syslog.LOG_INFO, 'Nixing playlists in db')
    cnx = mysql.connector.connect(host=db_host, user=db_user, password=db_pwd, database=db_name)
    cursor = cnx.cursor()
    for extractid in playlists_to_nix:
        log(syslog.LOG_INFO, 'Nixing playlists for extract {0}'.format(extractid))
        cursor.execute("update extract set locationhost=NULL where id=%s", (extractid, ))
    cnx.close()

syslog.openlog('playlist_monitor', syslog.LOG_PID, syslog.LOG_USER)
# setup mysql connection
db_host = '167.88.34.62'
db_user = 'Brun0'
db_pwd = '65UB3b3$'
db_name = 'vidblit'

MAX_MODIFIED_MIN = 5
SLEEP_MIN  = 10

while True:
    results = GetHostedPlayists()
    if results is not None:
        playlists_to_nix = FilterHostedPlaylistsToNix(results)
        if playlists_to_nix is not None:
            NixHostedPlaylists(playlists_to_nix)

    time.sleep(SLEEP_MIN * 60)

