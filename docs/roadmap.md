# Roadmap

This document records the planned directions and the decisions behind them.

## Non-goals

Chat Archive is not a WhatsApp client. It does not send messages and has no
live client of its own. An opt-in read-only sync through WAHA exists. An
unofficial client puts the WhatsApp number at risk (see the warning in the
[user guide](USER-GUIDE.md#live-sync-waha)). The project does not put the
user's number at risk by default.

## AI agent access

Goal: the user asks an AI agent "what did we agree about the invoice in
March" and the agent reads the archive inside Nextcloud. No export.

Finding, September 2026: the agent paths into Nextcloud reach apps through
the unified search providers.

| Path | How it reaches an app |
|---|---|
| Nextcloud Assistant with the Context Agent app | "All search providers in Nextcloud are also automatically available as tools." The same tools are served on the Context Agent MCP server at `/index.php/apps/app_api/proxy/context_agent/mcp/`. |
| MCP Connector ([street1983nk/nextcloud-mcp-connector](https://github.com/street1983nk/nextcloud-mcp-connector), on GitHub only; the app store has no MCP Connector app, checked 2026-09-15) | Fixed tool list. Has `unified_search`. |
| nc_mcp_server (cloud-py-api) | Fixed tool list for 23 apps. Has unified search. |
| Dynamic MCP server (Rello) | Builds tools from the OpenAPI documents that `ocs_api_viewer` serves. Needs an `openapi.json` in the app. |

Chat Archive registers a unified search provider (MessageSearchProvider) that
makes the archive visible in the Nextcloud search bar, in the Assistant, and
— with filters — on the Context Agent and nc_mcp_server MCP paths. See the
developer guide section "Unified search provider".

### Per MCP path: does a filter set by the agent reach the provider?

Read directly from each project's source, 2026-09-15:

| Path | What the tool sends | Filters reach the provider |
|---|---|---|
| Context Agent | `search(search_query: dict)` forwards the whole mapping as the OCS request params. Its doc string tells the model to pick names from `provider['filters']`, the list `getSupportedFilters()`/`getCustomFilters()` produce. [`search.py`](https://github.com/nextcloud/context_agent/blob/main/ex_app/lib/all_tools/search.py) | Yes |
| nc_mcp_server | `unified_search(provider, term, limit, cursor, filters)` takes a JSON `filters` object and merges it into the OCS request params. [`search.py`](https://github.com/cloud-py-api/nc_mcp_server/blob/main/src/nc_mcp_server/tools/search.py) | Yes |
| MCP Connector | `unified_search(query, limit, providers)` has no filter parameter. The OCS call it makes (`ocs.provider_search`) carries only the term and the limit. [`search.py`](https://github.com/street1983nk/nextcloud-mcp-connector/blob/main/src/mcp_connector/tools/search.py) | No |
| Dynamic MCP server (Rello) | Does not call unified search at all. It reads an app's own `openapi.json` and builds one tool per route. | Not applicable |

An agent on the MCP Connector path can still find a hit by term. It cannot
narrow the search to one chat, one date range, one kind or one sender: the
tool drops those before the request leaves the connector.

### Fallback for the MCP Connector path: openapi.json

Fixing MCP Connector itself is out of scope; it is a separate project. The
fallback already on this roadmap as step 2 below is also the fix here: once
Chat Archive ships `openapi.json`, the Rello dynamic MCP server reads it
and builds one tool per route, with the same query parameters
`lib/Controller/ApiController.php::search()` already accepts — `source`,
`chat`, `from`, `to`, `kind`, `who` — with no unified search filter model
between the agent and the app. An agent on the Rello path gets the same
filters the web UI has, once step 2 ships. This does not help the MCP
Connector path itself, since Rello and MCP Connector are different servers;
it gives an agent stuck on a connector that drops filters another server to
use for the filtered case.

Plan, in order of cost:

1. (Done) Register an `OCP\Search\IFilteringProvider` for messages.
   See the developer guide section "Unified search provider".
2. Ship `openapi.json` from `nextcloud/openapi-extractor`. This exposes the
   full API (chats, message pages, calls) to the Rello dynamic MCP server,
   filters included, and is the only path above that gives an agent
   filtered access when the agent is stuck behind MCP Connector.
3. Add a "read one chat around a time" route for agents that need context
   around a hit. The message route already supports `at`.

Step 1 is done. Steps 2 and 3 wait for a real agent
use case that step 1 does not cover.

## Live bridge, opt-in

Built in 0.2.0: an opt-in, read-only sync through a self-hosted WAHA server.
It is a second source of rows, separate from the backup reader. The
[developer guide](DEVELOPER-GUIDE.md) describes it and the
[user guide](USER-GUIDE.md) shows how to use it.

It keeps the constraints of the design:

- Off by default. The administrator turns it on, assigns a session to a user,
  and the user accepts the risk warning (see the
  [user guide](USER-GUIDE.md#live-sync-waha)).
- Read-only. The app sends only GET requests to WAHA.
- Separate from the backup. A ban on the bridge must not affect the archive.

Left to do:

- WAHA webhooks, so that a message arrives without a poll.
- Edits and revokes from WAHA. Today only a backup import sets them.
- Merge of a `@lid` chat with its phone number chat.
- Linking a WAHA session to WhatsApp from inside the app.

## Other platforms

The importer sees platform-neutral rows. A new platform adds one provider
and one reader. Candidates with an exportable backup: Signal (backup with
passphrase), Telegram (JSON export), SMS backup apps.

## Smaller items

- Search matches only the original text of an edited message. Text that exists
  only in a later version is not found.
- Verify the GCM tag of the backup.
- Fetch media that the phone never downloaded. The predecessor project had
  `fetch-missing` for that.
- Resolve contact names in cron. Today they resolve in web requests only.
