"""Dependency-free validation shared by API, worker and contract tests."""
import base64
import copy
import hashlib
import json
import math
import re
from pathlib import PurePosixPath

MAX_BYTES = 20 * 1024 * 1024
MAX_FILES = 100
MAX_CELLS = 300


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":"),
                                     ensure_ascii=False).encode()).hexdigest()


def filename(value):
    if not isinstance(value, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_./-]{0,179}", value):
        raise ValueError("Invalid file name")
    path = PurePosixPath(value)
    if any(not p or p in (".", "..") or p.startswith(".") for p in value.split("/")) or path.is_absolute():
        raise ValueError("Unsafe path")
    if path.suffix.lower() not in {".ipynb", ".py", ".csv", ".json", ".txt", ".png", ".jpg", ".npy"}:
        raise ValueError("Unsupported file type")
    return value


def files(value):
    if not isinstance(value, dict) or not 1 <= len(value) <= MAX_FILES:
        raise ValueError("Invalid file count")
    result, total = {}, 0
    for name, content in value.items():
        filename(name)
        raw = base64.b64decode(content, validate=True)
        total += len(raw)
        if total > MAX_BYTES:
            raise ValueError("Submission exceeds 20 MiB")
        result[name] = raw
    return result


def notebook(value, master=False):
    if not isinstance(value, dict) or value.get("nbformat") != 4:
        raise ValueError("Expected nbformat 4")
    cells = value.get("cells")
    if not isinstance(cells, list) or not 1 <= len(cells) <= MAX_CELLS:
        raise ValueError("Invalid cell count")
    identifiers, points = set(), 0
    for cell in cells:
        if cell.get("cell_type") not in {"code", "markdown", "raw"}:
            raise ValueError("Invalid cell type")
        source = cell.get("source", "")
        if isinstance(source, list):
            source = "".join(source)
        if not isinstance(source, str) or len(source) > 100000:
            raise ValueError("Invalid cell source")
        cell["source"] = source
        if not isinstance(cell.get('metadata', {}), dict):
            raise ValueError('Cell metadata must be an object')
        meta = cell.get("metadata", {}).get("nbgrader", {})
        if master and (meta.get("grade") or meta.get("solution")):
            key = meta.get("grade_id", "")
            if not re.fullmatch(r"[A-Za-z0-9_-]{1,80}", key) or key in identifiers:
                raise ValueError("Missing or duplicate grade_id")
            identifiers.add(key)
        if master and meta.get("grade") and cell["cell_type"] == "code":
            score = meta.get("points", 0)
            if isinstance(score, bool) or not isinstance(score, (int, float)) or not math.isfinite(score) or score <= 0:
                raise ValueError("Invalid test points")
            points += score
    if master and points <= 0:
        raise ValueError("At least one graded code test is required")
    return value


def bundle(value):
    result = copy.deepcopy(value)
    if result.get("profile", "python") != "python":
        raise ValueError("Only the prepared Python profile is available")
    result["master"] = notebook(result["master"], master=True)
    weight = result.get("tests_weight", 70)
    if isinstance(weight, bool) or not isinstance(weight, int) or not 0 <= weight <= 100:
        raise ValueError("Invalid tests_weight")
    result["tests_weight"] = weight
    result["profile"] = "python"
    hosts = result.get('egress_hosts', [])
    if not isinstance(hosts, list) or len(hosts) > 10 or any(not isinstance(h, str) or not re.fullmatch(
            r'[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?', h) or '.' not in h for h in hosts):
        raise ValueError('Invalid API host list')
    if not isinstance(result.get("rubric"), str) or not result["rubric"].strip():
        raise ValueError("An AI rubric is required")
    if len(result["rubric"]) > 50000:
        raise ValueError("Rubric too long")
    result.setdefault("files", {})
    if result["files"]:
        files(result["files"])
    if "assignment.ipynb" in result["files"]:
        raise ValueError("Reserved file name")
    if len(json.dumps(result).encode()) > MAX_BYTES:
        raise ValueError("Bundle too large")
    return result


def reconstruct(master, submitted):
    """Restore trusted tests, ordering and points. Only answer sources are copied."""
    notebook(submitted)
    answers = {}
    for cell in submitted["cells"]:
        key = cell.get("metadata", {}).get("nbgrader", {}).get("grade_id")
        if key:
            if key in answers:
                raise ValueError("Duplicate submitted grade_id")
            answers[key] = cell["source"]
    result = copy.deepcopy(master)
    for cell in result["cells"]:
        meta = cell.get("metadata", {}).get("nbgrader", {})
        if meta.get("solution"):
            cell["source"] = answers.get(meta.get("grade_id"), "")
        cell.pop("attachments", None)
        if cell["cell_type"] == "code":
            cell["outputs"] = []
            cell["execution_count"] = None
    return result


def combine(tests, ai, weight=70):
    if any(not isinstance(x, (int, float)) or not math.isfinite(x) or not 0 <= x <= 100
           for x in (tests, ai, weight)):
        raise ValueError("Invalid score")
    score = round((tests * weight + ai * (100 - weight)) / 100, 2)
    levels = ["Maîtrise insuffisante", "Maîtrise fragile", "Maîtrise satisfaisante", "Très bonne maîtrise"]
    return {"score": score, "niveau": levels[sum(score >= n for n in (25, 50, 80))]}
