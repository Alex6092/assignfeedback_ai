"""Docker-only execution. Never import student modules in the controller."""
import base64
import copy
import io
import json
import os
import tarfile
import tempfile
import time
from pathlib import Path

from .contracts import bundle, files, reconstruct


def release(master):
    import nbformat
    from nbgrader.preprocessors import ClearSolutions, ClearOutput, ClearHiddenTests, ClearMarkScheme
    nb = nbformat.from_dict(copy.deepcopy(master))
    nb, resources = ClearSolutions().preprocess(nb, {})
    nb, resources = ClearHiddenTests().preprocess(nb, resources)
    nb, resources = ClearMarkScheme().preprocess(nb, resources)
    nb, _ = ClearOutput().preprocess(nb, resources)
    # Hidden tests must not be recoverable from cell metadata or attachments.
    for cell in nb.cells:
        meta = cell.metadata.get("nbgrader", {})
        cell.metadata = {"nbgrader": {k: v for k, v in meta.items()
                                    if k in {"grade", "grade_id", "solution", "locked", "points", "schema_version"}}}
        cell.pop("attachments", None)
    nb.metadata = {"kernelspec": {"name": "python3", "display_name": "Python 3", "language": "python"}}
    return json.loads(nbformat.writes(nb))


def environment(requested=None):
    import docker
    client = docker.from_env()
    references = [os.environ['PYTHON_IMAGE']] + [r for r in os.getenv('PYTHON_IMAGES', '').split(',') if r]
    approved = [client.images.get(ref).id for ref in references]
    if requested is not None and requested not in approved:
        raise ValueError('The assignment image is not installed and approved on this server')
    return requested or approved[0]


def execute(nb, attachments, image):
    import docker
    client = docker.from_env()
    with tempfile.TemporaryDirectory() as folder:
        root = Path(folder)
        (root / "input").mkdir()
        (root / "work").mkdir()
        (root / "input" / "assignment.ipynb").write_text(json.dumps(nb), encoding="utf-8")
        for name, raw in files(attachments).items() if attachments else []:
            path = root / "work" / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(raw)
        def archive(directory, uid):
            content = io.BytesIO()
            with tarfile.open(fileobj=content, mode='w') as tar:
                for path in directory.rglob('*'):
                    info = tar.gettarinfo(str(path), arcname=path.relative_to(directory).as_posix())
                    info.uid = info.gid = uid
                    info.mode = 0o700 if path.is_dir() else 0o600
                    if path.is_file():
                        with path.open('rb') as handle:
                            tar.addfile(info, handle)
                    else:
                        tar.addfile(info)
            return content.getvalue()
        container = client.containers.create(
            image, ["python", "/opt/notebook/execute.py"], user="0:0", network_mode="none",
            mem_limit="2g", nano_cpus=1_000_000_000, pids_limit=128, read_only=True,
            cap_drop=["ALL"], cap_add=["SETUID", "SETGID", "SETPCAP"], security_opt=["no-new-privileges:true"],
            tmpfs={"/tmp": "rw,nosuid,nodev,size=256m", "/work": "rw,nosuid,nodev,size=128m,uid=1000,gid=1000",
                   "/input": "rw,nosuid,nodev,size=32m,mode=0700", "/results": "rw,nosuid,nodev,size=32m,mode=0700"},
            environment={"HOME": "/tmp", "CELL_TIMEOUT": str(min(600, max(1, int(os.getenv("CELL_TIMEOUT", "60"))))),
                         'COPY_TIMEOUT': str(min(3600, max(1, int(os.getenv('COPY_TIMEOUT', '300')))))},
            working_dir="/work", labels={"moodle-notebook": "grader"})
        try:
            # tmpfs exists only after start: the runner waits for its input marker.
            container.start()
            container.put_archive('/input', archive(root / 'input', 0))
            container.put_archive('/work', archive(root / 'work', 1000))
            marker = io.BytesIO()
            with tarfile.open(fileobj=marker, mode="w") as tar:
                info = tarfile.TarInfo("ready")
                info.size = 0
                tar.addfile(info)
            container.put_archive("/input", marker.getvalue())
            deadline = time.monotonic() + min(3600, max(1, int(os.getenv('COPY_TIMEOUT', '300'))))
            while time.monotonic() < deadline:
                container.reload()
                if container.status != 'running':
                    raise RuntimeError('Execution infrastructure failed')
                if container.exec_run(['test', '-f', '/results/complete'], user='0:0').exit_code == 0:
                    break
                time.sleep(.5)
            else:
                raise RuntimeError('Notebook exceeded the total execution time limit')
            chunks, _ = container.get_archive("/results/executed.ipynb")
            raw = b"".join(chunks)
            if len(raw) > 24 * 1024 * 1024:
                raise RuntimeError("Execution output too large")
            with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
                output = json.load(tar.extractfile(tar.getmembers()[0]))
        finally:
            container.remove(force=True)
    # Points and identifiers come from the trusted input, never from result metadata.
    earned, maximum, outcomes = 0.0, 0.0, []
    if len(output.get("cells", [])) != len(nb["cells"]):
        raise RuntimeError("Invalid execution result")
    for index, cell in enumerate(nb["cells"]):
        meta = cell.get("metadata", {}).get("nbgrader", {})
        if cell["cell_type"] == "code" and meta.get("grade"):
            points = float(meta["points"])
            maximum += points
            actual = output["cells"][index]
            passed = actual.get("execution_count") is not None and not any(
                o.get("output_type") == "error" for o in actual.get("outputs", []))
            earned += points if passed else 0
            # Do not return hidden test source, traceback or stdout.
            outcomes.append({"id": meta["grade_id"], "passed": passed, "points": points if passed else 0,
                             "maximum": points})
    return {"score": round(earned / maximum * 100, 2), "tests": outcomes}


def run_job(job):
    payload = job["payload"]
    spec = bundle(payload["bundle"])
    image = environment(spec.get('environment'))
    if job["kind"] == "validate":
        released = release(spec["master"])
        correct = execute(spec["master"], spec["files"], image)
        empty = reconstruct(spec["master"], {**released, "cells": [
            {**c, "source": ""} if c.get("metadata", {}).get("nbgrader", {}).get("solution") else c
            for c in released["cells"]]})
        negative = execute(empty, spec["files"], image)
        return {"valid": correct["score"] == 100 and negative["score"] < 100,
                "reference": correct, "negative": negative, "release": released, 'environment': image}
    if job["kind"] == "grade":
        if not spec.get('environment'):
            raise ValueError('Validate the assignment to pin its execution environment first')
        submitted = files(payload["files"])
        nb = json.loads(submitted.pop("assignment.ipynb"))
        attachments = {**spec["files"], **{n: base64.b64encode(b).decode() for n, b in submitted.items()}}
        return execute(reconstruct(spec["master"], nb), attachments, image)
    raise ValueError("Unknown job kind")
