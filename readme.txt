=== AI Chatbot & Agents for Amazon Bedrock ===
Contributors: glay, glayguo
Tags: ai, chatbot, ai-agent, mcp, connector
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 1.66.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot with cited answers from your site and WooCommerce store, AI agents, an MCP server and the WordPress AI provider for Amazon Bedrock.

== Description ==

Connect WordPress directly to **Amazon Bedrock** using your own AWS account. Add a chat powered by
Claude, Amazon Nova, Meta Llama, Mistral, DeepSeek, OpenAI gpt-oss, Qwen or Kimi, let authenticated conversations use
governed tools through the Model Context Protocol, and check that the answers are still right after
you change something.

Model requests go from your WordPress server to the Amazon Bedrock endpoint you configure. The
plugin author does not operate an AI relay service, and no request passes through anyone else. It is
built for site owners, developers and teams already on AWS who want predictable requests and
security-focused defaults.

= The Amazon Bedrock provider for WordPress AI =

On WordPress 7.0 and later, every plugin that calls the WordPress AI Client, including the
official AI plugin, can use Amazon Bedrock without knowing anything about AWS. The plugin offers
core what Bedrock can actually do, model by model:

* **Text** from Claude, Nova and the other chat models, with system instructions, several
  candidates, top P and stop sequences where the model accepts them
* **Images in the prompt** for the models that read them, such as Claude, Nova Lite and Pro,
  Llama 4 and Qwen3 VL, so alt text and image descriptions work
* **JSON answers**, checked before they are returned and constrained by the schema on the models
  that take one
* **Image generation** with Stability AI Stable Image Core, Stable Diffusion 3.5 Large and Stable
  Image Ultra, including editing an image with Stable Diffusion 3.5
* **Embeddings** with Amazon Titan and Cohere on WordPress 7.2, where core adds them

Each request takes the same path as the chat, so the guardrail, daily limit, token ceiling and
usage dashboard apply to all of them. A capability a model lacks is not offered, so core picks a
model that has it rather than one that fails.

= Images in the Media Library =

Choose an image model and turn on the media helpers, and every JPEG, PNG or WebP image gets
**Remove background** and, up to about one megapixel, **Upscale 4×**. The result is saved as a new
image next to the original, which is never changed.

= What you can build =

* An internal assistant, or a public chatbot with explicit guest access
* An agent that answers from your own content and from approved MCP tools
* A WordPress MCP endpoint that clients such as Claude Code, Cursor or VS Code can read
* Answers for a WeChat Official Account, from the same pages, and mini game customer service
* A record of where each post is published on Bilibili, YouTube or Xiaohongshu that your agents
  keep, Bilibili embeds, and uploads to YouTube

Add the chat with the `[ai_chat_bedrock]` shortcode or the chat block, or let it float on every page.

= Three things this does differently =

**It runs without storing AWS keys.** An instance role, a task role or environment variables are
enough. Off AWS, one Bedrock API key is enough. Where keys are stored, they are encrypted, and Diagnostics generates the least-privilege
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
visitor can read is ever indexed or quoted, so members-only sections stay out of answers, and pages
your SEO plugin keeps out of search engines stay out too. Add a
reranking model and the passages that best answer the question are kept, from your pages and a
knowledge base alike. Questions
the site does not cover return no context, and the Conversations screen lists them as content gaps
with a shortcut to draft the page that is missing.

= For WooCommerce stores =

With WooCommerce active, a WooCommerce tab adds three features, each off until you turn it on:

* **Product answers.** A question about products is answered from the live catalog, with the
  price, sale price, stock and options the shop shows right now, and the products appear as cards
  under the answer with View product and, where nothing has to be chosen, Add to cart. On a
  product page, "is this in stock?" is about that product. Drafts, private and password-protected
  products, products hidden from the catalog, and out-of-stock products on a store that hides them
  are never described.
* **Order questions.** A signed-in customer can ask where their order is. Only their own recent
  orders are read, and only the order number, dates, status, items, total, shipping method and
  tracking number are sent to the model; addresses, email, phone and payment details never are.
  Naming another customer's order number reveals nothing.
* **Product assistant.** On the product edit screen, draft the short and the full description from
  the product's real name, attributes and categories, or summarize its approved reviews without
  reviewer names. The draft is shown for review, and nothing is saved until you update the product.

The plugin declares compatibility with High-Performance Order Storage and the Cart and Checkout
blocks, and adds suggested text for the site's privacy policy under Settings > Privacy.

= An MCP server, and an MCP client =

The site can expose its own read-only tools to AI clients over JSON-RPC on protocol revision
2026-07-28, with anonymous access and OAuth both off by default. It can also call external MCP
servers and an Amazon Bedrock AgentCore Gateway, with no authentication, a bearer token stored
encrypted, or SigV4. Endpoints must be public HTTPS URLs.

= Stored data and privacy =

No custom table is created. The conversation log is optional and off by default; when enabled it
holds the 200 most recent exchanges in a WordPress option, with a retention window you set, and it
supports the WordPress personal-data export and erase tools. Conversation memory is off by default
too: it can keep a conversation in the visitor's browser tab only, or also save a signed-in visitor's
recent conversation in their user data for the days you set. Debug mode records redacted metadata,
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

The dashboard also counts chat requests that reached Amazon Bedrock and still failed after any
fallback model, today and for the last seven days, grouped as throttled, access denied, rejected,
unavailable, network or other. These are counters only: no message, error text or identity is kept.

The usage panel also shows how long streamed answers take to start.

= The rest =

Streaming, reading answers and posts aloud with Amazon Polly, managed prompts from Bedrock Prompt Management, a fallback model, multiple chats with
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
2. Paste it into **AI Chat Bedrock > Settings > AWS authentication > Amazon Bedrock API key** and save. It is stored encrypted and never shown again. On WordPress 7.1 and later it can be pasted under **Settings > Connectors > Amazon Bedrock** instead, where it is checked with Bedrock before it is kept.
3. Run **Diagnostics**. It sends one short question to the model and reports the answer.

To keep the key out of the database, define it in `wp-config.php` instead:

`define( 'AI_CHAT_BEDROCK_API_KEY', 'replace-with-bedrock-api-key' );`

The plugin also reads `AWS_BEARER_TOKEN_BEDROCK`, the variable the AWS SDKs use. An API key covers chat, streaming, the model list and embeddings. Knowledge Bases, reranking, Prompt Management and AgentCore Gateway do not accept API keys, so they still need an IAM role or access keys, and Diagnostics says so when one of them is configured. Short-term keys expire after at most 12 hours, which suits a test but not a live site.

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

= Where do I get help? =

Run **AI Chat Bedrock > Diagnostics** first: most problems are a missing IAM permission or model access, and it names which. Then ask in the [support forum](https://wordpress.org/support/plugin/ai-chat-for-amazon-bedrock/), without posting keys. Longer answers are in the [detailed FAQ](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md).

= Do I need an OpenAI API key? =

No. Model requests use Amazon Bedrock and your AWS credentials. Availability, model access, pricing, and data handling are governed by your AWS account and Region.

= Can I try it without an AWS account? =

Yes, in the Live Preview on the plugin's WordPress.org page, which runs WordPress in your browser with demo mode on. The chat then quotes the passage of the site's pages that best matches each question, and says that no AI model was called. Demo mode is off unless `AI_CHAT_BEDROCK_DEMO` is defined, and ends once AWS credentials are found.

= Which Bedrock models are supported? =

Claude, Amazon Nova and Titan, Meta Llama, Mistral, DeepSeek and the other chat models your Region offers, including Claude Sonnet 5, Claude Opus 5.5 and Claude Haiku 4.5; Stability AI models for images; Cohere Rerank 3.5 and Amazon Rerank 1.0 for reranking. The settings screen lists what your account offers, and a new installation starts on Amazon Nova Lite. [Regions, inference profiles and image models](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#which-bedrock-models-are-supported).

= What is an Amazon Bedrock API key, and should I use one? =

It is a single credential created in the Amazon Bedrock console and sent as a bearer token, so there is no IAM user or access key pair to manage. It is the quickest way to get a first answer, especially on hosting outside AWS. On an EC2 instance, ECS or EKS an IAM role is still the better choice, because nothing long-lived is stored at all. If a key stops working, check that it has not expired or been revoked and that its identity is allowed `bedrock:CallWithBearerToken`; the IAM policy that Diagnostics generates includes it when a key is configured.

= Does the plugin use prompt caching? =

Yes, on Claude 3.5 Haiku, Claude 3.7 Sonnet and newer Claude models, which Bedrock supports it for. The site's system prompt and tool definitions are the same for every visitor, so they are marked for Bedrock's prompt cache; a later request that starts the same way reads them at a fraction of the input price. Writing to the cache costs slightly more than a normal input token, and a prompt shorter than the model's minimum is simply not cached, so a site with a short prompt pays what it paid before. The dashboard and `wp ai-chat-bedrock usage` show cache reads and writes. The `ai_chat_bedrock_prompt_caching` filter turns it off.

= What does "Time to first text" on the dashboard mean? =

The "Time to first text (streamed, 7 days)" line under the usage panel shows how long visitors waited for a streamed answer to start: the number of answers measured, the average in milliseconds and the median band (under 1 s, 1 to 2 s, 2 to 5 s, 5 to 10 s, or 10 s and over). It covers streamed answers only. It is measured on the server, from the moment the plugin starts the Bedrock request to the moment the first text arrives, so it does not include page load or network time in the visitor's browser. When the primary model sends no text and the fallback model answers, the wait is counted from the first attempt. Requests that fail before any text, setup errors and empty answers are not measured.

"Unknown, no streamed answers recorded yet" means no streamed answer has been measured in the last seven days, for example on a new installation, right after an upgrade or reset, or when streaming is off. It never reports 0 ms for an empty period. Only a count, a sum of milliseconds and five band counters are kept for each day, with no prompt, answer, visitor or request ID. They follow the same 30-day retention as the other usage counters and are cleared when usage is reset.

From a terminal or a scheduled report, `wp ai-chat-bedrock usage` prints the same figure as its last line, for example `Time to first text (streamed, 7 days): 12 answers, average 1840 ms, median 1 to 2 s.` It covers the days asked for, up to the 30 that are kept, reads `average unknown` or `median unknown` when a stored figure is missing or invalid, and exits zero when nothing was measured. `--by-model` leaves the line out, because latency is not recorded per model.

= Why do I receive AccessDeniedException or a model access error? =

Amazon Bedrock no longer has a Model access page: a model is turned on for the AWS account the first time it is called. That first call fails when the identity may not subscribe through AWS Marketplace, when the account has no payment method, or, for Anthropic models, before the one-time use case form is submitted. Opening the model once in the Bedrock console playground as an administrator settles all three, and the chat's error names which one it was. Otherwise, check the model ID, whether it needs an inference profile ID, and that the identity can call `bedrock:InvokeModel` on it.

= Does the chat work on a multilingual site? =

Yes, with Polylang or WPML. The chat title, welcome message and suggested questions are listed for translation under "AI Chat for Amazon Bedrock" in Languages > Translations (Polylang) or String Translation (WPML), including those of each profile, and each edition of the site shows its own. Answers are asked for in the language of the page, and with Polylang are drawn from pages in that language first. Keep the system prompt in one language; it is not translated. The chat's own buttons and notices, and the plugin's admin screens, come in Simplified Chinese and Japanese with the plugin, and in other languages once translate.wordpress.org has them.

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

Each message is one authenticated POST to a plugin REST route, relayed to the browser with Server-Sent Events, with a buffered fallback when PHP cURL is unavailable. Credentials come from an API key, `wp-config.php` constants, encrypted settings, environment variables, an ECS or EKS task role, or an EC2 instance role, in that order; Diagnostics shows which one is used. [The full order and caching](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#how-does-streaming-work-and-where-do-credentials-come-from).

= What can the agent do with tools, and what stops it? =

An authenticated conversation can call tools from an MCP server an administrator configured. A capability is required (`edit_posts` by default), tools that change data stay blocked until allowed, rounds are capped, and every call is audited without its values. [How the policy works](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#what-can-the-agent-do-with-tools-and-what-stops-it).

= Can I keep the system prompt in AWS instead of in WordPress? =

Yes. Point the chat at a prompt in Bedrock Prompt Management, pinned to a version or following the draft; the local prompt is used if it cannot be read. [Placeholders and caching](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-i-keep-the-system-prompt-in-aws-instead-of-in-wordpress).

= How does semantic search differ from keyword search? =

Choose an embedding model and questions are matched by meaning, not shared words, over published content as a signed-out visitor sees it. Vectors live in the WordPress database or, for a larger site, in Amazon S3 Vectors in your AWS account. [Indexing, S3 Vectors, filters and languages](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#how-does-semantic-search-differ-from-keyword-search).

= What does reranking do? =

A reranking model scores up to eight passages from site content and the knowledge base against the question and keeps only the best. It is one extra request per question, shown apart on the dashboard, and the passages are used as before if it fails. [Thresholds and IAM](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#what-does-reranking-do).

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

= Can the chat remember a conversation? =

Yes, under Chat > Conversation memory, which is off by default so every page starts a new conversation. **Keep it while the visitor browses** stores the conversation in the browser tab's session storage: it follows the visitor from page to page and is gone when the tab closes, and nothing is stored on the site. **Also save it for signed-in visitors** keeps a signed-in visitor's last 30 messages per chat on the site, so the conversation is there on their next visit and on another device. Saved messages are deleted after the days you set (30 by default), when the visitor clears the chat, through Tools > Erase Personal Data, and all at once when you switch the option off. Guests only ever get the browser-tab memory. Add the suggested text from Settings > Privacy to your privacy policy before turning saving on.

= Can answers and posts be read aloud? =

Yes, with Amazon Polly, off by default: a Listen button under chat answers and one above posts, in a voice for the page's language. Post audio is saved once and reused. New audio has a daily limit for the site and for each visitor, crawlers cannot have posts read, and posts can be kept for signed-in visitors. [Voices, costs and filters](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-answers-and-posts-be-read-aloud).

= Can a visitor ask for a person? =

Yes, under Chat > Contact requests, off by default. A Contact a person button below the chat opens a short form, and the assistant points to it when it cannot help. Requests need consent and are listed under Contact requests; Akismet, Flamingo and Joinchat are used when present. [Spam checks, email and hooks](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-a-visitor-ask-for-a-person).

= Can I see what the chat does in Google Analytics? =

Yes, under Chat > Analytics events, off by default. Opens, questions, answers, followed sources and products, ratings and contact requests go to Site Kit, MonsterInsights, Google Tag Manager, Matomo or Plausible, without message text, and wait for statistics consent where the WP Consent API is used. [Events and parameters](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-i-see-what-the-chat-does-in-google-analytics).

= Can it answer my WeChat Official Account? =

Yes, under Channels > WeChat Official Account, off by default. Followers' text messages are answered from your pages in plain text with sources, in plaintext or safe mode, within the time WeChat waits, and a new follower gets the welcome message. It works with unverified personal subscription accounts and needs no AppSecret. [Setup and limits](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-it-answer-my-wechat-official-account).

= Can it post to my WeChat Official Account? =

It makes drafts, under Settings > Channels, off by default: featured posts become articles in the account's draft box, from the editor, by an agent or on a schedule, and you publish them in the Official Accounts Platform, since WeChat lets only verified company accounts publish through its API. Followers who write 菜单 get your menu text instead of a menu. [How it works](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-it-post-to-my-wechat-official-account).

= Can it handle my WeChat mini game's customer service? =

Yes, under Channels > WeChat mini game, off by default. Players who open the game's customer service chat are welcomed and get your set answers by keyword, without AI, and the site counts players, chats, scenes and subscription choices a day without keeping their IDs. [Setup and limits](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-it-handle-my-wechat-mini-games-customer-service).

= Can it publish to Bilibili, YouTube or Xiaohongshu? =

It keeps the record, under Settings > Channels, off by default: your agents publish with the platforms' creator tools and record each item through abilities and MCP, since Bilibili and Xiaohongshu offer individual creators no publishing API. YouTube uploads go through its API once a channel is connected. [The record, embeds and uploads](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#can-it-publish-to-bilibili-youtube-or-xiaohongshu).

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

On WordPress 7.0 and later, Bedrock is registered with core's AI Client, so `wp_ai_client_prompt()` reaches it from any plugin that knows nothing about AWS. Those calls use this plugin's request path, so the guardrail, model, region, token ceiling, daily limit and usage accounting configured here apply to them.

On 7.1 and later, Amazon Bedrock also appears under Settings > Connectors. On AWS it shows as connected with nothing entered, because the IAM role is used. Elsewhere, paste an Amazon Bedrock API key there: it is checked with Bedrock before it is kept, and stored encrypted. A key in the plugin settings or `wp-config.php` takes precedence.

Core asks for a model by what it must do, and the plugin describes each Bedrock model truthfully: image input only on models that read images, image generation only on the Stability models, and embeddings only on WordPress 7.2 and later. [More on model capabilities](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#does-it-work-with-the-ai-features-in-wordpress-core).

= Can it generate or edit images? =

Yes, once you choose an image model on the Model tab. Plugins that use the WordPress AI Client can
then generate images, and with the media helpers on, the Media Library offers Remove background and
Upscale 4× on each image. Edits are saved as new images. A prompt is checked with your guardrail
first, because Bedrock Guardrails headers do not apply to image models, and an image the model
filtered is reported rather than saved. Each image is a billed request to Stability AI on Amazon
Bedrock, counted against the daily limit. The IAM policy in Diagnostics includes the image models
you turned on.

= How do I connect Claude Code, Cursor or another AI client to this site? =

Point the client at `https://example.com/wp-json/ai-chat-bedrock/v1/mcp` and sign in with a WordPress Application Password over HTTPS, or turn on OAuth 2.1 so the client connects by signing in and approving, with no password copied. Every tool call is capability-checked and audited; anonymous access and OAuth are off by default. [Protocol revisions, tools and OAuth details](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#how-do-i-connect-claude-code-cursor-or-another-ai-client-to-this-site).

= How do I connect an external MCP server or an AgentCore Gateway? =

External servers are called with JSON-RPC over Streamable HTTP with a declared protocol version. Three authentication modes are available:

* None, for a public endpoint.
* Bearer token, stored encrypted and never displayed again.
* AWS SigV4, which signs each request with the same AWS credentials already used for Bedrock. An AgentCore Gateway endpoint therefore needs no extra secret; the signing service defaults to `bedrock-agentcore` and the region falls back to the Bedrock region.

Endpoints must be public HTTPS URLs. Private, loopback, link-local and credential-bearing URLs are rejected, redirects are disabled and response size is capped. Discovered tools remain subject to the tool policy above.

= What does the WooCommerce integration send to Amazon Bedrock? =

With Product answers on, the facts of up to four matching products (eight at most, set under WooCommerce) are sent with the question: name, link, SKU, price, stock, rating, categories, visible attributes, options and a short description, exactly as the shop shows them to any visitor. With Order questions on, a signed-in customer's question about orders or delivery adds their five most recent orders, plus any they name by number that are theirs: order number, dates, status, items, total, shipping method, tracking number and the link to the order page. Billing and shipping addresses, email, phone, payment details and customer notes are never read into the prompt. A question that is not about orders sends no order data, and a visitor who is not signed in is asked to sign in. Add the suggested text from Settings > Privacy to your privacy policy before turning Order questions on. The `ai_chat_bedrock_woocommerce_products`, `ai_chat_bedrock_woocommerce_product_facts`, `ai_chat_bedrock_woocommerce_orders` and `ai_chat_bedrock_woocommerce_order_lines` filters adjust what is sent.

= Why does the chat not show a product I know is in the shop? =

Only products any visitor can see are described: published, without a password, visible in the catalog or in search, and in stock when WooCommerce is set to hide out-of-stock items. A SKU in the question, such as "is ARM-6 in stock?", finds that product directly. Otherwise products are found by name and description, then by category, and a follow-up such as "how much is it?" searches with the previous question. The `ai_chat_bedrock_woocommerce_listable` filter can leave out more products.

= What can an agent read from and write to my site? =

With site abilities on (off by default), agents can search and read published posts and pages, get SEO suggestions without saving, look up published products and create a draft. Nothing is published, updated or deleted, and orders and customers are never exposed. The abilities are in WordPress's own registry, so the official MCP adapter and `/wp-abilities/v1/` see them too. [How the declared behaviour is enforced](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#what-can-an-agent-read-from-and-write-to-my-site).

= What does the site description tell an agent? =

Turn on Site description and agents get a describe-site ability listing what the site holds as schema.org types, with counts per language, how the types relate and a sensitivity class for each property. IDs match the ones Yoast SEO and WooCommerce print in the page. [Sensitivity rules and single-item descriptions](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md#what-does-the-site-description-tell-an-agent).

= What do business insights show, and to whom? =

Turn on Business insights under Answer grounding. Editors see content figures, administrators also see AI usage and question figures, and store figures need the WooCommerce reports capability. Store figures come from WooCommerce Analytics with its status and date settings. Any figure counted from fewer than five orders or questions is withheld, together with one more where it could be worked out from the total. Agents get the same figures through the query-metrics ability and MCP tool, except those about visitors' questions.

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

= 1.66.0 =
* Publishing record, under Settings > Channels and off by default: where each post is published on Bilibili, YouTube or Xiaohongshu, with the item's ID, address, account, language, status and the edition it replaces. Agents read a post's publishing package and record what they published through abilities and the MCP server, with their account's permissions; the record shows when editing a post and, optionally, as links under it.
* Bilibili videos embed from their links, as YouTube's do, when turned on.
* Uploads to YouTube through its API, after connecting a channel with Google's sign-in: resumable, in the background, added to the record and followed until YouTube says whether the video is public. Uploads default to private and are limited per day.
* WeChat mini game customer service, under Chat and off by default: the game's message push comes to its own address, in JSON or XML, plaintext or safe mode. Players who open the chat are welcomed, and questions get the site owner's set answers, matched by keyword, through WeChat's customer service API; nothing is generated, as an AI answer in a mini game needs an AI category and an algorithm filing. Daily totals show players, chats opened by scene, messages, how many questions matched an answer and how players answered subscription prompts, without keeping any OpenID.
* WeChat Official Account drafts, under Settings > Channels and off by default: featured posts become articles in the account's draft box, with cover, text, images and a Read more link to the post, sent from the editor, by an agent or on a daily or weekly schedule that emails the site. Only verified company accounts may publish through WeChat's API, so the owner publishes them. Followers who write 菜单 get the site's menu, and 精选 the newest featured posts, without the model. Drafts are formatted for WeChat, keep titles and digests whole, update in place when a post is sent again, and, with the option on, follow changes to their posts on schedule unless edited in WeChat. A lesson's video can be uploaded to the material library as a copy of at most 10 MB and is inserted in WeChat's editor.
* Settings grouped by task: a Channels tab holds the WeChat Official Account, its drafts and the mini game beside the video platforms; Agents and tools has its own tab; guest access, rate limits, the conversation log and debug logging are under Safety and spend. The menu lists daily work first, starting with Overview. Old tab links still work.

= 1.65.0 =
* WeChat Official Account, under Chat and off by default: followers' text messages are answered from the site's pages in plain text with sources and a note that AI wrote them, in plaintext, compatible or safe mode, optionally by a faster model than the site's. WeChat's retries give an answer about fifteen seconds; a slower one is kept until the follower sends 1. Works with unverified personal subscription accounts, needs no AppSecret, and limits each follower per hour.
* Faster on hosts outside AWS before credentials are entered: when no IAM role answers, the plugin no longer waits for the instance metadata service to time out on every page, admin screen and cron run. It asks again after five minutes, when the settings are saved or when Diagnostics runs its checks.
* Ready for PHP 8.5: streamed answers no longer call a function PHP 8.5 deprecates, which could write a notice into the reply, and the conversation log export no longer relies on a CSV default PHP 8.4 deprecates. The release checks now run on PHP 7.4, 8.3 and 8.5 and fail on any PHP notice.
* The dashboard shows how long visitors wait for the first text of a streamed answer: the number of answers, the average and the median range over the last 7 days, or unknown before any streamed answer. The time is taken on the server, from the start of the Bedrock request to the first text, and is recorded after that text has been sent. Only counters are kept, for 30 days, with no message text.
* `wp ai-chat-bedrock usage` ends with streamed time to first text: count, average ms and median band, up to 30 days, or unknown, never 0 ms. Output is otherwise unchanged.

== Upgrade Notice ==

= 1.66.0 =
Optional record of where posts are published on Bilibili, YouTube and Xiaohongshu, kept by your agents, Bilibili embeds, uploads to YouTube, and customer service with daily counts for a WeChat mini game.

= 1.65.0 =
Optional answers for a WeChat Official Account, streamed time to first text on the dashboard and in WP-CLI, faster pages on hosts outside AWS before credentials are set, and PHP 8.5 support.

= 1.64.0 =
Pages your SEO plugin marks noindex are no longer used for answers; Answer grounding can include them again. Optional contact requests and analytics events, WP Consent API support, and fairer read-aloud limits.

= 1.63.0 =
Amazon Bedrock appears under Settings > Connectors: connected through the IAM role, or with an API key that is checked and stored encrypted. Renamed AI Chatbot & Agents for Amazon Bedrock.

= 1.62.0 =
Optional business insights: content, chat, AI usage and WooCommerce figures over a period, asked in words or queried by agents, with small counts withheld. Off until enabled.

= 1.61.0 =
An optional site description for agents: schema.org types, counts per language, relations and data-sensitivity rules, through an ability and the MCP server. Off until enabled.

= 1.60.0 =
Optional reading aloud of chat answers and posts with Amazon Polly. It is off until enabled, and then needs polly:SynthesizeSpeech for the AWS identity.

= 1.59.0 =
Optional conversation memory, in the browser tab or saved for signed-in visitors, and a fix for long conversations in Chinese and Japanese. Memory is off until enabled.

= 1.58.0 =
Optional reranking of retrieved passages with Cohere or Amazon rerank models, and S3 Vectors filtering before the search. Reranking is off until enabled.

= 1.57.0 =
A full WordPress AI Client provider: image input, JSON, Stability image generation and embeddings. Media Library background removal and upscaling. All new features are off until enabled.

= 1.56.1 =
WooCommerce order questions send a customer's orders only when the question is clearly about an order.

= 1.56.0 =
WooCommerce: product answers with live prices and stock, order questions for signed-in customers, and a product description assistant. All off until enabled.

= 1.55.1 =
Chinese and Japanese list separators in the admin.

= 1.55.0 =
The whole admin, not only the chat, in Chinese and Japanese.

= 1.54.0 =
The popup's Send button is no longer hidden by themes like Blocksy. New Color scheme setting; the chat is now light unless set to follow the device. Indexing no longer uses up the daily request limit.

= 1.53.0 =
The dashboard counts failed Bedrock chat requests by cause, today and over seven days. Counters only; no messages are stored.

= 1.52.1 =
The token count under each answer in Chinese and Japanese.

= 1.52.0 =
The chat's buttons and notices in Chinese and Japanese.

= 1.51.1 =
With Polylang, the chat's strings are listed once for translation instead of twice.

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

When semantic search is on, the text a signed-out visitor can read on each published post is sent to Amazon Bedrock to create embeddings, and each question is embedded the same way. With a reranking model chosen, the question and the passages found for it are also sent to that model on Amazon Bedrock. With Amazon S3 Vectors chosen, those passages and their vectors are stored in the vector bucket of your own AWS account, labelled with the site and post they came from. Uninstalling the plugin does not delete them; delete the index in AWS.

Conversation logging is disabled by default, and with it off no chat content is written to the database. When an administrator enables it, questions and answers are stored for the configured retention window, capped at the 200 most recent exchanges, and can be deleted per user or in full from the Conversations screen. Administrators are responsible for disclosing this recording to visitors.

Conversation memory is off by default. In browser-tab mode the conversation is kept in the visitor's own session storage and nothing is stored on the site. When saving for signed-in visitors is on, their questions, answers and the links listed under each answer are stored in their user data on this site, up to 30 messages per chat, for the configured number of days. They are deleted when the visitor clears the chat or the option is switched off, and are reachable through Tools > Export Personal Data and Erase Personal Data.

Reading aloud is off by default. With Listen under answers on, the text of an answer is sent to Amazon Polly when the visitor presses Listen, and the audio is not kept. With Listen to this post on, the text a signed-out visitor can read on the post is sent to Amazon Polly the first time someone listens, and the audio is kept in the uploads folder until the post changes or the option is switched off. Nothing about the visitor is sent.

Contact requests are off by default. When a visitor sends one, the name, email address, phone number and message they enter, the page and, if they choose, the conversation are stored on this site for the days set (180 by default), with their consent. With Akismet set up, the request, IP address and browser are sent to Akismet to check for spam. Requests are reachable through Tools > Export Personal Data and Erase Personal Data, and uninstalling deletes them.

Business insights are off by default. They are worked out on the site from content, the conversation log, usage counters and WooCommerce Analytics, and only totals are shown; those from fewer than five orders or questions are withheld. Asking in words sends the question and the list of figures to Amazon Bedrock, never a figure.

The plugin creates no custom database tables; the optional log is kept in a WordPress option and is reachable through Tools > Export Personal Data and Erase Personal Data. Request limiting stores a salted hash-derived transient counter for each visitor for up to one minute, and reading aloud a count of the characters read for that visitor for a day. Debug logging is optional and records only redacted operational metadata. Administrators are responsible for disclosing these data flows and obtaining any consent required in their jurisdiction.

On a WooCommerce store with Product answers on, the public details of matching products are sent to Amazon Bedrock with each question. With Order questions on, a signed-in customer's question about orders sends their recent orders' number, dates, status, items, total, shipping method and tracking number to Amazon Bedrock; addresses, email, phone and payment details are not sent. The product assistant sends the product's own details, or its approved review texts without reviewer names, when an editor asks for a draft.

With WeChat answers on, followers' text messages, passed on by WeChat (Tencent), go to Amazon Bedrock with up to three earlier exchanges, kept 30 minutes under a hash of the OpenID. Embedded Bilibili players load from Bilibili, which receives the visitor's IP address and can set cookies. YouTube uploads, off until a channel is connected with its owner's consent, send the chosen video file, title, description and tags to the YouTube Data API, under the [Google Privacy Policy](https://policies.google.com/privacy) and the [YouTube Terms of Service](https://www.youtube.com/t/terms). WeChat mini game messages arrive through WeChat and set answers return through its API, with only daily totals kept; WeChat drafts send chosen posts' public text and images to WeChat.

When an image model is chosen, image prompts, and any image supplied for editing, are sent to Stability AI models on Amazon Bedrock in US West (Oregon) unless the site moves them to another region. The prompt is first checked with the site's guardrail in its own region. With the media helpers on, Remove background and Upscale send the selected image when an editor asks. Requests from other plugins through the WordPress AI Client send what those plugins put in the prompt, including any images.
