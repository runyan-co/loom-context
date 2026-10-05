# loom-context

Turn a Loom recording into agent-readable context: its transcript aligned with de-duplicated screenshots, a timeline, and detected entities. The Claude Code and Codex skill guides an agent from a Loom link to a structured `SUMMARY.md` with the observed problem, reproduction steps, expected and actual behavior, open questions, and likely code areas.

The generated bundle is written to `.loom/<video-id>/` in the current project. It stays local; loom-context does not post the recording or its contents anywhere.

## Requirements

- PHP 8.3 or newer
- Composer
- `yt-dlp` 2026 or newer
- `ffmpeg` and `ffprobe`
- Network access to Loom (`www.loom.com` and, for media, `cdn.loom.com` and `luna.loom.com`)

## Install as a Claude Code skill

Install for all your projects:

```bash
mkdir -p ~/.claude/skills
git clone https://github.com/runyan-co/loom-context.git ~/.claude/skills/loom-context
cd ~/.claude/skills/loom-context
composer install --no-dev
bash scripts/setup.sh
```

Or install for one project by cloning into that project's `.claude/skills/loom-context/` directory. Start a new Claude Code session, then invoke `/loom-context` or ask Claude to inspect a Loom link.

## Install as a Codex skill

Install for your user:

```bash
mkdir -p ~/.agents/skills
git clone https://github.com/runyan-co/loom-context.git ~/.agents/skills/loom-context
cd ~/.agents/skills/loom-context
composer install --no-dev
bash scripts/setup.sh
```

Or install for one project by cloning into that project's `.agents/skills/loom-context/` directory. Codex detects local skills automatically; if it does not appear, restart Codex. Invoke it with `$loom-context` or ask Codex to inspect a Loom link.

`bash scripts/setup.sh` checks the requirements and does not install anything. If tools are missing, it prints available install commands. In an interactive terminal, `bash scripts/setup.sh --ask` asks before each supported install action.

## Use the CLI directly

After installing the skill's Composer dependencies, run a context build with:

```bash
php loom context "https://www.loom.com/share/<video-id>"
```

The command accepts a share URL, embed URL, or bare video ID. Use `php loom help` to see the available commands. Context options include `--interval` (frame cadence in seconds), `--max-frames`, `--password`, and `--cookies-from-browser`. A public recording needs no extra configuration.

Private recordings may require Loom browser cookies or a `LOOM_COOKIE` session cookie. Optional settings live in `.env` beside `loom`; start from `.env.example`. Never share `.env` or the generated `.loom/` bundle, which can contain real user data. See [SKILL.md](SKILL.md) for the full workflow, privacy guardrails, and output format.

## Development

```bash
composer install
vendor/bin/pest
```

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
