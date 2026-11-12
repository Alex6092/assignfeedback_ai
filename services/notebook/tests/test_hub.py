import runpy
from pathlib import Path
from traitlets.config import Config


def test_hub_configuration_uses_supported_traits(monkeypatch):
    from dockerspawner import DockerSpawner
    from jupyterhub.spawner import Spawner
    from jupyterhub.app import JupyterHub
    hub = Path(__file__).parents[1] / 'hub'
    monkeypatch.syspath_prepend(str(hub))
    for key, value in {'HUB_PUBLIC_URL': 'https://notebooks.example.net', 'HUB_TOKEN': 'x' * 64,
                       'PYTHON_IMAGE': 'prepared:test'}.items():
        monkeypatch.setenv(key, value)
    config = Config()
    runpy.run_path(str(hub / 'jupyterhub_config.py'), init_globals={'get_config': lambda: config})
    for section, cls in [('DockerSpawner', DockerSpawner), ('Spawner', Spawner), ('JupyterHub', JupyterHub)]:
        for key in config[section]:
            assert key in cls.class_traits(), f'Unsupported {section}.{key}'
    from moodleauth import MoodleAuthenticator
    auth = MoodleAuthenticator()
    assert auth.login_url('/hub/') == '/hub/moodle'
    assert auth.refresh_pre_spawn is True
