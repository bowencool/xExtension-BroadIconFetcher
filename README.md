# FreshRSS Broad Site Icon Fetcher

[English](#english) · [简体中文](#简体中文)

## English

A third-party FreshRSS extension that discovers feed icons from more sources than the built-in favicon lookup.

### Supported sources

Sources are tried in this order:

1. RSS/Atom channel images, including RSSHub's `channel.image.url` field (`channel/image/url` in RSS/XML)
2. `apple-touch-icon`, `icon`, `shortcut icon`, `mask-icon`, `fluid-icon`, and `image_src`
3. Web App Manifest `icons`
4. `og:image`, Twitter Card images, and `meta name="image"`
5. JSON-LD `image`, `logo`, `icon`, and related fields
6. The site's `/favicon.ico`

Relative URLs, protocol-relative URLs, and HTML `<base>` elements are supported. Downloaded content is validated and stored by FreshRSS's native favicon logic.

### Installation

Copy the `xExtension-IconFetcher` directory into FreshRSS's `extensions/` directory, then enable it under **Configuration → Extensions**.

The repository root can be renamed to `xExtension-IconFetcher` before installation. FreshRSS expects third-party extension directories to use the `x` prefix.

### Usage

- **New feeds automatically get an icon by default.**
- **Fetch missing icons** only processes feeds without an icon file managed by this extension.
- **Refresh all extension icons** re-fetches and replaces icons managed by this extension.
- **Reset extension icons** removes only icons set by this extension; user-uploaded icons are preserved.

The extension never replaces a favicon explicitly uploaded by the user. Network requests use FreshRSS's HTTP utility and inherit FreshRSS's proxy and HTTP configuration.

### Installation directory

The recommended installation path is:

```text
extensions/xExtension-IconFetcher/
```

The entrypoint declared in `metadata.json` is `IconFetcherExtension`.

### License

GNU AGPL v3.0.

## 简体中文

这是一个 FreshRSS 第三方扩展，用来从更多来源获取订阅源图标，弥补内置 favicon 获取逻辑覆盖范围较窄的问题。

### 支持的来源

按以下顺序尝试：

1. RSS/Atom 频道图标，包括 RSSHub 的 `channel.image.url` 字段（RSS/XML 中为 `channel/image/url`）
2. `apple-touch-icon`、`icon`、`shortcut icon`、`mask-icon`、`fluid-icon` 和 `image_src`
3. Web App Manifest 的 `icons`
4. `og:image`、Twitter Card 图片和 `meta name="image"`
5. JSON-LD 中的 `image`、`logo`、`icon` 等字段
6. 站点根目录的 `/favicon.ico`

支持相对 URL、协议相对 URL 和 HTML `<base>`。下载内容会交给 FreshRSS 原生的图标校验与存储逻辑处理。

### 安装

将 `xExtension-IconFetcher` 目录放入 FreshRSS 的 `extensions/` 目录，然后在“配置 → 扩展”中启用。

安装前可以将仓库根目录改名为 `xExtension-IconFetcher`。FreshRSS 的第三方扩展目录名应使用 `x` 前缀。

### 使用

- **新订阅默认会自动获取图标。**
- **补齐缺失图标**只处理尚未有本扩展图标文件的订阅。
- **强制刷新扩展图标**会重新请求并覆盖本扩展管理的图标。
- **重置扩展图标**只删除本扩展设置的图标，不会删除用户手动上传的图标。

扩展不会覆盖用户明确设置的自定义图标。网络请求使用 FreshRSS 的 HTTP 工具，因此会继承 FreshRSS 的代理和 HTTP 配置。

### 目录安装名

建议安装为：

```text
extensions/xExtension-IconFetcher/
```

`metadata.json` 中声明的入口类为 `IconFetcherExtension`。

### 许可

GNU AGPL v3.0。
