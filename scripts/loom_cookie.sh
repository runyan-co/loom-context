#!/usr/bin/env bash
# Exports LOOM_COOKIE (Loom's connect.sid) from a 1Password item, for runs where no logged-in browser can be read.
# Usage: source <skill dir>/scripts/loom_cookie.sh [item_ref]
#   item_ref defaults to $LOOM_OP_ITEM, from the environment or else the skill's .env. The item needs a field labelled `connect.sid`
#   (copy it from loom.com DevTools > Application > Cookies; it lasts about 30 days).
# Never prints the value. Source it in the same shell call as the command that uses it.
_loom_op_item="${1:-${LOOM_OP_ITEM:-}}"

# This file's own path, in bash and in zsh.
_loom_self="${BASH_SOURCE[0]:-$0}"

_loom_env_file="${_loom_self%/*}/../.env"

if [ -z "$_loom_op_item" ] && [ -f "$_loom_env_file" ]; then
  _loom_op_item=$(sed -n 's/^LOOM_OP_ITEM=//p' "$_loom_env_file" | tail -1)
  _loom_op_item=${_loom_op_item#[\"\']}
  _loom_op_item=${_loom_op_item%[\"\']}
fi

_loom_cookie_cleanup() {
  unset _loom_op_item _loom_self _loom_env_file _loom_cookie_cleanup 2>/dev/null || true
}

if [ -z "$_loom_op_item" ]; then
  echo "[loom-context] No 1Password item configured. Set LOOM_OP_ITEM or pass the item name/UUID as arg 1." >&2
  _loom_cookie_cleanup
  return 1 2>/dev/null || exit 1
fi

if ! command -v op >/dev/null; then
  echo "[loom-context] op missing: install the 1Password CLI (brew install 1password-cli)" >&2
  _loom_cookie_cleanup
  return 1 2>/dev/null || exit 1
fi

if ! op whoami &>/dev/null; then
  eval "$(op signin 2>/dev/null)" 2>/dev/null

  if ! op whoami &>/dev/null; then
    echo "[loom-context] 1Password sign-in failed. Run: eval \$(op signin)" >&2
    _loom_cookie_cleanup
    return 1 2>/dev/null || exit 1
  fi
fi

LOOM_COOKIE=$(op item get "$_loom_op_item" --fields label=connect.sid --reveal 2>/dev/null)

if [ -z "$LOOM_COOKIE" ]; then
  echo "[loom-context] Item '$_loom_op_item' has no 'connect.sid' field or you lack vault access." >&2
  unset LOOM_COOKIE
  _loom_cookie_cleanup
  return 1 2>/dev/null || exit 1
fi

export LOOM_COOKIE

_loom_cookie_cleanup
