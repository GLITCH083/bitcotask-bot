"""CLI menu bar — main hub that connects all Vernuable scripts/bots."""
from __future__ import annotations

import os
import sys
from typing import Callable

# ANSI colors (safe on most terminals)
C = {
    "reset": "\033[0m",
    "bold": "\033[1m",
    "dim": "\033[2m",
    "cyan": "\033[36m",
    "green": "\033[32m",
    "yellow": "\033[33m",
    "red": "\033[31m",
    "magenta": "\033[35m",
    "blue": "\033[34m",
    "white": "\033[37m",
}


def _c(name: str, text: str) -> str:
    if not sys.stdout.isatty():
        return text
    return f"{C.get(name, '')}{text}{C['reset']}"


def clear() -> None:
    os.system("cls" if os.name == "nt" else "clear")


def banner() -> None:
    print()
    print(_c("cyan", "╔══════════════════════════════════════════════════════════╗"))
    print(_c("cyan", "║") + _c("bold", "     VERNUABLE  ·  OFFICIAL SCRIPTS HUB                  ") + _c("cyan", "║"))
    print(_c("cyan", "║") + _c("dim", "     Main launcher — connect any bot / script            ") + _c("cyan", "║"))
    print(_c("cyan", "╚══════════════════════════════════════════════════════════╝"))
    print()


def line(char: str = "─", n: int = 58) -> None:
    print(_c("dim", char * n))


class MenuItem:
    def __init__(self, key: str, label: str, handler: Callable | None = None, enabled: bool = True):
        self.key = key
        self.label = label
        self.handler = handler
        self.enabled = enabled


class Menu:
    def __init__(self, title: str = "MAIN MENU"):
        self.title = title
        self.items: list[MenuItem] = []
        self.footer: str | None = None

    def add(self, key: str, label: str, handler: Callable | None = None, enabled: bool = True) -> "Menu":
        self.items.append(MenuItem(key, label, handler, enabled))
        return self

    def show(self) -> None:
        print(_c("bold", f"  {self.title}"))
        line()
        for it in self.items:
            if it.enabled:
                print(f"  {_c('green', it.key)})  {it.label}")
            else:
                print(f"  {_c('dim', it.key)})  {_c('dim', it.label + '  [soon]')}")
        line()
        if self.footer:
            print(_c("dim", f"  {self.footer}"))
            print()

    def run_once(self) -> bool:
        """Show menu, get choice, run handler. Returns False to exit."""
        self.show()
        try:
            choice = input(_c("yellow", "  › ") + "Select: ").strip().lower()
        except (EOFError, KeyboardInterrupt):
            print()
            return False
        if not choice:
            return True
        for it in self.items:
            if it.key.lower() == choice:
                if not it.enabled or it.handler is None:
                    print(_c("yellow", "  Not available yet."))
                    input(_c("dim", "  [Enter]"))
                    return True
                try:
                    it.handler()
                except SystemExit as e:
                    if e.code not in (0, None):
                        print(_c("red", f"  Exit: {e}"))
                except KeyboardInterrupt:
                    print(_c("yellow", "\n  Interrupted."))
                except Exception as e:
                    print(_c("red", f"  Error: {e}"))
                input(_c("dim", "\n  [Enter] back to menu"))
                return True
        if choice in ("0", "q", "quit", "exit"):
            return False
        print(_c("red", "  Invalid choice."))
        return True

    def loop(self) -> None:
        while True:
            clear()
            banner()
            if not self.run_once():
                print(_c("cyan", "\n  Bye.\n"))
                break
