# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-29
- 初回リリース。
- WebP・AVIF への変換（GD / Imagick）、アップロード時の自動変換、既存画像の一括変換（管理画面・WP-CLI）。
- `<picture>` タグによる配信（サーバー設定不要・ページキャッシュ対応）。
- 元画像は削除・変更しない。変換ファイルは「元のファイル名.webp / .avif」として同じフォルダに保存。
- 配信テスト（Content-Type の確認）と、問題のある形式の自動停止。
- 管理画面: 区画ごとのカード・スイッチ・スライダー・ツールチップで整理。スマホ幅に対応。
- 一括変換: 進み具合をサーバーに保存。別のページへ移っても進捗が出続け、停止しても続きから再開できる。
