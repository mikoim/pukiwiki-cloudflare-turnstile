# PukiWiki用プラグイン<br>スパム対策 turnstile.inc.php

[Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) によりスパムを防ぐ [PukiWiki](https://pukiwiki.sourceforge.io/) 用プラグイン。

ページ編集・コメント投稿・ファイル添付など、 PukiWiki 標準の編集機能をスパムから守ります。  
Cloudflare Turnstile は不審な送信者を自動判定する不可視の防壁です。煩わしい入力を要求せず、ウィキの使用感に影響しません。(Widget Mode = Invisible の場合)  
Google reCAPTCHA v2 や hCaptcha のようにユーザーにインタラクションを求めることも可能です。(Managed の場合)

追加ファイルはこのプラグインのファイル１つだけ。 PukiWiki 本体の変更も最小限にし、なるべく簡単に導入できるようにしています。  
ただし、 JavaScript を活用する高度な編集系サードパーティ製プラグインとは相性が悪いかもしれません。  
PukiWiki をほぼ素のままで運用し、手軽にスパム対策したいかた向けです。

禁止語句によるスパム判定機能もあります。 Cloudflare Turnstile を使わず、禁止語句判定のみ用いることも可能です。

|     対象PukiWikiバージョン      |  対象PHPバージョン   |
|:------------------------:|:-------------:|
| PukiWiki 1.5.4 ~ (UTF-8) | PHP 7.4 ~ 8.3 |



## インストール

以下の手順に沿って PukiWiki に導入してください。

1. ダウンロードした turnstile.inc.php を PukiWiki の plugin ディレクトリに配置する。
2. Cloudflare のダッシュボード -> Turnstile より対象 PukiWiki サイトのドメインを登録、取得したサイトキーとシークレットキーとをこのプラグインの定数 ``PLUGIN_TURNSTILE_SITE_KEY``, ``PLUGIN_TURNSTILE_SECRET_KEY`` にそれぞれ設定する。
3. PukiWiki スキンファイル（デフォルトは skin/pukiwiki.skin.php）のほぼ末尾、 ``</body>`` タグの直前に次のコードを挿入する。  
    ```php
    <?php if (exist_plugin_convert('turnstile')) echo do_plugin_convert('turnstile'); // Cloudflare Turnstile plugin ?>
    ```
4. PukiWikiライブラリファイル lib/plugin.php の「function do_plugin_action($name)」関数内、 ``$retvar = call_user_func('plugin_' . $name . '_action');`` 行の直前に次のコードを挿入する。  
    ```php
    if (exist_plugin_action('turnstile') && ($__v = call_user_func_array('plugin_turnstile_action', array($name))['body'])) die_message($__v); // Cloudflare Turnstile plugin
    ```

## 詳細

### 設定

ソース内の下記の定数で動作を制御することができます。

| 定数名                            |      値       | 既定値 | 意味                                                           |
|:-------------------------------|:------------:|:---:|:-------------------------------------------------------------|
| PLUGIN_TURNSTILE_SITE_KEY      |     文字列      |     | Cloudflare Turnstile サイトキー。空の場合、判定は実施されない                    |
| PLUGIN_TURNSTILE_SECRET_KEY    |     文字列      |     | Cloudflare Turnstile シークレットキー。空の場合、判定は実施されない                 |
| PLUGIN_TURNSTILE_API_TIMEOUT   |    任意の数値     |  0  | Cloudflare Turnstile APIタイムアウト時間（秒）。0ならPHP設定に準じる             |
| PLUGIN_TURNSTILE_CENSORSHIP    |     文字列      |     | 投稿禁止語句を表す正規表現                                                |
| PLUGIN_TURNSTILE_CHECK_REFERER |    0 or 1    |  0  | 1ならリファラーを参照し自サイト以外からの要求を拒否。信頼性が低いため非推奨だが、防壁をわずかでも強化したい場合に用いる |
| PLUGIN_TURNSTILE_ERR_STATUS    | HTTPステータスコード | 403 | 拒否時に返すHTTPステータスコード                                           |
| PLUGIN_TURNSTILE_DISABLED      |    0 or 1    |  0  | 1なら本プラグインを無効化（メンテナンス用）                                       |

### 動作確認

本プラグインが正しく導入されていれば、編集ページやコメント投稿フォーム等に Cloudflare Turnstile のウィジェットが表示されます。  
この状態でページ編集やコメント投稿ができていれば正常です。

拒否される場合を確認したければ、シークレットキーの値をわざと不正にしてみてください。  
その状態でページ編集などを試みるとエラーになるはずです（デフォルト設定の場合）。  
スパムに対する正しいテストケースではありませんが、少なくともプラグインが動作し Cloudflare Turnstile API と連絡していることは確かめられます。  
実際のスパム攻撃については、 Cloudflare Turnstile のダッシュボードに統計が表示されます。

なお、本プラグインが正しく導入されていても、レガシーブラウザーでは常に編集に失敗するかもしれませんが、仕様（非対応）としてご了承ください。

### スパム拒否の仕組み

* ブラウザー側において JavaScript によってページ内のすべての ``<form>...</form>`` にCloudflare Turnstile のウィジェットを追加
  * 不審者はトークンを得られずに弾かれる
  * 副作用として、この細工がサードパーティ製プラグインの動作を邪魔する可能性がある
* サーバー側において、受信したリクエストがPOSTメソッドかつ既知のプラグイン呼び出しなら次の判定を行う
  * パラメーターに ``cf-turnstile-response`` が含まれなければ、不正アクセスとみなしてリクエストを拒否する
* フォームを経ず直接プラグインURLにアクセスしてくるロボットは弾かれる
  * Cloudflare Turnstile APIにトークンおよびクライアントのIPアドレスを送信し、検証が失敗すればリクエストを拒否する
* 不審な送信元IPアドレスや偽造トークンは弾かれる
  * 投稿禁止語句が設定されており、かつテキスト投稿を伴うプラグインであればその内容を判定
    * 特定の宣伝文句などを含むスパムを弾くことができる。URLを禁止するのが最も広範で効果的だが、不便にもなるので注意

### 高度な設定：対象プラグインの追加

本プラグインはデフォルトで、 PukiWiki に標準添付の編集系プラグインのみをスパム判定の対象としています。具体的には次の通り。

``article, attach, bugtrack, comment, edit, freeze, insert, loginform, memo, pcomment, rename, template, tracker, unfreeze, vote``

スパムボットは標準プラグインを標的にすると考えられるため、一般的にはこれで十分なはずです。  
しかし、もし特定のサードパーティ製プラグインを標的として攻撃されていたら、コード内の $targetPlugins 配列にそのプラグイン名を他行に倣って追加してください。  
ただし上述した通り、プラグインの編集・投稿機能が POST メソッドの form 要素かつ submit ボタンで送信する仕組みになっていないと効果がなく、処理内容による相性にも左右されます。

### ご注意

* 標準プラグイン以外の動作確認はしていません。サードパーティ製プラグインによっては機能が妨げられる場合があります。
* JavaScript が有効でないと動作しません。
* サーバーから Cloudflare Turnstile へのアクセスに [cURL](https://www.php.net/manual/ja/book.curl.php) を使用します。
* 閲覧専用（PKWK_READONLY が 1）のウィキにおいては本プラグインは何もしません。
* Cloudflare Turnstile はサードパーティー・クッキーを生成します。サイトのプライバシーポリシーや法令等に応じて適切に運用してください。

### 既知の問題

* ``<form>`` タグが複数含まれるページでは Cloudflare Turnstile のウィジェットも同様に複数表示される
