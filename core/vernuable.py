"""Vernuable captcha client — shared across all bots.

Docs: https://vernuable.my.id/docs
"""
from __future__ import annotations

import base64
import time
from typing import Any

import requests


class Vernuable:
    def __init__(
        self,
        api_key: str,
        base: str = "https://vernuable.my.id",
        timeout: int = 180,
        poll: float = 2.0,
    ):
        self.key = api_key
        self.base = base.rstrip("/")
        self.timeout = timeout
        self.poll = poll
        self.s = requests.Session()
        self.s.headers.update({"User-Agent": "VernuableOfficialScripts/1.0"})

    def balance(self) -> float:
        r = self.s.get(
            f"{self.base}/res.php",
            params={"key": self.key, "action": "getbalance", "json": 1},
            timeout=30,
        )
        j = r.json()
        if str(j.get("status")) == "1":
            return float(j.get("request") or j.get("balance") or 0)
        raise RuntimeError(j)

    def solve_bitcotask(self, gif_bytes: bytes) -> dict[str, Any]:
        """Submit motion GIF; returns {x, y} click point."""
        b64 = base64.b64encode(gif_bytes).decode()
        r = self.s.post(
            f"{self.base}/in.php",
            data={
                "key": self.key,
                "method": "bitcotask",
                "body": b64,
                "json": 1,
            },
            timeout=60,
        )
        j = r.json()
        if str(j.get("status")) != "1":
            r = self.s.post(
                f"{self.base}/in.php",
                data={
                    "key": self.key,
                    "method": "bitcotask",
                    "image": b64,
                    "json": 1,
                },
                timeout=60,
            )
            j = r.json()
        if str(j.get("status")) != "1":
            raise RuntimeError(f"create failed: {j}")
        task_id = j["request"]
        t0 = time.time()
        while time.time() - t0 < self.timeout:
            time.sleep(self.poll)
            rr = self.s.get(
                f"{self.base}/res.php",
                params={"key": self.key, "action": "get", "id": task_id, "json": 1},
                timeout=30,
            )
            out = rr.json()
            st = str(out.get("status"))
            if st == "1":
                req = out.get("request")
                if isinstance(req, dict):
                    return {"x": float(req["x"]), "y": float(req["y"]), "raw": req}
                if isinstance(req, str) and "|" in req:
                    a, b = req.split("|", 1)
                    return {"x": float(a), "y": float(b), "raw": req}
                return {"raw": req}
            if st == "0" and "NOT_READY" not in str(out.get("request", "")):
                raise RuntimeError(f"solve failed: {out}")
        raise TimeoutError(f"bitcotask timeout id={task_id}")

    def solve(self, method: str, **kwargs) -> dict[str, Any]:
        """Generic entry for future methods (hcaptcha, turnstile, etc.)."""
        if method == "bitcotask":
            body = kwargs.get("body") or kwargs.get("gif") or kwargs.get("image")
            if isinstance(body, str):
                body = base64.b64decode(body)
            return self.solve_bitcotask(body)
        raise NotImplementedError(f"method={method} not wired yet — add in core/vernuable.py")
