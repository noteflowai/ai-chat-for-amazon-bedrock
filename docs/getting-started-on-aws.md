# Add an AI chatbot with sources to a WordPress site on AWS

This guide sets up [AI Chatbot, Agents & MCP for Amazon Bedrock](https://wordpress.org/plugins/ai-chat-for-amazon-bedrock/) so a WordPress site answers visitors from its own pages, with links to the pages it used, on Claude or another model in Amazon Bedrock.

To see it first without an AWS account, open the **Live Preview** on the plugin's WordPress.org page. It runs a small bakery site in your browser, and the chat quotes the best-matching passage of the site's pages.

## Before you start

- An AWS account on a paid plan. Amazon Bedrock is not part of the AWS Free plan. New AWS accounts get up to $200 in Free Tier credits, which apply to AWS bills after upgrading to the Paid plan ([AWS Free Tier](https://aws.amazon.com/free/)).
- WordPress 6.4 or later and PHP 7.4 or later.
- Bedrock bills per use. Review [Amazon Bedrock pricing](https://aws.amazon.com/bedrock/pricing/) and set an [AWS Budget](https://aws.amazon.com/aws-cost-management/aws-budgets/) before opening the chat to the public.

## 1. Install the plugin

In WordPress, open **Plugins > Add New**, search for **Amazon Bedrock**, then install and activate **AI Chatbot, Agents & MCP for Amazon Bedrock**.

## 2. Choose a Region and a model

Open **AI Chat Bedrock > Settings**, choose the AWS Region, and pick a model. Enable access to that model in the same Region in the Amazon Bedrock console if your account requires it.

## 3. Connect to Bedrock

Choose one:

- **The server's IAM role (recommended on EC2, ECS or EKS).** Leave the credentials empty. Attach an IAM role to the instance or task that may invoke only the models the site uses. **Diagnostics** shows the least-privilege policy for your settings.
- **An Amazon Bedrock API key (anywhere else, including Amazon Lightsail or other hosting).** In the Bedrock console, open **API keys** in the same Region, generate a long-term key with an expiry, and paste it under **AI Chat Bedrock > Settings > AWS authentication**. It is stored encrypted. On WordPress 7.1 and later you can paste it under **Settings > Connectors > Amazon Bedrock** instead. To keep it out of the database, define `AI_CHAT_BEDROCK_API_KEY` in `wp-config.php`.

Never give a WordPress site broad AWS administrator permissions.

## 4. Check it

Save the settings and run **Diagnostics** while signed in. It sends one short question to the model and reports the answer, or what to fix.

## 5. Add the chat

Insert the chat block, add `[ai_chat_bedrock]` to a page, or turn on the floating chat for every page. Answers come from your published pages in the visitor's language, with their sources. Guest chat stays off until you turn it on, and requests are rate limited; set a daily request limit under the settings as well.

## Optional

- **The official WordPress AI plugin.** On WordPress 7.0 and later, Bedrock is a provider for core's AI Client, so the WordPress AI plugin's features run on Bedrock. Its image generation and editing need an image model chosen under **AI Chat Bedrock > Settings > Model**; image requests go to US West (Oregon), where Bedrock offers the Stability models.
- **AI agents.** Under **Settings > Agents and tools** you can turn on the site description for agents, an `llms.txt` file, site content abilities and a WordPress MCP server. **Diagnostics** lists what is turned on for agents. Only published, public content is described.

More answers: [FAQ](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md) · [Support](https://wordpress.org/support/plugin/ai-chat-for-amazon-bedrock/)
