# Vernuable Official Scripts

**Buxads-style PHP hub** for Vernuable captcha bots.

```bash
php bot.php
```

## Why no accounts.example.json?

Same as **Buxads-scripts**:

- Accounts are **added inside each script menu** (`[3] Add account`)
- Stored under `configs/<site>-config/accounts` as plain lines  
  `name|publisher_key|sub_id|proxy`
- Vernuable API key: first run prompts → `configs/vernuable-Bot-config/vernuable-apikey`

No JSON templates to copy.

## Setup

```bash
git clone -b vernuable-officialscripts https://github.com/GLITCH083/bitcotask-bot.git
cd bitcotask-bot
php bot.php
```

1. Menu → **Vernuable balance / set API key** (or first solve prompts for key)
2. Open **Offerwall** → **bitcotasks.com.php**
3. **[3] Add account** → name, publisher key, sub_id, proxy
4. **[1] Run all** or **[2] Run single**

Optional (private repo updates):

```bash
echo YOUR_GITHUB_TOKEN > github_token.txt
```

Then use **Check for Updates** in the main menu.

## Layout (like Buxads)

```text
bot.php                 # main menu + GitHub update
version.json            # version for auto-update
functions/
  function.php          # colors, saveData, theme boxes
  vernuable.php         # Vernuable API (bitcotask)
scripts/
  offerwall/
    bitcotasks.com.php  # BitcoTasks multi-account
configs/                # created on first use (gitignored keys)
  vernuable-Bot-config/vernuable-apikey
  bitcotasks.com-config/accounts
```

## Add another site script

1. Put `scripts/<category>/yoursite.php`
2. It appears automatically in the menu
3. Use `saveData("host", "filename")` and `configPath()` like Buxads

## Update

Menu → **Check for Updates**  
Pulls `version.json` + managed PHP/JSON from branch `vernuable-officialscripts`.

## Vernuable

- Docs: https://vernuable.my.id/docs#bitcotask  
- Price: **$0.00005** / solve (`method=bitcotask`)
