# Export content gaps for editorial planning

Open **AI Chat for Amazon Bedrock → Conversations** as an administrator and select
**Download content gaps CSV** in the Content gaps panel. Use the download to plan
articles, for example explanations of missing robot evaluation results or experiment
procedures. Downloading does not create posts, collect additional data, or call Bedrock.

Pick the window first. **Period**, at the top of the panel, offers Last 7 days,
Last 30 days and Last 90 days; choose one and select **Show**. The summary, the table
and the download then cover that window. The file contains the same ranked gaps as
the panel: up to 15 groups from the retained visitor exchanges in the chosen period.
Thirty days is the default, and any other value in the address falls back to it.

The period stays selected while you search, filter, page through or reset the
conversation list below. That list's search, source and rating filters do not change
the panel or its download. The period is not saved: opening the screen again shows
30 days. When there are no gaps in the period, the panel says so for that number of
days and offers no download.

File names carry the period, except for the default:

- 30 days: `ai-chat-bedrock-content-gaps-YYYYMMDD-HHMMSS.csv`, as before
- 7 days: `ai-chat-bedrock-content-gaps-7d-YYYYMMDD-HHMMSS.csv`
- 90 days: `ai-chat-bedrock-content-gaps-90d-YYYYMMDD-HHMMSS.csv`

The time in the name is UTC. Retention and the 200-entry log cap limit how far back
any period reaches, so on a site that keeps less than 90 days, 90 days can show the
same gaps as 30.

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
