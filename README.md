# 📋 WhatFUHaveDone — 工作日志与智能助手

一个功能完整的个人工作日志系统，集成了**黄历、八字命理、天气预报**，并搭载**AI 智能助手**（支持 DeepSeek / Ollama），帮助你记录任务、分析工作趋势、管理人际关系。

---

## Quick Start

### 环境要求

- **PHP 8.0+**（需 curl 扩展）
- **MySQL 8.0+**
- **Apache**（或 Nginx + PHP-FPM）
- **pdftotext**（可选，用于简历 PDF 解析）

### 1. 部署代码

```bash
git clone https://github.com/hyphenzhao/WhatFUHaveDone.git
cd WhatFUHaveDone
```

### 2. 创建数据库

```bash
mysql -u root -p < schema.sql
```

### 3. 修改配置

编辑 `config.php`：

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'worklog');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

### 4. 配置 Apache

将项目目录指向 Apache 的 DocumentRoot，确保 `.htaccess` 或 vhost 配置正确：

```apache
<VirtualHost *:8080>
    DocumentRoot /path/to/WhatFUHaveDone
    <Directory /path/to/WhatFUHaveDone>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 5. 启动

访问 `http://localhost:8080`，进入主页。

---

## AI 智能助手配置（推荐 DeepSeek）

### 为什么选 DeepSeek？

| 对比 | DeepSeek | Ollama |
|------|----------|--------|
| 部署 | 无需 GPU，云端调用 | 需本地 GPU |
| 速度 | 快（云端算力） | 取决于本地硬件 |
| 费用 | 极低（¥2/百万 token） | 免费（电费） |
| 推荐模型 | `deepseek-chat` (V3) | `qwen2.5:7b` 或 `llama3.1:8b` |

> 💡 **建议**：追求效果和速度选 DeepSeek；追求隐私和免费选 Ollama。

### 配置 DeepSeek

1. 访问 DeepSeek 官网 [platform.deepseek.com](https://platform.deepseek.com) 注册并获取 **API Key**
   - 新用户通常有免费额度

2. 打开你的 WorkLog → 左侧导航 → **🤖 AI 配置**

3. 填写：
   | 字段 | 值 |
   |------|-----|
   | AI 提供者 | `DeepSeek (云端)` |
   | API 地址 | `https://api.deepseek.com/v1` |
   | API Key | `sk-xxxxxxxxxxxxxxxx` |
   | 模型名称 | `deepseek-chat` |

4. 点击 **🔍 测试连接** → 看到 ✅ 即为成功 → 点击 **💾 保存配置**

5. 回到主页，右侧面板切换到 **🤖 智能助手** Tab，开始对话

### 配置 Ollama（本地）

1. 安装 [Ollama](https://ollama.com/) 并拉取模型：

   ```bash
   ollama pull qwen2.5:7b
   ```

2. 在 **🤖 AI 配置** 页面填写：

   | 字段 | 值 |
   |------|-----|
   | AI 提供者 | `Ollama (本地)` |
   | API 地址 | `http://localhost:11434/v1` |
   | API Key | （留空） |
   | 模型名称 | `qwen2.5:7b` |

3. 测试连接 → 保存

---

## 功能概览

### 🏠 主页

```
┌ 当日状态 ────────────────────────────────────┐
│ ┌天气┐ ┌ 黄历 ┐ ┌ 任务列表 ───────────────┐ │
│ │☀️28°│ │阳历   │ │ 🔄 进行中              │ │
│ │ 晴  │ │农历   │ │ ✅ 阶段性完成           │ │
│ │     │ │干支   │ │ 🎉 已完成              │ │
│ └────┘ │ 📆流日│ │ ❌ 失败/放弃           │ │
│        └──────┘ └─────────────────────────┘ │
├──────────────────────────────────────────────┤
│ 📅 日历（含农历、节气、节假日标注、周末着色）  │
├──────────────────────────────────────────────┤
│ 🔮 八字大运流年（可折叠，AI 解析）              │
├──────────────────────────────────────────────┤
│ 💪 工作量排行 ｜ 🏆 成果排行（点击查看详情）    │
└──────────────────────────────────────────────┘
```

### 🔮 八字命理系统

- **黄历**：阳历 + 农历 + 天干地支（五行着色）+ 节气
- **大运/流年/流月/流日**：基于用户八字实时计算，AI 一键解析
- **个人侧写**（`/profile`）：上传八字文件、紫微命盘，AI 据此个性化分析
- **技能系统**（`/skills`）：内置八字、紫微、奇门遁甲、倪海厦等 6 个知识库

### 🤖 AI 智能助手

- **Plan-then-Execute** 工作流：先规划、再拉取数据、最后综合分析
- **工具调用**：AI 可直接读取/操作系统中的任务、人员、标签等数据
- **写入需确认**：所有创建/修改/删除操作需用户二次确认
- **对话历史**：自动保存、可切换和删除历史对话
- **上下文感知**：自动携带当前查看日期的黄历信息

### 📅 日历功能

- 农历日期 + 节气标注 + 节假日标注（蓝色【假期】）
- 周末着色（周六蓝、周日红）+ 调休【班】标记
- 任务工作量标记点（彩色圆点）
- 手动更新日历数据（`/calendar-admin`）

### 🌤️ 天气预报

- 基于 Open-Meteo 免费 API，无需密钥
- 自动获取今日天气，手动获取往日/未来天气
- 天气主题渐变背景 + 大号 emoji
- 点击城市名可搜索切换城市

---

## 页面导航

| 路径 | 功能 |
|------|------|
| `/` | 主页（日历 + 黄历 + 任务 + AI） |
| `/people` | 人物管理 |
| `/tasks` | 任务管理 |
| `/results` | 成果管理 |
| `/tags` | 标签管理 |
| `/relationships` | 人际关系图谱 |
| `/profile` | 个人侧写（八字/紫微/简历） |
| `/skills` | AI 技能管理 |
| `/calendar-admin` | 日历数据更新 |
| `/ai-admin` | AI 模型配置 |

---

## 数据库表

| 表 | 说明 |
|------|------|
| `people` / `tags` / `results` | 人物、标签、成果 |
| `tasks` / `task_people` / `task_tags` | 任务及关联 |
| `work_logs` / `result_logs` / `plans` | 每日记录 |
| `worklog_notes` | 工作量备注 |
| `calendar_meta` | 农历/节气/节假日缓存 |
| `weather_cache` | 天气缓存 |
| `user_profile` | 个人侧写（八字/目标/简历） |
| `ai_config` / `ai_skills` / `ai_conversations` | AI 配置与对话 |
| `bazi_analysis` | 八字 AI 解析缓存 |

---

## 技术栈

| 层级 | 技术 |
|------|------|
| 后端 | PHP 8.0+（无框架） |
| 数据库 | MySQL 8.0 |
| 前端 | Vanilla JS + CSS3 Grid/Flexbox |
| AI | DeepSeek / Ollama（OpenAI 兼容 API） |
| 命理引擎 | lunar-javascript（6tail） |
| 天气 | Open-Meteo API |
| 节日 | chinese-days CDN |

## License

MIT
