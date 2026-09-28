"""BitcoTasks HTTP client — firewall → offerwall → PTC lead (from HAR)."""
from __future__ import annotations

import json
import random
import re
import time
from typing import Any
from urllib.parse import urljoin, urlparse

import requests

from .notif_cookie import make_notif_cookies

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/153.0.0.0 Safari/537.36"
)


def parse_proxy(p: str | None) -> str | None:
    if not p:
        return None
    p = p.strip()
    if not p:
        return None
    if p.startswith("http://") or p.startswith("socks"):
        return p
    parts = p.split(":")
    if len(parts) == 4:
        host, port, user, pwd = parts
        return f"http://{user}:{pwd}@{host}:{port}"
    if len(parts) == 2:
        return f"http://{p}"
    return p


def base64_decode(s: str) -> bytes:
    import base64

    pad = "=" * (-len(s) % 4)
    return base64.b64decode(s + pad)


class BitcoClient:
    def __init__(
        self,
        key: str,
        sub_id: str,
        proxy: str | None = None,
        base: str = "https://bitcotasks.com",
    ):
        self.key = key
        self.sub_id = str(sub_id)
        self.base = base.rstrip("/")
        self.s = requests.Session()
        self.s.headers.update(
            {
                "User-Agent": UA,
                "Accept": "application/json, text/javascript, */*; q=0.01",
                "Accept-Language": "en-US,en;q=0.9",
                "Origin": self.base,
                "Referer": f"{self.base}/",
            }
        )
        px = parse_proxy(proxy)
        if px:
            self.s.proxies = {"http": px, "https": px}
        self.offer_token: str | None = None
        self.offerwall_path: str | None = None

    def _apply_notif(self):
        for k, v in make_notif_cookies().items():
            self.s.cookies.set(k, v, domain=urlparse(self.base).hostname)

    def load_firewall(self) -> str:
        url = f"{self.base}/firewall.php"
        r = self.s.get(url, params={"key": self.key, "sub_id": self.sub_id}, timeout=60)
        r.raise_for_status()
        return r.text

    def extract_captcha_js(self, html: str) -> str | None:
        m = re.search(r"/captcha2/([a-f0-9]{32,})\.js\?action=captcha", html)
        if m:
            return f"{self.base}/captcha2/{m.group(1)}.js?action=captcha"
        m = re.search(r'captcha2/([^"\']+\.js\?action=captcha)', html)
        return urljoin(self.base + "/", m.group(0)) if m else None

    def fetch_captcha_gif(self, captcha_url: str) -> tuple[bytes, dict]:
        r = self.s.post(
            captcha_url,
            json={"t": int(time.time() * 1000), "r": random.random()},
            headers={"Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest"},
            timeout=60,
        )
        r.raise_for_status()
        j = r.json()
        preload = j.get("preload") or ""
        if preload.startswith("R0lGOD"):
            return base64_decode(preload), j
        if "," in preload and "base64" in preload[:40]:
            return base64_decode(preload.split(",", 1)[1]), j
        try:
            return base64_decode(preload), j
        except Exception:
            return preload.encode() if isinstance(preload, str) else preload, j

    def submit_captcha_click(self, captcha_url: str, x: float, y: float, meta: dict) -> dict:
        cdata = meta.get("cdata") or meta.get("h")
        coords = json.dumps([int(x), int(y)])
        body = {
            "action": "data",
            "x": int(x),
            "y": int(y),
            "coords": coords,
            "point": coords,
        }
        params = {"action": "data"}
        if cdata:
            params["cdata"] = cdata
        r = self.s.post(
            captcha_url.split("?")[0],
            params=params,
            data=body,
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest",
            },
            timeout=60,
        )
        try:
            return r.json()
        except Exception:
            return {"raw": r.text, "status_code": r.status_code}

    def validate_firewall(self, token: str) -> str:
        r = self.s.post(
            f"{self.base}/firewall.php",
            params={"key": self.key, "sub_id": self.sub_id},
            data={"action": "validate", "token": token, "UEjS": token},
            headers={"X-Requested-With": "XMLHttpRequest"},
            timeout=60,
        )
        j = r.json()
        if j.get("status") != "success" and not j.get("redirect"):
            raise RuntimeError(f"firewall validate failed: {j}")
        redirect = j.get("redirect") or ""
        self.offerwall_path = redirect
        parts = redirect.rstrip("/").split("/")
        if parts:
            self.offer_token = parts[-1]
        return redirect

    def open_offerwall(self, url: str | None = None) -> str:
        url = url or self.offerwall_path
        if not url:
            raise RuntimeError("no offerwall url")
        if not url.startswith("http"):
            url = urljoin(self.base + "/", url)
        r = self.s.get(url, timeout=60)
        r.raise_for_status()
        m = re.search(r'token["\']?\s*[:=]\s*["\']([a-f0-9]{32,})["\']', r.text)
        if m:
            self.offer_token = m.group(1)
        return r.text

    def switch_ptc(self) -> list[dict]:
        if not self.offerwall_path or not self.offer_token:
            raise RuntimeError("offerwall not ready")
        url = self.offerwall_path
        if not url.startswith("http"):
            url = urljoin(self.base + "/", url)
        r = self.s.post(
            url,
            data={
                "token": self.offer_token,
                "action": "switch_cat",
                "type": "ptc",
            },
            headers={"X-Requested-With": "XMLHttpRequest"},
            timeout=60,
        )
        j = r.json()
        return j.get("items") or []

    def init_transaction(self, item: dict) -> str:
        url = self.offerwall_path
        if not url.startswith("http"):
            url = urljoin(self.base + "/", url)
        r = self.s.post(
            url,
            data={
                "token": self.offer_token,
                "action": "init_transaction",
                "hash": item["hash"],
                "sid": item.get("sid") or self.sub_id,
                "key": item.get("key") or self.key,
                "type": item.get("type") or "ptc",
            },
            headers={"X-Requested-With": "XMLHttpRequest"},
            timeout=60,
        )
        j = r.json()
        offer = j.get("offer") or ""
        if not offer:
            raise RuntimeError(f"init_transaction failed: {j}")
        return offer if offer.startswith("http") else urljoin(self.base + "/", offer)

    def start_view(self, lead_url: str) -> None:
        self._apply_notif()
        r = self.s.post(
            lead_url,
            data={"action": "start_view"},
            headers={"X-Requested-With": "XMLHttpRequest"},
            timeout=60,
        )
        if r.text.strip().lower() not in ("ok", "success") and r.status_code >= 400:
            raise RuntimeError(f"start_view: {r.status_code} {r.text[:200]}")

    def process_lead(self, item: dict, lead_url: str, wait_s: float | None = None) -> dict:
        duration = float(item.get("duration") or 2)
        if wait_s is None:
            wait_s = max(duration, 2) + random.uniform(0.3, 1.2)
        time.sleep(wait_s)
        self._apply_notif()
        token = lead_url.rstrip("/").split("/")[-1]
        atxr = item.get("atxrN") or self.s.cookies.get("atxrN") or ""
        r = self.s.post(
            f"{self.base}/system/ajax.php",
            data={
                "hash": item["hash"],
                "sub_id": item.get("sid") or self.sub_id,
                "key": item.get("key") or self.key,
                "token": token,
                "action": "proccessLead",
                "atxrN": atxr,
            },
            headers={"X-Requested-With": "XMLHttpRequest"},
            timeout=60,
        )
        try:
            return r.json()
        except Exception:
            return {"raw": r.text, "status_code": r.status_code}

    def get_notif_ad(self) -> dict:
        self._apply_notif()
        r = self.s.get(f"{self.base}/getads.php", timeout=30)
        try:
            return r.json()
        except Exception:
            return {}
