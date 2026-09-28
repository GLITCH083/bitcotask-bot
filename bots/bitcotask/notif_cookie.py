"""BitcoTasks notification-ad cookies (matches PHP notifedCookie)."""
from __future__ import annotations

import hashlib
from datetime import datetime, timedelta, timezone


def make_notif_cookies(minutes: int = 30) -> dict[str, str]:
    """
    PHP equivalent:
      $dt = new DateTime("now", new DateTimeZone("GMT"));
      $dt->modify("+30 minutes");
      $exp = $dt->format("D, d M Y H:i:s") . " GMT";
      $randstr = substr(md5($exp), 2, 9);
      return '_bitco_notifad=ad_value_' . $randstr
           . '; _bitco_notifad_expire=expires=' . $exp;
    """
    dt = datetime.now(timezone.utc) + timedelta(minutes=minutes)
    exp = dt.strftime("%a, %d %b %Y %H:%M:%S") + " GMT"
    randstr = hashlib.md5(exp.encode()).hexdigest()[2:11]
    return {
        "_bitco_notifad": f"ad_value_{randstr}",
        "_bitco_notifad_expire": f"expires={exp}",
    }


def cookie_header(extra: dict[str, str] | None = None) -> str:
    c = make_notif_cookies()
    if extra:
        c.update(extra)
    return "; ".join(f"{k}={v}" for k, v in c.items())
