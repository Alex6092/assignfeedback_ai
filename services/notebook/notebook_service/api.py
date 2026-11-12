import asyncio
import base64
import hmac
import json
import os
import re
import time
import shutil
import stat
from contextlib import asynccontextmanager
from pathlib import Path
import httpx
from fastapi import FastAPI, Header, HTTPException, Depends, Request
from .contracts import bundle, digest, files, MAX_BYTES
from .store import Store

ROOT = Path(os.getenv("STATE_ROOT", "/state"))
ROOT.mkdir(parents=True, exist_ok=True)
store = Store(ROOT / "platform.sqlite3")
ROLE = os.getenv("ROLE", "workspace")
user_locks = {}


def user_lock(username):
    return user_locks.setdefault(username, asyncio.Lock())


def authorize(authorization: str = Header(default="")):
    expected = os.environ["SERVICE_TOKEN"]
    if len(expected) < 32 or not hmac.compare_digest(authorization, "Bearer " + expected):
        raise HTTPException(401, "Invalid service credential")


async def moodle(action, data):
    async with httpx.AsyncClient(timeout=10, follow_redirects=False) as client:
        response = await client.post(os.environ["MOODLE_URL"].rstrip("/") + "/local/notebook/api.php",
                                     headers={"Authorization": "Bearer " + os.environ["MOODLE_TOKEN"]},
                                     json={"action": action, **data})
        response.raise_for_status()
        return response.json()


async def stop(username):
    try:
        async with httpx.AsyncClient(timeout=3) as client:
            r = await client.delete(os.environ["HUB_API_URL"] + "/users/" + username + "/server",
                                    headers={"Authorization": "token " + os.environ["HUB_TOKEN"]})
            if r.status_code not in (204, 202, 404):
                r.raise_for_status()
    finally:
        # A failed Hub must not keep an already-running kernel alive after access revocation.
        def kill_owned():
            import docker
            client = docker.from_env(timeout=5)
            for container in client.containers.list(all=True, filters={'label': 'moodle.notebook.user=' + username}):
                try:
                    container.remove(force=True)
                except docker.errors.NotFound:
                    pass
        await asyncio.to_thread(kill_owned)


async def leases():
    async def check(session):
        try:
            result = await moodle("access", {"lease": session["id"]})
            allowed = result.get("allowed") is True
        except Exception:
            allowed = False
        if not allowed:
            async with user_lock(session['username']):
                # A new login may have replaced the lease while Moodle was answering.
                if not any(s['id'] == session['id'] for s in store.sessions()):
                    return
                try:
                    await stop(session["username"])
                    store.remove_session(session["id"])
                except Exception:
                    pass
    while True:
        started = time.monotonic()
        await asyncio.gather(*(check(s) for s in store.sessions()))
        await asyncio.sleep(max(1, 30 - (time.monotonic() - started)))


async def worker():
    from .runner import run_job
    while True:
        job = store.claim()
        if not job:
            await asyncio.sleep(2)
            continue
        try:
            result = await asyncio.to_thread(run_job, job)
            store.finish(job["id"], result=result, lease=job['updated'])
        except Exception as error:
            store.finish(job["id"], error=str(error)[:500], lease=job['updated'])


@asynccontextmanager
async def lifespan(app):
    if ROLE == 'grader':
        def recover():
            import docker
            client = docker.from_env(timeout=10)
            for container in client.containers.list(all=True, filters={'label': 'moodle-notebook=grader'}):
                container.remove(force=True)
            store.recover()
        await asyncio.to_thread(recover)
    tasks = [asyncio.create_task(worker()) for _ in range(4)] if ROLE == "grader" else [asyncio.create_task(leases())]
    yield
    for task in tasks:
        task.cancel()
    await asyncio.gather(*tasks, return_exceptions=True)


app = FastAPI(lifespan=lifespan, dependencies=[Depends(authorize)])


@app.middleware("http")
async def body_limit(request: Request, call_next):
    from fastapi.responses import JSONResponse
    chunks, size = [], 0
    async for chunk in request.stream():
        size += len(chunk)
        if size > MAX_BYTES * 3:
            return JSONResponse({"detail": "Request too large"}, 413)
        chunks.append(chunk)
    request._body = b''.join(chunks)
    return await call_next(request)


@app.exception_handler(ValueError)
async def invalid(request, error):
    from fastapi.responses import JSONResponse
    return JSONResponse({"detail": str(error)}, 422)


@app.get("/v1/health")
def health():
    with store.connect() as db:
        counts = {r['status']: r['n'] for r in db.execute('SELECT status,COUNT(*) AS n FROM jobs GROUP BY status')}
        used = db.execute('SELECT COUNT(*) FROM slots').fetchone()[0]
    disk = shutil.disk_usage(ROOT)
    return {"status": "ok", "role": ROLE, "sessions": len(store.sessions()), 'jobs': counts,
            'workspaces': used, 'state_disk_free_bytes': disk.free}


@app.post("/v1/jobs")
def enqueue(data: dict, idempotency_key: str = Header()):
    if ROLE != "grader" or data.get("kind") not in ("validate", "grade"):
        raise HTTPException(400, "Unsupported operation")
    data["payload"]["bundle"] = bundle(data["payload"]["bundle"])
    if data["kind"] == "grade":
        files(data["payload"]["files"])
    ident = store.enqueue(idempotency_key, data["kind"], data["payload"])
    return {"id": ident, "status": store.get(ident)["status"]}


@app.get("/v1/jobs/{ident}")
def job(ident: str):
    try:
        row = store.get(ident)
        return {key: row[key] for key in ("id", "status", "result", "error")}
    except KeyError:
        raise HTTPException(404, "Unknown job")


def workspace(identity):
    key = digest({k: identity[k] for k in ("site", "userid", "cmid", "version", "teacher")})
    root = Path(os.environ.get("WORKSPACE_ROOT", "/workspaces"))
    # Host provisioning prepares quota-limited slots; never silently fall back to unlimited storage.
    with store.connect() as db:
        db.execute("BEGIN IMMEDIATE")
        row = db.execute("SELECT slot,initialized FROM slots WHERE workspace=?", (key,)).fetchone()
        if row:
            return root / str(row["slot"]), not bool(row['initialized'])
        used = {r[0] for r in db.execute("SELECT slot FROM slots")}
        for slot in range(int(os.getenv("WORKSPACE_SLOTS", "128"))):
            target = root / str(slot)
            if slot not in used and target.is_dir() and not target.is_symlink() and not any(target.iterdir()) \
                    and (root / (str(slot) + ".quota")).is_file():
                db.execute("INSERT INTO slots(slot,workspace,identity) VALUES(?,?,?)", (slot, key, json.dumps({k: identity[k]
                    for k in ('site', 'userid', 'cmid', 'version', 'teacher')})))
                return target, True
    raise HTTPException(503, "No quota-limited workspace available")


@app.post("/v1/launch")
async def launch(data: dict):
    if ROLE != "workspace":
        raise HTTPException(400, "Workspace server required")
    identity = await moodle("exchange", {"code": data["code"]})
    from .runner import environment
    image = environment(identity.get('environment'))
    username = "m-" + digest({"site": identity["site"], "userid": identity["userid"]})[:32]
    async with user_lock(username):
        return await prepare_launch(identity, image, username)


async def prepare_launch(identity, image, username):
    await stop(username)
    for previous in store.sessions():
        if previous['username'] == username:
            store.remove_session(previous['id'])
    path, fresh = workspace(identity)
    if fresh:
        # A failed initialization remains owned and is retried before any student server can open it.
        for name, raw in files(identity["files"]).items():
            target = path / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(raw)
            os.chown(target, 1000, 1000)
        for folder in path.rglob("*"):
            if folder.is_dir():
                os.chown(folder, 1000, 1000)
        with store.connect() as db:
            db.execute('UPDATE slots SET initialized=1 WHERE slot=?', (int(path.name),))
    identity["slot"] = path.name
    store.session(identity["lease"], username, identity)
    return {"name": username, "auth_state": {"slot": path.name, "lease": identity["lease"],
            "cmid": identity["cmid"], "teacher": identity["teacher"], "return_url": identity["return_url"],
            "egress_hosts": identity.get('egress_hosts', []), 'environment': image}}


@app.post("/v1/capture")
async def capture(data: dict):
    session = next((s for s in store.sessions() if s["id"] == data["lease"]), None)
    if not session:
        raise HTTPException(404, "Open the notebook before submitting")
    async with user_lock(session['username']):
        if not any(s['id'] == session['id'] for s in store.sessions()):
            raise HTTPException(409, 'The workspace changed; return to Moodle and reopen it')
        return await capture_session(session)


async def capture_session(session):
    await stop(session["username"])
    path = Path(os.getenv("WORKSPACE_ROOT", "/workspaces")) / session["payload"]["slot"]
    result, size = {}, 0
    from .contracts import filename
    for target in path.rglob("*"):
        if target.is_symlink():
            raise HTTPException(422, "Symbolic links are not accepted")
        if not target.is_file() or any(p.startswith(".") for p in target.relative_to(path).parts):
            continue
        name = target.relative_to(path).as_posix()
        filename(name)
        size += target.stat().st_size
        if size > MAX_BYTES or len(result) >= 100:
            raise HTTPException(422, "Submission exceeds capture limits")
        result[name] = base64.b64encode(target.read_bytes()).decode()
    files(result)
    return {"files": result, "sha256": digest(result)}


@app.post("/v1/revoke")
async def revoke(data: dict):
    for session in store.sessions():
        if session["id"] == data["lease"]:
            await stop(session["username"])
            store.remove_session(session["id"])
    return {"ok": True}


def matches(identity, query):
    return identity.get('site') == query.get('site') and identity.get('cmid') == query.get('cmid') and (
        not query.get('userid') or identity.get('userid') == query['userid'])


def owned_slots(query):
    with store.connect() as db:
        return [dict(row) for row in db.execute('SELECT * FROM slots') if matches(json.loads(row['identity']), query)]


async def stop_owned(query):
    for session in store.sessions():
        if matches(session['payload'], query):
            await stop(session['username'])
            store.remove_session(session['id'])


@app.post('/v1/data/export')
async def export_data(data: dict):
    if ROLE != 'workspace':
        raise HTTPException(400, 'Workspace service required')
    # Administrative privacy export stops notebooks to keep files stable.
    await stop_owned(data)
    entries = []
    root = Path(os.getenv('WORKSPACE_ROOT', '/workspaces'))
    for slot in owned_slots(data):
        folder = root / str(slot['slot'])
        for file in sorted(folder.rglob('*')):
            if file.is_symlink() or file.is_file():
                entries.append((slot['slot'], folder, file))
    index, offset = int(data.get('index', 0)), int(data.get('offset', 0))
    if index < 0 or offset < 0:
        raise ValueError('Invalid export cursor')
    if index >= len(entries):
        return {'entry': None, 'next': None}
    slot, folder, file = entries[index]
    entry = {'workspace': slot, 'path': file.relative_to(folder).as_posix(), 'offset': offset}
    if file.is_symlink():
        entry['symlink'] = os.readlink(file)
        next_cursor = {'index': index + 1, 'offset': 0}
    else:
        entry['size'] = file.stat().st_size
        with file.open('rb') as handle:
            handle.seek(offset)
            raw = handle.read(4 * 1024 * 1024)
        entry['base64'] = base64.b64encode(raw).decode()
        next_cursor = {'index': index, 'offset': offset + len(raw)} if offset + len(raw) < entry['size'] else {
            'index': index + 1, 'offset': 0}
    return {'entry': entry, 'next': next_cursor if next_cursor['index'] < len(entries) else None}


@app.post('/v1/data/delete')
async def delete_data(data: dict):
    if not data.get('site') or not isinstance(data.get('cmid'), int) or data['cmid'] <= 0:
        raise ValueError('Explicit site and activity required')
    if ROLE == 'workspace':
        await stop_owned(data)
        root = Path(os.getenv('WORKSPACE_ROOT', '/workspaces')).resolve()
        for slot in owned_slots(data):
            folder = root / str(slot['slot'])
            if folder.is_symlink() or folder.resolve().parent != root:
                raise ValueError('Invalid workspace root')
            for child in folder.iterdir():
                if child.is_dir() and not child.is_symlink():
                    shutil.rmtree(child)
                else:
                    child.unlink()
            with store.connect() as db:
                db.execute('DELETE FROM slots WHERE slot=?', (slot['slot'],))
    else:
        with store.connect() as db:
            selected = [r for r in db.execute('SELECT id,status,payload FROM jobs')
                        if matches(json.loads(r['payload']).get('owner', {}), data)]
            if any(r['status'] == 'running' for r in selected):
                raise HTTPException(409, 'Wait for running corrections to finish before erasure')
            for row in selected:
                db.execute('DELETE FROM jobs WHERE id=?', (row['id'],))
    with store.connect() as db:
        db.execute('PRAGMA wal_checkpoint(TRUNCATE)')
    return {'deleted': True}
