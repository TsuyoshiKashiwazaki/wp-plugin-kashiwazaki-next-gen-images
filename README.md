# Kashiwazaki Next-Gen Images

![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)
![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)

WordPress のメディアライブラリの画像（JPEG・PNG・GIF・WebP）を、軽い次世代の画像形式 **WebP・AVIF** に変換し、対応しているブラウザにだけ軽い画像を表示するプラグインです。**元の画像は削除も変更もしません。**

📖 マニュアル: [docs/index.html](docs/index.html)

## 主な機能

- **自動変換**: 新しくアップロードした画像を、その場で WebP・AVIF に変換します（時間を区切って変換し、終わらなかった分はあとで続けます）。
- **一括変換**: 既存の画像を管理画面からまとめて変換できます。進み具合を表示し、止めても続きから再開できます。WP-CLI にも対応しています。
- **軽い画像を表示**: ブラウザが使える形式を AVIF → WebP → 元の画像の順に選んで表示します。サーバーの設定ファイルの書き換えは不要で、ページキャッシュとも併用できます。
- **配信テスト**: サーバーが WebP・AVIF を正しく送れるかを確かめ、問題がある形式は自動で表示を止めます。
- **安全な共存**: 元の画像は上書きも削除もしません。他の画像最適化プラグインが作ったファイルも上書き・削除しません。
- **診断画面**: サーバーの対応状況（GD・Imagick）、変換の実績（減った容量）を確認できます。

## 動作要件

- WordPress 5.9 以上
- PHP 7.4 以上
- PHP の GD または Imagick（AVIF は、PHP 8.1 以上で AVIF に対応した GD、または AVIF に対応した Imagick が必要。無ければ WebP だけで動きます）

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

## ライセンス

GPL-2.0-or-later。詳しくは [LICENSE](LICENSE) を参照してください。

## 作者

柏崎剛 (Tsuyoshi Kashiwazaki) — https://www.tsuyoshikashiwazaki.jp/profile/
