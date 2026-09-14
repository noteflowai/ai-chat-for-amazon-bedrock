=== AI Agents & Chat for Amazon Bedrock – MCP Server, Claude, AWS ===
Contributors: glay, glayguo
Tags: amazon bedrock, claude, chatbot, mcp, mcp-server
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 1.25.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Streaming chat and governed tool-using agents on Amazon Bedrock. IAM roles, no stored keys, an MCP server for AI clients, security-first defaults.

== Description ==

Connect WordPress directly to **Amazon Bedrock** using your own AWS account. Add a configurable AI chat powered by Claude, Amazon Nova or Titan, Meta Llama, Mistral, or DeepSeek model formats, then let authenticated conversations use governed tools through the Model Context Protocol (MCP).

The plugin is built for site owners, developers and teams already using AWS who want predictable requests and security-focused defaults. Model requests go from your WordPress server to the configured Amazon Bedrock endpoint; the plugin author does not operate an AI relay service.

= What you can build =

* An authenticated internal assistant, or a public chatbot with explicit guest access
* An agent that answers from your own content and from approved MCP tools
* A WordPress MCP endpoint that clients such as Claude Code, Cursor or VS Code can read

= Highlights =

* Add chat with the `[ai_chat_bedrock]` shortcode or the chat block
* Streaming by default, with an automatic buffered fallback
* Supports Anthropic Claude, Amazon Nova and Titan, Meta Llama, Mistral and DeepSeek formats
* Runs without stored AWS keys, using an instance role, task role or environment variables
* Guest access off by default, per-visitor limits, and an optional daily site cap
* Tools execute on the server, so a browser cannot forge tool results

= Streaming and credentials =

Streaming is on by default. Each visitor message sends one authenticated POST request to a plugin REST route, and Bedrock response events are relayed to the browser with Server-Sent Events. Conversation content never appears in a URL, and one visitor message still results in exactly one Bedrock invocation. Streaming needs the PHP cURL extension; when it is unavailable, disabled or interrupted, the chat falls back to a single buffered request so answers are still delivered.

Credentials are resolved in this order: `wp-config.php` constants, encrypted WordPress settings, environment variables, an ECS or EKS task role, then an EC2 instance role using IMDSv2. The last three let a site on AWS run with no long-lived keys in WordPress at all. Role credentials are cached encrypted and refreshed before expiry, role lookups can be disabled with the `ai_chat_bedrock_use_role_credentials` filter, and the active source is shown in the settings without revealing secrets.

= Security and cost defaults =

* Only signed-in users can chat until guest access is explicitly enabled.
* External MCP tools are unavailable to anonymous visitors by default.
* Built-in WordPress MCP routes require authentication unless public read-only access is deliberately enabled.
* Chat and public MCP requests are rate limited per minute and per profile, with optional per-role limits so staff and anonymous visitors need not share one cap.
* The chat is not shown to visitors until it can actually answer, so a half-finished setup is never public.
* A visitor can stop a long answer, and the server stops the Bedrock request with it rather than paying for text nobody will read.
* Diagnostics generates the least-privilege IAM policy this site needs, and the dashboard checklist reads the site rather than guessing, so it can be finished and then says what is left worth configuring.
* Input, history, token, tool-call, redirect and remote-response limits are enforced server-side.
* MCP destinations must use public HTTPS URLs; private, loopback, link-local, credential-bearing and unsafe redirect targets are rejected.
* Debug mode records redacted operational metadata, not prompts, responses, credentials or authorization headers.

The dashboard shows requests and tokens for the last seven days, broken down by the model that actually answered, so a fallback or a profile using a different model is visible. Counters are kept for 30 days and contain no prompts, responses or identities.

Request limiting reduces accidental usage but is not a billing guarantee. Token counts are what Bedrock reported, not a price estimate: review Amazon Bedrock pricing and configure AWS Budgets before opening chat to public traffic.

= Tool use and visible agent steps =

An authenticated conversation can discover and call tools from an administrator-configured MCP server. Tool calls execute on the WordPress server and their results go back to Bedrock for the final answer, always framed as untrusted data.

Tool use is governed by a policy layer:

* A capability is required to use tools at all, defaulting to `edit_posts`.
* Tools that appear to change data are blocked until an administrator allows them, and stay administrator-only.
* Up to five calls run per round, rounds are configurable from 1 to 5, and the final round answers without tools so a conversation always terminates.
* Every call is audited with the tool, round, outcome, duration and parameter key names. Values, output and chat content are never stored.

While the agent works, the chat names the tools it is running. The finished answer carries a collapsible list of every call, its round and whether it succeeded, showing metadata only. If the round limit is reached, the answer says so instead of quietly stopping.

= WP-CLI =

`wp ai-chat-bedrock index` builds the semantic index without keeping a browser tab open, with `--batch`, `--max` and `--force`. `index-status` reports coverage, `diagnose` runs the same checks as the admin screen with an optional `--live` Bedrock request, and `usage` prints requests and tokens per day or per model. Useful in a deploy step or a cron job.

= Managed prompts =

Point the chat at a prompt in Amazon Bedrock Prompt Management and its text replaces the local system prompt, so one prompt can be reviewed and versioned in AWS and reused by every site. Pin a version for stability or follow the draft to pick up edits. `{{site_name}}`, `{{site_description}}`, `{{site_url}}` and `{{current_date}}` are filled in; anything else is sent exactly as written. The prompt must live in the same region as the chat, the text is cached briefly, and if it cannot be read the local system prompt is used instead rather than sending an empty one.

= Semantic search =

Keyword search only finds passages that share words with the question, so "when will my parcel arrive" misses a page titled "Getting parcels to you". Choose an embedding model and the plugin indexes your published content, then matches questions by meaning. Indexing runs in small batches from the settings screen, or unattended: switch on background indexing to let WP-Cron finish the job, and use `wp ai-chat-bedrock index` on a large site. Editing a post marks it for re-indexing, and keyword search still runs whenever nothing relevant is found. Questions about subjects your site does not cover return no context at all rather than an unrelated passage.

= Fallback model =

Model access is the most common reason a Bedrock chat stops answering: a model is not enabled in the account, a request is throttled, or the service is briefly unreachable. Choose a fallback model and those requests are retried once on it, with the reply stating which model answered. A model identifier Bedrock does not recognize is treated the same way, since another model may well work. Requests rejected for any other reason, including an invalid payload, missing credentials or the site's own daily limit, are never retried because a second model would fail the same way. A stream is only retried while nothing has reached the browser, so text is never duplicated.

= Chat experience =

The chat is a self-contained, responsive interface with message bubbles, a typing indicator, a live streaming caret, tool activity status and per-answer token counts. Answers can be copied, failed requests can be retried, and every message carries a timestamp. It follows dark-mode and reduced-motion preferences and keeps focus styles and screen-reader labels intact.

Add up to four suggested questions and they appear as buttons above the input, disappear once the conversation starts and return when the chat is cleared. When the conversation log is enabled, each answer also gets a discreet **Was this helpful?** control; only the rating is stored, never anything about the visitor.

= Floating chat =

Any chat can render as a floating button instead of an inline panel:

`[ai_chat_bedrock mode="popup" launcher="Ask us" profile="support"]`

A site-wide floating chat can be enabled in the settings with its own profile. Pages that already contain the chat block or shortcode are left unchanged, so the chat is never duplicated. The launcher is keyboard accessible, closes with Escape, stays open while a visitor browses other pages in the same tab, and adapts to small screens.

= Multiple chats with profiles =

One installation can serve several chats. Each profile has its own key and can override the model, system prompt, title, welcome message, suggested questions, token limit, temperature, request limit, guest access, grounding and passage count. Anything left empty inherits the main settings.

`[ai_chat_bedrock profile="support"]`

Profile keys arriving from a page, block or chat request are validated against the stored profiles, so an unknown or crafted key falls back to the main settings and can never unlock guest access. Requests are rate limited per profile, and up to ten profiles can be stored.

= Content tools =

All content tools are optional, require the capability to edit the item, and are rate limited.

* **Content generator**: turn a topic into a draft post with a chosen tone, length and language, plus source notes the draft should rely on. The draft streams into the screen as it is written and the post is only created once the model finishes, so an interrupted generation leaves nothing behind. Output is always a draft, existing posts are never modified, and the model is told not to invent statistics, quotes, prices, dates or named sources.
* **Editor assistant**: a block editor sidebar with six writing actions, namely improve, shorten, expand, summarize, suggest titles and translate. Suggestions are never saved automatically.
* **Image alt text**: describe an image with a Bedrock vision model and store the result in the standard WordPress alt text field, one image at a time or as a media library bulk action. Existing alt text is never replaced unless you ask, and only JPEG, PNG, GIF and WebP files are accepted.
* **Excerpts**: summarize the current post into the excerpt field for review before saving.
* **Site pages**: describe the business and get a first set of pages as drafts, with titles you can edit before anything is written. Nothing is published, a title that already exists is left alone, and the theme and menus are never touched.
* **Content gaps**: the questions visitors asked that no site content answered, or that they marked unhelpful, grouped and counted. Each one links straight to the generator with the subject filled in.

= Connect AI clients to this site =

The plugin exposes this WordPress site as an MCP server, so clients such as Claude Code, Cursor, VS Code or an agent framework can read it.

* Endpoint: `https://example.com/wp-json/ai-chat-bedrock/v1/mcp`
* Transport: JSON-RPC 2.0 over Streamable HTTP, with `initialize`, `tools/list` and `tools/call`
* Authentication: a WordPress Application Password works out of the box, over HTTPS
* Tools: five read-only content tools, plus SEO suggestions, WooCommerce lookup and draft creation when site abilities are on. Every call is capability-checked and audited.

Clients can also connect with OAuth 2.1 instead of copying tokens: paste the endpoint, sign in to WordPress and approve, and no WordPress password reaches the client. Discovery uses `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`, client registration is dynamic, PKCE with S256 is mandatory, redirect targets must be HTTPS or loopback, authorization codes are single use, access tokens last an hour, and refresh tokens rotate so reusing one revokes the connection. Tokens are stored only as hashes. Each connection inherits the approving account's permissions and can be revoked at any time.

Anonymous access and OAuth are both disabled by default. If the endpoint returns 404, open Settings > Permalinks and save once so WordPress registers pretty REST routes.

= Connecting an MCP server or Amazon Bedrock AgentCore Gateway =

External servers are called with JSON-RPC over Streamable HTTP with a declared protocol version. Three authentication modes are available:

* None, for a public endpoint.
* Bearer token, stored encrypted and never displayed again.
* AWS SigV4, which signs each request with the same AWS credentials already used for Bedrock. An AgentCore Gateway endpoint therefore needs no extra secret; the signing service defaults to `bedrock-agentcore` and the region falls back to the Bedrock region.

Endpoints must be public HTTPS URLs. Private, loopback, link-local and credential-bearing URLs are rejected, redirects are disabled and response size is capped. Discovered tools remain subject to the tool policy above.

= Site content abilities =

An optional set of narrow abilities can be registered for agents and other plugins: search published posts and pages, read one published post or page, suggest an SEO title and meta description without saving, look up published WooCommerce products, and create a draft post.

Reads never return draft, private or password-protected content. The only write operation creates a new draft: nothing is published, updated or deleted, and WooCommerce orders and customers are never exposed. Draft creation requires `edit_posts`, reads require the capability configured for MCP tools, and the feature is disabled by default.

= Stored data and privacy =

No custom database table is created. The conversation log is optional and off by default; when enabled it holds the 200 most recent exchanges in a WordPress option, with retention from 1 to 90 days, and can be searched, filtered, exported to CSV and deleted per user or in full.

The Privacy Policy section below sets out what is sent where, what is stored and what administrators are responsible for disclosing.

== Installation ==

Before starting, enable access to the model in the selected AWS Region and create an IAM identity that can invoke only the models the site needs. Do not grant broad AWS administrator permissions to a WordPress site.

1. Install and activate the plugin.
2. Open **AI Chat Bedrock > Settings**.
3. Enter the AWS Region, credentials and Bedrock model ID.
4. Save the settings and run **Diagnostics** while signed in.
5. Add `[ai_chat_bedrock]` to a page or post, or insert the chat block.
6. Review model pricing and request limits before enabling guest chat.

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

Version 1.1.0 includes request and response formats for Anthropic Claude, Amazon Nova and Titan, Meta Llama, Mistral, and DeepSeek model families. A specific model may still require model access, a supported Region, the correct model or inference-profile ID, and suitable IAM permissions.

= Why do I receive AccessDeniedException or a model access error? =

Confirm that the model is available and enabled in the configured AWS Region, the model ID is correct, and the IAM identity can call `bedrock:InvokeModel` for the required resource. Some models use inference profiles with different IDs and IAM resources.

= Why can guests not use the chat after upgrading? =

Version 1.1.0 defaults to signed-in users to reduce the risk of anonymous scripts generating unbounded AWS charges. An administrator can explicitly enable guest access and configure a request limit.

= Are AWS credentials stored in plaintext? =

Newly saved credentials are encrypted with authenticated encryption derived from WordPress salts. Existing plaintext credentials are migrated when an administrator opens the dashboard. `wp-config.php` constants remain the preferred production option.

= Can I use temporary AWS credentials? =

Yes. Configure the access key, secret key, and session token together, or define all three constants in `wp-config.php`.

= Does version 1.2.0 stream responses? =

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

== Screenshots ==

1. A grounded answer on the front end, citing the pages it used, with a timestamp, a copy action and a helpfulness control.
2. Dashboard with today's usage, a seven-day trend, a per-model breakdown of requests and tokens, and the setup checklist.
3. Grounding settings: site content search, semantic search with an embedding model, batch indexing progress, knowledge base and controlled abilities.
4. Diagnostics running a live Amazon Bedrock connectivity test, with round-trip time and tokens used.
5. MCP screen split into Servers, AI clients, Tool policy and Activity, here showing the tool policy and audit log switch.
6. Content generator streaming a draft as it is written, before the post is created.
7. Conversation log with search, source and rating filters, CSV export and helpfulness counts.
8. Chat settings with the system prompt, an optional Amazon Bedrock managed prompt, suggested questions and streaming.
9. Per-role request limits, so editors and administrators can be given more requests per minute than anonymous visitors.
10. The least-privilege IAM policy generated for this site's own configuration, ready to paste into AWS.
11. Drafting a first set of pages from a description of the business. Every page is a draft, and nothing existing is touched.
12. Content gaps: the questions visitors asked that the site has no content for, each with a shortcut to draft an answer.
13. A setup checklist that reads the site's own state, followed by the improvements still worth making.

== Changelog ==

= 1.25.1 =
* Saving settings now confirms it. The page displayed notices for its own slug while WordPress registers the built-in confirmation under another, so a save came back silently with no way to tell whether it had worked.

= 1.25.0 =
* The chat title is now a second level heading instead of a third, which skipped a level under the page title on the front end and on the Test Chat screen.
* The editor assistant sidebar no longer uses WordPress APIs deprecated in 6.6, so it will not quietly disappear when they are removed. Older versions still work through a fallback.

= 1.24.0 =
* Fixed the Amazon Bedrock Chat block, which could not be inserted in the block editor at all. Its editor script was registered without dependencies, so it ran before the editor libraries existed and failed silently.
* The block now offers a menu of the chat profiles that exist, instead of asking for a profile key typed from memory.
* Uninstall now removes the marker left on scaffolded pages, which was being left behind.

= 1.23.0 =
* The chat no longer renders for visitors when it cannot answer. Until now a fresh install showed a working-looking chat that failed on the first message.
* Administrators see a short message in its place saying what is still missing, with a link to finish the setup. Visitors see nothing at all.
* The floating widget stays silent in that state rather than putting a notice in the footer.

= 1.22.0 =
* Added a Stop button while an answer is streaming. Whatever has arrived is kept, and stopping is not reported as an error.
* Stopping now stops the Amazon Bedrock request too. Measured on a 3,939 character answer: stopping after 222 characters took 1.3 seconds instead of 8.9, so the rest was never generated or billed.
* A visitor closing the tab has the same effect. Until now the server carried on consuming the answer nobody was reading.

= 1.21.0 =
* Fixed the chat being close to unusable with a screen reader. Streaming rewrote the whole answer into a live region on every chunk, so one short answer was read out fifteen times over. Answers are announced once now, when complete, from a dedicated region.
* Announcements use the rendered text rather than the raw reply, so a screen reader no longer reads markdown asterisks aloud.
* When Amazon Bedrock refuses a request the message now names the credential source that was used, and warns when temporary credentials are in use that nothing will renew.
* Checked the visitor chat at phone width: no horizontal overflow and no tap target under 24 pixels.

= 1.20.0 =
* Fixed the dashboard checklist. The last step was written as permanently incomplete, so the list could never be finished however the site was configured. It now detects the chat block, the shortcode and the floating button, and says which one it found.
* The checklist shows progress, and once setup is done it lists what is still worth configuring: grounding, conversation recording, a fallback model, and a guest limit when guest chat is on.
* Each suggestion disappears once it no longer applies.
* Fixed the seven-day usage chart drawing a small bar for days with no requests, which made idle days look busy.

= 1.19.0 =
* Added a content gap report to the Conversations screen: the questions visitors asked that no site content answered, or that they marked unhelpful, grouped by subject and counted.
* Each gap links to the content generator with the subject filled in, so the loop from question to draft is one click.
* Each stored exchange now records whether site content was found for the question, and the CSV export carries that column.
* Similar wordings are grouped without stemming, so two subjects are never merged into one; the same subject may appear twice if worded very differently.

= 1.18.1 =
* Fixed accessibility on the Site Pages screen: every row of the proposed page list exposed the same name, so a screen reader could not tell the title fields apart. Names now carry the page title and follow it as you edit.
* Removed stray hidden labels from that list which were read out as loose text.
* Shortened the description, which repeated what the Privacy Policy section already states.

= 1.18.0 =
* Added Site Pages: describe the business, review the proposed page list, and get each page as a draft. Output is always a draft, an existing title is skipped rather than overwritten, and the theme, menus and options are untouched.
* Bedrock failures now say what to do. A model that cannot be called on demand, a model ID the region does not offer, a model the provider retired, a missing model grant and an IAM denial were all reported with one generic message before, and each has a different fix.
* An IAM denial now names the action that was refused.
* Replaced a call to get_page_by_title(), which WordPress deprecated in 6.2.

= 1.17.0 =
* Diagnostics now generates the IAM policy this site actually needs, scoped to the configured models, region and optional features, with a copy button.
* The policy covers what is easy to get wrong by hand: InvokeModelWithResponseStream is a separate action from InvokeModel, a cross-region inference profile also needs its underlying foundation model, a guardrail needs ApplyGuardrail, and an AgentCore gateway uses its own service prefix.
* Diagnostics reports which AWS identity the credentials belong to, so a site pointing at the wrong account is obvious.
* Added the matching entries to Common fixes, including why chat can work while streaming fails.

= 1.16.0 =
* Added per-role request limits on the Chat tab, so editors or administrators can be given more requests per minute than anonymous visitors.
* A visitor holding several roles receives the most permissive of them, matching how WordPress capabilities accumulate.
* Leaving a role empty keeps the site-wide limit and setting it to zero removes the override. Existing sites are unchanged until an override is added.

= 1.15.2 =
* Restored the Privacy Policy section, which 1.15.1 removed by accident while shortening an upgrade notice. The plugin behaved the same, but the data flow disclosure was missing from the readme.
* Added a pre-release script that checks version consistency, readme limits, every test suite and the package contents, so a missing section cannot slip through again. It caught this very regression.
* Added a WordPress coding standards ruleset and worked the code to a clean run: 623 errors and 230 warnings down to none.
* Replaced dirname( __FILE__ ) with __DIR__, renamed view variables that shadowed WordPress globals such as $paged and $post_id, and renamed parameters that used reserved words.
* Fixed a latent bug in the event stream parser found while tidying: the frame loop cached the buffer length without shrinking it, so a read containing several frames could stall. Added assertions for multi-frame reads and trailing partial frames.

= 1.15.1 =
* Ran the official Plugin Check against the plugin and worked through every finding: shipped errors went from ten to none, and warnings from 160 to one documented case.
* Added the missing translator comments so strings with placeholders can be translated correctly.
* AWS endpoints are now built in one place and can be redirected with a filter, which suits FIPS endpoints and VPC interface endpoints.
* Global functions and uninstall variables now carry the plugin prefix, avoiding collisions with other code.
* Environment variable reads are unslashed and sanitized, and one option write is sanitized explicitly rather than by usage.
* Removed the manual text domain loading, which WordPress.org has handled automatically since WordPress 4.6, and the now unused class behind it.
* Shortened two overlong upgrade notices to the 300 character limit.

= 1.15.0 =
* Added WP-CLI commands: index, index-status, diagnose and usage, so indexing and checks can run in a deploy step or from cron instead of a browser tab.
* Added optional background indexing through WP-Cron, scheduled only while semantic search is on and removed again when it is switched off or the plugin is deactivated.
* Indexing a large site no longer requires the settings page to stay open; a full rebuild of ten items took eight seconds from the command line in testing.
* Tightened the legacy text domain regression check to look at translation calls rather than any occurrence of the string, so a legitimately named CLI command no longer trips it.

= 1.14.1 =
* Every settings control is now programmatically associated with its row label, so screen readers announce a name instead of an unlabelled field. Checkbox rows keep their single inline label rather than being announced twice.
* Gave the MCP signing region input an accessible name.
* Refreshed all catalog screenshots for the current interface and added two, covering the usage breakdown, semantic search, the streaming generator, the conversation log and managed prompts.
* Audited all twelve plugin screens for overflow, duplicate headings, console errors and unlabelled controls; no layout or script problems remained.

= 1.14.0 =
* Added Amazon Bedrock Prompt Management support: the system prompt can come from a versioned prompt in AWS, with a settings preview of the exact text that will be sent.
* Pinning a version keeps the prompt stable while following the draft picks up edits, both verified against a real prompt.
* A prompt that cannot be read falls back to the local system prompt instead of sending nothing, and the reason is shown in the settings.
* Fixed request signing for AWS paths that end in a slash. The canonical path dropped the trailing slash, so every Bedrock control plane call of that shape, including GetPrompt, failed with a signature mismatch.
* Site variables in a prompt template are substituted, and any variable this plugin cannot resolve is listed in the settings instead of being guessed.

= 1.13.0 =
* Added semantic search: published content can be indexed with an Amazon Titan or Cohere embedding model, and questions are then matched by meaning rather than by shared words. Verified against a question keyword search could not answer at all.
* Vectors are stored in post meta, indexing runs in batches from the settings screen, and editing a post marks it for re-indexing.
* Relevance filtering uses a measured floor plus a relative cut instead of one fixed threshold, so an off-topic question returns no context rather than an unrelated passage.
* Embedding requests appear in the per-model usage panel like any other Bedrock call.
* Only published, publicly readable content is indexed or returned; drafts and password protected posts are never embedded.
* The chat request builder now accepts conversation history as an array as well as a JSON string.

= 1.12.0 =
* Added a seven-day usage panel to the dashboard with a per-day trend and a per-model breakdown of requests and tokens.
* Usage is now attributed to the model that actually answered, so a fallback or a profile on another model shows up separately. Failed requests are still not counted.
* The fallback model now also covers a model identifier Bedrock does not recognize, which it reports as HTTP 400. Payload errors are still never retried.
* Fixed usage pruning, which rebuilt each day's record and would have discarded the new per-model counters.
* Streaming now keeps a small copy of the first response bytes, because the event-stream parser consumed the body and an HTTP error message was gone before it could be classified.

= 1.11.0 =
* The content generator now streams the draft as it is written instead of leaving the screen blank until the model finishes. In testing the first text appeared after about 1.5 seconds of a 4.5 second generation.
* The draft post is only created after the model completes, so an interrupted or failed generation leaves no orphan post.
* The generator form still works without JavaScript, falling back to the previous synchronous submission.
* Moved Server-Sent Events framing into one shared class used by both the chat stream and the generator, so the two cannot drift apart.
* Split the generator into request preparation and draft creation, which is what let the streaming route reuse the same permission checks, limits and prompt.

= 1.10.0 =
* Added an optional fallback model. When the main model is denied, throttled or unreachable, the request is retried once on the fallback and the reply says which model answered.
* Streams are only retried before any text has been sent, so a fallback can never duplicate output.
* Requests refused for other reasons, such as an invalid payload, missing credentials or the daily limit, are never retried.
* When the fallback also fails, the primary error is still reported but now carries the fallback outcome so the cause is diagnosable.
* Registered the conversation log with WordPress' personal data export and erase tools, so privacy requests cover stored chat content.
* Bedrock HTTP failures now carry the status code internally, which is what makes retry decisions possible.

= 1.9.1 =
* Renamed the plugin to AI Agents & Chat for Amazon Bedrock, which describes the governed tool-using agent it has become. The plugin slug, text domain and settings are unchanged, so nothing needs to be reconfigured.
* Tool rounds now report which tools ran, instead of only how many, while the agent is working.
* Answers that used tools gain a collapsible step trace listing each tool, its round and whether it succeeded. Parameter values and tool output are never included, matching the audit log.
* Reaching the tool round limit is now stated in the answer instead of silently truncating the agent loop.
* Tool names in the trace and status line are shown in a readable form, such as core/get-site-info, with the internal identifier available on hover.
* Fixed WordPress Abilities used as chat tools, which could never run: the MCP handler claimed every ability call because it shares the owner___tool shape, and failed it as an invalid server.

= 1.9.0 =
* Added suggested questions that appear as buttons above the chat input, with a per-profile list and a global default.
* Added answer feedback: visitors can mark an answer helpful or not, and only the rating is stored.
* Added search, source and rating filters, pagination and CSV export to the conversation log, with spreadsheet formula characters neutralized in the export.
* Added a media library bulk action that generates alt text for up to 20 selected images at once, skipping images that already have alt text.
* Added result notices after alt text generation, reporting how many images were described, skipped or failed.
* The floating chat now stays open while a visitor browses other pages in the same tab.
* Fixed conversation log pruning, which dropped the entry identifier and rating so feedback could not be stored after a prune.

= 1.8.0 =
* Added optional media helpers: a Generate alt text action in the media library that describes images with a Bedrock vision model, and a Generate excerpt button in the block editor sidebar.
* Existing alt text is preserved unless overwriting is requested, unsupported file types are refused, and images are downscaled before being sent.
* Split the settings screen into five tabs, which reduced its height from about 3090 to about 970 pixels, and made each tab save only its own fields so other tabs keep their values.
* Split the MCP screen into Servers, AI clients, Tool policy and Activity sections, which reduced its height from about 2070 to about 1225 pixels; the page stays fully readable without JavaScript.
* Added Settings and Diagnostics links to the plugin row on the Plugins screen.
* Added a copy button, a timestamp and a retry action to chat messages.
* Added an administrator notice when no usable AWS credentials are found, linking to the settings screen.
* Fixed the floating chat, whose styles and script were queued too late in the footer, leaving the launcher invisible and unable to open.
* Hardened settings saving so a submission without the tab field list, such as a cached form, no longer resets unrelated settings to their defaults.

= 1.7.0 =
* Added a floating chat mode for the shortcode and block, plus an optional site-wide floating chat that skips pages already containing a chat.
* Added a content generator that turns a topic into a draft post with a chosen tone, length, language and optional source notes.
* Converted generated markdown into paragraph, heading and list blocks, with headings escaped and bodies capped.
* Rebuilt the translation template, which had been left at the 1.1.0 string set; it now covers all 514 translatable strings with file references and translator comments.

= 1.6.0 =
* Added named chat profiles so one site can run several chats with their own model, prompt, presentation, guest access, grounding and limits.
* Added a Chat Profiles admin screen with per-profile shortcodes, and a profile attribute for the chat block and shortcode.
* Validated profile keys from untrusted input against the stored profiles, so an unknown key falls back to the main settings and cannot unlock guest access.
* Applied rate limiting per profile instead of one shared bucket.
* Allowed the Bedrock client to receive per-request model, token and temperature overrides while access flags stay server-side.

= 1.5.0 =
* Added an optional block editor assistant with improve, shorten, expand, summarize, title suggestion and translate actions; suggestions are never saved automatically.
* Added an optional conversation log with a 200-entry cap, configurable retention from 1 to 90 days, per-user deletion and one-click clearing.
* Added a Conversations admin screen showing stored exchanges, token totals and the oldest entry.
* Recorded editor assistant requests under their own source so they can be reviewed separately.
* Extended uninstall cleanup to conversation, OAuth and site ability options.

= 1.4.0 =
* Added an OAuth 2.1 authorization server so MCP clients can connect by signing in to WordPress and approving, with no token copying.
* Added authorization server and protected resource discovery documents at the standard well-known paths.
* Added dynamic client registration, mandatory PKCE S256, single-use authorization codes, and HTTPS or loopback redirect validation.
* Added bearer token authentication for the MCP endpoint, with hashed token storage, one-hour access tokens and rotating refresh tokens.
* Revoked a connection automatically when a rotated refresh token is reused.
* Added a connected clients list in the MCP settings with individual and bulk revocation.

= 1.3.0 =
* Rebuilt the built-in WordPress MCP server on JSON-RPC 2.0 over Streamable HTTP, so standard MCP clients can now connect; the previous custom routes could not be used by any MCP client.
* Added `initialize` with protocol-version negotiation, `tools/list`, `tools/call`, `ping`, batch requests and notification handling.
* Exposed the controlled site abilities through the MCP server: SEO suggestions, WooCommerce product lookup and draft-only post creation, each capability-checked.
* Recorded every MCP server tool call in the audit log with metadata only.
* Kept the legacy discovery and tool routes for existing integrations.

= 1.2.0 =
* Added streaming Bedrock responses over an authenticated POST request with Server-Sent Events, enabled by default.
* Added automatic fallback to a buffered request when streaming is unavailable, disabled, or interrupted.
* Added an AWS credential provider chain covering environment variables, ECS and EKS task roles, and EC2 instance roles (IMDSv2).
* Cached temporary role credentials encrypted, with refresh before expiry and invalidation when settings change.
* Fixed SigV4 canonicalization so model identifiers containing colons or slashes are signed correctly; affected requests previously failed with a signature mismatch.
* Replaced the fixed model list with live discovery of Bedrock models and cross-region inference profiles, and expanded the region list.
* Added a diagnostics screen and Site Health check covering credentials, region, model, streaming, encryption, guest access and MCP, with an optional live connectivity test.
* Added an Amazon Bedrock Chat block with server-side rendering alongside the existing shortcode.
* Added optional Amazon Bedrock Guardrails support and a site-wide daily request limit.
* Added request and token usage counters with 30-day retention; counters never include prompts or responses.
* Added MCP tool governance: a required capability, per-tool allow list, administrator-only handling of tools that change data, configurable multi-round tool loops, and a metadata-only audit log.
* Replaced the custom MCP request format with JSON-RPC over Streamable HTTP, so standard MCP servers and Amazon Bedrock AgentCore Gateway endpoints work; the previous format is still accepted as a fallback.
* Added MCP authentication choices: none, an encrypted bearer token, or AWS SigV4 for AgentCore Gateway.
* Added WordPress Abilities API support: this plugin registers text generation and status abilities, and abilities registered by other plugins can be offered to the chat model under the tool policy.
* Added answer grounding from published site content and from an Amazon Bedrock knowledge base, inserted as clearly labelled reference data.
* Added optional narrow site abilities: published content search and read, SEO suggestions, WooCommerce product lookup, and draft-only post creation.
* Redesigned the chat interface with message bubbles, a typing indicator, a streaming caret, tool status, token counts, dark-mode and reduced-motion support.
* Redesigned the admin experience with a status dashboard, quick-start checklist and usage overview.
* Shared one validated request builder between the streaming and buffered endpoints.
* Extended uninstall cleanup to usage counters, model caches, credential caches, tool policy and the audit log.

= 1.1.0 =
* Added encrypted AWS credential storage and `wp-config.php` credential constants.
* Added AWS session-token support for temporary credentials.
* Disabled guest chat by default and added configurable per-visitor rate limits.
* Removed duplicate and arbitrary-option AJAX handlers.
* Removed the duplicate-request EventSource implementation and conversation data in URLs.
* Moved MCP tool-result processing entirely to the server.
* Added HTTPS-only, SSRF-resistant MCP requests with response-size and redirect limits.
* Made WordPress MCP routes authenticated by default and restricted them to published, non-sensitive content.
* Removed unconditional prompt/tool logging and ensured debug logs contain metadata only.
* Fixed AI-response and admin-notice XSS paths.
* Removed PHP sessions and the unused chat-history table.
* Added strict settings, message, history, model, and REST input limits.
* Unified the `ai-chat-for-amazon-bedrock` text domain.
* Completed uninstall cleanup and added automated security regression checks.

= 1.0.7 =
* Added Model Context Protocol client, server, management, and tool integration.

= 1.0.6 =
* Improved escaping, internationalization, and direct-file-access protection.

= 1.0.5 =
* Added additional Bedrock models and chat customization.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

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

Conversation logging is disabled by default, and with it off no chat content is written to the database. When an administrator enables it, questions and answers are stored for the configured retention window, capped at the 200 most recent exchanges, and can be deleted per user or in full from the Conversations screen. Administrators are responsible for disclosing this recording to visitors.

The plugin creates no custom database tables; the optional log is kept in a WordPress option and is reachable through Tools > Export Personal Data and Erase Personal Data. Request limiting stores a salted hash-derived transient counter for each visitor for up to one minute. Debug logging is optional and records only redacted operational metadata. Administrators are responsible for disclosing these data flows and obtaining any consent required in their jurisdiction.
