# Vernuable × BitcoTasks Bot

Official-style multi-account BitcoTasks bot using **[Vernuable](https://vernuable.my.id/docs)** for motion captcha (`method=bitcotask`).

## Features

| Feature | Detail |
|---------|--------|
| **Captcha** | Vernuable `bitcotask` → click `x,y` on motion GIF |
| **Notif cookies** | `_bitco_notifad` + `_bitco_notifad_expire` (required for lead credit) |
| **Multi-account** | `data/accounts.json` |
| **Proxy** | Per account `host:port:user:pass` or DIRECT |
| **Smart claim** | Prefer boosted, min reward, max duration |
| **Flow** | Firewall → captcha → offerwall PTC → `start_view` → `proccessLead` |

## Quick start

```bash
cd bitcotask_bot
pip install -r requirements.txt
cp config.example.json config.json
cp data/accounts.example.json data/accounts.json
# edit config.json → vernuable_key
# edit data/accounts.json → key, sub_id, proxy
python main.py
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

## Notif cookie (must set before lead)

Same as PHP:

```php
function notifedCookie() {
    $dt = new DateTime("now", new DateTimeZone("GMT"));
    $dt->modify("+30 minutes");
    $exp = $dt->format("D, d M Y H:i:s") . " GMT";
    $randstr = substr(md5($exp), 2, 9);
    return '_bitco_notifad=ad_value_' . $randstr
         . '; _bitco_notifad_expire=expires=' . $exp;
}
```

Python: `lib/notif_cookie.py` → `make_notif_cookies()`.

## Account fields

```json
{
  "name": "acc1",
  "key": "publisher_key_from_bitcotasks",
  "sub_id": "26113",
  "proxy": "host:port:user:pass",
  "claims": 5
}
```

## Smart claim

```json
"smart_claim": {
  "prefer_boosted": true,
  "min_reward": 1,
  "max_duration": 25
}
```

## Notes

1. BitcoTasks captcha field names are **obfuscated per session**. If `validate` fails after Vernuable solve, update `submit_captcha_click` using a fresh HAR of the captcha POST.
2. Always set **notif cookies** before `start_view` / `proccessLead` or leads may not credit.
3. Use **1 proxy per account** for best results.
4. Keep Vernuable balance topped up.

## Layout

```text
bitcotask_bot/
  main.py                 # entry
  config.example.json
  requirements.txt
  README.md
  lib/
    notif_cookie.py
    vernuable.py
    bitco_client.py
  data/
    accounts.example.json
    proxies.example.txt
```

## License

For use with your own BitcoTasks publisher keys + Vernuable account.
