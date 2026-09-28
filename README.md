# Vernuable Official Scripts Hub

**Main launcher** with menu bar — one entry point to connect and run every Vernuable-powered bot/script.

```text
python main.py
```

```
╔══════════════════════════════════════════════════════════╗
║     VERNUABLE  ·  OFFICIAL SCRIPTS HUB                  ║
║     Main launcher — connect any bot / script            ║
╚══════════════════════════════════════════════════════════╝

  MAIN MENU — Vernuable Official Scripts
  ──────────────────────────────────────────────────────────
  1)  BitcoTasks          (multi-account · motion captcha · smart claim)
  2)  hCaptcha / Turnstile helpers  [soon]
  3)  Faucet claim helpers  [soon]
  4)  Run custom script from scripts/
  5)  List installed bots
  6)  Vernuable balance
  7)  Settings / paths
  0)  Exit
```

## Features

| Feature | Detail |
|---------|--------|
| **Menu hub** | One `main.py` connects all bots |
| **BitcoTasks** | Vernuable `bitcotask` motion captcha → x,y |
| **Notif cookies** | `_bitco_notifad` + expire (required for lead credit) |
| **Multi-account** | `data/accounts/bitcotask.json` |
| **Proxy** | Per account `host:port:user:pass` or DIRECT |
| **Smart claim** | Prefer boosted, min reward, max duration |
| **Plugin bots** | Drop new bots under `bots/<name>/` |
| **Custom scripts** | Drop `.py` in `scripts/` → run from menu |

## Quick start

```bash
git clone -b vernuable-officialscripts https://github.com/GLITCH083/bitcotask-bot.git
cd bitcotask-bot
pip install -r requirements.txt
cp config.example.json config.json
# edit config.json → vernuable_key

mkdir -p data/accounts
cp bots/bitcotask/accounts.example.json data/accounts/bitcotask.json
# edit accounts (key, sub_id, proxy)

python main.py
# → choose 1) BitcoTasks
```

## Vernuable API

```text
POST https://vernuable.my.id/in.php
  key=YOUR_KEY
  method=bitcotask
  body=<base64 GIF>
  json=1

GET  https://vernuable.my.id/res.php?key=...&action=get&id=TASK&json=1
  → { "x": 164, "y": 134 }
```

Docs: https://vernuable.my.id/docs#bitcotask  
Price: **$0.00005** / solve

## Layout

```text
.
├── main.py                 # MENU BAR — hub entry
├── config.example.json
├── requirements.txt
├── README.md
├── core/
│   ├── vernuable.py        # shared Vernuable client
│   ├── config.py
│   └── menu.py             # CLI menu system
├── bots/
│   └── bitcotask/          # first plugin bot
│       ├── runner.py
│       ├── client.py
│       ├── notif_cookie.py
│       └── accounts.example.json
├── data/
│   ├── accounts/           # bitcotask.json etc (gitignored)
│   └── proxies.example.txt
└── scripts/                # drop your own .py scripts here
```

## Add a new bot

1. Create `bots/mybot/runner.py` with a `run()` function.
2. In `main.py` → `build_menu()`, add:

```python
def action_mybot():
    from bots.mybot.runner import run
    run()

m.add("8", "MyBot description", action_mybot)
```

3. Put accounts under `data/accounts/mybot.json` if needed.

## BitcoTasks notes

1. Captcha field names are **obfuscated per session**. If validate fails after Vernuable solve, refresh HAR and update `submit_captcha_click`.
2. Always set **notif cookies** before `start_view` / `proccessLead` or leads may not credit.
3. Use **1 proxy per account**.
4. Keep Vernuable balance topped up.

## License

For use with your own BitcoTasks publisher keys + Vernuable account.
