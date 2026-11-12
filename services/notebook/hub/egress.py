"""Per-workspace HTTP(S) proxy; teachers select hosts from an administrator allowlist."""
import re


def proxy_config(hosts, approved):
    if any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?', host) or '.' not in host
           or host not in approved for host in hosts):
        raise ValueError('API host is not approved by the administrator')
    return '''http_port 3128
pid_filename /tmp/squid.pid
cache_log /dev/stderr
access_log stdio:/dev/stdout
cache_store_log none
cache deny all
cache_effective_user nobody
acl SSL_ports port 443
acl Safe_ports port 80 443
acl CONNECT method CONNECT
acl private dst 0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 192.168.0.0/16 224.0.0.0/4 ::/128 ::1/128 fc00::/7 fe80::/10
acl APIs dstdomain ''' + ' '.join(hosts) + '''
http_access deny !Safe_ports
http_access deny CONNECT !SSL_ports
http_access deny private
http_access allow APIs
http_access deny all
forwarded_for delete
via off
'''
