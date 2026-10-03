# AI Chatbot & Agents for Amazon Bedrock roadmap

Updated: 2026-09-29 · Owner: NoteFlowAI maintainer · Review: weekly; monthly operator-value review.

Proposed priorities, with acceptance gates rather than promised release dates.

## User and outcome

A WordPress operator offers a dependable Bedrock assistant that **answers from the site's permitted knowledge, shows supporting sources and helps identify unanswered reader needs**.

The plugin remains useful for general WordPress sites. A particular site's editorial automation belongs outside the plugin.

## Current baseline

Reviewed `530e7e2` / v1.48.0. Streaming chat, Bedrock integration, retrieval, evaluation and tool/MCP capabilities already exist. Native WordPress AI Client registration, Abilities and live integration checks also exist. Role-based AWS credentials, permission checks, restricted tools and package checks are valuable foundations.

Published-only retrieval, guest-chat defaults and draft-only content tools must retain their existing boundaries. The review did not establish external site adoption, answer usefulness or cost per resolved question.

## External evidence and positioning — reviewed 2026-09-29

[WordPress 7's native AI Client](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) provides a provider-neutral prompt API and Connectors setup. The [Abilities API](https://make.wordpress.org/ai/handbook/projects/abilities-api/) standardizes discoverable capabilities with authorization. Generic chat and another independent tool registry face increasing platform overlap.

This plugin already integrates both APIs; its release history includes a real hook-wiring correction verified against live WordPress. Prioritize compatibility with the native workflow, Bedrock role credentials, AWS operational controls, permitted site knowledge and useful citations. Retain standalone chat where it serves an observed site need.

[AI Engine](https://wordpress.org/plugins/ai-engine/) already advertises chat, knowledge retrieval and MCP. A community [Bedrock AI Client provider](https://github.com/itzmekhokan/ai-provider-for-bedrock) also exists; its README describes a thin API-key/OpenAI-compatible path. Native Bedrock support is therefore not unique by itself. Treat these as comparison candidates, not independently verified quality claims. Match the task, model/region, permitted data and authentication requirements; an API-key-only path is not automatically an equivalent baseline for a role-credential deployment.

Extend WP-01/WP-03 with a 30-day operator comparison: the same site questions and native AI Client workflow, using current plugin defaults and a simple permitted baseline. Record setup failures, citation correctness, actual provider/model where available, latency and cost per accepted answer. Native `model_preference` is a preference, so do not infer the executed model from configuration. Seek three independent operators; defer new provider/tool breadth if grounded answers or recurring use remain unproven.

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
