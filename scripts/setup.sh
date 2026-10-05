#!/usr/bin/env bash
# Checks what loom-context needs and, only when told to, installs what is missing.
#
#   setup.sh                 check everything; exit 1 when something required is missing
#   setup.sh --install <id>  run the install action for one requirement (the check prints each id)
#   setup.sh --ask           check, then ask at the terminal before each install action
#
# Nothing is installed without --install or a "y" at the --ask prompt. An agent gets the user's explicit
# permission for each action before running it with --install.
#
# Required: php >= 8.3, composer, the skill's Composer packages, yt-dlp >= 2026.01, ffmpeg + ffprobe, egress to
# www.loom.com. Informational: browsers with a Loom cookie source, 1Password CLI, LOOM_COOKIE / LOOM_OP_ITEM.
set -u

case "$0" in
  */*)
    script_dir=${0%/*}
    ;;
  *)
    script_dir=.
    ;;
esac

skill_dir=$(cd "$script_dir/.." && pwd)

status=0

# Ids of the missing requirements this script can install, in the order they have to be installed.
missing=""

# The command that installs one requirement on this machine; set by install_action.
action=()

ok() {
  printf 'ok    %s\n' "$1"
}

warn() {
  printf 'warn  %s\n' "$1"
}

fail() {
  printf 'FAIL  %s\n' "$1"
  status=1
}

has() {
  command -v "$1" >/dev/null 2>&1
}

# Whether the skill's .env gives a variable a value.
in_env_file() {
  local line

  [ -f "$skill_dir/.env" ] || return 1

  while IFS= read -r line; do
    case "$line" in
      "$1"=?*)
        return 0
        ;;
    esac
  done < "$skill_dir/.env"

  return 1
}

# Leaves `action` empty when there is no command this script can choose for this machine.
install_action() {
  action=()

  case "$1" in
    php)
      if has brew; then
        action=(brew install php)
      elif has apt-get; then
        action=(sudo apt-get install -y php-cli php-curl php-mbstring)
      fi
      ;;
    composer)
      if has brew; then
        action=(brew install composer)
      elif has apt-get; then
        action=(sudo apt-get install -y composer)
      fi
      ;;
    packages)
      action=(composer install --no-dev --working-dir "$skill_dir")
      ;;
    yt-dlp)
      # Distro packages of yt-dlp lag months behind Loom's API changes, so apt is never offered for it.
      if has brew; then
        action=(brew install yt-dlp)
      elif has pipx && has yt-dlp; then
        action=(pipx upgrade yt-dlp)
      elif has pipx; then
        action=(pipx install yt-dlp)
      elif has python3; then
        action=(python3 -m pip install --user --upgrade yt-dlp)
      fi
      ;;
    ffmpeg)
      if has brew; then
        action=(brew install ffmpeg)
      elif has apt-get; then
        action=(sudo apt-get install -y ffmpeg)
      fi
      ;;
  esac
}

# Reports a missing requirement with its fix, and queues it when this script can run the fix itself.
need() {
  local id=$1
  local problem=$2
  local by_hand=$3

  install_action "$id"

  if [ "${#action[@]}" -eq 0 ]; then
    fail "$problem. Fix by hand: $by_hand"
    return
  fi

  # A password prompt cannot be answered from here, so anything behind sudo is the user's to run.
  if [ "${action[0]}" = sudo ]; then
    fail "$problem. Fix by hand (needs sudo): ${action[*]}"
    return
  fi

  fail "$problem. Fix: ${action[*]}  [--install $id]"

  missing="$missing $id"
}

check() {
  status=0
  missing=""

  if has php && php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    ok "php $(php -r 'echo PHP_VERSION;')"
  else
    need php "php >= 8.3 missing" "install PHP 8.3 or newer, see https://www.php.net/downloads"
  fi

  if has composer; then
    ok "composer"
  else
    need composer "composer missing" "see https://getcomposer.org/download/"
  fi

  if [ -f "$skill_dir/vendor/autoload.php" ]; then
    ok "skill dependencies installed"
  else
    need packages "skill dependencies missing" "composer install --no-dev --working-dir $skill_dir"
  fi

  if ! has yt-dlp; then
    need yt-dlp "yt-dlp missing" "see https://github.com/yt-dlp/yt-dlp#installation"
  else
    version=$(yt-dlp --version 2>/dev/null)

    if [ "${version%%.*}" -ge 2026 ] 2>/dev/null; then
      ok "yt-dlp $version"
    else
      need yt-dlp "yt-dlp $version is too old" "upgrade it, see https://github.com/yt-dlp/yt-dlp#update"
    fi
  fi

  if has ffmpeg && has ffprobe; then
    read -r _ _ ffmpeg_version _ < <(ffmpeg -version)

    ok "ffmpeg $ffmpeg_version"
  else
    need ffmpeg "ffmpeg/ffprobe missing" "see https://ffmpeg.org/download.html"
  fi

  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 https://www.loom.com/ 2>/dev/null) || code=000

  case "$code" in
    2*|3*)
      ok "egress to www.loom.com ($code)"
      ;;
    *)
      fail "cannot reach www.loom.com (http $code). In a sandboxed or cloud session, allowlist www.loom.com, cdn.loom.com and luna.loom.com in its network policy, or run on a machine with open internet access."
      ;;
  esac

  # yt-dlp's name for each browser, then where its profile lives on macOS and on Linux.
  found=""

  while IFS='|' read -r browser mac linux; do
    if [ -d "$HOME/Library/Application Support/$mac" ] || [ -d "$HOME/$linux" ]; then
      found="$found $browser"
    fi
  done <<'BROWSERS'
chrome|Google/Chrome|.config/google-chrome
brave|BraveSoftware/Brave-Browser|.config/BraveSoftware/Brave-Browser
edge|Microsoft Edge|.config/microsoft-edge
chromium|Chromium|.config/chromium
firefox|Firefox|.mozilla/firefox
BROWSERS

  if [ -n "$found" ]; then
    ok "browser cookie sources:$found"
  else
    warn "no browser profile found; private Looms will need LOOM_COOKIE"
  fi

  if [ -n "${LOOM_COOKIE:-}" ]; then
    ok "LOOM_COOKIE is set (value not shown)"
  elif in_env_file LOOM_COOKIE; then
    ok "LOOM_COOKIE is set in .env (value not shown)"
  elif has op && [ -n "${LOOM_OP_ITEM:-}" ]; then
    ok "1Password fallback available (LOOM_OP_ITEM set)"
  elif has op && in_env_file LOOM_OP_ITEM; then
    ok "1Password fallback available (LOOM_OP_ITEM set in .env)"
  else
    warn "no cookie for private Looms: set LOOM_COOKIE or LOOM_OP_ITEM in $skill_dir/.env (see .env.example)"
  fi
}

install_one() {
  install_action "$1"

  if [ "${#action[@]}" -eq 0 ] || [ "${action[0]}" = sudo ]; then
    echo "[loom-context] '$1' has no install action this script can run; the check prints its fix by hand." >&2
    return 2
  fi

  echo "[loom-context] running: ${action[*]}"

  "${action[@]}"
}

ask_and_install() {
  local id
  local answer

  for id in $missing; do
    install_action "$id"

    printf 'Run "%s" to install %s? [y/N] ' "${action[*]}" "$id"

    read -r answer || answer=""

    case "$answer" in
      y|Y|yes|YES)
        "${action[@]}"
        ;;
      *)
        echo "skipped $id"
        ;;
    esac
  done
}

case "${1:-}" in
  "")
    check

    if [ -n "$missing" ]; then
      printf '\nNothing was installed. Once the user has agreed to a fix, run it with: bash %s/scripts/setup.sh --install <id>\n' "$skill_dir"
      printf 'Install in this order:%s\n' "$missing"
    fi

    exit $status
    ;;
  --install)
    install_one "${2:-}"
    exit $?
    ;;
  --ask)
    check

    if [ -n "$missing" ]; then
      echo
      ask_and_install
      echo
      check
    fi

    exit $status
    ;;
  *)
    echo "usage: setup.sh [--install <id> | --ask]" >&2
    exit 2
    ;;
esac
