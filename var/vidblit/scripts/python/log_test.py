import syslog

syslog.openlog('anna-2013', syslog.LOG_PID, syslog.LOG_USER)
syslog.syslog(syslog.LOG_INFO, 'hello world 2')
syslog.closelog()
