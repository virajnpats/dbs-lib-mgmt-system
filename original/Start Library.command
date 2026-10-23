#!/bin/bash
# =====================================================================
#  Library Management System - double-click to start the website (macOS)
#
#  1. Installs PHP if this Mac does not have it (via Homebrew, asks first).
#  2. Starts MySQL if it is not running (asks first where needed).
#  3. Runs setup.php: connects to MySQL (asks your password once), backs up
#     and upgrades your existing LibraryDB safely - or creates it if missing -
#     and runs the 58 automated tests.
#  4. Starts the website and opens it in your browser.
#
#  Keep this window open while you use the website. Close it to stop.
#  First time only: if macOS blocks it, right-click the file > Open > Open.
# =====================================================================

cd "$(dirname "$0")" || exit 1
PROJECT="$(pwd)"

bold() { printf '\n\033[1m%s\033[0m\n' "$1"; }
finish() { echo; read -r -p "Press Enter to close this window..." _; exit "${1:-1}"; }
yes_no() { local a; read -r -p "$1 [y/N] " a; [[ "$a" =~ ^[Yy] ]]; }
load_brew() {
  for b in /opt/homebrew/bin/brew /usr/local/bin/brew; do
    [ -x "$b" ] && eval "$("$b" shellenv)" && return 0
  done
  return 1
}

clear
echo "Library Management System"
echo "========================="
load_brew

# ---------- 1. PHP ----------
PHP=""
if command -v php >/dev/null 2>&1; then
  PHP="$(command -v php)"
else
  for p in /Applications/XAMPP/xamppfiles/bin/php /Applications/MAMP/bin/php/php8*/bin/php; do
    [ -x "$p" ] && PHP="$p" && break
  done
fi

if [ -z "$PHP" ]; then
  bold "PHP is not installed on this Mac."
  echo "It will be installed with Homebrew, the standard free Mac installer (brew.sh)."
  if ! command -v brew >/dev/null 2>&1; then
    echo "Homebrew is not installed either. Installing it asks for your Mac login password"
    echo "and takes about 5 minutes."
    yes_no "Install Homebrew and PHP now?" || { echo "Cannot start without PHP."; finish 1; }
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" || { echo "Homebrew install failed."; finish 1; }
    load_brew || { echo "Homebrew installed but not found. Close this window and double-click again."; finish 1; }
  else
    yes_no "Install PHP now with Homebrew (about 2 minutes)?" || { echo "Cannot start without PHP."; finish 1; }
  fi
  bold "Installing PHP..."
  brew install php || { echo "PHP install failed. Try in Terminal: brew install php"; finish 1; }
  PHP="$(command -v php)"
fi
echo "PHP: $("$PHP" -r 'echo PHP_VERSION;')  ($PHP)"

if ! "$PHP" -m | grep -qi '^pdo_mysql$'; then
  echo "This PHP cannot talk to MySQL (pdo_mysql missing). Fix: brew reinstall php"
  finish 1
fi

# ---------- 2. MySQL server ----------
mysql_up() { "$PHP" -r 'exit(@fsockopen("127.0.0.1", 3306, $e, $s, 2) ? 0 : 1);'; }
wait_for_mysql() {
  for _ in $(seq 1 20); do mysql_up && return 0; sleep 1; done
  return 1
}

if ! mysql_up; then
  bold "MySQL is not running. Starting it..."
  if command -v brew >/dev/null 2>&1 && brew list --formula 2>/dev/null | grep -q '^mysql'; then
    brew services start "$(brew list --formula | grep '^mysql' | head -1)"
  elif [ -x /usr/local/mysql/support-files/mysql.server ]; then
    echo "MySQL from mysql.com needs your Mac password to start:"
    sudo /usr/local/mysql/support-files/mysql.server start
  elif [ -x /Applications/XAMPP/xamppfiles/xampp ]; then
    echo "Starting XAMPP's MySQL (needs your Mac password):"
    sudo /Applications/XAMPP/xamppfiles/xampp startmysql
  else
    echo "No MySQL server was found on this Mac."
    if yes_no "Install MySQL with Homebrew now (a new, empty server; LibraryDB will be created with sample data)?"; then
      command -v brew >/dev/null 2>&1 || { echo "Homebrew is needed first: https://brew.sh"; finish 1; }
      brew install mysql && brew services start mysql
    else
      echo "Start your MySQL server (System Settings > MySQL), then double-click this file again."
      finish 1
    fi
  fi
  if ! wait_for_mysql; then
    echo
    echo "MySQL still is not answering on port 3306."
    echo "Start it from System Settings > MySQL (or MySQL Workbench), then press Enter."
    read -r -p "" _
    wait_for_mysql || { echo "MySQL did not start."; finish 1; }
  fi
fi

# ---------- 3. Database setup + self-test ----------
"$PHP" setup.php
rc=$?
if [ $rc -eq 3 ]; then
  echo "MySQL is not reachable on 127.0.0.1:3306. If it uses another port, put it in config.local.php."
  finish 1
elif [ $rc -ne 0 ]; then
  finish $rc
fi

# ---------- 4. Website ----------
PORT="$("$PHP" setup.php --free-port)"
URL="http://127.0.0.1:$PORT/library.html"
LOG="$PROJECT/backups/server.log"
mkdir -p "$PROJECT/backups"

"$PHP" -S "127.0.0.1:$PORT" -t "$PROJECT" "$PROJECT/router.php" >"$LOG" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; echo; echo "Website stopped."; exit 0' INT TERM HUP

for _ in $(seq 1 30); do
  "$PHP" -r "exit(@fsockopen('127.0.0.1', $PORT) ? 0 : 1);" && break
  sleep 0.2
done
if ! kill -0 $SERVER 2>/dev/null; then
  echo "The web server did not start:"; cat "$LOG"; finish 1
fi

open "$URL"
bold "The website is running:  $URL"
echo "It opened in your browser. Keep this window open while you use it."
echo "To stop: close this window or press Ctrl+C."
wait $SERVER
