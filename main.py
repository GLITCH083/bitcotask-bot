#!/usr/bin/env python3
"""
Vernuable Official Scripts Hub
==============================
Main launcher with menu bar — connect and run any bot/script from one place.

  python main.py

Structure:
  core/          shared Vernuable client, config, menu
  bots/          each bot is a plugin (bitcotask, …)
  data/accounts/ per-bot account JSON files
  scripts/       drop custom one-off scripts here

Add a new bot:
  1. Create bots/mybot/ with runner.py exposing run()
  2. Register it in build_menu() below
"""
from __future__ import annotations

import logging
import sys
from pathlib import Path

# Ensure project root is on path
ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from core.config import load_global_config, vernuable_from_config
from core.menu import Menu, _c, clear, banner


def action_bitcotask() -> None:
    clear()
    banner()
    print(_c("bold", "  ▶ BitcoTasks multi-account"))
    print(_c("dim", "  Vernuable method=bitcotask · notif cookies · smart claim · proxy\n"))
    from bots.bitcotask import run_bitcotask
    run_bitcotask()


def action_balance() -> None:
    clear()
    banner()
    print(_c("bold", "  ▶ Vernuable balance\n"))
    try:
        vern = vernuable_from_config()
        bal = vern.balance()
        print(_c("green", f"  Balance: ${bal:.5f}"))
    except Exception as e:
        print(_c("red", f"  Failed: {e}"))


def action_settings() -> None:
    clear()
    banner()
    print(_c("bold", "  ▶ Settings / paths\n"))
    cfg = load_global_config()
    key = cfg.get("vernuable_key") or ""
    masked = (key[:6] + "…" + key[-4:]) if len(key) > 12 else ("(not set)" if not key else key)
    print(f"  vernuable_key : {masked}")
    print(f"  vernuable_base: {cfg.get('vernuable_base', 'https://vernuable.my.id')}")
    print(f"  workers       : {cfg.get('workers', 2)}")
    print(f"  log_level     : {cfg.get('log_level', 'INFO')}")
    print()
    print(_c("dim", "  Config file : config.json  (copy from config.example.json)"))
    print(_c("dim", "  Accounts    : data/accounts/<bot>.json"))
    print(_c("dim", "  BitcoTask   : data/accounts/bitcotask.json"))
    print()
    print(_c("dim", "  To change: edit config.json then re-run."))


def action_list_bots() -> None:
    clear()
    banner()
    print(_c("bold", "  ▶ Installed bots / scripts\n"))
    bots_dir = ROOT / "bots"
    if not bots_dir.exists():
        print("  (none)")
        return
    for p in sorted(bots_dir.iterdir()):
        if p.is_dir() and not p.name.startswith("_"):
            runner = p / "runner.py"
            status = _c("green", "ready") if runner.exists() else _c("yellow", "incomplete")
            print(f"  • {p.name:20}  {status}")
    scripts = ROOT / "scripts"
    print()
    print(_c("bold", "  Custom scripts/ folder:"))
    if scripts.exists():
        found = [f for f in scripts.iterdir() if f.suffix == ".py"]
        if not found:
            print(_c("dim", "  (empty — drop .py files here)"))
        for f in found:
            print(f"  • {f.name}")
    else:
        print(_c("dim", "  (no scripts/)"))


def action_run_script() -> None:
    """List and run a file from scripts/."""
    clear()
    banner()
    print(_c("bold", "  ▶ Run custom script\n"))
    scripts = ROOT / "scripts"
    files = sorted(scripts.glob("*.py")) if scripts.exists() else []
    if not files:
        print(_c("dim", "  No .py files in scripts/"))
        print(_c("dim", "  Add your own scripts there to launch from this menu."))
        return
    for i, f in enumerate(files, 1):
        print(f"  {i}) {f.name}")
    print("  0) cancel")
    try:
        choice = input(_c("yellow", "\n  › ") + "Script #: ").strip()
    except (EOFError, KeyboardInterrupt):
        return
    if choice in ("0", ""):
        return
    try:
        idx = int(choice) - 1
        path = files[idx]
    except (ValueError, IndexError):
        print(_c("red", "  Invalid"))
        return
    print(_c("cyan", f"\n  Running {path.name} …\n"))
    import runpy
    try:
        runpy.run_path(str(path), run_name="__main__")
    except SystemExit:
        pass
    except Exception as e:
        print(_c("red", f"  Error: {e}"))


def action_placeholder(name: str):
    def _fn():
        clear()
        banner()
        print(_c("yellow", f"  {name} — coming soon"))
        print(_c("dim", "  Drop your script in bots/ or scripts/ and register in main.py"))
    return _fn


def build_menu() -> Menu:
    m = Menu("MAIN MENU — Vernuable Official Scripts")
    m.add("1", "BitcoTasks          (multi-account · motion captcha · smart claim)", action_bitcotask)
    m.add("2", "hCaptcha / Turnstile helpers", action_placeholder("hCaptcha / Turnstile"), enabled=False)
    m.add("3", "Faucet claim helpers", action_placeholder("Faucet"), enabled=False)
    m.add("4", "Run custom script from scripts/", action_run_script)
    m.add("5", "List installed bots", action_list_bots)
    m.add("6", "Vernuable balance", action_balance)
    m.add("7", "Settings / paths", action_settings)
    m.add("0", "Exit", None)
    m.footer = "Tip: add new bots under bots/<name>/ and register in main.py"
    return m


def main() -> None:
    logging.basicConfig(
        level=logging.WARNING,
        format="%(asctime)s | %(levelname)-7s | %(message)s",
        datefmt="%H:%M:%S",
    )
    menu = build_menu()
    menu.loop()


if __name__ == "__main__":
    main()
