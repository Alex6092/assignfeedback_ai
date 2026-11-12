import base64
import copy
import json
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import pytest
from notebook_service.contracts import bundle, combine, files, reconstruct
from notebook_service.store import Store
from notebook_service.runner import release


@pytest.fixture
def sample():
    return json.loads((Path(__file__).parents[3] / 'skills/moodle-ciel/templates/notebook/bundle.json').read_text(encoding='utf-8'))


def test_release_removes_answers_tests_outputs_and_metadata(sample):
    spec = bundle(sample)
    student = release(spec['master'])
    text = json.dumps(student)
    assert 'return a + b' not in text
    assert 'SECRET_TEST_SENTINEL' not in text
    assert all(c.get('outputs', []) == [] for c in student['cells'])
    assert sample['master']['cells'][1]['source'].find('return a + b') >= 0


def test_reconstruction_ignores_student_tests_points_order_and_outputs(sample):
    master = bundle(sample)['master']
    student = release(master)
    student['cells'][1]['source'] = 'def add(a, b): return 42'
    student['cells'][-1]['source'] = 'pass'
    student['cells'][-1]['metadata']['nbgrader']['points'] = 100000
    student['cells'][-1]['outputs'] = [{'output_type': 'stream', 'text': 'all passed'}]
    student['cells'].reverse()
    result = reconstruct(master, student)
    assert result['cells'][1]['source'] == 'def add(a, b): return 42'
    assert result['cells'][-1]['source'] == master['cells'][-1]['source']
    assert result['cells'][-1]['metadata']['nbgrader']['points'] == 10
    assert result['cells'][-1]['outputs'] == []


def test_missing_answer_never_inserts_teacher_solution(sample):
    master = bundle(sample)['master']
    student = release(master)
    del student['cells'][1]
    assert reconstruct(master, student)['cells'][1]['source'] == ''


@pytest.mark.parametrize('name', ['../secrets.txt', 'a/../../data.py', '/etc/passwd', 'x\\test.py', '.env', 'a//x.py', 'x.html'])
def test_reject_traversal_and_unsupported_files(name):
    with pytest.raises(ValueError):
        files({name: base64.b64encode(b'data').decode()})


def test_duplicate_cell_ids_rejected(sample):
    sample['master']['cells'][-1]['metadata']['nbgrader']['grade_id'] = 'answer_add'
    with pytest.raises(ValueError):
        bundle(sample)


@pytest.mark.parametrize('scores,expected', [((100, 50, 70), 85), ((0, 100, 70), 30), ((33, 77, 70), 46.2)])
def test_weighted_grade(scores, expected):
    assert combine(*scores)['score'] == expected


def test_nan_cannot_become_a_grade():
    with pytest.raises(ValueError):
        combine(float('nan'), 90)


def test_job_idempotency_conflicts_claims_and_restart(tmp_path):
    store = Store(tmp_path / 'jobs.db')
    def submit(_):
        return store.enqueue('same-key', 'grade', {'attempt': 1})
    with ThreadPoolExecutor(max_workers=8) as pool:
        identifiers = list(pool.map(submit, range(20)))
    assert len(set(identifiers)) == 1
    with pytest.raises(ValueError):
        store.enqueue('same-key', 'grade', {'attempt': 2})
    with ThreadPoolExecutor(max_workers=8) as pool:
        claims = list(pool.map(lambda _: store.claim(), range(8)))
    assert len([c for c in claims if c]) == 1
    store.finish(identifiers[0], result={'score': 80})
    reopened = Store(tmp_path / 'jobs.db')
    assert reopened.get(identifiers[0])['result']['score'] == 80
    assert reopened.claim() is None


def test_expired_worker_cannot_overwrite_new_claim(tmp_path):
    store = Store(tmp_path / 'jobs.db')
    ident = store.enqueue('run', 'grade', {'attempt': 1})
    first = store.claim()
    with store.connect() as db:
        db.execute('UPDATE jobs SET updated=0 WHERE id=?', (ident,))
    second = store.claim()
    assert not store.finish(ident, result={'score': 0}, lease=first['updated'])
    assert store.finish(ident, result={'score': 100}, lease=second['updated'])
    assert store.get(ident)['result']['score'] == 100


def test_restart_recovers_only_unfinished_jobs(tmp_path):
    store = Store(tmp_path / 'jobs.db')
    complete = store.enqueue('complete', 'grade', {})
    store.claim()
    store.finish(complete, result={'score': 90})
    pending = store.enqueue('interrupted', 'grade', {})
    first = store.claim()
    store.recover()
    assert store.get(complete)['status'] == 'done'
    assert store.get(pending)['status'] == 'queued'
    assert not store.finish(pending, result={'score': 0}, lease=first['updated'])


def test_environment_uses_only_approved_immutable_images(monkeypatch):
    import docker
    from types import SimpleNamespace
    from notebook_service.runner import environment
    mapping = {'current': 'sha256:current', 'previous': 'sha256:previous'}
    monkeypatch.setenv('PYTHON_IMAGE', 'current')
    monkeypatch.setenv('PYTHON_IMAGES', 'previous')
    monkeypatch.setattr(docker, 'from_env', lambda: SimpleNamespace(images=SimpleNamespace(
        get=lambda ref: SimpleNamespace(id=mapping[ref]))))
    assert environment() == 'sha256:current'
    assert environment('sha256:previous') == 'sha256:previous'
    with pytest.raises(ValueError, match='not installed and approved'):
        environment('untrusted:latest')
