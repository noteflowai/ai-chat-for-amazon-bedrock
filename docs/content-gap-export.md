# Export content gaps for editorial planning

Open **AI Chat for Amazon Bedrock → Conversations** as an administrator and select
**Download content gaps CSV** in the Content gaps panel. Use the download to plan
articles, for example explanations of missing robot evaluation results or experiment
procedures. Downloading does not create posts, collect additional data, or call Bedrock.

The file contains the same ranked gaps as the panel: up to 15 groups from the last
30 days of retained visitor exchanges. The conversation list's search, source and
rating filters do not change this panel or its download. An empty report downloads
only the header.

Columns are `question`, `occurrences`, `last_asked_utc`, `grounded_count` and
`ungrounded_count`. Counts include only exchanges that contribute to the gap:
answers with no grounding or answers marked unhelpful. They are not totals of all
answers about that subject. Times use UTC.

CSV quoting preserves commas, quotes, newlines and literal backslashes. Questions
that could execute as spreadsheet formulas are prefixed with an apostrophe.
The file contains visitor questions; share it only with people who should have
access to the conversation report.

Logging remains opt-in and existing retention limits still apply. Access requires
`manage_options` and a valid download nonce, just like the existing conversation
export.
