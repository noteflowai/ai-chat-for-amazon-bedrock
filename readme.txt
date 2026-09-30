=== AI Agents & Chat for Amazon Bedrock – MCP Server, Claude, AWS ===
Contributors: glay, glayguo
Tags: amazon bedrock, claude, ai-chatbot, chatbot, mcp-server
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 1.51.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot and agents on Amazon Bedrock with Claude, Nova, Llama, gpt-oss and more. Connect with an API key or IAM role. Includes an MCP server.

== Description ==

Connect WordPress directly to **Amazon Bedrock** using your own AWS account. Add a chat powered by
Claude, Amazon Nova, Meta Llama, Mistral, DeepSeek, OpenAI gpt-oss, Qwen or Kimi, let authenticated conversations use
governed tools through the Model Context Protocol, and check that the answers are still right after
you change something.

Model requests go from your WordPress server to the Amazon Bedrock endpoint you configure. The
plugin author does not operate an AI relay service, and no request passes through anyone else. It is
built for site owners, developers and teams already on AWS who want predictable requests and
security-focused defaults.

= What you can build =

* An internal assistant, or a public chatbot with explicit guest access
* An agent that answers from your own content and from approved MCP tools
* A WordPress MCP endpoint that clients such as Claude Code, Cursor or VS Code can read

Add the chat with the `[ai_chat_bedrock]` shortcode or the chat block, or let it float on every page
from a single setting.

= Three things this does differently =

**It runs without storing AWS keys.** An instance role, a task role or environment variables are
enough. Off AWS, one Amazon Bedrock API key from the Bedrock console is all it takes to connect. Where keys are stored, they are encrypted, and Diagnostics generates the least-privilege
IAM policy this site actually needs rather than asking you to attach a broad managed policy.

**It assumes a public chat will be abused.** Every default below is the safe one, and each is a
setting you can change rather than a promise you have to trust:

* Guest access is off until you enable it, and the chat is hidden from visitors until it can
  actually answer, so a half-finished setup is never public
* Requests are rate limited per visitor and per profile, with optional per-role limits and an
  optional daily site cap
* Tools run on the server, so a browser cannot forge a tool result, and a tool that changes data
  needs an explicit capability
* External MCP tools are off for visitors, and the built-in MCP routes require authentication unless
  you deliberately open read-only access
* Input, history, token and tool-call limits are enforced on the server
* A visitor can stop a long answer, and the server stops the Bedrock request with it rather than
  paying for text nobody will read

**It can tell you whether an answer was good.** Write questions whose right answer you already know
and run them: each goes through the pipeline the chat uses, and the result is reported by category,
so you can see which part of an answer changed after a prompt edit or a model swap. Every check is a
program, so nothing scores style or tone and no model is asked to judge another model. It runs from
the command line too and exits nonzero, which is what lets it gate a deployment.

= Grounded in your own content =

Point the chat at your published pages and it answers from them, citing what it used. Choose an
embedding model and it matches by meaning rather than by shared words, so "when will my parcel
arrive" can find a page titled "Getting parcels to you". Keep the vectors in Amazon S3 Vectors and
every passage of every page is searched, in the visitor's language first. Only what a signed-out
visitor can read is ever indexed or quoted, so members-only sections stay out of answers. Questions
the site does not cover return no context, and the Conversations screen lists them as content gaps
with a shortcut to draft the page that is missing.

= An MCP server, and an MCP client =

The site can expose its own read-only tools to AI clients over JSON-RPC on protocol revision
2026-07-28, with anonymous access and OAuth both off by default. It can also call external MCP
servers and an Amazon Bedrock AgentCore Gateway, with no authentication, a bearer token stored
encrypted, or SigV4. Endpoints must be public HTTPS URLs.

= Stored data and privacy =

No custom table is created. The conversation log is optional and off by default; when enabled it
holds the 200 most recent exchanges in a WordPress option, with a retention window you set, and it
supports the WordPress personal-data export and erase tools. Debug mode records redacted metadata,
not prompts, responses or credentials. AWS states that model providers have no access to Bedrock
prompts and completions, and that they are not used to train the base models. The Privacy Policy section
sets out what is sent, to whom, and what is kept.

= What it costs, and how to watch it =

The dashboard shows requests and tokens for the last seven days, broken down by the model that
actually answered, so a fallback or a profile on a different model is visible. Those counters are
kept for 30 days and contain no prompts, responses or identities. On Claude, the system prompt and
tool definitions every visitor shares are cached by Bedrock, and the dashboard shows how many input
tokens were read from that cache instead of being billed at the full price.

Token counts are what Bedrock reported and are not a price estimate. Rate limiting reduces
accidental usage but guarantees nothing about your bill, so review Amazon Bedrock pricing and set
AWS Budgets before opening a chat to public traffic.

= The rest =

Streaming, managed prompts from Bedrock Prompt Management, a fallback model, multiple chats with
profiles, a floating launcher, the content tools, the WordPress abilities integration, WP-CLI, moving
a configuration between sites and the full MCP setup are covered in the FAQ tab, with their limits
stated.

== Installation ==

Before starting, enable access to the model in the selected AWS Region and create an IAM identity that can invoke only the models the site needs. Do not grant broad AWS administrator permissions to a WordPress site.

1. Install and activate the plugin.
2. Open **AI Chat Bedrock > Settings**.
3. Choose the AWS Region, then paste an Amazon Bedrock API key, enter AWS credentials, or leave both empty to use the server's IAM role. Pick a Bedrock model.
4. Save the settings and run **Diagnostics** while signed in.
5. Add `[ai_chat_bedrock]` to a page or post, or insert the chat block.
6. Review model pricing and request limits before enabling guest chat.

= Quickest start: an Amazon Bedrock API key =

1. In the Amazon Bedrock console, in the same Region you chose in the plugin, open **API keys** and generate a long-term key. Give it an expiry.
2. Paste it into **AI Chat Bedrock > Settings > AWS authentication > Amazon Bedrock API key** and save. It is stored encrypted and never shown again.
3. Run **Diagnostics**. It sends one short question to the model and reports the answer.

To keep the key out of the database, define it in `wp-config.php` instead:

`define( 'AI_CHAT_BEDROCK_API_KEY', 'replace-with-bedrock-api-key' );`

The plugin also reads `AWS_BEARER_TOKEN_BEDROCK`, the variable the AWS SDKs use. An API key covers chat, streaming, the model list and embeddings. Knowledge Bases, Prompt Management and AgentCore Gateway do not accept API keys, so they still need an IAM role or access keys, and Diagnostics says so when one of them is configured. Short-term keys expire after at most 12 hours, which suits a test but not a live site.

= Minimal IAM policy =

Replace `REGION` and `MODEL_ID` with your own values. Inference profiles and some model types use different resource ARNs; follow the AWS documentation for the model you select.

`{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": "bedrock:InvokeModel",
      "Resource": "arn:aws:bedrock:REGION::foundation-model/MODEL_ID"
    }
  ]
}`

= Keeping credentials out of the database =

For stronger isolation, define credentials in `wp-config.php` instead of saving them in the WordPress database:

`define( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY', 'replace-with-access-key' );`
`define( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY', 'replace-with-secret-key' );`
`define( 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN', 'replace-with-session-token' ); // Optional.`

Credentials saved through the settings screen are encrypted with authenticated encryption derived from the site's WordPress authentication salts, and saved secrets are never rendered back into the form.

== Frequently Asked Questions ==

= Do I need an OpenAI API key? =

No. Model requests use Amazon Bedrock and your AWS credentials. Availability, model access, pricing, and data handling are governed by your AWS account and Region.

= Which Bedrock models are supported? =

Text models in the Anthropic Claude, Amazon Nova, Amazon Titan, Meta Llama, Mistral and DeepSeek families that your Region offers, including Claude Sonnet 5, Claude Opus 5.5 and Claude Haiku 4.5, and the other chat models Bedrock serves, such as OpenAI gpt-oss, Qwen3, Llama 4, Mistral Large, DeepSeek R1 and Kimi. Models other than Claude, Nova and Titan are called through the Bedrock Converse API, which applies each model's own chat format; when a model refuses a setting such as temperature or a system prompt, the plugin retries once without it and remembers that for the model. The settings screen lists the models your account offers in that Region, and "Refresh model list" updates it. A new installation starts on Amazon Nova Lite because it answers with nothing enabled beyond an IAM role. Newer Claude models such as Sonnet 5 and Opus 5.5 are called through a cross-region inference profile, an ID beginning with `us.`, `eu.` or `global.`, and they reject the temperature setting, so the plugin does not send it to them. A specific model may still need a supported Region and suitable IAM permissions.

= What is an Amazon Bedrock API key, and should I use one? =

It is a single credential created in the Amazon Bedrock console and sent as a bearer token, so there is no IAM user or access key pair to manage. It is the quickest way to get a first answer, especially on hosting outside AWS. On an EC2 instance, ECS or EKS an IAM role is still the better choice, because nothing long-lived is stored at all. If a key stops working, check that it has not expired or been revoked and that its identity is allowed `bedrock:CallWithBearerToken`; the IAM policy that Diagnostics generates includes it when a key is configured.

= Does the plugin use prompt caching? =

Yes, on Claude 3.5 Haiku, Claude 3.7 Sonnet and newer Claude models, which Bedrock supports it for. The site's system prompt and tool definitions are the same for every visitor, so they are marked for Bedrock's prompt cache; a later request that starts the same way reads them at a fraction of the input price. Writing to the cache costs slightly more than a normal input token, and a prompt shorter than the model's minimum is simply not cached, so a site with a short prompt pays what it paid before. The dashboard and `wp ai-chat-bedrock usage` show cache reads and writes. The `ai_chat_bedrock_prompt_caching` filter turns it off.

= Why do I receive AccessDeniedException or a model access error? =

Confirm that the model is available and enabled in the configured AWS Region, the model ID is correct, and the IAM identity can call `bedrock:InvokeModel` for the required resource. Some models use inference profiles with different IDs and IAM resources.

= Does the chat work on a multilingual site? =

Yes, with Polylang or WPML. The chat title, welcome message and suggested questions are listed for translation under "AI Chat for Amazon Bedrock" in Languages > Translations (Polylang) or String Translation (WPML), including those of each profile, and each edition of the site shows its own. Answers are asked for in the language of the page, and with Polylang are drawn from pages in that language first. Keep the system prompt in one language; it is not translated.

= Why can guests not use the chat? =

The chat defaults to signed-in users to reduce the risk of anonymous scripts generating unbounded AWS charges. Guests see a sign-in link that brings them back to the same page; the `ai_chat_bedrock_sign_in_url` filter can point it at a custom sign-in page, or hide the chat from guests by returning an empty string. An administrator can explicitly enable guest access and configure a request limit.

= Are AWS credentials stored in plaintext? =

Newly saved credentials are encrypted with authenticated encryption derived from WordPress salts. Existing plaintext credentials are migrated when an administrator opens the dashboard. `wp-config.php` constants remain the preferred production option.

= Can I use temporary AWS credentials? =

Yes. Configure the access key, secret key, and session token together, or define all three constants in `wp-config.php`. A short-term Amazon Bedrock API key also works, and Diagnostics warns that it expires within 12 hours.

= Does the chat stream responses? =

Yes. Streaming is the default and uses an authenticated POST request with Server-Sent Events. The removed 1.0.x implementation was unsafe because it used a GET EventSource that placed conversation data in URLs and issued duplicate Bedrock requests. If the PHP cURL extension is missing or a proxy buffers the stream, the chat falls back to one buffered request automatically.

= Can I run without storing AWS keys? =

Yes. Leave the key fields empty and keep IAM role credentials enabled. The plugin then uses server environment variables, an ECS or EKS task role, or the EC2 instance role through IMDSv2.

= Why must external MCP servers use HTTPS? =

HTTPS and WordPress safe HTTP validation reduce server-side request forgery risk and protect tool inputs in transit. Private, loopback, link-local, credential-bearing, and unsafe redirect targets are rejected.

= How is the built-in WordPress MCP server protected? =

It is read-only and returns published content only. WordPress authentication is required by default. Administrators may explicitly expose it publicly; public requests are then rate limited.

= Does MCP support multi-round tool chains? =

Yes. The model can call tools, read the results, and continue reasoning for up to the configured number of rounds, defaulting to 3. The last round is answered without tools so the conversation always terminates. Tool output is always framed as untrusted data.

= Can the AI change my site or a remote system? =

Not by default. The built-in WordPress MCP endpoint is read-only. For external MCP servers, any tool that looks like it changes data is blocked until an administrator allows it, and those tools stay restricted to administrators.

= Does the plugin collect telemetry? =

No. This plugin does not send plugin-usage telemetry to the plugin author. Requests are sent only to services the administrator configures, as described in the Data flow and privacy section.

= Is this plugin affiliated with Amazon Web Services? =

No. Amazon Bedrock and AWS are trademarks of Amazon.com, Inc. or its affiliates. This is an independent open-source WordPress plugin.

= How does streaming work, and where do credentials come from? =

Streaming is on by default. Each message sends one authenticated POST request to a plugin REST route, and Bedrock response events are relayed to the browser with Server-Sent Events. Conversation content never appears in a URL, and one visitor message still results in exactly one Bedrock invocation. Streaming needs the PHP cURL extension; when it is unavailable, disabled or interrupted, the chat falls back to a single buffered request so answers are still delivered.

An Amazon Bedrock API key, when one is configured, is used for Bedrock and Bedrock Runtime requests. Signing credentials are resolved in this order: `wp-config.php` constants, encrypted WordPress settings, environment variables, an ECS or EKS task role, then an EC2 instance role using IMDSv2. The last three let a site on AWS run with no long-lived keys in WordPress at all. Role credentials are cached encrypted and refreshed before expiry, role lookups can be disabled with the `ai_chat_bedrock_use_role_credentials` filter, and the active source is shown in the settings without revealing secrets.

= What can the agent do with tools, and what stops it? =

An authenticated conversation can call tools from an administrator-configured MCP server. Tool calls execute on the WordPress server and their results go back to Bedrock for the final answer, always framed as untrusted data.

Tool use is governed by a policy layer:

* A capability is required to use tools at all, defaulting to `edit_posts`.
* Tools that appear to change data are blocked until an administrator allows them, and stay administrator-only.
* Up to five calls run per round, rounds are configurable from 1 to 5, and the final round answers without tools so a conversation always terminates.
* Every call is audited with the tool, round, outcome, duration and parameter key names. Values, output and chat content are never stored.

While the agent works, the chat names the tools it is running. The finished answer carries a collapsible list of every call, its round and whether it succeeded, showing metadata only. If the round limit is reached, the answer says so instead of quietly stopping.

= Can I keep the system prompt in AWS instead of in WordPress? =

Point the chat at a prompt in Bedrock Prompt Management and its text replaces the local system prompt, so one prompt can be reviewed and versioned in AWS and reused by every site. Pin a version for stability or follow the draft to pick up edits. `{{site_name}}`, `{{site_description}}`, `{{site_url}}` and `{{current_date}}` are filled in; anything else is sent exactly as written. The prompt must live in the same region as the chat, the text is cached briefly, and if it cannot be read the local system prompt is used instead rather than sending an empty one.

= How does semantic search differ from keyword search? =

Keyword search only finds passages sharing words with the question, so "when will my parcel arrive" misses "Getting parcels to you". Choose an embedding model and the plugin indexes published content, then matches questions by meaning. Indexing runs in small batches from the settings screen, unattended through WP-Cron, or with `wp ai-chat-bedrock index`. Editing a post marks it for re-indexing, and keyword search runs when nothing relevant is found. Questions the site does not cover return no context.

By default one vector per post is kept in the WordPress database and a question is compared with the 500 most recent items. For a larger site, choose Amazon S3 Vectors under Answer grounding: every post is split into overlapping passages, each passage gets its own vector in a vector bucket in your AWS account, and every one of them is searched. Create the bucket in the Amazon S3 console, then check or create the index from the settings screen or with `wp ai-chat-bedrock index --create-index`. S3 Vectors needs an IAM role or access keys, not a Bedrock API key; Diagnostics lists the `s3vectors` actions to allow. Several sites can share one index, and each only reads and deletes its own vectors.

Only published, public content is indexed, as a signed-out visitor sees it: sections that a membership or visibility plugin hides from guests are left out, and every result is checked against the live post again before it is quoted. Blocks with Block Visibility rules are left out whoever they are shown to, since that plugin applies its rules only on front-end pages; to leave out blocks that another plugin restricts, return true from the `ai_chat_bedrock_block_is_restricted` filter. With Polylang, a question is answered from pages in the visitor's language first. With Polylang or WPML, the model is also asked to reply in the language of the page; change or remove that instruction with the `ai_chat_bedrock_language_instruction` filter.

= Can the chat show where an answer came from? =

Turn on Show sources under Answer grounding and up to three links are listed under each answer, to the published pages and knowledge base documents it was given. Pages that only share a single word with the question are not listed.

= What are the fixes for other plugins? =

The Fixes for other plugins tab has small, optional adjustments for FluentAuth, Polylang and Yoast SEO. Each is off until you turn it on, and does nothing while the plugin it adjusts is inactive: read-only GitHub sign-in, social-only registration, an hreflang x-default for search engines, and crediting articles to the organization in Yoast's structured data.

= Why do all guests share one rate limit behind a CDN? =

Behind a load balancer or CDN every guest arrives from the proxy's address. If you control the proxy, return the address it reports from the `ai_chat_bedrock_client_ip` filter, for example CloudFront's `CloudFront-Viewer-Address` header. Never use a header a visitor can set directly, such as an unverified `X-Forwarded-For`, or anyone can escape the limit by sending a new value each time.

= What happens when a model is throttled or unavailable? =

Model access is the most common reason a Bedrock chat stops answering: a model is not enabled, throttled, or briefly unreachable. Choose a fallback model and those requests are retried once on it, and the reply states which model answered. An unrecognized identifier is treated the same way; requests rejected for any other reason are never retried. A stream is retried only before anything reaches the browser.

= What does the chat look like to a visitor? =

The chat is a self-contained, responsive interface with message bubbles, a typing indicator, a live streaming caret, tool activity status and per-answer token counts. Answers can be copied, failed requests can be retried, and every message carries a timestamp. It follows dark-mode and reduced-motion preferences and keeps focus styles and screen-reader labels intact.

Add up to four suggested questions and they appear as buttons above the input, disappear once the conversation starts and return when the chat is cleared. When the conversation log is enabled, each answer also gets a discreet **Was this helpful?** control; only the rating is stored, never anything about the visitor.

= Can the chat float instead of sitting in the page? =

Any chat can render as a floating button instead of an inline panel:

`[ai_chat_bedrock mode="popup" launcher="Ask us" profile="support"]`

A site-wide floating chat can be enabled with its own profile. Pages that already contain the chat block or shortcode are left unchanged, so the chat is never duplicated. The launcher is keyboard accessible, closes with Escape, stays open while a visitor browses other pages in the same tab, and adapts to small screens.

= Can one site run several different chats? =

One installation serves several chats. Each profile has its own key and can override the model, system prompt, title, welcome message, suggested questions, token limit, temperature, request limit, guest access, grounding and passage count. Anything left empty inherits the main settings.

`[ai_chat_bedrock profile="support"]`

Profile keys arriving from a page, block or chat request are validated against the stored profiles, so an unknown or crafted key falls back to the main settings and can never unlock guest access. Requests are rate limited per profile, and up to ten profiles can be stored.

= What content tools are included? =

All content tools are optional, require the capability to edit the item, and are rate limited.

* **Content generator**: turn a topic into a draft post with a chosen tone, length and language, plus source notes to rely on. The draft streams in as it is written and the post is created only when the model finishes, so an interruption leaves nothing behind. Output is always a draft, existing posts are never modified, and the model is told not to invent statistics, quotes, prices or dates.
* **Editor assistant**: a sidebar with six writing actions: improve, shorten, expand, summarize, suggest titles, translate. Suggestions are never saved automatically.
* **Image alt text**: describe an image with a Bedrock vision model and store it in the standard alt text field, singly or in bulk. Existing text is never replaced unless you ask; JPEG, PNG, GIF and WebP only.
* **Excerpts**: summarize the post into the excerpt field for review before saving.
* **Site pages**: describe the business and get a first set of pages as drafts, with editable titles. Nothing is published, an existing title is left alone, and the theme is never touched.
* **Content gaps**: questions no content answered, or that visitors marked unhelpful, grouped and counted. Each links to the generator with the subject filled in.

= Does it work with the AI features in WordPress core? =

On WordPress 7.0 and later, Bedrock is registered with core's AI Client, so
`wp_ai_client_prompt()` reaches it from any plugin that knows nothing about AWS.
Those calls use this plugin's request path, so the guardrail, model, region, token ceiling,
daily limit and usage accounting configured here apply to them.

On 7.1 it also joins the connector registry, declared as storing no credential: Bedrock signs
with IAM, not a key this site must keep. The Settings > Connectors screen lists only
connectors with a credential to manage, so Bedrock is absent there.

= How do I connect Claude Code, Cursor or another AI client to this site? =

The plugin exposes this WordPress site as an MCP server, so clients such as Claude Code, Cursor, VS Code or an agent framework can read it.

* Endpoint: `https://example.com/wp-json/ai-chat-bedrock/v1/mcp`
* Transport: JSON-RPC 2.0 over Streamable HTTP on protocol revision 2026-07-28, with `server/discover`, `tools/list` and `tools/call`. Clients on 2025-11-25 and 2025-06-18 are still answered
* Authentication: a WordPress Application Password works out of the box, over HTTPS
* Tools: five read-only content tools, plus SEO suggestions, WooCommerce lookup and draft creation when site abilities are on. Every call is capability-checked and audited.

Clients can connect with OAuth 2.1 instead of copying tokens: paste the endpoint, sign in to WordPress and approve, and no WordPress password reaches the client. Discovery uses `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`, client registration is dynamic, PKCE with S256 is mandatory, redirect targets must be HTTPS or loopback, authorization codes are single use, access tokens last an hour, and refresh tokens rotate so reusing one revokes the connection. Tokens are stored only as hashes. Each connection inherits the approving account's permissions and can be revoked at any time.

Anonymous access and OAuth are both disabled by default. If the endpoint returns 404, open Settings > Permalinks and save once so WordPress registers pretty REST routes.

= How do I connect an external MCP server or an AgentCore Gateway? =

External servers are called with JSON-RPC over Streamable HTTP with a declared protocol version. Three authentication modes are available:

* None, for a public endpoint.
* Bearer token, stored encrypted and never displayed again.
* AWS SigV4, which signs each request with the same AWS credentials already used for Bedrock. An AgentCore Gateway endpoint therefore needs no extra secret; the signing service defaults to `bedrock-agentcore` and the region falls back to the Bedrock region.

Endpoints must be public HTTPS URLs. Private, loopback, link-local and credential-bearing URLs are rejected, redirects are disabled and response size is capped. Discovered tools remain subject to the tool policy above.

= What can an agent read from and write to my site? =

Narrow abilities can be registered for agents and other plugins: search published posts and pages, read one published post or page, suggest an SEO title and meta description without saving, look up published WooCommerce products, and create a draft post.

Reads never return draft, private or password-protected content. The only write operation creates a new draft: nothing is published, updated or deleted, and WooCommerce orders and customers are never exposed. Draft creation requires `edit_posts`, reads require the capability configured for MCP tools, and the feature is disabled by default.

These register into WordPress's own Abilities registry, so anything that reads it sees them,
including the core REST routes under `/wp-abilities/v1/` and the official WordPress MCP adapter.
Checked against that adapter rather than assumed: an MCP client that connects to it discovers these
abilities alongside the core ones, reads each one's schema and behaviour before calling it, and can
execute them, which was confirmed by searching this site's content and by creating a draft through
the protocol.

Each ability declares what it does in a form a client can check rather than a sentence it has to
trust, and WordPress enforces the declaration: the read-only ones are refused over POST, and draft
creation, marked as updating but not destructive, is refused over GET. Text generation is
deliberately not marked read-only even though it changes nothing here, because it spends money on a
model request, and a client treating read-only as safe to call unattended would find that out by
billing the account. Listing and running abilities needs authentication; an anonymous request is
refused.

= Is there a command line? =

`wp ai-chat-bedrock index` builds the semantic index without keeping a browser tab open, with `--batch`, `--max` and `--force`. `index-status` reports coverage, `diagnose` runs the same checks as the admin screen with an optional `--live` Bedrock request, and `usage` prints requests and tokens per day or per model. Useful in a deploy step or a cron job.

= How do I check that answers are still right after a change? =

Write questions with expectations, then run `wp ai-chat-bedrock eval`. Each goes through the
pipeline the chat uses; results are reported by category: grounding, match strength, citation,
required and forbidden text, tool call, token budget. It exits nonzero to gate a deployment, and
`--compare` shows the per-category difference after a change.

Every check is a program, not an opinion: no judge model, no scoring of style, and anything
not checkable this way is reported as unchecked rather than as a pass.

The Answer checks screen edits and runs the set, and proposes cases from questions the site was
asked and answered badly. A proposal carries the question, never the expectation: no record holds
what a good answer says.


== Screenshots ==

1. A grounded answer on the front end, citing the pages it used, with a timestamp, a copy action and a helpfulness control.
2. Dashboard with today's usage, a seven-day trend, a per-model breakdown of requests and tokens, and the setup checklist.
3. Grounding settings: site content search, semantic search with an embedding model, batch indexing progress, knowledge base and controlled abilities.
4. Diagnostics running a live Amazon Bedrock connectivity test, with round-trip time and tokens used.
5. Answer checks: run a set of questions whose right answer you know, and read the result by category. Every check is a program; no model judges another model.
6. The golden set itself: what each question expects, the text it must or must not contain, the page it should credit, and the match it must clear.
7. MCP screen split into Servers, AI clients, Tool policy and Activity, here showing the tool policy and audit log switch.
8. Content generator streaming a draft as it is written, before the post is created.
9. Conversations: the content gaps this site has, each with a shortcut to draft the missing page, above the log with its filters, CSV export and per-answer token counts.
10. Chat settings with the system prompt, an optional Amazon Bedrock managed prompt, suggested questions and streaming.
11. Per-role request limits, so editors and administrators can be given more requests per minute than anonymous visitors.
12. The least-privilege IAM policy generated for this site's own configuration, ready to paste into AWS.
13. Drafting a first set of pages from a description of the business. Every page is a draft, and nothing existing is touched.


== Changelog ==

= 1.51.0 =
* Multilingual sites: the chat tells the model which language the visitor is reading the site in, from Polylang or WPML, and asks it to reply in that language unless the question is clearly written in another. A short Japanese question written mostly in kanji was answered in Chinese. The new `ai_chat_bedrock_language_instruction` filter changes the instruction, or removes it when it returns an empty string.
* Multilingual sites: the chat title, welcome message and suggested questions, of the main settings and of every profile, are registered with Polylang or WPML under "AI Chat for Amazon Bedrock", and shown translated in each language. Translate them in Languages > Translations (Polylang) or String Translation (WPML). Before, they appeared in the language they were typed in on every edition of the site.
* Source links and site abilities show post titles as plain text. A title with an ampersand or curly quotes showed its HTML entity, such as `&#038;`, under the answer.

= 1.50.0 =
* Security: members-only blocks are left out of the index and of answers wherever the text is read. Block Visibility hides blocks only on front-end requests, so text indexed with the Index content now button, or retrieved for a chat sent from wp-admin, could include blocks it shows only to signed-in visitors. Any block with Block Visibility rules is now removed before the post is rendered, and the new `ai_chat_bedrock_block_is_restricted` filter lets a site name blocks another plugin restricts. After the update every post counts as not yet indexed, and until it is indexed again an answer quotes its text as read now rather than the stored passage. Run Index content now, or let background indexing catch up.
* Chat: a visitor the chat is not open to sees a sign-in link instead of a message box, and returns to the same page after signing in. Before, a guest could type a question and only then learn that the chat was for members. The new `ai_chat_bedrock_sign_in_url` filter points the link at a custom sign-in page, or leaves the chat out for guests when it returns an empty string.
* With Polylang, the settings screen counts indexed posts and clears the index in every language, not only the admin's own, so indexing no longer looks unfinished.

Earlier releases are listed in changelog.txt, which ships with the plugin.

== Upgrade Notice ==

= 1.51.0 =
Multilingual chats reply in the language of the page, their title, greeting and suggested questions can be translated with Polylang or WPML, and source links show titles without HTML entities.

= 1.50.0 =
Security fix: blocks Block Visibility shows only to signed-in visitors could be indexed from the settings screen. Rebuild the index after updating. Guests now see a sign-in link in a members-only chat.

= 1.49.0 =
Optional Amazon S3 Vectors search over every passage, members-only text kept out of answers, OAuth tokens limited to MCP, a working Stop button, and settings tabs that no longer reset each other.

= 1.48.0 =
Choose a 7, 30 or 90-day period for the content gaps panel and its CSV. The default 30-day view and download are unchanged.

= 1.47.6 =
Plural forms and site date formats throughout wp-admin, clearer MCP and profile screens, and an accurate site builder summary.

= 1.47.5 =
The setup notice can be dismissed, and running answer checks saves your edits first and cannot be sent twice.

= 1.47.4 =
Tidier settings screens: import and export on its own tab, clearer profile limits and status badges.

= 1.47.3 =
Guest ratings on profile chats, the block's editor preview and popup mode, and confirmations before deleting.

= 1.47.2 =
Fixes the Test Chat screen in wp-admin, where the chat did not respond.

= 1.47.1 =
Fixes "Amazon Bedrock could not be reached" on sites whose VPC has a private-DNS interface endpoint for Bedrock.

= 1.47.0 =
Adds a protected CSV download to the Content gaps panel. Existing logging settings and stored conversations are unchanged.

= 1.46.0 =
Connect with an Amazon Bedrock API key instead of an IAM user. gpt-oss, Qwen3, DeepSeek R1, Llama 4, Mistral Large and Kimi now answer through the Converse API, and Claude prompts are cached.

= 1.45.0 =
Makes Claude Sonnet 5, Opus 5.5 and other current Claude models work; Bedrock rejected every request the plugin sent them. Also stops a missing model setting falling back to a retired model.

= 1.44.0 =
New installations defaulted to a model its provider has retired, so the first question failed. Existing sites that already chose a model are unaffected.

= 1.43.0 =
Fixes a fault that could produce a fatal error on sites where the AI client library WordPress bundles is incomplete. Worth taking.

= 1.42.0 =
The abilities this plugin defines were never reaching the WordPress Abilities registry, so AI clients reading it saw nothing from this plugin. They register now, and declare what each one does.

= 1.41.0 =
The documented wp ai-chat-bedrock eval --json had never worked, because WP-CLI maps --json onto a format parameter the command did not declare. Both --json and --format=json now work.

= 1.40.0 =
Deactivating the plugin no longer leaves an hourly scheduled event behind, and the streaming wire format is now covered by tests.

= 1.39.0 =
Streaming is now enabled on new installations as the settings field always said it was, and the generated IAM policy no longer includes the streaming action when streaming is off.

= 1.38.0 =
Test coverage for the retrieval filters that exclude unpublished and password-protected content, and for the capability checks on abilities.

= 1.37.0 =
Test coverage for the prompt-injection frame around tool output, which could previously have been removed without any suite failing.

= 1.36.0 =
The plugin now links to the repository that actually carries the released code, and uninstall cleans up two options it had been leaving behind.

= 1.35.0 =
Screenshots only: all thirteen re-shot against the current admin menu, with the Answer checks screen added. No functional change.

= 1.34.0 =
Documentation only: the plugin page now reads as a description rather than a feature list, with the detail moved to the FAQ tab. No functional change.

= 1.33.0 =
Answer checks now has a screen, and can propose cases from questions your site did not answer well. Expectations are still yours to state.

= 1.32.0 =
New: write a golden set of questions and run `wp ai-chat-bedrock eval` to check answers by category, with a nonzero exit for CI. Programmatic checks only; no judge model.

= 1.31.0 =
The Diagnostics screen no longer prints your full AWS account number or EC2 instance id, so the screen is safe to share. The generated IAM policy is unchanged.

= 1.30.1 =
Documentation correction: Bedrock joins the WordPress 7.1 connector registry but is not shown on the Connectors screen, which lists only connectors that store a credential.

= 1.30.0 =
On WordPress 7.0+, any plugin's wp_ai_client_prompt() call now reaches Bedrock under this site's guardrail, limits and usage accounting. Older WordPress is unaffected.

= 1.29.0 =
Speaks MCP revision 2026-07-28, so clients built on the current SDKs can connect. Older clients are unaffected.

= 1.28.0 =
Adds configuration export and import for moving a setup between sites. Credentials are never included in the file.

= 1.27.0 =
Diagnostics now verifies a configured guardrail and knowledge base, and a rejected guardrail says so instead of blaming permissions.

= 1.26.0 =
An unavailable MCP server now reports why instead of only that it is unavailable.

= 1.25.1 =
Saving settings now shows a confirmation instead of returning silently.

= 1.25.0 =
Accessibility and forward-compatibility fixes: the chat title no longer skips a heading level, and the editor sidebar stops relying on deprecated WordPress APIs.

= 1.24.0 =
Fixes the chat block, which could not be inserted in the block editor. If you have been using the shortcode because the block did not appear, the block works now.

= 1.23.0 =
A chat that cannot answer is no longer shown to visitors. Configured sites are unaffected.

= 1.22.0 =
Visitors can stop a long answer, and the Bedrock request stops with it. A visitor closing the tab now also stops the request instead of it running to completion unread.

= 1.21.0 =
Recommended if any of your visitors use a screen reader: streamed answers were announced repeatedly and are announced once now. Bedrock refusals also name the credential source in use.

= 1.20.0 =
The dashboard checklist now reflects the site instead of being permanently unfinished, and suggests what is worth configuring next.

= 1.19.0 =
Adds a content gap report showing what visitors asked that your site does not answer. Gaps appear for exchanges recorded from this version on.

= 1.18.1 =
Accessibility fixes for the Site Pages screen. Recommended if anyone uses a screen reader with it.

= 1.18.0 =
Adds Site Pages for drafting a starting set of pages, and replaces the generic Bedrock error message with the specific fix for each cause. Nothing is published automatically.

= 1.17.0 =
Diagnostics now generates a least-privilege IAM policy for your exact configuration and shows which AWS identity is in use. Nothing needs changing on existing sites.

= 1.16.0 =
Request limits can now be set per role, so staff are not held to the same per-minute cap as anonymous visitors. Existing sites keep their current limit until an override is added.

= 1.15.2 =
Restores the readme privacy disclosure that 1.15.1 dropped, and applies WordPress coding standards throughout. No configuration changes.

= 1.15.1 =
Coding standards and internationalization fixes from the official Plugin Check. No behaviour changes and nothing to reconfigure.

= 1.15.0 =
Adds WP-CLI commands and optional background indexing for semantic search. Both are additions; nothing changes unless you use them.

= 1.14.1 =
Accessibility fixes for the settings screens and refreshed screenshots. No configuration changes.

= 1.14.0 =
Adds optional Amazon Bedrock Prompt Management support and fixes request signing for paths ending in a slash. Nothing changes until you enter a prompt identifier.

= 1.13.0 =
Adds optional semantic search over your published content. It stays off until you pick an embedding model and index, and keyword search continues to work as before.

= 1.12.0 =
Adds a seven-day usage breakdown per model on the dashboard, and lets the fallback model cover an unrecognized model ID. Counters only; no chat content is stored.

= 1.11.0 =
The content generator now streams while it writes and only creates the draft once it finishes. Nothing to reconfigure.

= 1.10.0 =
Adds an optional fallback model for denied or throttled requests, and wires the conversation log into WordPress privacy requests. No fallback is configured until you choose one.

= 1.9.1 =
New name, same plugin: nothing to reconfigure. Adds visible agent steps so you can see which tools an answer used.

= 1.9.0 =
Adds suggested questions, answer feedback, a searchable and exportable conversation log, and bulk alt text. Feedback and the log stay off unless conversation logging is enabled.

= 1.8.0 =
Fixes the floating chat, which could not open in 1.7.0. Also reorganizes the settings and MCP screens and adds optional alt text and excerpt helpers that stay off until you enable them.

= 1.7.0 =
Adds an optional floating chat and a content generator that only creates drafts. Existing chats are unchanged, and the site-wide floating chat stays off until you enable it.

= 1.6.0 =
Adds optional chat profiles. Existing chats keep using the main settings, and no profile exists until you create one.

= 1.5.0 =
Adds an optional editor assistant and an optional conversation log. Both are disabled by default and no chat content is stored unless you enable logging and disclose it to your visitors.

= 1.4.0 =
Optional OAuth connections for AI clients are available and disabled by default. Enable them in the MCP settings if you want clients such as Claude Desktop to connect by signing in and approving. Existing Application Password access is unchanged.

= 1.3.0 =
The built-in WordPress MCP endpoint now speaks standard MCP JSON-RPC at /wp-json/ai-chat-bedrock/v1/mcp and works with MCP clients using a WordPress Application Password. Authentication is still required by default. If the endpoint returns 404, save your permalink settings once.

= 1.2.0 =
Streaming is now the default and IAM role credentials are supported, so a site on AWS needs no stored keys. Review the new guest access and spend limits before opening chat to the public.

= 1.1.0 =
Security and reliability release. Review the AWS credential settings after upgrading, and keep guest chat disabled unless you intend to pay for anonymous requests.

== Privacy Policy ==

Chat messages and the configured system prompt are sent to Amazon Bedrock. When MCP tools are enabled for authenticated users, relevant tool parameters are sent to the selected external MCP server and tool output is sent to Amazon Bedrock to complete the answer. Review AWS and each MCP provider's privacy terms before use.

When semantic search is on, the text a signed-out visitor can read on each published post is sent to Amazon Bedrock to create embeddings, and each question is embedded the same way. With Amazon S3 Vectors chosen, those passages and their vectors are stored in the vector bucket of your own AWS account, labelled with the site and post they came from. Uninstalling the plugin does not delete them; delete the index in AWS. The optional fixes for other plugins send nothing anywhere.

Conversation logging is disabled by default, and with it off no chat content is written to the database. When an administrator enables it, questions and answers are stored for the configured retention window, capped at the 200 most recent exchanges, and can be deleted per user or in full from the Conversations screen. Administrators are responsible for disclosing this recording to visitors.

The plugin creates no custom database tables; the optional log is kept in a WordPress option and is reachable through Tools > Export Personal Data and Erase Personal Data. Request limiting stores a salted hash-derived transient counter for each visitor for up to one minute. Debug logging is optional and records only redacted operational metadata. Administrators are responsible for disclosing these data flows and obtaining any consent required in their jurisdiction.
