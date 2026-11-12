import json
import os
import sqlite3
import time
import uuid
from contextlib import contextmanager
from .contracts import digest


class Store:
    def __init__(self, path):
        self.path = str(path)
        with self.connect() as db:
            db.executescript('''
                PRAGMA journal_mode=WAL;
                PRAGMA secure_delete=ON;
                CREATE TABLE IF NOT EXISTS jobs (
                    id TEXT PRIMARY KEY, requestkey TEXT UNIQUE NOT NULL, fingerprint TEXT NOT NULL,
                    kind TEXT NOT NULL, payload TEXT NOT NULL, status TEXT NOT NULL,
                    result TEXT, error TEXT, updated REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS sessions (
                    id TEXT PRIMARY KEY, username TEXT NOT NULL, payload TEXT NOT NULL, updated REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS slots (
                    slot INTEGER PRIMARY KEY, workspace TEXT UNIQUE, identity TEXT NOT NULL,
                    initialized INTEGER NOT NULL DEFAULT 0);
            ''')
            if 'initialized' not in {r['name'] for r in db.execute('PRAGMA table_info(slots)')}:
                db.execute('ALTER TABLE slots ADD COLUMN initialized INTEGER NOT NULL DEFAULT 1')

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=30)
        db.row_factory = sqlite3.Row
        db.execute('PRAGMA secure_delete=ON')
        try:
            with db:
                yield db
        finally:
            db.close()

    def enqueue(self, key, kind, payload):
        fingerprint = digest({"kind": kind, "payload": payload})
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            row = db.execute("SELECT * FROM jobs WHERE requestkey=?", (key,)).fetchone()
            if row:
                if row["fingerprint"] != fingerprint:
                    raise ValueError("Idempotency key reused with a different payload")
                return row["id"]
            ident = uuid.uuid4().hex
            db.execute("INSERT INTO jobs VALUES(?,?,?,?,?,?,?,?,?)",
                       (ident, key, fingerprint, kind, json.dumps(payload), "queued", None, None, time.time()))
            return ident

    def get(self, ident):
        with self.connect() as db:
            row = db.execute("SELECT * FROM jobs WHERE id=?", (ident,)).fetchone()
        if not row:
            raise KeyError(ident)
        return {**dict(row), "payload": json.loads(row["payload"]),
                "result": json.loads(row["result"]) if row["result"] else None}

    def claim(self):
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            # A lease exceeds the hard Docker timeout. Recover interrupted jobs.
            lease = 2 * min(3600, max(1, int(os.getenv('COPY_TIMEOUT', '300')))) + 60
            db.execute("UPDATE jobs SET status='queued' WHERE status='running' AND updated<?", (time.time() - lease,))
            row = db.execute("SELECT id FROM jobs WHERE status='queued' ORDER BY updated LIMIT 1").fetchone()
            if not row:
                return None
            db.execute("UPDATE jobs SET status='running', updated=? WHERE id=?", (time.time(), row["id"]))
        return self.get(row["id"])

    def recover(self):
        """Call only after the dedicated grader's old containers have been stopped."""
        with self.connect() as db:
            db.execute("UPDATE jobs SET status='queued', updated=? WHERE status='running'", (time.time(),))

    def finish(self, ident, result=None, error=None, lease=None):
        with self.connect() as db:
            where = "id=? AND status='running'"
            params = ["failed" if error else "done", json.dumps(result), error, time.time(), ident]
            if lease is not None:
                where += ' AND updated=?'
                params.append(lease)
            return db.execute("UPDATE jobs SET status=?, result=?, error=?, updated=? WHERE " + where, params).rowcount == 1

    def session(self, ident, username, payload):
        with self.connect() as db:
            db.execute("INSERT OR REPLACE INTO sessions VALUES(?,?,?,?)",
                       (ident, username, json.dumps(payload), time.time()))

    def sessions(self):
        with self.connect() as db:
            return [{**dict(r), "payload": json.loads(r["payload"])} for r in db.execute("SELECT * FROM sessions")]

    def remove_session(self, ident):
        with self.connect() as db:
            db.execute("DELETE FROM sessions WHERE id=?", (ident,))
