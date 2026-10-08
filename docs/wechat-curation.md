# Curated WeChat drafts

For PhysicalAI Lab, select the strongest useful work on embodied AI and robotics:
verified engineering, experiments, evaluations and learning material with an
original increment. A recent post, 600 public characters or an API success does
not establish quality. Prefer a useful article over daily volume; if nothing
qualifies, make no draft. Do not automatically repost every WordPress article.

This describes the source implementation. It does not establish which plugin
version is installed, whether drafts are enabled, or the live account's settings.
The existing installation owner must confirm those separately before adoption.

## Prepare a public edition, then review the exact version

Use the existing writer and choose one schedule owner: WordPress cron or the
external controller. Do not introduce another draft writer, scheduler, model call
in cron, or notification job.

The WordPress abilities `ai-chat-bedrock/set-wechat-edition`,
`ai-chat-bedrock/get-wechat-edition`, `ai-chat-bedrock/get-wechat-review` and
`ai-chat-bedrock/review-wechat-post` are available through the existing MCP tools
`set_wechat_edition`, `get_wechat_edition`, `get_wechat_review` and
`review_wechat_post`. All require an authenticated
WordPress principal with `publish_posts` and `edit_post` for the specified post.
Permissions are checked inside the methods as well as by ability callbacks.
Edition/review tools can prepare input and attestations while draft sending remains disabled;
`create_wechat_draft` still requires the existing enabled draft settings.
Existing tool allow policies apply; this does not grant an Agent new permissions.

1. Call `set_wechat_edition` for an existing public, published, password-free post:

   ```json
   {
     "post_id": 123,
     "title": "A robot experiment",
     "excerpt": "A concise public summary for mobile readers.",
     "html": "<h2>The experiment</h2><p>Explicit public editorial text…</p><img src=\"attachment:456\" alt=\"Public experiment diagram\">",
     "cover_id": 789
   }
   ```

   The setter writes only protected `_aicfab_wechat_edition` metadata. It never
   creates a second WordPress post or changes the canonical body, title, excerpt or
   featured image. All five fields are required; extra fields are rejected. Title
   and excerpt are plain text, respectively 1–32 and 1–120 characters. HTML must be
   nonempty UTF-8, at most 1,000,000 bytes, with at most 20 body images.
   Automatic selection still requires at least 600 non-whitespace editorial text
   characters; preparing a shorter edition returns a `too_short` shortfall.

   Use static paragraphs, headings, lists, quotes, code, tables and images. KSES
   strips unsupported tags/attributes, including arbitrary CSS and event handlers.
   Active elements, Gutenberg block markup and shortcode-like expressions are
   rejected, including encoded forms. Submitted HTML is never passed through
   `the_content`, `do_blocks`, `do_shortcode` or the guest renderer.
   Body images must use the exact double-quoted `src="attachment:ID"` form;
   remote URLs and arbitrary filesystem paths are rejected. Cover/body IDs must
   resolve to real locally readable JPEG/PNG bytes inside WordPress uploads.
   Public attachment URLs may be CDN URLs when the selected files remain local.
   Uploads with no local bytes must first be prepared locally by the source owner;
   this setter does not fetch or import remote editorial source or assets.
   Static editions do not render canonical video or block players; readers use
   “Read more” to watch the original on the site.

2. Call `get_wechat_edition` or `get_wechat_review` with `{"post_id":123}`.
   They return the current `digest`, explicit public edition `input`, stored
   `review` (or `null`), and `shortfall`. The edition read also returns `edition`.
   The setter returns this same readback. Inspect the **stored sanitized HTML**,
   resolved local assets and independent title/excerpt before reviewing. This is a
   local read: it does not fetch remote images or contact WeChat.
3. Have the independent editorial reviewer inspect those exact inputs, the public
   sources, rights and evidence. Keep the external review record tied to the post
   ID and digest. Do not expose credentials or member answers in review evidence.
4. Call `review_wechat_post` with `post_id`, the exact lowercase SHA-256 `digest`,
   `review_identity`, `evidence`, `checks` and `reasons`. Every check below must be
   boolean `true`, and every corresponding reason must give a specific substantive
   explanation. Identity is 3–160 bytes; evidence and each reason are 20–2,000
   bytes of plain text. `evidence` should identify the immutable external audit
   record and its content hash. The gate checks required structure, not the
   existence or truth of that external record.
5. Read the status back. An empty `shortfall` means this current version satisfies
   both the attestation gate and the existing public-text/cover basics. Only then
   use the existing `create_wechat_draft` tool or the already chosen schedule.

| Required check | What its reason must substantiate |
| --- | --- |
| `topic_fit` | Why this specific material fits PhysicalAI Lab and its WeChat readers. |
| `original_value` | The original engineering, experiment, evaluation or learning increment and why it is worth reading. |
| `evidence` | Which public sources, measurements and limitations support the claims. |
| `rights` | Permission or license for the selected public text and each image. |
| `mobile_readability` | Chinese copy, structure, title, excerpt and images suited to mobile WeChat reading. |
| `safety` | Appropriate claims and exclusions of private data, unsafe instructions and unsupported conclusions. |
| `public_only` | Explicit public editorial material with audited provenance; gated answers and member solution details excluded. |

The current attestation is stored in protected post meta `_aicfab_wechat_review`.
It includes schema version 1, post ID, digest, server-set review/expiry times,
authenticated WordPress user ID, external reviewer identity/evidence and all
checks/reasons. It expires after seven days. An authorized reviewer replaces it
with a fresh review when necessary; malformed, missing, future-dated, expired or
different-version records cannot authorize automatic sending.

**Provenance, rights and independence are external pipeline responsibilities.**
Static sanitization prevents dynamic rendering; it does not prove that submitted
text or local images are public, licensed, accurate or suitable for mobile reading.
The author must submit only intentionally public material, and the independent
reviewer must audit its provenance and each selected asset. Never copy private
source or member answers into the edition, title, excerpt or review evidence.
A caller saying
“independent”, providing a model name, or authenticating to a WordPress REST/MCP
endpoint does not prove that the author and reviewer are independent. The external
pipeline must audit the reviewer execution, provenance and separation from the
author against the exact digest, and control which authenticated identity may
submit reviews. This gate guarantees required attestations and version binding,
not review quality, licensing truth or model independence. It adds no anonymous
REST review or edition-meta route. The underscore-prefixed edition meta is not
registered with `show_in_rest`; ability discovery does not grant anonymous access.

The edition record has schema version 1, post ID, sanitized HTML, independent
title/excerpt/cover ID, a server-computed canonical source digest, authenticated
author user ID and server-set storage time. A canonical body, title, excerpt,
language or permalink change returns `edition_stale`: explicitly prepare the
edition against the new source, then independently review its new digest.
Preparation alone never supplies or renews an attestation.

### Structured preparation errors

Read/set/review abilities return `WP_Error` with `data.status` and
`data.shortfall.{code,message}` for native callers and transports. MCP uses `isError: true` with
`structuredContent.error.{code,message,data}` while retaining the readable message.

| Status | Codes | Operator action |
| --- | --- | --- |
| 403 | `forbidden` | Use an authenticated principal with publish and per-post edit permission. |
| 409 | `edition_missing`, `edition_stale`, `review_changed` | Prepare the current edition or renew the exact-digest review. |
| 413 | `review_input_large` | Shorten explicit editorial HTML to the byte bound. |
| 422 | `edition_invalid`, `review_assets`, `not_public`, `review_invalid` | Correct static input, locally available JPEG/PNG assets, target visibility or attestation fields. |

Local storage/encoding/hash failures remain internal errors. Malformed stored
editions are held, never silently downgraded to the canonical guest page.

## Binding and execution limits

The digest binds post ID, raw canonical source hash (without returning private source),
canonical source digest, the whole sanitized edition record, independent title,
excerpt, resolved static edition HTML and conversion HTML, permalink, article and selection
languages, locale, effective author, account AppID (never AppSecret), WeChat video
choice, edition cover attachment ID, and attachment IDs, selected local filenames,
MIME types and SHA-256 bytes of the selected cover and all body images. It also
binds the writer and guest/excerpt projection source files.
Conversion or projection code changes therefore require fresh reviews.

Explicit edition HTML is bounded as above. Each cover/body image must resolve
within the existing size rules (10 MiB cover, 1 MiB body); hashes cover the actual
selected smaller image when the writer uses one. Review never fetches remote
posters from the canonical page. Replacing local bytes at the same attachment ID,
even with unchanged modification time, invalidates review; the upload cache also
uses content hashes.

Candidates and shortfall checks require an explicit valid edition and current review.
Every automatic create,
including Agent and schedule requests, revalidates before its uploads and again
before `draft/add` or `draft/update`; an unreviewed item is not mixed into a batch.
If input changes during conversion, earlier uploads may already exist, but no
draft write is made. An edition image upload failure also holds the draft instead
of silently omitting the reviewed image. These checks are not an atomic sandbox against concurrent
WordPress code or same-user mutation. Keep editorial inputs controlled during
conversion, and renew review after any change.

Automatic updates preserve existing records when native state is missing,
unknown, edited, or update returns `40007`/another error. They do not infer
publication, recreate drafts, delete older drafts or manufacture recovery
records. The existing owner must reconcile the original effect through the
authorized path. A multi-post request containing an existing effect is also
held rather than creating a second copy.

The explicit human editor button remains a manual selection and does not require
the automatic attestation. When the edition is **missing**, that human path keeps
the existing guest projection and remote media handling. If an edition exists,
it uses that edition; malformed, stale or unavailable edition input is held, with
no guest fallback. The manual path still requires publish/edit permission and a
public post; it cannot bypass member privacy. Agent/MCP `create_wechat_draft` is
always automatic and never takes this human fallback.

After conversion, the existing authorized owner must read back and review the
native final copy: title, digest, mobile layout, cover, evidence, rights and
privacy. Source review does not establish native-copy approval. Final publication
and follower sending remain the user's manual action in the Official Accounts
Platform, including QR approval where required. No publish/send API is added.
