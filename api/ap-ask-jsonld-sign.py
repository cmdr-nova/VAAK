#!/usr/bin/env python3
"""Create the Wafrn RsaSignature2017 envelope used for AskQuestion."""
import argparse
import hashlib
import http.client
import ipaddress
import json
import os
import socket
import ssl
import subprocess
import sys
import urllib.parse

# Production keeps the JSON-LD runtime outside the web tree; local development
# can use a normal virtualenv or PYTHONPATH.
for candidate in (os.environ.get("VAAK_JSONLD_LIB", ""), "/opt/vaak-jsonld"):
    if candidate and candidate not in sys.path:
        sys.path.insert(0, candidate)

MAX_REDIRECTS = 3
MAX_CONTEXT_BYTES = 1024 * 1024


def _jsonld():
    try:
        import pyld.jsonld as jsonld
    except Exception as exc:  # pragma: no cover - exercised on hosts without the optional runtime
        print(f"jsonld runtime unavailable: {exc}", file=sys.stderr)
        raise SystemExit(2)
    return jsonld


def ip_is_public(text):
    """Same answers as ap_net_ip_is_public in api/ap-net-guard.php."""
    try:
        ip = ipaddress.ip_address(text.split("%", 1)[0].strip("[]"))
    except ValueError:
        return False
    if ip.version == 6:
        packed = ip.packed
        embedded = None
        if packed[:10] == bytes(10) and packed[10:12] in (b"\xff\xff", b"\x00\x00"):
            embedded = packed[12:16]  # ::ffff:a.b.c.d and ::a.b.c.d
        elif packed[:12] == b"\x00\x64\xff\x9b" + bytes(8):
            embedded = packed[12:16]  # NAT64 64:ff9b::/96
        elif packed[:2] == b"\x20\x02":
            embedded = packed[2:6]  # 6to4 2002::/16
        if embedded is not None:
            return ip_is_public(str(ipaddress.IPv4Address(embedded)))
        blocked = ("ff00::/8", "fc00::/7", "fe80::/10", "fec0::/10", "100::/64", "2001:db8::/32", "2001:10::/28")
    else:
        blocked = ("0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16", "172.16.0.0/12",
                   "192.0.0.0/24", "192.168.0.0/16", "198.18.0.0/15", "224.0.0.0/4", "240.0.0.0/4")
    return not any(ip in ipaddress.ip_network(net) for net in blocked)


def public_addresses(host):
    """Every address host resolves to, or [] when any of them is not public."""
    host = host.strip("[]").rstrip(".").lower()
    if not host or host == "localhost" or host.endswith((".localhost", ".local", ".internal")):
        return []
    try:
        ipaddress.ip_address(host)
        return [host] if ip_is_public(host) else []
    except ValueError:
        pass
    try:
        infos = socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)
    except OSError:
        return []
    addrs = sorted({info[4][0] for info in infos})
    return addrs if addrs and all(ip_is_public(a) for a in addrs) else []


class PinnedHTTPSConnection(http.client.HTTPSConnection):
    """Connects only to addresses the guard checked; TLS still verifies the name."""

    def __init__(self, host, addresses, **kwargs):
        super().__init__(host, context=ssl.create_default_context(), **kwargs)
        self._addresses = addresses

    def connect(self):
        error = None
        for address in self._addresses:
            try:
                sock = socket.create_connection((address, self.port), self.timeout)
            except OSError as exc:
                error = exc
                continue
            self.sock = self._context.wrap_socket(sock, server_hostname=self.host)
            return
        raise error or OSError("no address to connect to")


def fetch_context(url):
    """GET a remote JSON-LD context. https only, public hosts only, and each
    redirect hop is checked again before it is followed."""
    for _ in range(MAX_REDIRECTS + 1):
        parts = urllib.parse.urlsplit(url)
        if parts.scheme != "https" or not parts.hostname:
            raise ValueError(f"refused JSON-LD context URL {url}")
        addrs = public_addresses(parts.hostname)
        if not addrs:
            raise ValueError(f"JSON-LD context host is not public: {parts.hostname}")
        conn = PinnedHTTPSConnection(parts.hostname, addrs, port=parts.port or 443, timeout=8)
        try:
            path = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")
            conn.request("GET", path, headers={
                "Accept": "application/ld+json, application/json",
                "User-Agent": "VAAK-ActivityPub/1.0",
            })
            response = conn.getresponse()
            if response.status in (301, 302, 303, 307, 308) and response.getheader("Location"):
                url = urllib.parse.urljoin(url, response.getheader("Location"))
                continue
            if response.status != 200:
                raise ValueError(f"JSON-LD context HTTP {response.status}")
            body = response.read(MAX_CONTEXT_BYTES + 1)
            if len(body) > MAX_CONTEXT_BYTES:
                raise ValueError("JSON-LD context too large")
            return url, json.loads(body.decode("utf-8"))
        finally:
            conn.close()
    raise ValueError("too many JSON-LD context redirects")


def loader(url, options=None):
    final_url, document = fetch_context(url)
    return {"contextUrl": None, "documentUrl": final_url, "document": document}


def normalize(value):
    return _jsonld().normalize(
        value,
        {"algorithm": "URDNA2015", "format": "application/n-quads", "documentLoader": loader},
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--key", required=True)
    parser.add_argument("--creator", required=True)
    parser.add_argument("--context", required=True)
    args = parser.parse_args()
    data = json.load(sys.stdin)
    nonce = os.urandom(16).hex()
    options = {
        "type": "RsaSignature2017",
        "creator": args.creator,
        "nonce": nonce,
        "created": __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z"),
        "@context": args.context,
    }
    option_for_signing = dict(options)
    option_for_signing.pop("type", None)
    option_nquads = normalize(option_for_signing)
    data_for_signing = dict(data)
    data_for_signing.pop("signature", None)
    data_nquads = normalize(data_for_signing)
    verify_data = hashlib.sha256(option_nquads.encode()).hexdigest() + hashlib.sha256(data_nquads.encode()).hexdigest()
    signed = subprocess.run(
        ["openssl", "dgst", "-sha256", "-sign", args.key],
        input=verify_data.encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False,
    )
    if signed.returncode != 0:
        print(signed.stderr.decode("utf-8", "replace"), file=sys.stderr)
        return 3
    data["signature"] = {**{k: v for k, v in options.items() if k != "@context"}, "signatureValue": __import__("base64").b64encode(signed.stdout).decode("ascii")}
    print(json.dumps(data, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
