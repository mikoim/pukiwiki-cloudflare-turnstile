<?php
/**
PukiWiki - Yet another WikiWikiWeb clone.
recaptcha3.inc.php (turnstile.inc.php), v1.1.3 2020 M. Taniguchi
turnstile.inc.php 2024 Eshin Kunishima
License: GPL v3 or (at your option) any later version

Cloudflare Turnstile によるスパム対策プラグイン。

ページ編集・コメント投稿・ファイル添付など、 PukiWiki 標準の編集機能をスパムから守ります。
Cloudflare Turnstile は不審な送信者を自動判定する不可視の防壁です。煩わしい入力を要求せず、ウィキの使用感に影響しません。(Widget Mode = Invisible の場合)
Google reCAPTCHA v2 や hCaptcha のようにユーザーにインタラクションを求めることも可能です。(Managed の場合)

追加ファイルはこのプラグインのファイル１つだけ。 PukiWiki 本体の変更も最小限にし、なるべく簡単に導入できるようにしています。
ただし、 JavaScript を活用する高度な編集系サードパーティ製プラグインとは相性が悪いかもしれません。
PukiWiki をほぼ素のままで運用し、手軽にスパム対策したいかた向けです。

禁止語句によるスパム判定機能もあります。 Cloudflare Turnstile を使わず、禁止語句判定のみ用いることも可能です。

【導入手順】
以下の手順に沿ってシステムに導入してください。

1) Cloudflare のダッシュボード -> Turnstile より対象 PukiWiki サイトのドメインを登録、取得したサイトキーとシークレットキーとをこのプラグインの定数 PLUGIN_TURNSTILE_SITE_KEY, PLUGIN_TURNSTILE_SECRET_KEY にそれぞれ設定する。

2) PukiWikiスキンファイル（デフォルトは skin/pukiwiki.skin.php）のほぼ末尾、</body>タグの直前に次のコードを挿入する。
   <?php if (exist_plugin_convert('turnstile')) echo do_plugin_convert('turnstile'); // Cloudflare Turnstile plugin ?>

3) PukiWikiライブラリファイル lib/plugin.php の「function do_plugin_action($name)」関数内、「$retvar = call_user_func('plugin_' . $name . '_action');」の直前に次のコードを挿入する。
   if (exist_plugin_action('turnstile') && ($__v = call_user_func_array('plugin_turnstile_action', array($name))['body'])) die_message($__v); // Cloudflare Turnstile plugin

【ご注意】
* PukiWiki 1.5.4 / PHP 8.3.11 / UTF-8 / Chromium 128.0.6613.120, Firefox 129.0.2 で動作確認済み。旧バージョンでも動くかもしれませんが非推奨です。
* 標準プラグイン以外の動作確認はしていません。サードパーティ製プラグインによっては機能が妨げられる場合があります。
* JavaScript が有効でないと動作しません。
* サーバーから Cloudflare Turnstile API へのアクセスに cURL を使用します。
* Cloudflare Turnstile ついて詳しくはドキュメントをご覧ください。 https://developers.cloudflare.com/turnstile/
*/

/////////////////////////////////////////////////
// スパム対策プラグイン設定（turnstile.inc.php）
// Cloudflare Turnstile サイトキー。空の場合、判定は実施されない
if (!defined('PLUGIN_TURNSTILE_SITE_KEY')) {
    define('PLUGIN_TURNSTILE_SITE_KEY', '');
}
// Cloudflare Turnstile シークレットキー。空の場合、判定は実施されない
if (!defined('PLUGIN_TURNSTILE_SECRET_KEY')) {
    define('PLUGIN_TURNSTILE_SECRET_KEY', '');
}
// Cloudflare Turnstile API タイムアウト時間（秒）。0なら PHP 設定に準じる
if (!defined('PLUGIN_TURNSTILE_API_TIMEOUT')) {
    define('PLUGIN_TURNSTILE_API_TIMEOUT', 0);
}
// 投稿禁止語句を表す正規表現（例：'/((https?|ftp)\:\/\/[\w!?\/\+\-_~=;\.,*&@#$%\(\)\'\[\]]+|宣伝文句)/ui'）
if (!defined('PLUGIN_TURNSTILE_CENSORSHIP')) {
    define('PLUGIN_TURNSTILE_CENSORSHIP', '');
}
// 1ならリファラーを参照し自サイト以外からの要求を拒否。リファラーは未送や偽装があり得るため頼るべきではないが、防壁をわずかでも強化したい場合に用いる
if (!defined('PLUGIN_TURNSTILE_CHECK_REFERER')) {
    define('PLUGIN_TURNSTILE_CHECK_REFERER', 0);
}
// 拒否時に返すHTTPステータスコード
if (!defined('PLUGIN_TURNSTILE_ERR_STATUS')) {
    define('PLUGIN_TURNSTILE_ERR_STATUS', 403);
}
// 1なら本プラグインを無効化。メンテナンス用
if (!defined('PLUGIN_TURNSTILE_DISABLED')) {
    define('PLUGIN_TURNSTILE_DISABLED', 0);
}

// プラグイン出力
function plugin_turnstile_convert()
{
    // 本プラグインが無効か書き込み禁止なら何もしない
    if (PLUGIN_TURNSTILE_DISABLED || ((!PLUGIN_TURNSTILE_SITE_KEY || !PLUGIN_TURNSTILE_SECRET_KEY) && !PLUGIN_TURNSTILE_CENSORSHIP) || PKWK_READONLY || !PKWK_ALLOW_JAVASCRIPT) {
        return '';
    }

    // 二重起動禁止
    static $included = false;
    if ($included) {
        return '';
    }
    $included = true;

    $enabled = (PLUGIN_TURNSTILE_SITE_KEY && PLUGIN_TURNSTILE_SECRET_KEY);    // Cloudflare Turnstile 有効フラグ

    // JavaScript
    $siteKey = PLUGIN_TURNSTILE_SITE_KEY;
    $libUrl = '//challenges.cloudflare.com/turnstile/v0/api.js';
    $enabled = ($enabled) ? 'true' : 'false';
    $js = <<<EOT
<script>
'use strict';

window.addEventListener('DOMContentLoaded', () => {
    (new __PluginCloudflareTurnstile__()).update();
});

class __PluginCloudflareTurnstile__ {
    constructor() {
        this.timer = null;
        this.libLoaded = false;
    }

    /* Cloudflare Turnstile ライブラリーロード */
    loadLib() {
        if (!this.libLoaded) {
            this.libLoaded = true;
            const scriptElement = document.createElement('script');
            scriptElement.src = '{$libUrl}';
            scriptElement.setAttribute('defer', 'defer');
            document.body.appendChild(scriptElement);
        }
    }

    /* 設定 */
    setup() {
        const self = this;

        /* 全form要素を走査 */
        var elements = document.getElementsByTagName('form');
        for (var i = elements.length - 1; i >= 0; --i) {
            var form = elements[i];
            var div = document.createElement('div');
            div.className = 'cf-turnstile';
            div.dataset.sitekey = '{$siteKey}';
            form.appendChild(div);
        }

        /* Cloudflare Turnstile ライブラリーロード */
        self.loadLib();
    }

    /* 再設定 */
    update() {
        const self = this;
        if (this.timer) clearTimeout(this.timer);
        this.timer = setTimeout(function() {
            self.setup();
            self.timer = null;
        }, 50);
    }
}
</script>
EOT;

    $body = $js;

    $body = preg_replace("/((\s|\n){1,})/i", ' ', $body);    // 連続空白を単一空白に（※「//」コメント非対応）

    return $body;
}


// 受信リクエスト確認
function plugin_turnstile_action()
{
    $result = '';    // 送信者判定結果（許可：空, 拒否：エラーメッセージ）

    // 機能有効かつPOSTメソッド？
    if (!PLUGIN_TURNSTILE_DISABLED && ((PLUGIN_TURNSTILE_SITE_KEY && PLUGIN_TURNSTILE_SECRET_KEY) || PLUGIN_TURNSTILE_CENSORSHIP) && !PKWK_READONLY && $_SERVER['REQUEST_METHOD'] == 'POST') {
        /* 【対象プラグイン設定テーブル】
           判定の対象とするプラグインを列挙する配列。
           name   … プラグイン名
           censor … 検閲対象パラメーター名
           vars   … 併送パラメーター名
        */
        $targetPlugins = array(
            array('name' => 'article', 'censor' => 'msg'),
            array('name' => 'attach'),
            array('name' => 'bugtrack', 'censor' => 'body'),
            array('name' => 'comment', 'censor' => 'msg'),
            array('name' => 'edit', 'censor' => 'msg', 'vars' => 'write'),    // editプラグインはwriteパラメーター併送（ページ更新）時のみ対象
            array('name' => 'freeze'),
            array('name' => 'insert', 'censor' => 'msg'),
            array('name' => 'loginform'),
            array('name' => 'memo', 'censor' => 'msg'),
            array('name' => 'pcomment', 'censor' => 'msg'),
            array('name' => 'rename'),
            array('name' => 'template'),
            array('name' => 'tracker', 'censor' => 'Messages'),
            array('name' => 'unfreeze'),
            array('name' => 'vote'),
        );

        global $vars;
        list($name) = func_get_args();
        $enabled = (PLUGIN_TURNSTILE_SITE_KEY && PLUGIN_TURNSTILE_SECRET_KEY);    // Cloudflare Turnstile API 有効フラグ

        foreach ($targetPlugins as $target) {
            if ($target['name'] != $name) {
                continue;
            }    // プラグイン名一致？
            if (!isset($target['vars']) || isset($vars[$target['vars']])) {    // クエリーパラメーター未指定、または指定名が含まれる？
                if ($enabled && (!isset($vars['cf-turnstile-response']) || $vars['cf-turnstile-response'] == '')) {    // Cloudflare Turnstile API トークンあり？
                    // トークンのない不正要求なら送信者を拒否
                    $result = 'Rejected by Cloudflare Turnstile (no cf-turnstile-response)';
                } elseif (PLUGIN_TURNSTILE_CHECK_REFERER && strpos($_SERVER['HTTP_REFERER'], get_script_uri()) === false) {
                    // 自サイト以外からのアクセスを拒否
                    $result = 'Deny access';
                } else {
                    // 検閲対象パラメーターあり？
                    if (PLUGIN_TURNSTILE_CENSORSHIP && isset($target['censor']) && isset($vars[$target['censor']])) {
                        // 投稿禁止語句が含まれていたら受信拒否
                        if (preg_match(PLUGIN_TURNSTILE_CENSORSHIP, $vars[$target['censor']])) {
                            $result = 'Forbidden word detected';
                            break;
                        }
                    }

                    // Cloudflare Turnstile API 呼び出し
                    if ($enabled) {
                        $query = http_build_query(array('secret' => PLUGIN_TURNSTILE_SECRET_KEY, 'response' => $vars['cf-turnstile-response'], 'remoteip' => $_SERVER['HTTP_CF_CONNECTING_IP']));
                        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        if (PLUGIN_TURNSTILE_API_TIMEOUT > 0) {
                            curl_setopt($ch, CURLOPT_TIMEOUT, PLUGIN_TURNSTILE_API_TIMEOUT);
                        }
                        $data = json_decode(curl_exec($ch));
                        curl_close($ch);

                        // スコアが閾値未満なら送信者を拒否
                        if (!$data->success) {
                            $result = 'Rejected by Cloudflare Turnstile';
                        }
                    }
                }
                break;
            }
        }

        // エラー用のHTTPステータスコードを設定
        if ($result && PLUGIN_TURNSTILE_ERR_STATUS) {
            http_response_code(PLUGIN_TURNSTILE_ERR_STATUS);
        }
    }

    return array('msg' => 'turnstile', 'body' => $result);
}
