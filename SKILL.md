---
name: loom-context
description: Use when a task references a Loom recording (a loom.com/share link, or a ticket, issue, or chat thread that contains one) and you need what the video shows and says — repro steps, UI state, error screens, narrated feature asks — as text and screenshots you can read.
allowed-tools: Bash, Read, AskUserQuestion
---

# loom-context — turn a Loom recording into context you can read

People report bugs and describe features as narrated Loom screen recordings, which an agent cannot watch. This
skill fetches one recording, aligns its transcript with de-duplicated screenshots, and ends with you writing a
structured `SUMMARY.md` (observed problem, repro steps, expected vs actual, entities, open questions, likely code
areas) that can be pasted into a ticket.

- **Entry point:** `php "${CLAUDE_SKILL_DIR}/loom" <command>`, the `loom` file beside this one; shell helpers are in `scripts/`. Claude Code provides `CLAUDE_SKILL_DIR`. In Codex, replace `${CLAUDE_SKILL_DIR}` in command examples with the absolute directory containing this `SKILL.md`, from the skill path Codex reports.
- **Output:** `.loom/<videoId>/` under the current working directory. The folder git-ignores itself.
- **Needs:** PHP 8.3+, Composer and the skill's Composer packages, `yt-dlp`, `ffmpeg`. Step 3 checks all of it and, with the user's permission, installs what is missing.
- Nothing here posts anywhere; the human decides what to share.

## Required inputs

- A Loom link (`https://www.loom.com/share/<32 hex>`, `/embed/`, or a bare id), **or** a ticket key, issue, or chat permalink that contains one.
- Optional: the video password; a different frame cadence (`--interval`, default 4 s); `--max-frames` (default 60).

## Missing information handling

- No link, key, or permalink in the request: ask "Which Loom should I read? Paste the loom.com/share link, or the ticket or thread that has it."
- A ticket or thread with several Loom links: list them with the surrounding sentence and ask which one (or "all, one at a time").
- The build exits with code 4 and names a password: ask the user for it (use AskUserQuestion in Claude Code), then re-run with `--password`. Never store it.

## Workflow

### Step 1: Resolve the link

- Loom URL given: use it as is.
- Ticket key, issue, or chat permalink: read it with whatever connector this session has (Atlassian or Linear MCP, `gh issue view`, Slack MCP) and collect every `loom.com/share/<id>` in the body, comments, and replies. Slack wraps links as `<url|label>`; the script accepts that form. No connector that can read it: ask for the Loom link.

### Step 2: Take Loom's own brief and comments when an official tool offers them

Only if a `getLoomVideo` tool is available (Atlassian Rovo MCP; check deferred tools too): call it once with `videoUrl`,
`expand: ["transcript", "agentBriefs", "comments"]`, `transcriptFormat: "phrases"`. If it returns phrases, create
`.loom/<id>/` and write them to `transcript.json` as `{"phrases": [{"ts": <seconds>, "value": <text>, "speakerName": <name>}]}`,
the brief to `brief.md`, and comments to `comments.json`; Step 4 keeps and uses them. If the tool is absent, or returns
`{}` or "No Loom workspace" (the Loom workspace is not linked to that Atlassian site), say nothing and continue.

### Step 3: Set up

```bash
bash "${CLAUDE_SKILL_DIR}/scripts/setup.sh"
```

It checks PHP, Composer, the skill's Composer packages, yt-dlp, ffmpeg, and access to loom.com. It installs nothing.
No `FAIL` line: continue. Otherwise, for each `FAIL`:

- **Ends in `[--install <id>]`:** the line shows the exact command that would run. Ask the user which missing
  install actions they approve; in Claude Code, use AskUserQuestion with one option per item and its command in the
  description. In the order the check lists, run `bash "${CLAUDE_SKILL_DIR}/scripts/setup.sh" --install <id>` for each approved item.
- **Says `Fix by hand`:** it needs sudo or has no automatic install. Give the user the command or link to run themselves.
- **`cannot reach www.loom.com`:** sandboxed and cloud sessions often block it. Tell the user to allowlist the hosts the
  line names, or to run this on their own machine.

Never install anything the user has not approved in this conversation, and do not work around an item they declined.
Run the check again after installing; if a `FAIL` remains, stop and say what is still missing. `warn` lines only matter
for private Looms, and the browser line lists what was detected; nothing is read here.

### Step 4: Build the bundle

```bash
php "${CLAUDE_SKILL_DIR}/loom" context "<loom url>" [--interval 4] [--max-frames 60] [--password <pw>] [--cookies-from-browser none|<browser>]
```

The last stdout line is a JSON manifest; its `notes` say what is missing. On failure it is `{"error", "detail", "exit"}`
and the error names the fix.
Exit codes: 2 not a Loom link or a mistyped option · 3 network · 4 auth (private, deleted, password) · 5 yt-dlp missing or outdated · 6 ffmpeg missing · 7 the skill's Composer packages are not installed.
Partial bundles are kept: a transcript without frames, or frames without a transcript, is still worth summarising.

**Private Looms.** A link that does not open anonymously is retried with each installed browser's Loom cookies. On
macOS that can raise one Keychain prompt per browser, so warn the user first, or add `--cookies-from-browser chrome`
(or `brave`, `edge`, `chromium`, `firefox`, `safari`) to name the browser they are logged into Loom with.
`--cookies-from-browser none` never reads a browser; use it when the user has not agreed to that. A Loom opened with browser cookies
comes back without a transcript unless `LOOM_COOKIE` is set, because the transcript lookup cannot reuse a browser's
cookies. `LOOM_COOKIE` is Loom's `connect.sid` cookie, and the user supplies it, never you. It goes in the skill's own
`.env` (a copy of `.env.example`, beside `loom`), which is read on every run; a variable already in the environment
wins over it. Or it stays in 1Password with the item named by `LOOM_OP_ITEM` in that `.env`, and then
`source "${CLAUDE_SKILL_DIR}/scripts/loom_cookie.sh"` in the same Bash call as the command exports it. Do not read or
print `.env`; `setup.sh` says whether a cookie is configured. Re-run with `--refresh` once it is.

### Step 5: Read the context

Read `.loom/<id>/CONTEXT.md` in full. It has Loom's brief and chapters when they exist, the entities spotted (ticket
keys, URLs, emails, money, HTTP status words, plus any project-specific ones), and a timeline where each transcript
phrase points at the nearest frame. A section with nothing to show is left out. Frame files are named
`f-<mmss>-<reason>.jpg`, so `f-0100-tick.jpg` is at one minute.

The transcript is machine-made: it mishears words, runs several speakers together, and sometimes adds filler nobody
said ("Thanks for watching!"). Check a quote against the frame before leaning on it.

### Step 6: Look at the frames, selectively

Frames cost about 1.5k tokens each. View, in order: every `say` frame (the screen right after "click / see / error"),
every `cut` frame (navigation, modals), the last frame (the end state, often after the narration stops), then
`frames/contact-sheet.jpg` for the whole arc (thumbnails: good for sequence, too small to read text). Open other `tick`
frames only when the timeline leaves a gap you cannot explain. Quote timestamps (`[mm:ss]`) whenever you cite what you saw.

### Step 7: Write `.loom/<id>/SUMMARY.md`

Use these headings, in this order:

```markdown
# <title> — summary
Source: <loom url> · <recorder> · <YYYY-MM-DD> · <duration>

## Observed problem              ← or `## Requested change`, or `## What it shows` for a demo or walkthrough
## Steps to reproduce            ← numbered; each step ends with [mm:ss], plus the frame file if you viewed that frame
## Expected vs actual
## Environment and entities      ← app, site or tenant, user role, record IDs, browser, page or URL
## What the narrator asked for
## Open questions                ← what the video does not show (data state, flags, other accounts)
## Likely code areas             ← repo paths when the working directory is the codebase on screen (follow its CLAUDE.md / AGENTS.md); otherwise "Not applicable"
```

Redact emails and card fragments when quoting. Then report to the user: the paths to `CONTEXT.md` and `SUMMARY.md`, a
three-line gist, and the open questions. If you cannot write files, give the summary in your reply instead. Offer, do
not perform, posting the summary to the ticket.

## Project-specific identifiers

To spot identifiers only one project has, put `.loom-context.json` in that project's root
(`~/.config/loom-context/config.json` applies everywhere; `LOOM_CONTEXT_CONFIG` names any other file):

```json
{"entities": [
  {"kind": "order_id", "regex": "\\b[A-Z0-9]{16}\\b"},
  {"kind": "tenant", "regex": "\\bworkspace\\s+([a-z0-9-]{3,})", "flags": "i", "lower": true}
]}
```

The first capture group is the value. A `kind` that matches a built-in (`ticket`, `url`, `email`, `money`,
`http_status`) replaces it, which is how a project narrows `ticket` to its own prefixes.

## Guardrails

- Never print `LOOM_COOKIE`, a password, the skill's `.env`, or a cookie jar's contents; never pass a cookie on the command line (the scripts write an owner-only jar and delete it).
- One video per run, on recordings the user already has access to. No workspace listing, no batch scraping.
- Frames and `video.mp4` show real user data. Keep them under `.loom/`; never attach them to a public PR or issue; redact when quoting. Offer to delete the bundle once the user has what they need.
- Do not claim what a frame shows without having viewed it. If the manifest reports no frames, say the summary is transcript-only.

## Manual fallback: download disabled

If `CONTEXT.md` notes that the MP4 could not be downloaded, the recorder disabled downloads. Ask the user to open the
Loom in their browser and either enable downloads (video settings) or screenshot the two or three moments the
transcript points at; then describe those screenshots in `SUMMARY.md` alongside the transcript.

## Validation checklist

- [ ] `setup.sh` reports no `FAIL`
- [ ] `manifest.json` exists; `phrases` or `frames` is non-zero
- [ ] Every repro step in `SUMMARY.md` cites a timestamp, and names a frame file only if you viewed it
- [ ] No secrets or raw emails in `SUMMARY.md`
- [ ] Open questions list what the video could not show

## Maintaining this skill

Tests: `composer install && vendor/bin/pest` from this folder. The suite never touches the network or installs anything
(yt-dlp, Loom and the package managers are faked) and skips the frame tests when ffmpeg is missing. `php loom` lists the
commands. At a terminal, `bash scripts/setup.sh --ask` asks before each install instead of going through an agent.
