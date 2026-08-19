# FreshRSS Broad Site Icon Fetcher

一个面向最新版 FreshRSS 的第三方扩展，用来扩大订阅源图标的获取范围。

## 支持的来源

按优先级尝试：

1. RSS/Atom 的频道图标：RSSHub 的 `channel.image.url`（XML 中为 `channel/image/url`）
2. `apple-touch-icon`、`icon`、`shortcut icon`、`mask-icon`、`fluid-icon` 和 `image_src`
3. Web App Manifest 的 `icons`
4. `og:image`、Twitter Card 图片和 `meta name="image"`
5. JSON-LD 中的 `image`、`logo`、`icon` 等字段
6. 站点根目录的 `/favicon.ico`

相对 URL、协议相对 URL 和 HTML `<base>` 都会被解析。下载结果交给 FreshRSS 原生的图标校验与存储逻辑处理。

## 安装

将整个 `xExtension-IconFetcher` 目录放入 FreshRSS 的 `extensions/` 目录，然后在“配置 → 扩展”中启用。

本仓库目录名可以直接改成 `xExtension-IconFetcher` 后安装；FreshRSS 要求第三方扩展目录名以 `x` 开头。

## 使用

- 新订阅默认自动获取图标。
- “补齐缺失图标”只处理尚未有本扩展图标文件的订阅。
- “强制刷新扩展图标”会重新请求并覆盖本扩展管理的图标。
- “重置扩展图标”只删除本扩展设置的图标，不会删除用户手动上传的图标。

扩展不会覆盖用户明确设置的自定义图标。网络请求使用 FreshRSS 的 HTTP 工具，因此会继承 FreshRSS 的代理和 HTTP 配置。

## 目录安装名

建议安装为：

```text
extensions/xExtension-IconFetcher/
```

`metadata.json` 的入口类为 `IconFetcherExtension`。

## 许可

GNU AGPL v3.0。
