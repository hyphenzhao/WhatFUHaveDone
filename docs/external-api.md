# 外部 AI 只读 API / MCP

现有 `/api/tasks`、`/api/mail` 等接口主要服务网页：使用登录 Cookie，接口较多，有些直接返回完整文本。外部助手使用独立的 Bearer 只读接口，可通过 MCP 自动发现三个工具，不必浏览网页、理解数据库或调用站内 AI。

## 接入 Codex

1. 打开系统管理 → **外部 AI 接入**（`/external-access`），选择资料类别和有效期，创建密钥。完整密钥只显示一次；遗失后撤销并重建。请勿把密钥发进 AI 对话或提交 Git。
2. 在运行 Codex 的进程环境中设置 `WORKLOG_API_TOKEN`。已有进程不会自动获得后设置的环境变量，需要重新启动该进程。CLI 可在 PowerShell 中交互输入，避免明文命令历史：

   ```powershell
   $worklogSecret = Read-Host 'WorkLog API key' -AsSecureString
   $worklogCredential = [System.Net.NetworkCredential]::new('', $worklogSecret)
   $env:WORKLOG_API_TOKEN = $worklogCredential.Password
   Remove-Variable worklogCredential, worklogSecret
   codex
   ```

3. 将页面给出的配置加入 Codex 的 MCP 配置（通常为 `~/.codex/config.toml`）：

   ```toml
   [mcp_servers.worklog]
   url = "http://192.168.50.6:8080/api/external/mcp"
   bearer_token_env_var = "WORKLOG_API_TOKEN"
   ```

   这是 Streamable HTTP MCP，密钥从环境变量读取。配置方式参见 [Codex 官方 MCP 文档](https://learn.chatgpt.com/docs/extend/mcp?surface=cli)。这里不需要 OpenAI API key。Codex 所在主机必须能够访问网站；公网接入请使用 HTTPS 或 VPN，不要直接暴露当前 HTTP 服务。

4. 连接后可以说：“读取 WorkLog，分析本周进展和待办安排；必要时查阅我的简历和相关邮件。”

## 最少调用的工作流

| 工具 | 用途 | 默认读取量 |
| --- | --- | --- |
| `worklog_overview` | 今日或指定期间的任务、工作总量、日志/计划/邮件样本、已有报告、身份资料引用 | 活跃任务 6 条；各日常类别 4 条；报告 3 条；主身份文档和印象各 1 条 |
| `worklog_search` | 按类别、日期、关键词定位证据，或遍历全部历史 | 每页 12 条，最多 30 条；每条摘要最多 160 个字符 |
| `worklog_read` | 批量读取正文、已有分析或摘要 | 每批 1–8 个引用；每条正文默认 2000 字符 |

通常先调用一次概览，再批量读取与问题有关的引用。问题明确时可直接检索。不要为简单问题拉取全部邮件和全部聊天。

示例参数：

```json
{"date_from":"2026-10-05","date_to":"2026-10-11"}
```

```json
{"kind":"mail","query":"会议","date_from":"2026-10-05","limit":5}
```

```json
{"refs":["mail:123","mail:456"],"part":"analysis","max_chars":2000}
```

每个返回项有稳定引用，如 `task:42`、`mail:123`、`profile_document:7`。AI 回答时应保留这些引用，重要结论回查原文。相关任务详情附有标签、成果以及授权范围内的人物、邮件和附件引用。

### 完整性与缓存

- **概览是样本，不是全量**。每组返回 `sample` 和 `has_more`。概览按重要性取样；完整回顾应重新用 `search`，携带相同筛选条件，不断将 `next_before_id` 传为 `before_id`，直到 `has_more=false`。
- `search` 按记录 ID 倒序，分页无需扫描越来越大的 OFFSET。它不是数据库快照：数据在读取期间可能变化；历史补录的 ID 顺序也不代表发生日期顺序。
- `read` 的 `offset` 从 0 开始，按 Unicode 字符计算；`next_offset` 非空说明正文尚未读完。单项 `max_chars` 为 100–8000，`refs 数量 × max_chars` 不得超过 16000。这是**正文字符预算**，不是包含元数据的硬 Token 上限。
- `excerpt_truncated`、`text_chars`、`text_available` 明确区分节选、全文和未提取出文本。附件/身份文档的空文本不代表文件没有内容；需要在站内提取或重新上传可提取的文档。
- 每次调用返回 `version`。客户端已保留上次同一请求结果时，带上 `if_version`，内容相同则只返回 `{"version":"…","unchanged":true}`。上下文中没有旧结果时，不应使用此参数。
- 版本对应**本次查询投影**，不是全库的变更序号；未展示的正文后半段变化不一定改变摘要版本。它减少重复响应，不缓存 SQL，也不是增量备份/删除事件接口。
- 无任何新增摘要推理、站内 AI 调用或邮件同步。已有邮件 AI 分析失败/缺失时返回原始节选；请检查 `analysis_status`，不能把节选当成 AI 分析。

## 资料类别与权限

| scope | 可查询 kind | 日期筛选含义 |
| --- | --- | --- |
| `work` | `task`, `worklog`, `note`, `plan`, `result`, `result_log`, `tag` | 工作日志/备注/成果记录按记录日；计划按计划日；任务/成果/标签按最后修改日 |
| `mail` | `mail` | 未读邮件归今日；已读邮件按已读日（旧数据回退发送日）。原发送时间另见 `sent_at` |
| `reports` | `report` | 检索按报告起始日；概览选取与请求期间相交的报告 |
| `profile` | `profile_document`, `profile`, `impression`, `profile_snapshot` | 文档/简介/字段印象按修改时间，快照按生成时间 |
| `contacts` | `person` | 最后修改日 |
| `attachments` | `attachment` | 附件创建日；还必须有附件所属任务/邮件/报告的权限 |
| `conversations` | `conversation` | 最后修改日；搜索只查标题和已有摘要，原始聊天需显式读取正文 |

用户上传文档标记 `authority=user_uploaded`，是身份判断的权威来源；印象为 `secondary_impression`。个性化分析应先读相关文档，再结合最新基础/阶段印象及其后的增量印象。概览中的印象锚点选基础与阶段快照中最新的一份。

不开放 AI 密钥、邮箱密码、授权码、会话令牌、服务器路径、二进制下载，也不提供发送邮件、写入数据、触发同步或 AI 推理的工具。站内历法、天气、命理模块和技能配置不在此研究资料 API 中。

## HTTP API（不使用 MCP 时）

公共前缀：`/api/external/v1`；请求头：`Authorization: Bearer <密钥>`。不依赖登录 Cookie，密钥不能用于原有网页写入接口。

| 方法和路径 | 参数 |
| --- | --- |
| `GET /manifest` | 返回已授权 scope、三个工具的 JSON Schema |
| `GET /overview` | `date_from?`, `date_to?`, `if_version?`；默认今日，范围最多相差 366 天 |
| `GET /search` | `kind`, `query?`, `date_from?`, `date_to?`, `limit?`, `before_id?`, `task_id?`, `parent_ref?`, `archived?`, `if_version?` |
| `POST /read` | JSON：`refs`, `part?`, `offset?`, `max_chars?`, `if_version?` |

`task_id` 适用于任务、日志、备注、计划、成果记录；`parent_ref` 仅适用于附件，值为 `task:N` / `note:N` / `result:N` / `mail:N` / `report:N`。`archived=0/1` 适用于任务、人物、标签、成果。关键词为字面子串，`%`、`_` 不会变成 SQL 通配符。

`part=body` 是原文；`part=analysis` 仅适用于邮件的已有详细分析；`part=summary` 对邮件/聊天取已有摘要，其他类型取正文。批量读取不允许混入未授权类别（整个调用返回权限错误）；不存在或不属于当前用户的记录只返回 `not_found`。

```powershell
$worklogHeaders = @{ Authorization = "Bearer $env:WORKLOG_API_TOKEN" }
$overview = Invoke-RestMethod -Uri 'http://192.168.50.6:8080/api/external/v1/overview' -Headers $worklogHeaders
$overview.data
# 引用必须取自 overview/search，不要猜测 ID。
$request = @{ refs = @($overview.data.active_tasks.items[0].ref); max_chars = 2000 } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri 'http://192.168.50.6:8080/api/external/v1/read' -Headers $worklogHeaders -ContentType 'application/json' -Body $request
```

成功响应是紧凑 JSON `{version,data}`，不是原网页 API 的 `{error,message,data}` 包装。GET 同时支持 `ETag` / `If-None-Match`，命中为 HTTP 304。浏览器自动缓存禁用；客户端应显式保留先前结果再用条件请求。

错误状态：400 参数错误，401 无效/到期/撤销密钥，403 权限或 Origin 拒绝，404 未知操作，405 不支持的方法，413 请求超过 64 KiB，415 MCP 内容类型错误，429 超过每密钥每分钟 120 次（`Retry-After: 60`），500 服务不可用。多用户和附件归属均在数据库查询中验证。

## MCP 传输与维护

`POST /api/external/mcp` 实现 [MCP 2025-06-18 Streamable HTTP](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports) 的无状态 JSON 响应模式。支持 `initialize`、`ping`、`tools/list`、`tools/call`；通知返回 202。无需 SSE、会话 ID 或服务端回调；GET/DELETE 返回 405。协商版本为 `2025-06-18`，后续请求应发送该 `MCP-Protocol-Version`。不接受浏览器 Origin，使用原生 MCP/HTTP 客户端。MCP 工具业务错误通过 `isError=true` 返回。

数据库部署：新安装已包含在 `schema.sql`；旧安装执行 `migrations/011_external_api.sql`（只增加密钥表，不生成密钥）。`.htaccess` 传递 Authorization 给 PHP；改用 Nginx 等服务器时也需传递该请求头。

检查命令：

```sh
php scripts/test_external_api.php          # 两个合成用户，事务回滚
php scripts/test_external_api.php --http   # 另建临时用户，经 localhost:8080 验证 HTTP/认证/MCP，然后删除
node --check assets/js/external-access.js
```

生成/撤销密钥使用站内登录和 CSRF 校验；密钥仅保存 SHA-256 摘要、名称、前缀、权限、有效期和使用统计。外部调用只更新密钥使用计数，不修改科研资料。不记录请求正文或返回内容。
