# AWS 上の WordPress に、出典つきで答える AI チャットボットを追加する

このガイドでは [AI Chatbot, Agents & MCP for Amazon Bedrock](https://ja.wordpress.org/plugins/ai-chat-for-amazon-bedrock/) を設定し、WordPress サイトが自分のページの内容から、参照したページへのリンクつきで訪問者に答えるようにします。モデルは Amazon Bedrock の Claude などを使います。

AWS アカウントがなくても、まずプラグインの WordPress.org ページにある **ライブプレビュー** で試せます。ブラウザー内で小さなパン屋のサイトが動き、チャットはサイトのページから質問に最も合う一節を引用して答えます。

## 始める前に

- 有料プランの AWS アカウント。Amazon Bedrock は AWS の無料プランには含まれません。新しい AWS アカウントには最大 200 ドルの無料利用枠クレジットがあり、有料プランにアップグレードすると AWS の請求に充当されます（[AWS 無料利用枠](https://aws.amazon.com/jp/free/)）。
- WordPress 6.4 以降、PHP 7.4 以降。
- Bedrock は使った分だけ課金されます。チャットを一般公開する前に [Amazon Bedrock の料金](https://aws.amazon.com/jp/bedrock/pricing/) を確認し、[AWS Budgets](https://aws.amazon.com/jp/aws-cost-management/aws-budgets/) を設定してください。

## 1. プラグインをインストールする

WordPress の **プラグイン > 新規追加** で **Amazon Bedrock** を検索し、**AI Chatbot, Agents & MCP for Amazon Bedrock** をインストールして有効化します。

## 2. リージョンとモデルを選ぶ

**AI Chat Bedrock > 設定** を開き、AWS リージョンとモデルを選びます。アカウントで必要な場合は、Amazon Bedrock コンソールで同じリージョンのそのモデルへのアクセスを有効にします。東京リージョン（ap-northeast-1）も選べます。

## 3. Bedrock に接続する

次のどちらかを選びます。

- **サーバーの IAM ロール（EC2・ECS・EKS ではこちらを推奨）。** 認証情報は空のままにします。サイトが使うモデルだけを呼び出せる IAM ロールを、インスタンスまたはタスクに付けてください。設定に合わせた最小権限のポリシーは **診断** に表示されます。
- **Amazon Bedrock API キー（Amazon Lightsail やほかのホスティングなど、それ以外の環境）。** Bedrock コンソールで同じリージョンの **API キー** を開き、有効期限つきの長期キーを作成して、**AI Chat Bedrock > 設定 > AWS 認証** に貼り付けます。キーは暗号化して保存されます。WordPress 7.1 以降では **設定 > コネクター > Amazon Bedrock** に貼り付けることもできます。データベースに保存したくない場合は、`wp-config.php` で `AI_CHAT_BEDROCK_API_KEY` を定義します。

WordPress サイトに AWS の広い管理者権限を与えないでください。

## 4. 確認する

設定を保存し、ログインしたまま **診断** を実行します。モデルに短い質問を 1 つ送り、その答え、または直すべき点を表示します。

## 5. チャットを追加する

チャットブロックを挿入するか、ページに `[ai_chat_bedrock]` を追加するか、全ページにフローティングチャットを表示します。回答は公開済みのページから訪問者の言語で作られ、出典が示されます。ゲストのチャットは有効にするまでオフで、リクエストにはレート制限があります。あわせて 1 日のリクエスト上限も設定してください。

## さらに

- **WordPress 公式 AI プラグイン。** WordPress 7.0 以降では Bedrock がコアの AI Client のプロバイダーになるため、WordPress AI プラグインの機能が Bedrock で動きます。画像の生成と編集には **AI Chat Bedrock > 設定 > モデル** で画像モデルを選ぶ必要があります。画像のリクエストは、Bedrock が Stability のモデルを提供している米国西部（オレゴン）に送られます。
- **AI エージェント。** **設定 > エージェントとツール** で、エージェント向けのサイトの説明、`llms.txt`、サイトのコンテンツ機能、WordPress MCP サーバーを有効にできます。エージェント向けに何が有効かは **診断** に表示されます。説明されるのは公開済みの公開コンテンツだけです。

詳しくは：[FAQ（英語）](https://github.com/noteflowai/ai-chat-for-amazon-bedrock/blob/main/docs/faq.md) · [サポート](https://wordpress.org/support/plugin/ai-chat-for-amazon-bedrock/)
