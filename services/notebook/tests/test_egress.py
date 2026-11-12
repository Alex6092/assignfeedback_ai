import importlib.util
from pathlib import Path
import pytest

spec = importlib.util.spec_from_file_location('egress', Path(__file__).parents[1] / 'hub/egress.py')
egress = importlib.util.module_from_spec(spec)
spec.loader.exec_module(egress)


def test_proxy_is_restricted_to_exact_approved_hosts():
    config = egress.proxy_config(['api.example.com'], ['api.example.com', 'other.example.com'])
    assert 'http_access deny private' in config
    assert 'http_access deny all' in config
    assert 'acl APIs dstdomain api.example.com\n' in config
    assert 'other.example.com' not in config
    for host in ('localhost', 'api.example.com\nhttp_access allow all', 'evil.example.com', '.example.com'):
        with pytest.raises(ValueError):
            egress.proxy_config([host], ['api.example.com'])
