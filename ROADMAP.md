# AI Chat for Amazon Bedrock roadmap

Updated: 2026-09-29 · Owner: NoteFlowAI maintainer · Review: weekly; monthly operator-value review.

Proposed priorities, with acceptance gates rather than promised release dates.

## User and outcome

A WordPress operator offers a dependable Bedrock assistant that **answers from the site's permitted knowledge, shows supporting sources and helps identify unanswered reader needs**.

The plugin remains useful for general WordPress sites. A particular site's editorial automation belongs outside the plugin.

## Current baseline

Reviewed `530e7e2` / v1.48.0. Streaming chat, Bedrock integration, retrieval, evaluation and tool/MCP capabilities already exist. Role-based AWS credentials, permission checks, restricted tools and package checks are valuable foundations.

Published-only retrieval, guest-chat defaults and draft-only content tools must retain their existing boundaries. The review did not establish external site adoption, answer usefulness or cost per resolved question.

## Now

| ID | Outcome | Acceptance evidence |
| --- | --- | --- |
| WP-01 | A site owner reaches the first grounded answer | Observe three setup attempts on supported WordPress/PHP environments. Exercise role credentials, region/model access and empty-content cases. Target a cited first answer within 30 minutes with actionable diagnostic steps. |
| WP-02 | Permission and evidence failures are visible | Maintain a regression corpus for private/draft content, insufficient capabilities, invalid nonces, stale sources, prompt-injected retrieved text and unavailable models. Demonstrate citations or an explicit inability to answer; preserve tool authorization. |
| WP-03 | Operators can judge service quality and cost | Establish baseline first-token latency, request failures, retrieved-source coverage and available usage/cost information without storing unnecessary conversation data. Label unavailable provider usage as unknown. Verify timeout/retry behavior and readable operator diagnostics. |

## Next

| ID | Outcome | Entry and exit gates |
| --- | --- | --- |
| WP-04 | Repeated unanswered questions improve site content | Begin with consented, minimized data and a deletion/retention policy. Show grouped knowledge gaps and draft suggestions with source links. Preserve the existing restriction that agent tools cannot publish or update public posts. |
| WP-05 | Retrieval quality improves on a real site corpus | Use a reviewed question set and explicit relevance/citation criteria. Compare a simple baseline and the candidate on held-out questions; document failures and migration/rollback. |

## Later

More providers, tools or site-builder integrations require a recurring operator need and compatibility maintenance. A general agent scheduler, autonomous publisher and robotics-specific framework are outside scope.

## Measures and decisions

- Measure first successful setup, recurring active installations where voluntarily reported, cited-answer usefulness, failures and support burden.
- Evaluate answer usefulness against a labeled question set; do not substitute model self-scoring alone.
- Establish cost and latency baselines before setting improvement percentages.
- Review non-use and abandonment before adding another tool integration.

## Delivery policy

Every task links a milestone ID, operator problem, reproduction and acceptance evidence. Maintenance and justified no-change are valid. Preserve PHP/WordPress support, least-privilege defaults, credential redaction and existing release gates: supported PHP checks, PHPCS, Plugin Check, tests, consistent versions and verified distribution artifacts.
