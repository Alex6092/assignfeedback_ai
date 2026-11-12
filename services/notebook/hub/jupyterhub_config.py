import os
import sys
sys.path.insert(0, '/opt/moodle')
from moodleauth import MoodleAuthenticator
from dockerspawner import DockerSpawner
from egress import proxy_config

c = get_config()  # noqa: F821 - Jupyter configuration namespace.
c.JupyterHub.authenticator_class = MoodleAuthenticator
c.JupyterHub.spawner_class = DockerSpawner
c.JupyterHub.bind_url = 'http://0.0.0.0:8000'
c.JupyterHub.hub_bind_url = 'http://0.0.0.0:8081'
c.JupyterHub.hub_connect_url = 'http://hub-gateway:8081'
c.JupyterHub.subdomain_host = os.environ['HUB_PUBLIC_URL']
c.JupyterHub.db_url = 'sqlite:////data/hub.sqlite'
c.JupyterHub.cookie_secret_file = '/data/cookie_secret'
c.JupyterHub.services = [{'name': 'notebook-controller', 'api_token': os.environ['HUB_TOKEN']}]
c.JupyterHub.load_roles = [{'name': 'notebook-controller',
                           'scopes': ['read:users', 'read:servers', 'servers'],
                           'services': ['notebook-controller']}]
c.JupyterHub.allow_named_servers = False
c.Spawner.default_url = '/lab/tree/assignment.ipynb'
c.Spawner.disable_user_config = True
c.DockerSpawner.image = os.environ['PYTHON_IMAGE']
c.DockerSpawner.allowed_images = []
c.DockerSpawner.remove = True
c.DockerSpawner.mem_limit = '2G'
c.DockerSpawner.cpu_limit = 1
c.DockerSpawner.notebook_dir = '/home/jovyan/work'
c.DockerSpawner.extra_host_config = {'cap_drop': ['ALL'], 'pids_limit': 128,
    'dns': ['127.0.0.1'],
    'security_opt': ['no-new-privileges:true'], 'read_only': True,
    'tmpfs': {'/tmp': 'rw,nosuid,nodev,size=256m,uid=1000,gid=1000',
              '/home/jovyan/.local': 'rw,nosuid,nodev,size=64m,uid=1000,gid=1000'}}


async def configure(spawner, state):
    if not state or not str(state['slot']).isdigit():
        raise RuntimeError('Open this assignment from Moodle')
    spawner.image = state['environment']
    # Only this assignment volume is mounted. No other assignment, solution or credential.
    spawner.volumes = {os.environ['HOST_WORKSPACE_ROOT'] + '/' + state['slot']: '/home/jovyan/work'}
    spawner.environment.update({'MOODLE_RETURN_URL': state['return_url'],
                               'MOODLE_TEACHER': '1' if state['teacher'] else '0',
                               'JUPYTER_CONFIG_DIR': '/etc/jupyter', 'IPYTHONDIR': '/tmp/ipython'})
    network = 'nb-' + spawner.user.name
    client = spawner.client
    try:
        client.inspect_network(network)
    except Exception:
        client.create_network(network, internal=True)
    try:
        client.connect_container_to_network(os.environ['HUB_CONTAINER'], network, aliases=['hub-gateway'])
    except Exception:
        # Already connected is expected on subsequent launches; verify membership.
        containers = client.inspect_network(network).get('Containers', {})
        if not any(v.get('Name') == os.environ['HUB_CONTAINER'] for v in containers.values()):
            raise
    spawner.network_name = network
    spawner.extra_create_kwargs = {'labels': {'moodle.notebook.user': spawner.user.name}}
    for variable in ('HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY'):
        spawner.environment.pop(variable, None)
    hosts = state.get('egress_hosts', [])
    if hosts:
        config = proxy_config(hosts, os.getenv('API_ALLOWLIST', '').split(','))
        proxyname = 'egress-' + spawner.user.name
        try:
            client.remove_container(proxyname, force=True)
        except Exception:
            pass
        proxy = client.create_container(os.environ['PROXY_IMAGE'], name=proxyname,
            command=['sh', '-c', 'printf "%s" "$1" > /tmp/squid.conf; exec squid -N -f /tmp/squid.conf', '_', config],
            user='65534:65534', labels={'moodle.notebook.user': spawner.user.name}, host_config=client.create_host_config(
                network_mode=os.environ['CONTROL_NETWORK'], read_only=True, cap_drop=['ALL'],
                security_opt=['no-new-privileges:true'], mem_limit='128m', pids_limit=32,
                tmpfs={'/tmp': 'rw,nosuid,nodev,size=32m,mode=1777'}, sysctls={'net.ipv4.ip_forward': '0'}))
        client.connect_container_to_network(proxy['Id'], network, aliases=['api-egress'])
        client.start(proxy['Id'])
        spawner.environment.update({'HTTP_PROXY': 'http://api-egress:3128', 'HTTPS_PROXY': 'http://api-egress:3128',
                                    'NO_PROXY': 'localhost,127.0.0.1,hub-gateway'})


async def cleanup(spawner):
    try:
        spawner.client.remove_container('egress-' + spawner.user.name, force=True)
    except Exception:
        pass


c.Spawner.auth_state_hook = configure
c.Spawner.post_stop_hook = cleanup
