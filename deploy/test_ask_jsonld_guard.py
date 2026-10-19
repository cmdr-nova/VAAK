#!/usr/bin/env python3
"""The Wafrn Ask JSON-LD helper only fetches contexts from public https hosts."""
import importlib.util
import json
import pathlib
import subprocess
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("ask_jsonld_sign", ROOT / "api" / "ap-ask-jsonld-sign.py")
sign = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sign)

CASES = [
    "8.8.8.8", "10.0.0.1", "100.64.0.1", "127.0.0.1", "169.254.169.254", "172.16.0.1", "192.0.0.1", "192.0.2.1",
    "192.168.1.1", "198.18.0.1", "224.0.0.1", "240.0.0.1", "0.1.2.3",
    "::", "::1", "::ffff:8.8.8.8", "::ffff:127.0.0.1", "::127.0.0.1", "64:ff9b::808:808", "64:ff9b::a9fe:a9fe",
    "2002:808:808::1", "2002:7f00:1::", "fc00::1", "fd12::1", "fe80::1", "fe80::1%eth0", "fec0::1", "ff02::1",
    "100::1", "2001:db8::1", "2001:10::1", "2001::1", "2606:4700:4700::1111", "[::1]", "not-an-ip",
]


class AskJsonLdGuardTest(unittest.TestCase):
    def test_matches_php_guard(self) -> None:
        php = subprocess.run(
            ["php", "-r", "require $argv[1]; echo json_encode(array_map('ap_net_ip_is_public', json_decode(stream_get_contents(STDIN), true)));",
             str(ROOT / "api" / "ap-net-guard.php")],
            input=json.dumps(CASES), capture_output=True, text=True, check=True,
        )
        for ip, expected in zip(CASES, json.loads(php.stdout)):
            self.assertEqual(sign.ip_is_public(ip), expected, ip)

    def test_refused_hosts_have_no_addresses(self) -> None:
        for host in ["localhost", "a.localhost", "printer.local", "db.internal", "127.0.0.1", "[::1]", "", "no-such-host.invalid"]:
            self.assertEqual(sign.public_addresses(host), [], host)
        self.assertEqual(sign.public_addresses("8.8.8.8"), ["8.8.8.8"])

    def test_any_private_answer_refuses_the_name(self) -> None:
        answers = [(None, None, None, None, ("93.184.215.14", 443)), (None, None, None, None, ("fd00::1", 443, 0, 0))]
        with mock.patch.object(sign.socket, "getaddrinfo", return_value=answers):
            self.assertEqual(sign.public_addresses("context.example"), [])
        with mock.patch.object(sign.socket, "getaddrinfo", return_value=answers[:1]):
            self.assertEqual(sign.public_addresses("context.example"), ["93.184.215.14"])

    def test_loader_refuses_before_connecting(self) -> None:
        with mock.patch.object(sign.socket, "create_connection", side_effect=AssertionError("connected")):
            for url in ["http://www.w3.org/ns/activitystreams", "https://127.0.0.1/ctx", "https://localhost/ctx",
                        "https://[::ffff:10.0.0.1]/ctx", "file:///etc/passwd", "https:///nohost"]:
                with self.assertRaises(ValueError, msg=url):
                    sign.loader(url)

    def test_redirect_to_private_host_is_not_followed(self) -> None:
        class Response:
            status = 302
            def getheader(self, name):
                return "https://169.254.169.254/latest/meta-data" if name == "Location" else None

        class Conn:
            def __init__(self, host, addresses, **kwargs):
                Conn.seen.append((host, addresses))
            def request(self, *args, **kwargs):
                pass
            def getresponse(self):
                return Response()
            def close(self):
                pass
        Conn.seen = []
        with mock.patch.object(sign, "PinnedHTTPSConnection", Conn), \
                mock.patch.object(sign, "public_addresses", side_effect=lambda h: ["93.184.215.14"] if h == "w3id.org" else []):
            with self.assertRaises(ValueError):
                sign.loader("https://w3id.org/security/v1")
        self.assertEqual(Conn.seen, [("w3id.org", ["93.184.215.14"])])


if __name__ == "__main__":
    unittest.main()
