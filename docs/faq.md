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

Pages that search engines are told not to index are left out of answers and of what agents can search, since a page is usually kept out of search for a reason: a thank-you page with the download a form gives away, a campaign landing page, a page for one customer. The plugin reads the noindex settings of Yoast SEO, Rank Math and SEOPress for each page and post type, and of All in One SEO for each page; discouraging search engines in Settings > Reading is ignored, since that is how staging sites are hidden. Tick **Also answer from pages that search engines are told not to index** under Answer grounding to use them anyway, return true from `ai_chat_bedrock_post_is_noindex` for another SEO plugin, or decide per post with `ai_chat_bedrock_is_answerable_post`. Reading a post aloud is not affected.

A heading, the summary of a details block and each question of a Yoast SEO or Rank Math FAQ block are kept in one passage with the text that follows, so a passage that matches a question also holds its answer.

Only published, public content is indexed, as a signed-out visitor sees it: sections that a membership or visibility plugin hides from guests are left out, and every result is checked against the live post again before it is quoted. Blocks with Block Visibility rules are left out whoever they are shown to, since that plugin applies its rules only on front-end pages; to leave out blocks that another plugin restricts, return true from the `ai_chat_bedrock_block_is_restricted` filter. With Polylang, a question is answered from pages in the visitor's language first. With Polylang or WPML, the model is also asked to reply in the language of the page; change or remove that instruction with the `ai_chat_bedrock_language_instruction` filter.

## What does reranking do?

Without it, the closest few passages from site content and from the knowledge base are each passed to the model. Choose a reranking model under Answer grounding and up to eight passages from each are gathered, then the reranking model scores them all against the question and only the best are kept, up to the number of passages set. It is one extra request per question, billed per query rather than per token, and shown apart from chat requests on the dashboard. If reranking fails the passages are used as before, and a refused request pauses it for an hour. The IAM policy in Diagnostics adds `bedrock:Rerank`. To drop passages that score below a threshold, return it, between 0 and 1, from the `ai_chat_bedrock_rerank_floor` filter; the best passage is always kept.

## Can answers and posts be read aloud?

Yes, under Chat > Read aloud, which is off by default. **Add a Listen button to chat answers** puts Listen next to Copy under each answer, and **Add a Listen to this post button to posts** puts one above the text of each post. Amazon Polly reads in a voice for the language, chosen from the page or from the answer itself: Mandarin for Chinese, Japanese, Korean, the main European languages and more. The chat only reads answers it gave to that visitor, so the site cannot be used as a free text-to-speech service. A post is read as a signed-out visitor sees it, so member-only sections are never read; each part is saved in the uploads folder the first time it is played, so later listeners cost nothing, and changing the post makes new audio. Polly is priced per character, and the generative voices cost about twice as much as the neural ones. The dashboard counts the characters read, and reading stops for the day at the limit you set (100,000 by default). What costs money is making new audio, so that is what is limited: saved post audio plays for everyone, while one visitor may have at most a quarter of the daily limit made, and at least one post of the longest length read (30,000 characters by default), so no single visitor or script can use up the day for everyone else. Crawlers, link previews and scripts cannot have posts read at all, and administrators are not limited. **Only for signed-in visitors** hides the post button from everyone else, as on a members site. Change the allowance with `ai_chat_bedrock_speech_visitor_chars` and what counts as automated with `ai_chat_bedrock_speech_is_automated`. The AWS identity needs `polly:SynthesizeSpeech`, which the IAM policy in Diagnostics includes. Change the voice with the `ai_chat_bedrock_speech_voice` filter, the post types with `ai_chat_bedrock_speech_post_types`, the Region with `ai_chat_bedrock_speech_region` and the length read with `ai_chat_bedrock_speech_max_chars`. Add the suggested text from Settings > Privacy to your privacy policy before turning it on.

## How do I see time to first text from the command line?

Run `wp ai-chat-bedrock usage`. After the per-day table, the Total line and any Prompt cache line, the last line reports streamed time to first text for the same window, for example:

```
Time to first text (streamed, 7 days): 12 answers, average 1840 ms, median 1 to 2 s.
```

The median band is one of under 1 s, 1 to 2 s, 2 to 5 s, 5 to 10 s, or 10 s and over, as on the dashboard. Counters are kept for 30 days, so `--days=90` reports `(streamed, 30 days)` for this line while the table still covers the days asked for. With no streamed answer measured in the window the line reads `unknown, no streamed answers recorded.`, and a stored sum or band that is missing or invalid reads `average unknown` or `median unknown`, never 0 ms. Unknown is reported, not an error, so the command still exits zero. `--by-model` leaves the line out, because latency is not recorded per model. The line reads only the stored counters: no prompt, answer, visitor or request ID, and no Bedrock request.

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

## Can it answer my WeChat Official Account?

Yes, under Settings > Channels > WeChat Official Account, which is off by default. Followers who write to the account in WeChat get an answer from your pages, as visitors to the site do.

1. In the WeChat Official Accounts Platform, open Settings and Development > Basic Configuration and choose a token: 3 to 32 letters and digits. Use a long random one.
2. Enter the token in the plugin, tick **Answer messages that followers send to the account** and save.
3. Back in WeChat, set the server URL to the address the setting shows, `https://your-site/wp-json/ai-chat-bedrock/v1/wechat`, enter the same token, choose a message encryption mode and enable the configuration. WeChat checks the address at once.

Plaintext mode needs only the token. For compatible or safe mode, which WeChat recommends, also enter the EncodingAESKey and the AppID; replies are then encrypted too. No AppSecret is needed, since nothing is sent to WeChat's API: the answer is the reply to WeChat's own request. That is also why it works with an unverified personal subscription account, which cannot send customer service messages.

WeChat waits five seconds for a reply and asks twice more, so an answer can take about fifteen seconds. One that takes longer is kept for ten minutes, and the follower is told to send 1 to see it. If the chat's model usually takes longer than that, choose a faster one under **Model for WeChat**, such as Claude Haiku or Amazon Nova Lite; the site keeps its own model, and when the WeChat model is also the fallback, the main model stands in for it. The answer is plain text, with up to two source links, cut to the 2,000 bytes WeChat shows, and ends with a note that AI generated it: in WeChat it arrives as a message from the account, and WeChat's rules for AI question answering and China's rules on labelling AI-generated content both ask for such a label. Change or remove the note with the `ai_chat_bedrock_wechat_ai_label` filter. Each follower's last three questions and answers are kept for 30 minutes so a follow-up is understood. A new follower gets the welcome message and the suggested questions; images, voice and other messages get a note that only text can be read.

Every request must carry WeChat's signature for your token and be no more than 15 minutes old; XML with a document type is refused. Each follower may send 20 messages an hour by default, and every answer counts towards the site's daily request limit. The conversation log, when it is on, records the questions under the source WeChat, without the follower's ID. Add the suggested text from Settings > Privacy to your privacy policy before turning it on.

## Can it post to my WeChat Official Account?

It makes drafts. Under Settings > Channels > WeChat Official Account drafts, off by default, posts become articles in the account's draft box; you look at them and publish in the Official Accounts Platform. Since July 2025 WeChat lets only verified company accounts publish or send to all followers through its API, and a personal or unverified account can still make drafts, so the plugin stops there. That also leaves a person to see each article before followers do.

1. Under Settings > Channels > WeChat Official Account, enter the AppID.
2. In the WeChat Developers Platform, under Basic Information > Developer Key, enable the AppSecret and add the server's outgoing IP address to the IP whitelist. If the address is not there, the settings screen names the one WeChat saw.
3. In the same tab, enter the AppSecret, tick **Send posts to the Official Account's draft box**, and choose the category of featured posts.

Each post becomes an article: its title (a longer one ends at its last comma or colon within 32 characters), the excerpt or else the first paragraph that says something as digest (120, ended at a sentence), the author you set, the featured image as cover (or else the post's first image; JPEG or PNG, uploaded once as a permanent image), the text and images a signed-out visitor sees, so members-only sections stay out, and the post's address as **Read more**. Images in the text are uploaded to WeChat, which shows no others; one over 1 MB is sent in its large size, one on another host, such as a CDN the media is offloaded to, is fetched over https and uploaded, and GIFs are left out. Links become plain text, since WeChat does not open links outside it, and sign-in and other buttons are left out. Lists, paragraphs and headings lose the white space WeChat would show as empty bullet points or lines, and every block gets inline styles at WeChat's usual size and spacing, since WeChat takes no style sheet. Folded sections are shown open. A link that is a whole paragraph or list item, such as a quiz or an online lab, says to open it from Read more. A last line points to Read more. A long post is cut at a paragraph under WeChat's 20,000 characters.

Posts reach the draft box three ways:

- **From the editor.** The Published elsewhere box has **Send to the WeChat draft box**.
- **By an agent.** The `create-wechat-draft` ability, also the MCP tool `create_wechat_draft`, makes one draft of up to eight posts in the order given. It needs permission to publish posts and to edit each one, and a curated review bound to each current article version.
- **On a schedule.** Every day at 9:00 or every week, the newest featured posts of the last 60 days that have a featured image and at least 600 characters a guest can read, were not sent before, and have a valid current-version curated review are selected, in Chinese when Polylang has it. Up to eight posts are selected (three by default), each as its own draft. With **Email the site when a scheduled draft is ready** on, the existing notification tells the site to check them. No qualifying review means no draft; there is no forced daily quota.

**Curated quality.** For PhysicalAI Lab, prefer useful embodied-AI/robotics engineering, experiments, evaluations and learning material with original value over quantity. API length and recency checks do not assess quality. The protected `get_wechat_review` and `review_wechat_post` tools let an editorial reviewer read the exact public input/digest and record required checks with substantive reasons and external audit evidence. The seven-day review binds content, title, excerpt, language, conversion choices and image bytes; changes require renewed review. Reviewer independence is audited by the external pipeline, not proved by a caller's model name or identity string. See [Curated WeChat drafts](wechat-curation.md) for the contract and limits. This source description does not confirm a live installation or enabled settings. Use one writer and one schedule owner, and review the native final copy before the user's manual publication/QR approval.

Each draft is noted in the publishing record of its posts, as planned with the draft's media ID, so a scheduled run never sends a post twice; mark it public with the article's address once it is out.

**Videos.** WeChat plays no video from another site in an article, and its draft API has no video type: an article is `news` or `newspic`, and a `<video>` tag is removed. A video or embedded player therefore becomes its poster and a line telling readers to watch it on the site from Read more. To show it in the article instead, upload it in the Official Accounts Platform (Content > Material library > Video) and insert it in the editor, or use a Channels (视频号) video or a Tencent Video link, as WeChat's staff advise: a video sent to the material API, which takes at most 10 MB, is never reviewed and cannot be placed in an article, even in the editor, and mass sending refuses it (48022). Such a copy, uploaded through the `ai-chat-bedrock/v1/wechat-video` route, serves only to reply to followers with a video message. A video uploaded in the Official Accounts Platform has a `wxv_` ID, which can be entered as the post's **WeChat video ID** to have WeChat's player placed on every update; that player code is the editor's own, not a documented API, so inserting the video in the editor is the dependable way.

**Keeping drafts current.** The explicit editor button **Send to the WeChat draft box again** replaces the post's article with `draft/update`, and deletes older copies unless another post shares them; a draft already published or deleted can be made anew by that human choice. With **update drafts whose post changed** on, scheduled updates require renewed curated review of the current version and leave alone drafts edited in WeChat. The existing email option tells the site once for each post change that holds such edits. Automatic Agent and scheduled updates hold missing or unknown native drafts for reconciliation, preserve their original records without inferring publication, and never recreate them or retire older copies. Update errors such as `40007` also cannot trigger automatic recreation.

**A menu without a menu.** With message push on, WeChat turns off the menu set in its console, and an unverified account cannot set one through the API. Under Settings > Channels > WeChat Official Account, write a menu text instead: followers who send 菜单, 目录, menu or メニュー get it, new followers get it after the welcome, and 精选, 最新, new, latest or 新着 lists the five newest featured posts with their addresses. Neither calls the model or counts towards the hourly limit. The `ai_chat_bedrock_wechat_keywords` filter changes the words.

## Can it handle my WeChat mini game's customer service?

Yes, under Settings > Channels > WeChat mini game, which is off by default. A mini game sends what happens in its customer service chat to one address: a player opening the chat, from which part of the game, their messages, and their answers to subscription message prompts. The plugin takes them, answers what it can and keeps daily counts.

1. Enter the game's AppID, a token of 3 to 32 letters and digits and, for safe mode, an EncodingAESKey. To send answers, also enter the AppSecret, which is stored encrypted and never exported.
2. In the mini game's console, under Development Management > Development Settings > Message Push, enter the URL the setting shows, `https://your-site/wp-json/ai-chat-bedrock/v1/wechat-game`, the same token and key, safe mode and either JSON or XML. WeChat checks the address at once.
3. Add the server's outgoing IP address to the game's IP whitelist; the settings screen names the address WeChat saw if it refuses an answer.
4. In the game, open the chat with `wx.openCustomerServiceConversation({ sessionFrom: 'level-3' })`. The `sessionFrom` is counted as the scene, so you can see which screens send players to the chat.

**Answers.** Write a welcome for players who open the chat, sent at most every 12 hours per player, and set answers, one per line as `keywords = answer`, keywords separated by commas or `|`, such as `recharge, payment = Payments are handled by WeChat Pay; send your order number if one is missing.` A question gets the first answer one of whose keywords it contains, or the optional reply for no match. Nothing is generated: answering with generative AI in a mini game needs an AI service category and an algorithm filing, which a personal mini game cannot have. A mini game cannot reply to WeChat's request, so answers go through WeChat's customer service API with an access token from the stable token API; WeChat allows a few answers within 48 hours of a player's message, and two within a minute of opening the chat.

**Counts.** For each day of the last 90 the plugin keeps the players (each counted once a day), chats opened, the scenes they were opened from, messages by type, questions that matched an answer or not, answers sent or refused, and per subscription template how many players accepted or declined the prompt, turned it off later and received the message. The settings screen shows the last 30 days. No OpenID is kept: players are counted under a hash with a salt made for the day, and both are discarded after it. Questions go to the conversation log, when it is on, under the source WeChat mini game, so unanswered ones show as content gaps.

What players do in the game itself, such as levels played or items bought, does not come through message push. Report it from the game with `wx.reportEvent` and read it in the console's data analysis. Declare what the game collects in its user privacy guide, and add the suggested text from Settings > Privacy to the site's privacy policy before turning this on.

## Can it publish to Bilibili, YouTube or Xiaohongshu?

It keeps the record of where each post is published, and uploads to YouTube; under Settings > Channels, all off by default.

**The publishing record.** For each post and language it records the items published from it: platform, account, item ID (a Bilibili BV ID, a YouTube video ID or a Xiaohongshu note ID), https address on that platform, status (planned, submitted, processing, public, unlisted, private, replaced, removed or failed), an identity of the edition such as the file's SHA-256, and which record a new edition replaces, which marks the old one replaced. Reporting the same item again updates it. The record shows in a **Published elsewhere** box when editing a post, and with **Link to the public ones under each post**, as "Also on Bilibili · YouTube" under the post for public items in its language.

Agents keep it through three abilities, which the plugin's MCP server also offers as tools: `get_publish_package` returns a post's title, address, the text a signed-out visitor reads (so members-only sections are never handed over), excerpt, tags, image, translations and existing records; `record_publication` records or updates one item; `list_publications` lists records by platform, status, language or post, for example editions still public after being replaced. Each uses the WordPress permissions of the account the agent signs in with: recording needs permission to edit the post.

**Publishing kits.** With **Publishing kits** on, the Published elsewhere box of a published post has **Write platform copy**. The chat's model reads the text a signed-out visitor sees and writes, in the post's language, the Bilibili title (80 characters), description, up to 10 tags and the 分区 that fits; the Xiaohongshu title (20 characters), a body of up to 1,000 characters without links and up to 10 topics; and the YouTube title, description and tags. It is told to use only what the article says. Each platform's copy opens with a link to its creator page and to the cover; check it, publish, and enter the address under **Record where it was published**, which is saved with the post. Each kit is one request of the chat's model, limited to six a minute per user, and agents can ask for one through the `prepare-publish-kit` ability or the MCP tool `prepare_publish_kit`. The copy is an AI draft: China's rules on AI-generated content ask whoever publishes it to declare it with the platform's own label.

The plugin never signs in to Bilibili or Xiaohongshu. Neither offers a publishing API to individual creators, and keeping someone's creator login on a WordPress server is not something a plugin should do, so publishing there stays with an agent or person using the platforms' own creator tools, which then records the result.

**Bilibili videos.** WordPress embeds YouTube videos from their address but not Bilibili's, which has no oEmbed. With Bilibili videos on, a Bilibili video address on its own line or in an Embed block shows Bilibili's player, paused, without danmaku, at the linked part and time. The player comes from Bilibili, which can then set its cookies; the suggested privacy text says so.

**YouTube uploads.** In Google Cloud, enable the YouTube Data API v3 and create an OAuth client of type Web application with the redirect URI shown on the settings screen, enter its ID and secret, and connect the channel with Google's sign-in. The site keeps only an encrypted refresh token; Disconnect revokes it. A post's Published elsewhere box then offers **Upload a video to YouTube**: choose a video from the Media Library, the title, description, tags, visibility, whether it is made for children and whether it has realistic altered or synthetic content, such as an AI voice. The upload runs in the background with YouTube's resumable protocol in 8 MB parts, survives time limits and restarts, is added to the record, and is followed until YouTube says whether the video is public, unlisted or private.

Each upload costs 1,600 of the 10,000 quota units Google gives a project a day, so uploads are limited to 5 a day by default and 6 at most. Google keeps videos uploaded from a project that has not passed YouTube's API audit private whatever is chosen; the record then says so. The video file must be on the server, not only in offloaded storage.

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
