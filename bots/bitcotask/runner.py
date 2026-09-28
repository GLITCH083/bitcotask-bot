"""BitcoTasks multi-account runner — called from main hub menu."""
from __future__ import annotations

import logging
import random
import sys
import time
import traceback
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

from core.config import ROOT, load_global_config, load_json, vernuable_from_config
from core.vernuable import Vernuable

from .client import BitcoClient
from .notif_cookie import make_notif_cookies

log = logging.getLogger("bitcotask")
BOT_DIR = Path(__file__).resolve().parent
ACCOUNTS_PATH = ROOT / "data" / "accounts" / "bitcotask.json"
ACCOUNTS_EXAMPLE = BOT_DIR / "accounts.example.json"


def smart_pick(items: list[dict], cfg: dict) -> dict | None:
    if not items:
        return None
    min_reward = float(cfg.get("min_reward", 1))
    max_duration = float(cfg.get("max_duration", 30))
    prefer_boosted = bool(cfg.get("prefer_boosted", True))

    usable = []
    for it in items:
        try:
            rew = float(it.get("reward") or 0)
            dur = float(it.get("duration") or 99)
        except Exception:
            continue
        if rew < min_reward or dur > max_duration:
            continue
        usable.append(it)
    if not usable:
        usable = list(items)
    if prefer_boosted:
        boosted = [x for x in usable if x.get("boosted")]
        if boosted:
            usable = boosted
    usable.sort(key=lambda x: float(x.get("reward") or 0), reverse=True)
    return usable[0]


def run_account(acc: dict, cfg: dict, vern: Vernuable) -> dict:
    name = acc.get("name") or acc.get("sub_id") or "acc"
    key = acc["key"]
    sub_id = str(acc["sub_id"])
    proxy = acc.get("proxy") or cfg.get("default_proxy")
    result = {"account": name, "ok": 0, "fail": 0, "errors": []}

    log.info("[%s] start proxy=%s", name, (proxy or "DIRECT")[:40])
    client = BitcoClient(key=key, sub_id=sub_id, proxy=proxy)

    try:
        html = client.load_firewall()
        cap_url = client.extract_captcha_js(html)
        if not cap_url:
            if "offerwall" in html:
                log.info("[%s] firewall already clear", name)
            else:
                raise RuntimeError("captcha URL not found on firewall page")
        else:
            log.info("[%s] captcha %s", name, cap_url[-48:])
            gif, meta = client.fetch_captcha_gif(cap_url)
            log.info("[%s] gif %d bytes → Vernuable bitcotask", name, len(gif))
            solved = vern.solve_bitcotask(gif)
            log.info("[%s] solved %s", name, solved)
            x, y = solved.get("x", 0), solved.get("y", 0)
            client.submit_captcha_click(cap_url, x, y, meta)
            token = None
            for v in meta.values() if isinstance(meta, dict) else []:
                if isinstance(v, str) and len(v) >= 40 and all(c in "0123456789abcdef" for c in v[:40]):
                    token = v
                    break
            if not token and isinstance(solved.get("raw"), str):
                token = solved["raw"]
            if not token:
                token = meta.get("token") or list(meta.values())[-1]
            redirect = client.validate_firewall(str(token))
            log.info("[%s] offerwall → %s", name, redirect[-60:])

        if client.offerwall_path:
            client.open_offerwall()
        else:
            raise RuntimeError("no offerwall path")

        rounds = int(acc.get("claims") or cfg.get("claims_per_account", 5))
        for i in range(rounds):
            try:
                items = client.switch_ptc()
                item = smart_pick(items, cfg.get("smart_claim") or {})
                if not item:
                    log.warning("[%s] no PTC items", name)
                    break
                log.info(
                    "[%s] claim #%d reward=%s dur=%ss boosted=%s | %s",
                    name, i + 1, item.get("reward"), item.get("duration"),
                    item.get("boosted"), (item.get("title") or "")[:40],
                )
                lead = client.init_transaction(item)
                client.start_view(lead)
                out = client.process_lead(item, lead)
                ok = out.get("status") == 200 or "SUCCESS" in str(out.get("message", ""))
                if ok:
                    result["ok"] += 1
                    log.info("[%s] SUCCESS %s", name, str(out.get("message", ""))[:80])
                else:
                    result["fail"] += 1
                    result["errors"].append(str(out)[:120])
                    log.warning("[%s] lead fail %s", name, out)
                time.sleep(random.uniform(
                    float(cfg.get("delay_min", 2)), float(cfg.get("delay_max", 5)),
                ))
            except Exception as e:
                result["fail"] += 1
                result["errors"].append(str(e))
                log.error("[%s] claim error: %s", name, e)
                time.sleep(2)

    except Exception as e:
        result["fail"] += 1
        result["errors"].append(str(e))
        log.error("[%s] fatal: %s", name, e)
        if cfg.get("debug"):
            traceback.print_exc()

    return result


def run() -> None:
    """Entry point from main menu."""
    cfg = load_global_config()
    logging.basicConfig(
        level=getattr(logging, str(cfg.get("log_level", "INFO")).upper(), logging.INFO),
        format="%(asctime)s | %(levelname)-7s | %(message)s",
        datefmt="%H:%M:%S",
        force=True,
    )

    accounts = load_json(ACCOUNTS_PATH) or []
    if not accounts:
        print(f"  Missing {ACCOUNTS_PATH}")
        print(f"  Copy example:  cp bots/bitcotask/accounts.example.json data/accounts/bitcotask.json")
        if ACCOUNTS_EXAMPLE.exists():
            print(f"  Example exists at {ACCOUNTS_EXAMPLE}")
        return

    try:
        vern = vernuable_from_config(cfg)
    except SystemExit as e:
        print(f"  {e}")
        return

    try:
        bal = vern.balance()
        log.info("Vernuable balance $%.5f", bal)
    except Exception as e:
        log.warning("balance check: %s", e)

    nc = make_notif_cookies()
    log.info("notif cookie sample: %s", nc)

    workers = int(cfg.get("workers", 2))
    workers = max(1, min(workers, len(accounts)))
    results = []
    with ThreadPoolExecutor(max_workers=workers) as ex:
        futs = {ex.submit(run_account, acc, cfg, vern): acc for acc in accounts}
        for fut in as_completed(futs):
            results.append(fut.result())

    log.info("======== SUMMARY ========")
    for r in results:
        log.info("%s  ok=%s fail=%s", r["account"], r["ok"], r["fail"])
    total_ok = sum(r["ok"] for r in results)
    total_fail = sum(r["fail"] for r in results)
    log.info("TOTAL ok=%s fail=%s", total_ok, total_fail)


if __name__ == "__main__":
    run()
