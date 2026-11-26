# streaming-tool-agent

A **tool-using AI agent on the Claude API, in PHP**, built the way I run one in production behind a mobile app. It
answers only from your data, puts real buttons in the app, and streams the reply token by token as NDJSON.

```
POST /  {"messages":[{"role":"user","content":"my notes are not syncing"}], "context":{"platform":"ios","plan":"free"}}

{"t":"status","s":"search","q":"sync stuck"}
{"t":"status","s":"read","label":"Sync is stuck or a note is missing"}
{"t":"ui","actions":[{"type":"open_screen","target":"settings.sync","label":"Open Sync"}],"replies":["Still not working"]}
{"t":"text","v":"Let's get it moving. "}
{"t":"text","v":"1. Open **Settings → Sync** and tap Sync now…"}
{"t":"done"}
```

> The original is the AI companion of **Selah**, my prayer and Bible app (Claude Haiku 5.5 with Bible search,
> passage lookup and app actions; in production since October 2026). This repo keeps the engineering and swaps the
> domain for a fictional note-taking app's help center, so it runs on its own.

## What is interesting here

**Grounded, not remembered.** The agent may only state what a tool returned in this conversation. `search_help`
is a keyword index (idf weighting, accent folding, light stemming, ×2 for exact phrases). In production it searches
31,000 Bible verses in about 100 ms with no vector database. `get_article` returns the source text. The reply cites
with `[[article-id]]` markers that the app renders as cards from its own copy, so a hallucinated quote cannot reach the
screen.

**The model proposes, the server decides.** `app_ui` lets the model add buttons, suggested replies and a hand-off to
a human. Every action type comes from a fixed list and every target must exist, so a made-up screen id is dropped. The
client's context goes through an allow-list too (`Input::context`), and it reaches the prompt labelled *data, not
instructions*.

**A loop that handles how current models behave** (`src/Agent.php`):

| Situation | Handling |
|---|---|
| Text written *before* a tool call comes back as a hidden thinking block on current Claude models | the prompt asks for all tools first, reply last; if a turn still ends with nothing visible, one extra round asks for the reply with tools disabled |
| A long chain of tool calls | the last allowed round is sent with `tool_choice: none`, so it always ends in an answer |
| Thinking blocks carry signatures | the assistant content goes back **unchanged** with the tool results |
| The UI call is made after the reply is already on screen | no further model round; this saves a full request per question |
| Several tool calls in one turn | all results go back in **one** user message, each with its id |
| Unknown tool, bad input, or a tool that throws | an `is_error` result the model can recover from, never a crash |
| A bad key or a 429 | `open()` sends the first request **before** the HTTP response starts, so the app gets a real 429 or 502, not a 200 that stops early |
| The connection drops halfway through | `error: interrupted` (the app keeps the partial text) vs `unavailable` (nothing shown yet) |

**Cost-aware.** The system prompt has two blocks: instructions plus catalog, identical for every user and marked for
prompt caching, then the user's context after the breakpoint. The tool list is in a fixed order, because it is part of
the cached prefix. Effort is `low`, which is enough for chat. Token usage, including cache reads and writes, is added up
per question and logged. In production about 5k tokens per question are read from the cache.

## Run it

```bash
composer install
composer test                                    # 26 tests, no network: the loop runs against a scripted model
bin/ask --demo "how do I export a notebook?"     # offline demo, no key, no cost
bin/ask --search "restore deleted note"          # just the search index

export ANTHROPIC_API_KEY=sk-ant-…                # real model
bin/ask "my notes are not syncing"
composer serve                                   # HTTP + NDJSON on :8080
curl -N localhost:8080 -d '{"messages":[{"role":"user","content":"How do I cancel Plus?"}],"context":{"platform":"android"}}'
```

`AGENT_MODEL` (default `claude-haiku-5-5`), `AGENT_EFFORT` (default `low`) and `ANTHROPIC_BASE_URL` (for a local API
simulator) are read from the environment.

## Layout

```
src/Agent.php                the loop: rounds, tools, safety nets, events, usage
src/Model/ModelClient.php    the seam to the provider
src/Model/AnthropicModel.php Claude via the official SDK (streaming, caching, effort, tool_choice)
src/Model/ScriptedModel.php  plays back a script: tests and the offline demo
src/Tools/                   search_help, get_article, app_ui (+ validation)
src/Search/KeywordIndex.php  idf keyword search, accent folding, stemming
src/Prompt.php, Input.php    cached/uncached system blocks; untrusted input → known fields
public/index.php             HTTP endpoint: open first, then stream NDJSON
data/help-center.json        the fictional help center
```

## Left out on purpose

The production endpoint also checks an app key, rate-limits per user per day, per IP per hour and globally per day
(a cost ceiling), and writes the daily token counters to a cache for a spend dashboard. Those parts are plain HTTP
plumbing, so they are not here.

MIT licensed.
