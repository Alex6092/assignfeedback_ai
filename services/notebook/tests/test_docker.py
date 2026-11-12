"""Run on a Linux Docker test VM: NOTEBOOK_DOCKER_TESTS=1 PYTHON_IMAGE=... pytest."""
import base64
import copy
import json
import os
from pathlib import Path
import pytest
from notebook_service.runner import run_job, release

pytestmark = pytest.mark.skipif(os.getenv('NOTEBOOK_DOCKER_TESTS') != '1', reason='Requires the built image and a Linux Docker daemon')


def sample():
    return json.loads((Path(__file__).parents[3] / 'skills/moodle-ciel/templates/notebook/bundle.json').read_text(encoding='utf-8'))


def test_full_reference_validation_and_wrong_submission():
    spec = sample()
    result = run_job({'kind': 'validate', 'payload': {'bundle': spec}})
    assert result['valid'] is True
    assert result['reference']['score'] == 100
    assert result['negative']['score'] == 0
    spec['environment'] = result['environment']
    student = copy.deepcopy(spec['master'])
    student['cells'][1]['source'] = 'def add(a, b): return 999'
    result = run_job({'kind': 'grade', 'payload': {'bundle': spec,
        'files': {'assignment.ipynb': base64.b64encode(json.dumps(student).encode()).decode()}}})
    assert result['score'] == 0


def test_kernel_cannot_read_reference_or_write_controller_results():
    spec = sample()
    from notebook_service.runner import environment
    spec['environment'] = environment()
    student = copy.deepcopy(spec['master'])
    student['cells'][1]['source'] = '''
import os
assert os.getuid() == 1000
for path in ('/input/assignment.ipynb', '/results/executed.ipynb'):
    try:
        open(path, 'w')
    except PermissionError:
        pass
    else:
        raise AssertionError('Unexpected access')
def add(a, b): return a + b
'''
    result = run_job({'kind': 'grade', 'payload': {'bundle': spec,
        'files': {'assignment.ipynb': base64.b64encode(json.dumps(student).encode()).decode()}}})
    assert result['score'] == 100
