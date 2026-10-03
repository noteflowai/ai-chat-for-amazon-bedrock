# AI Chatbot & Agents for Amazon Bedrock: details

The longer answers behind the FAQ on WordPress.org.

## How does streaming work, and where do credentials come from?

Streaming is on by default. Each message sends one authenticated POST request to a plugin REST route, and Bedrock response events are relayed to the browser with Server-Sent Events. Conversation content never appears in a URL, and one visitor message still results in exactly one Bedrock invocation. Streaming needs the PHP cURL extension; when it is unavailable, disabled or interrupted, the chat falls back to a single buffered request so answers are still delivered.

An Amazon Bedrock API key, when one is configured, is used for Bedrock and Bedrock Runtime requests. Signing credentials are resolved in this order: `wp-config.php` constants, encrypted WordPress settings, environment variables, an ECS or EKS task role, then an EC2 instance role using IMDSv2. The last three let a site on AWS run with no long-lived keys in WordPress at all. Role credentials are cached encrypted and refreshed before expiry, role lookups can be disabled with the `ai_chat_bedrock_use_role_credentials` filter, and the active source is shown in the settings without revealing secrets.

## What can the agent do with tools, and what stops it?

An authenticated conversation can call tools from an administrator-configured MCP server. Tool calls execute on the WordPress server and their results go back to Bedrock for the final answer, always framed as untrusted data.

Tool use is governed by a policy layer:

* A capability is required to use tools at all, defaulting to `edit_posts`.
* Tools that appear to change data are blocked until an administrator allows them, and stay administrator-only.
* Up to five calls run per round, rounds are configurable from 1 to 5, and the final round answers without tools so a conversation always terminates.
* Every call is audited with the tool, round, outcome, duration and parameter key names. Values, output and chat content are never stored.

Abilities that other plugins register in the WordPress Abilities API are offered to the model as tools too. Up to twenty are offered per question, ranked by the words they share with it (pairs of characters for Chinese, Japanese and Korean), with plugins taking turns between equal matches, so a plugin that registers many abilities cannot crowd out the rest. Change the number, from 1 to 50, with the `ai_chat_bedrock_ability_tool_limit` filter. MCP > Tool policy lists them by plugin: switch a plugin off as a whole, refuse a read-only ability, or allow one that changes data, which is then offered to administrators only. Saving the form changes only the tools it shows.

While the agent works, the chat names the tools it is running. The finished answer carries a collapsible list of every call, its round and whether it succeeded, showing metadata only. If the round limit is reached, the answer says so instead of quietly stopping.

## Can I keep the system prompt in AWS instead of in WordPress?

Point the chat at a prompt in Bedrock Prompt Management and its text replaces the local system prompt, so one prompt can be reviewed and versioned in AWS and reused by every site. Pin a version for stability or follow the draft to pick up edits. `{{site_name}}`, `{{site_description}}`, `{{site_url}}` and `{{current_date}}` are filled in; anything else is sent exactly as written. The prompt must live in the same region as the chat, the text is cached briefly, and if it cannot be read the local system prompt is used instead rather than sending an empty one.

## How does semantic search differ from keyword search?

Keyword search only finds passages sharing words with the question, so "when will my parcel arrive" misses "Getting parcels to you". Choose an embedding model and the plugin indexes published content, then matches questions by meaning. Indexing runs in small batches from the settings screen, unattended through WP-Cron, or with `wp ai-chat-bedrock index`. Editing a post marks it for re-indexing, and keyword search runs when nothing relevant is found. Questions the site does not cover return no context.

By default one vector per post is kept in the WordPress database and a question is compared with the 500 most recent items. For a larger site, choose Amazon S3 Vectors under Answer grounding: every post is split into overlapping passages, each passage gets its own vector in a vector bucket in your AWS account, and every one of them is searched. Create the bucket in the Amazon S3 console, then check or create the index from the settings screen or with `wp ai-chat-bedrock index --create-index`. S3 Vectors needs an IAM role or access keys, not a Bedrock API key; Diagnostics lists the `s3vectors` actions to allow. Several sites can share one index, and each only reads and deletes its own vectors. Each query asks S3 Vectors to filter by site, language and post type before the similarity search, so a selective filter still returns a full set of matches. Return `CLASSIC` from the `ai_chat_bedrock_s3_vectors_query_mode` filter to filter during the search instead.

Only published, public content is indexed, as a signed-out visitor sees it: sections that a membership or visibility plugin hides from guests are left out, and every result is checked against the live post again before it is quoted. Blocks with Block Visibility rules are left out whoever they are shown to, since that plugin applies its rules only on front-end pages; to leave out blocks that another plugin restricts, return true from the `ai_chat_bedrock_block_is_restricted` filter. With Polylang, a question is answered from pages in the visitor's language first. With Polylang or WPML, the model is also asked to reply in the language of the page; change or remove that instruction with the `ai_chat_bedrock_language_instruction` filter.

## What does reranking do?

Without it, the closest few passages from site content and from the knowledge base are each passed to the model. Choose a reranking model under Answer grounding and up to eight passages from each are gathered, then the reranking model scores them all against the question and only the best are kept, up to the number of passages set. It is one extra request per question, billed per query rather than per token, and shown apart from chat requests on the dashboard. If reranking fails the passages are used as before, and a refused request pauses it for an hour. The IAM policy in Diagnostics adds `bedrock:Rerank`. To drop passages that score below a threshold, return it, between 0 and 1, from the `ai_chat_bedrock_rerank_floor` filter; the best passage is always kept.

## Can answers and posts be read aloud?

Yes, under Chat > Read aloud, which is off by default. **Add a Listen button to chat answers** puts Listen next to Copy under each answer, and **Add a Listen to this post button to posts** puts one above the text of each post. Amazon Polly reads in a voice for the language, chosen from the page or from the answer itself: Mandarin for Chinese, Japanese, Korean, the main European languages and more. The chat only reads answers it gave to that visitor, so the site cannot be used as a free text-to-speech service. A post is read as a signed-out visitor sees it, so member-only sections are never read; each part is saved in the uploads folder the first time it is played, so later listeners cost nothing, and changing the post makes new audio. Polly is priced per character, and the generative voices cost about twice as much as the neural ones. The dashboard counts the characters read, and reading stops for the day at the limit you set (100,000 by default). The AWS identity needs `polly:SynthesizeSpeech`, which the IAM policy in Diagnostics includes. Change the voice with the `ai_chat_bedrock_speech_voice` filter, the post types with `ai_chat_bedrock_speech_post_types`, the Region with `ai_chat_bedrock_speech_region` and the length read with `ai_chat_bedrock_speech_max_chars`. Add the suggested text from Settings > Privacy to your privacy policy before turning it on.

## Can a visitor ask for a person?

Yes, under Chat > Contact requests, which is off by default. A **Contact a person** button appears below the chat, after a thumbs-down the chat offers it, and the assistant is told to point to it when it cannot answer instead of asking for contact details itself. The form asks for a name, an email address or a phone number and a message, prefilled with the last question; a signed-in visitor can leave the email empty and the account's address is used, read on the server so it never appears in a cached page. Including the conversation is the visitor's choice, and nothing is stored until they agree.

Requests are kept on this site as a private post type with no screens of its own, no REST route and no place in the WordPress export, and are listed under **Contact requests** with New, Handled and Spam views and a CSV export that spreadsheets open safely. They are deleted after the days you set (180 by default), included in Tools > Export Personal Data and Erase Personal Data, and deleted on uninstall, so export them first.

A hidden field, three requests per visitor in ten minutes and a hundred per site per day keep floods out. With Akismet set up each request is checked as a contact form; spam is kept to be checked, and marking it not spam passes it on. A request is passed on by email to the administration address when that is on (Reply-To is the visitor; the site needs working mail), to Flamingo's inbox when Flamingo is active, and to the `ai_chat_bedrock_lead_captured` action for a CRM. With Joinchat active, its WhatsApp number is offered beside the form unless you set another link, which may be a web, `mailto:` or `tel:` address. Filters: `ai_chat_bedrock_leads_enabled`, `ai_chat_bedrock_leads_email`, `ai_chat_bedrock_leads_daily_limit`, `ai_chat_bedrock_leads_akismet` and `ai_chat_bedrock_leads_flamingo`. Add the suggested text from Settings > Privacy to your privacy policy before turning it on.

## Can I see what the chat does in Google Analytics?

Yes, under Chat > Analytics events, which is off by default. The chat then reports what it did to the analytics tag already on the site, never what was written: no message text, no answers and no contact details.

| Event | When | Parameters |
| --- | --- | --- |
| `ai_chat_open` | The floating chat is opened | `chat_profile` |
| `ai_chat_question` | A question is sent | `chat_profile`, `question_source` (`typed` or `suggestion`) |
| `ai_chat_answer` | An answer arrives | `chat_profile`, `sources`, `products` (how many were shown) |
| `ai_chat_source_click` | A source under an answer is followed | `chat_profile`, `link_url` |
| `ai_chat_product_click` | A product card is followed | `chat_profile`, `link_url`, `product_action` (`view` or `add_to_cart`) |
| `ai_chat_feedback` | An answer is rated | `chat_profile`, `rating` (`up` or `down`) |
| `ai_chat_contact` | A contact request is sent | `chat_profile` |

`chat_profile` is left out for the default profile. Where the events go:

- **Google Tag Manager** (a container on the page, as GTM4WP and Site Kit add): pushed to the data layer as `{ event: 'ai_chat_answer', sources: 2, ... }`, GTM4WP's own layer name included, to use as Custom Event triggers.
- **Google Analytics** without a container (Site Kit, MonsterInsights or a gtag snippet): sent with `gtag('event', ...)`. Mark `ai_chat_contact` as a key event to count contact requests as conversions, and register the parameters as custom dimensions to report on them.
- **Matomo**: an event in the category *AI chat*, labelled with the rating, product action, question source or link.
- **Plausible**: a custom event with its properties; add a goal for each event you want to see.

With a consent plugin that uses the WP Consent API, such as Complianz, CookieYes, Cookiebot or Real Cookie Banner, events wait until the visitor allows statistics. Without one, the tag's own consent settings, such as Google Consent Mode, decide what is sent. The plugin also tells such consent plugins what it keeps in the browser: whether the floating chat is open, and the conversation while conversation memory is on. Both are in session storage, are needed for what the visitor asked for and are listed as functional.

For other tools, listen for the `ai-chat-bedrock:event` DOM event on `document`. It is dispatched for every event, whether or not the setting is on, with `detail.name` and `detail.data`:

```js
document.addEventListener( 'ai-chat-bedrock:event', ( event ) => {
	window.clarity && window.clarity( 'event', event.detail.name );
} );
```

Add the suggested text from Settings > Privacy to your privacy policy before turning the setting on.

## Which Bedrock models are supported?

Text models in the Anthropic Claude, Amazon Nova, Amazon Titan, Meta Llama, Mistral and DeepSeek families that your Region offers, including Claude Sonnet 5, Claude Opus 5.5 and Claude Haiku 4.5, and the other chat models Bedrock serves, such as OpenAI gpt-oss, Qwen3, Llama 4, Mistral Large, DeepSeek R1 and Kimi. Models other than Claude, Nova and Titan are called through the Bedrock Converse API, which applies each model's own chat format; when a model refuses a setting such as temperature or a system prompt, the plugin retries once without it and remembers that for the model. The settings screen lists the models your account offers in that Region, and "Refresh model list" updates it. A new installation starts on Amazon Nova Lite because it answers with nothing enabled beyond an IAM role. Newer Claude models such as Sonnet 5 and Opus 5.5 are called through a cross-region inference profile, an ID beginning with `us.`, `eu.` or `global.`, and they reject the temperature setting, so the plugin does not send it to them. A specific model may still need a supported Region and suitable IAM permissions.

For images: Stability AI Stable Image Core, Stable Diffusion 3.5 Large and Stable Image Ultra, plus Stable Image Remove Background and Stable Fast Upscale for the Media Library. Image models run in US West (Oregon) whatever region the chat uses; the `ai_chat_bedrock_image_region` filter moves them to another region that offers them.

For reranking: Cohere Rerank 3.5 and Amazon Rerank 1.0. US East (N. Virginia) offers only Cohere Rerank 3.5.

## How do I connect Claude Code, Cursor or another AI client to this site?

The plugin exposes this WordPress site as an MCP server, so clients such as Claude Code, Cursor, VS Code or an agent framework can read it.

* Endpoint: `https://example.com/wp-json/ai-chat-bedrock/v1/mcp`
* Transport: JSON-RPC 2.0 over Streamable HTTP on protocol revision 2026-07-28, with `server/discover`, `tools/list` and `tools/call`. Clients on 2025-11-25 and 2025-06-18 are still answered
* Authentication: a WordPress Application Password works out of the box, over HTTPS
* Tools: five read-only content tools, plus SEO suggestions, WooCommerce lookup and draft creation when site abilities are on. Every call is capability-checked and audited.

Clients can connect with OAuth 2.1 instead of copying tokens: paste the endpoint, sign in to WordPress and approve, and no WordPress password reaches the client. Discovery uses `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`, client registration is dynamic, PKCE with S256 is mandatory, redirect targets must be HTTPS or loopback, authorization codes are single use, access tokens last an hour, and refresh tokens rotate so reusing one revokes the connection. Tokens are stored only as hashes. Each connection inherits the approving account's permissions and can be revoked at any time.

Anonymous access and OAuth are both disabled by default. If the endpoint returns 404, open Settings > Permalinks and save once so WordPress registers pretty REST routes.

## What can an agent read from and write to my site?

Narrow abilities can be registered for agents and other plugins: search published posts and pages, read one published post or page, suggest an SEO title and meta description without saving, look up published WooCommerce products, and create a draft post.

Reads never return draft, private or password-protected content. The only write operation creates a new draft: nothing is published, updated or deleted, and these abilities never expose WooCommerce orders or customers. Draft creation requires `edit_posts`, reads require the capability configured for MCP tools, and the feature is disabled by default.

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

## What does the site description tell an agent?

Turn on Site description under Answer grounding, and agents get a describe-site ability and an MCP tool of the same name. It lists what the site holds as schema.org types, such as posts as Article, pages as WebPage and WooCommerce products as Product, with published counts per language and how the types relate.

Each property has a sensitivity class, with a rule for each use: public content may be quoted, members-only sections never reach a model or an index, personal data such as orders goes only to the signed-in person it is about, and store figures only as totals. Entity IDs are the ones Yoast SEO and WooCommerce print in the page, so an agent and a search engine see the same nodes.

Given a post ID, it describes one published item with its terms and published translations. Passages given to the chat model also say whether they come from a post, page or product, and in which language. Nothing is stored, and drafts, private and password-protected posts are never described.

## Does it work with the AI features in WordPress core?

On WordPress 7.0 and later, Bedrock is registered with core's AI Client, so `wp_ai_client_prompt()` reaches it from any plugin that knows nothing about AWS. Those calls use this plugin's request path, so the guardrail, model, region, token ceiling, daily limit and usage accounting configured here apply to them.

On 7.1 and later, Amazon Bedrock appears under Settings > Connectors. On AWS it shows as connected with nothing entered, because the plugin signs with the IAM role. Elsewhere, an Amazon Bedrock API key can be pasted there. Core hands the key to the plugin, which checks it with a free ListFoundationModels request before core keeps it; a refused key is not saved. The key is stored encrypted with the same envelope as the plugin's own settings, and a key in the plugin settings or `wp-config.php` takes precedence over it. Versions 1.30.0 to 1.62.0 declared the connector as storing no credential, which kept it off that screen.

Core asks for a model by what it must do, and the plugin describes each Bedrock model truthfully: image input only on models that read images, top P only where the model accepts it, image generation only on the Stability models and only once an image model is chosen, and embeddings only on WordPress 7.2 and later. JSON answers are checked and, when a model returns something else, asked for once more before an error is returned.
