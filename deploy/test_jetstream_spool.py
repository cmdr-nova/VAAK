#!/usr/bin/env python3
import importlib.util
import json
import pathlib
import sys
import tempfile
import types
import unittest
import urllib.parse

# CI does not install websocket-client; the spool logic under test never uses it.
stub = types.ModuleType("websocket")
stub.WebSocketTimeoutException = type("WebSocketTimeoutException", (Exception,), {})
sys.modules.setdefault("websocket", stub)

HERE = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("jetstream_spooler", HERE / "ap-bsky-jetstream.py")
js = importlib.util.module_from_spec(spec)
spec.loader.exec_module(js)


class Clock:
    def __init__(self):
        self.now = 1000.0

    def __call__(self):
        return self.now


class SegmentTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = pathlib.Path(self.tmp.name)
        self.clock = Clock()

    def tearDown(self):
        self.tmp.cleanup()

    def jsonl(self):
        return sorted(p.name for p in self.dir.glob("*.jsonl"))

    def test_open_segment_is_hidden_from_worker_glob(self):
        seg = js.Segment(self.dir, self.clock)
        seg.write('{"a":1}\n')
        self.assertEqual(self.jsonl(), [])
        self.assertEqual(len(list(self.dir.glob("*.jsonl.part"))), 1)
        seg.close()
        names = self.jsonl()
        self.assertEqual(len(names), 1)
        self.assertEqual((self.dir / names[0]).read_text(), '{"a":1}\n')
        self.assertEqual(list(self.dir.glob("*.part")), [])

    def test_segment_due_by_age_and_count(self):
        seg = js.Segment(self.dir, self.clock)
        self.assertFalse(seg.due())
        seg.write("{}\n")
        self.assertFalse(seg.due())
        self.clock.now += js.SEGMENT_MAX_AGE
        self.assertTrue(seg.due())
        seg.close()
        for _ in range(js.MAX_EVENTS):
            seg.write("{}\n")
        self.assertTrue(seg.due())
        seg.close()
        self.assertEqual(len(self.jsonl()), 2)

    def test_each_segment_gets_a_unique_name(self):
        seg = js.Segment(self.dir, self.clock)
        for n in range(3):
            seg.write('{"n":%d}\n' % n)
            seg.close()
        self.assertEqual(len(self.jsonl()), 3)

    def test_recover_partials_publishes_and_drops_empty(self):
        prefix = "events-%d-" % js.SHARD_INDEX
        (self.dir / (prefix + "1-1-000001.jsonl.part")).write_text("{}\n")
        (self.dir / (prefix + "2-1-000001.jsonl.part")).write_text("")
        other = self.dir / ("events-%d-3-1-000001.jsonl.part" % (js.SHARD_INDEX + 1))
        other.write_text("{}\n")
        js.recover_partials(self.dir)
        self.assertEqual(self.jsonl(), [prefix + "1-1-000001.jsonl"])
        self.assertTrue(other.exists())


class FilterTest(unittest.TestCase):
    def dids(self, n):
        return ["did:plc:%024d" % i for i in range(n)]

    def query(self, url):
        return urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)

    def test_small_list_stays_in_url(self):
        q = self.query(js.stream_url(self.dids(3), 0))
        self.assertEqual(len(q["wantedDids"]), 3)
        self.assertNotIn("requireHello", q)
        self.assertFalse(js.use_hello(self.dids(3)))

    def test_large_list_uses_hello(self):
        dids = self.dids(400)
        q = self.query(js.stream_url(dids, 1234))
        self.assertNotIn("wantedDids", q)
        self.assertEqual(q["requireHello"], ["true"])
        self.assertEqual(q["cursor"], ["1234"])
        msg = json.loads(js.hello_message(dids))
        self.assertEqual(msg["type"], "options_update")
        self.assertEqual(msg["payload"]["wantedDids"], dids)
        self.assertEqual(msg["payload"]["wantedCollections"], js.COLLECTIONS)

    def test_huge_list_falls_back_to_unfiltered(self):
        dids = self.dids(js.MAX_FILTER_DIDS_IN_HELLO + 1)
        self.assertFalse(js.use_hello(dids))
        q = self.query(js.stream_url(dids, 0))
        self.assertNotIn("requireHello", q)
        self.assertNotIn("wantedDids", q)

    def test_hello_drops_malformed_dids(self):
        msg = json.loads(js.hello_message(["did:plc:abc", "did:", "did:web:example.com", "did:plc:bad/slash"]))
        self.assertEqual(msg["payload"]["wantedDids"], ["did:plc:abc", "did:web:example.com"])


if __name__ == "__main__":
    unittest.main()
