#!/usr/bin/env python3
"""Separate-process SQLite replay fixture. Never loads a provider or runtime authority."""
import multiprocessing as mp
from pathlib import Path
import sqlite3
import tempfile
import time
import unittest


def operation(database, command, owner, tick):
    with sqlite3.connect(database, timeout=5) as db:
        db.execute("PRAGMA synchronous=FULL")
        db.execute("BEGIN IMMEDIATE")
        row = db.execute("SELECT state, owner, expires FROM admissions WHERE operation_id='fixture-operation'").fetchone()
        if command == "reserve" and row is None:
            db.execute("INSERT INTO admissions VALUES ('fixture-operation', 'RESERVED', ?, ?)", (owner, tick + 10))
            return "RESERVED"
        if command == "finish" and row is not None and row[0:2] == ("RESERVED", owner) and tick < row[2]:
            db.execute("UPDATE admissions SET state='UNKNOWN' WHERE operation_id='fixture-operation'")
            return "UNKNOWN"
        return "DENIED"


def worker(database, command, owner, tick, start, sender):
    if not start.wait(5):
        raise RuntimeError("fixture process synchronization timed out")
    sender.send(operation(database, command, owner, tick))
    sender.close()


class ReplayAcceptance(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.database = str(Path(self.directory.name) / "anchor.sqlite")
        with sqlite3.connect(self.database) as db:
            db.execute("CREATE TABLE admissions (operation_id TEXT PRIMARY KEY, state TEXT NOT NULL,"
                       "owner TEXT NOT NULL, expires INTEGER NOT NULL)")
        self.context = mp.get_context("spawn")

    def launch(self, command, owners, tick):
        start = self.context.Event()
        claims = []
        for owner in owners:
            receiver, sender = self.context.Pipe(duplex=False)
            process = self.context.Process(target=worker, args=(self.database, command, owner, tick, start, sender))
            process.start()
            sender.close()
            claims.append((owner, process, receiver))
        start.set()
        return start, claims

    def run_workers(self, command, owners, tick):
        results = {}
        start, claims = self.launch(command, owners, tick)
        try:
            for owner, process, receiver in claims:
                self.assertTrue(receiver.poll(8))
                results[owner] = receiver.recv()
                process.join(5)
                self.assertEqual(0, process.exitcode)
        finally:
            for _, process, receiver in claims:
                if process.is_alive():
                    process.kill()
                process.join(5)
                receiver.close()
        return results

    def test_concurrent_claim_and_unknown_never_rearm(self):
        results = self.run_workers("reserve", ("actor-a", "actor-b"), 10)
        self.assertCountEqual(["RESERVED", "DENIED"], results.values())
        winner = next(actor for actor, state in results.items() if state == "RESERVED")
        loser = next(actor for actor, state in results.items() if state == "DENIED")
        self.assertEqual("DENIED", self.run_workers("finish", (loser,), 11)[loser])
        self.assertEqual("UNKNOWN", self.run_workers("finish", (winner,), 11)[winner])
        self.assertEqual("DENIED", self.run_workers("finish", (winner,), 12)[winner])
        self.assertEqual("DENIED", self.run_workers("reserve", ("new-process",), 30)["new-process"])
        with sqlite3.connect(self.database) as db:
            self.assertEqual([("UNKNOWN",)], db.execute("SELECT state FROM admissions").fetchall())

    def test_committed_reservation_survives_terminated_worker_and_expiry(self):
        # The child commits, reports readiness, then waits until explicitly killed.
        context = self.context
        start = context.Event()
        receiver, sender = context.Pipe(duplex=False)
        process = context.Process(target=parked_worker, args=(self.database, start, sender))
        process.start()
        sender.close()
        try:
            start.set()
            self.assertTrue(receiver.poll(8))
            self.assertEqual("RESERVED", receiver.recv())
        finally:
            process.kill()
            process.join(5)
            receiver.close()
        self.assertLess(process.exitcode, 0)
        self.assertEqual("DENIED", self.run_workers("reserve", ("recovery",), 21)["recovery"])
        self.assertEqual("DENIED", self.run_workers("finish", ("crashed",), 21)["crashed"])
        with sqlite3.connect(self.database) as db:
            self.assertEqual([("RESERVED",)], db.execute("SELECT state FROM admissions").fetchall())


def parked_worker(database, start, sender):
    worker(database, "reserve", "crashed", 10, start, sender)
    time.sleep(30)


if __name__ == "__main__":
    unittest.main(verbosity=2)
