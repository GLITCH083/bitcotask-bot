"""Load global + bot configs."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent.parent


def load_json(path: Path, default: Any = None) -> Any:
    if not path.exists():
        return default
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def save_json(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)
        f.write("\n")


def load_global_config() -> dict:
    cfg = load_json(ROOT / "config.json")
    if cfg is None:
        cfg = load_json(ROOT / "config.example.json") or {}
    return cfg


def vernuable_from_config(cfg: dict | None = None):
    from core.vernuable import Vernuable

    cfg = cfg or load_global_config()
    key = cfg.get("vernuable_key") or ""
    if not key or str(key).startswith("YOUR_"):
        raise SystemExit("Set vernuable_key in config.json (copy from config.example.json)")
    return Vernuable(
        api_key=key,
        base=cfg.get("vernuable_base", "https://vernuable.my.id"),
        timeout=int(cfg.get("solve_timeout", 180)),
        poll=float(cfg.get("poll_interval", 2)),
    )
