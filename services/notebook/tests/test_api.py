import importlib
import json
from pathlib import Path
import pytest
from fastapi.testclient import TestClient


@pytest.fixture
def api(tmp_path, monkeypatch):
    monkeypatch.setenv('STATE_ROOT', str(tmp_path))
    monkeypatch.setenv('SERVICE_TOKEN', 's' * 48)
    monkeypatch.setenv('ROLE', 'grader')
    import notebook_service.api as module
    importlib.reload(module)
    # No lifespan: these contract tests never run Docker or student code.
    return TestClient(module.app), module


def test_private_api_refuses_missing_and_wrong_tokens(api):
    client, _ = api
    assert client.get('/v1/health').status_code == 401
    assert client.get('/v1/health', headers={'Authorization': 'Bearer wrong'}).status_code == 401
    assert client.get('/v1/health', headers={'Authorization': 'Bearer ' + 's' * 48}).status_code == 200


def test_validation_job_can_be_polled_and_is_idempotent(api):
    client, _ = api
    bundle = json.loads((Path(__file__).parents[3] / 'skills/moodle-ciel/templates/notebook/bundle.json').read_text(encoding='utf-8'))
    headers = {'Authorization': 'Bearer ' + 's' * 48, 'Idempotency-Key': 'assignment-1-v1'}
    response = client.post('/v1/jobs', headers=headers, json={'kind': 'validate', 'payload': {'bundle': bundle}})
    assert response.status_code == 200, response.text
    ident = response.json()['id']
    assert client.post('/v1/jobs', headers=headers, json={'kind': 'validate', 'payload': {'bundle': bundle}}).json()['id'] == ident
    polled = client.get('/v1/jobs/' + ident, headers=headers).json()
    assert polled['status'] == 'queued'
    assert 'payload' not in polled


def test_workspace_requires_preprovisioned_quota(api, tmp_path, monkeypatch):
    _, module = api
    monkeypatch.setenv('WORKSPACE_ROOT', str(tmp_path / 'slots'))
    identity = {'site': 'school', 'userid': 1, 'cmid': 2, 'version': 3, 'teacher': False}
    with pytest.raises(Exception, match='No quota-limited'):
        module.workspace(identity)


def test_workspaces_keep_identity_and_never_reuse_untracked_files(api, tmp_path, monkeypatch):
    _, module = api
    root = tmp_path / 'slots'
    root.mkdir()
    for slot in range(3):
        (root / str(slot)).mkdir()
        (root / f'{slot}.quota').touch()
    (root / '0' / 'private.txt').write_text('existing untracked work')
    monkeypatch.setenv('WORKSPACE_ROOT', str(root))
    identity = {'site': 'school', 'userid': 1, 'cmid': 2, 'version': 3, 'teacher': False}
    assert module.workspace(identity) == (root / '1', True)
    assert module.workspace(identity) == (root / '1', True)  # Resume interrupted initialization.
    with module.store.connect() as db:
        db.execute('UPDATE slots SET initialized=1 WHERE slot=1')
    assert module.workspace(identity) == (root / '1', False)
    assert module.workspace({**identity, 'userid': 2}) == (root / '2', True)
    with pytest.raises(Exception, match='No quota-limited'):
        module.workspace({**identity, 'version': 4})
