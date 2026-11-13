# Kashiwazaki Next-Gen Images

![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)
![Version](https://img.shields.io/badge/version-1.0.2-blue.svg)

WordPress のメディアライブラリの画像（JPEG・PNG・GIF・WebP）を、軽い次世代の画像形式 **WebP・AVIF** に変換し、対応しているブラウザにだけ軽い画像を表示するプラグインです。**元の画像は削除も変更もしません。**

📖 マニュアル: [docs/index.html](docs/index.html)

## 主な機能

- **自動変換**: 新しくアップロードした画像を、その場で WebP・AVIF に変換します（時間を区切って変換し、終わらなかった分はあとで続けます）。
- **一括変換**: 既存の画像を管理画面からまとめて変換できます。進み具合を表示し、止めても続きから再開できます。WP-CLI にも対応しています。
- **軽い画像を表示**: ブラウザが使える形式を AVIF → WebP → 元の画像の順に選んで表示します。サーバーの設定ファイルの書き換えは不要で、ページキャッシュとも併用できます。
- **配信テスト**: サーバーが WebP・AVIF を正しく送れるかを確かめ、問題がある形式は自動で表示を止めます。サーバーが種類を正しく送っていないときは、診断タブの「自動で直す」で直せます（画像フォルダの `.htaccess` に種類の指定を書き足します。先にテスト用のフォルダで試し、効かないサーバーでは何も変更しません）。
- **安全な共存**: 元の画像は上書きも削除もしません。他の画像最適化プラグインが作ったファイルも上書き・削除しません。
- **診断画面**: サーバーの対応状況（GD・Imagick・cavif）、変換の実績（減った容量）を確認できます。
- **AVIF を作れないサーバーにも対応（任意）**: サーバーの PHP が AVIF を作れないときは、変換プログラムの cavif を追加して AVIF を作れます。

## 動作要件

- WordPress 5.9 以上
- PHP 7.4 以上
- PHP の GD または Imagick（AVIF は、PHP 8.1 以上で AVIF に対応した GD、または AVIF に対応した Imagick が必要。無ければ WebP だけで動きます。どちらも無いサーバーでは、下の「cavif で AVIF を作る」の方法も使えます）

## インストール

1. このリポジトリのフォルダを `/wp-content/plugins/` にアップロードします。
2. 管理画面の「プラグイン」で「Kashiwazaki Next-Gen Images」を有効化します。
3. 左メニューの「Kashiwazaki Next-Gen Images」を開き、「診断」タブで対応状況を確認します。
4. 既存の画像は「一括変換」タブの「一括変換を開始」で変換します。

## 使い方

- 設定は初期値のままで使えます（WebP の画質 82、AVIF の画質 60、アップロード時に自動変換、記事本文などの画像を切り替え）。
- 変換したファイルは、元のファイル名の後ろに `.webp` / `.avif` を付けた名前で、元の画像と同じフォルダに保存されます（例: `photo.jpg` の隣に `photo.jpg.webp`）。
- 元の表示に戻したいときは、「設定」タブの「変換した画像を表示する」をオフにします。

### WP-CLI

```bash
wp kashiwazaki-next-gen-images status
wp kashiwazaki-next-gen-images convert [<id>...] [--force] [--retry-failed]
```

### cavif で AVIF を作る（任意）

サーバーの PHP（GD・Imagick）が AVIF を作れないときは、変換プログラムの [cavif](https://github.com/kornelski/cavif-rs/releases) をサーバーに置き、設定画面でその場所を入力すると、AVIF を作れるようになります。入力しなければ、これまでと同じ動きで、外部のプログラムは一切実行しません。

1. cavif をサーバーの公開フォルダの外に置きます（ファイルと、それを置いたフォルダの権限は 755 か 700）。
2. 「設定」タブの「詳細設定」→「cavif の場所」に、置いた場所（例: `/home/アカウント名/bin/cavif`）を入力して保存します。欄の下に「使えます」と表示されれば完了です。
3. 「診断」タブで「cavif（外部プログラム）」の AVIF が「○ 利用可」になっていることを確かめます。

場所を入力できるのは、プラグインをインストールできる管理者だけです。入力できるのは、名前が「cavif」で始まるファイルだけです。

場所を設定画面から変えられないように固定したいときは、`wp-config.php` で指定することもできます（こちらが優先されます）。

```php
define( 'KASHIWAZAKI_NEXT_GEN_IMAGES_CAVIF', '/home/アカウント名/bin/cavif' );
```

詳しい手順は、マニュアルの [cavif で AVIF を作る](docs/cavif.html) を参照してください。

次のフィルターで動きを調整できます。

| フィルター | 初期値 | 内容 |
|---|---|---|
| `kashiwazaki_next_gen_images_cavif_threads` | 2 | 同時に使う CPU の数（1〜64） |
| `kashiwazaki_next_gen_images_cavif_speed` | 6 | 変換の速さ（1 = 遅いが小さい 〜 10 = 速いが粗い） |
| `kashiwazaki_next_gen_images_cavif_timeout` | 60（WP-CLI は 300） | 1 枚の変換を打ち切るまでの秒数（1〜600）。1 回の処理の締め切りが近いときは、それより短く打ち切る（その分は失敗に数えず、次の回で続ける） |

## ライセンス

GPL-2.0-or-later。詳しくは [LICENSE](LICENSE) を参照してください。

## 作者

柏崎剛 (Tsuyoshi Kashiwazaki) — https://www.tsuyoshikashiwazaki.jp/profile/
