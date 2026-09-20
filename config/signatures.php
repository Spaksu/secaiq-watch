<?php
/**
 * SecAIQ Watch — signature definitions.
 * To add a new tool/provider just edit this file.
 */
require_once dirname(__DIR__) . '/src/Platform.php';

$sig = [
    // First match on the process command line (path + arguments) wins — order matters.
    'tools' => [
        // key => [ad, kategori, regex]
        'claude-desktop' => ['Claude Desktop',         'Chat app', '#/Claude\.app/#i'],
        'claude-code'    => ['Claude Code (CLI)',      'Coding agent',      '#(^|/)claude(\s|$)|/\.claude/(local|versions)/|@anthropic-ai/claude-code#i'],
        'chatgpt'        => ['ChatGPT / Codex App', 'Chat app', '#/ChatGPT\.app/#i'],
        'codex'          => ['Codex CLI / Computer Use', 'Coding agent',   '#/\.codex/|(^|/)codex(\s|$)#i'],
        'cursor'         => ['Cursor',                 'AI editor',         '#^(?!/System/).*/Cursor\.app/#i'],
        'windsurf'       => ['Windsurf / Codeium',     'AI editor',         '#/Windsurf\.app/|codeium#i'],
        'copilot'        => ['GitHub Copilot',         'Code completion',     '#copilot-language-server|github\.copilot|copilot-agent#i'],
        'gemini-app'     => ['Gemini App',              'Chat app', '#/Gemini\.app/#i'],
        'gemini-cli'     => ['Gemini CLI',             'Coding agent',     '#@google/gemini-cli|(^|/)gemini(\s|$)#i'],
        'perplexity'     => ['Perplexity / Comet',     'Chat app', '#/(Perplexity|Comet)\.app/#i'],
        'ollama'         => ['Ollama',                 'Local model',       '#/Ollama\.app/|(^|/)ollama(\s|$)#i'],
        'lmstudio'       => ['LM Studio',              'Local model',       '#/LM Studio\.app/#i'],
        'local-llm'      => ['Local model server',    'Local model',       '#llama-server|llama\.cpp|mlx_lm|vllm|text-generation-server#i'],
        'jan'            => ['Jan',                    'Local model',       '#/Jan\.app/#i'],
        'aider'          => ['Aider',                  'Coding agent',     '#(^|/)aider(\s|$)#i'],
        // --- more coding agents / AI editors / CLIs
        'opencode'       => ['OpenCode',               'Coding agent',      '#(^|/)opencode(\s|$)|/\.opencode/#i'],
        'antigravity'    => ['Google Antigravity',     'AI editor',         '#/Antigravity\.app/|\.antigravity#i'],
        'zed'            => ['Zed',                    'AI editor',         '#/Zed\.app/#i'],
        'kiro'           => ['Kiro / Amazon Q',        'AI editor',         '#/Kiro\.app/|(^|/)kiro-cli(\s|$)|amazon-q|amazonwebservices\.amazon-q#i'],
        'warp'           => ['Warp (AI terminal)',     'AI terminal',       '#/Warp\.app/#i'],
        'cline'          => ['Cline / Roo / Kilo',     'Coding agent',      '#extensions/(saoudrizwan\.claude-dev|rooveterinaryinc\.roo-cline|kilocode\.kilo-code)#i'],
        'cody'           => ['Sourcegraph Cody',       'Code completion',   '#sourcegraph\.cody|(^|/)cody(\s|$)#i'],
        'continue'       => ['Continue',               'Coding agent',      '#extensions/continue\.continue|/\.continue/#i'],
        'tabnine'        => ['Tabnine',                'Code completion',   '#tabnine#i'],
        'goose'          => ['Goose',                  'Coding agent',      '#(^|/)goose(\s|$)|/Goose\.app/#i'],
        'openhands'      => ['OpenHands / Devin',      'Coding agent',      '#openhands|opendevin|(^|/)devin(\s|$)#i'],
        'crush'          => ['Crush',                  'Coding agent',      '#(^|/)crush(\s|$)#i'],
        'qwen-code'      => ['Qwen Code / Kimi CLI',   'Coding agent',      '#@qwen-code|qwen-code|kimi-cli#i'],
        'agent-frameworks' => ['Agent frameworks',     'Agent framework',   '#(python[\d.]*|node|uv|uvx)\b.*(langgraph|langchain|crewai|autogen|autogpt|openclaw|llama[-_]index|smolagents|pydantic[-_]ai|openai-agents)#i'],
        // --- AI browsers / chat apps
        'dia'            => ['Dia / Atlas browser',    'AI browser',        '#/(Dia|ChatGPT Atlas)\.app/#i'],
        // --- local model runtimes / image generation
        'gpt4all'        => ['GPT4All',                'Local model',       '#/GPT4All\.app/|gpt4all#i'],
        'msty'           => ['Msty',                   'Local model',       '#/Msty\.app/#i'],
        'anythingllm'    => ['AnythingLLM',            'Local model',       '#/AnythingLLM\.app/#i'],
        'llamafile'      => ['llamafile / llama.cpp',  'Local model',       '#\.llamafile|llama-cli|llama-server|koboldcpp#i'],
        'webui'          => ['Open WebUI / text-gen-webui', 'Local model',  '#open-webui|text-generation-webui|oobabooga#i'],
        'imagegen'       => ['Image generation (ComfyUI, Draw Things…)', 'Image generation', '#comfyui|/Draw Things\.app/|/DiffusionBee\.app/|stable-diffusion-webui#i'],
        'mcp'            => ['MCP servers',           'MCP',               '#mcp-server|@modelcontextprotocol|mcp_server|(^|/)mcp(\s|$)|chrome-native-host|/mcp-[a-z0-9-]+(\s|$)|(uvx|npx)\s.*mcp#i'],
    ],

    // Known AI provider domains. shared=true → the IP may be shared with other sites behind a CDN.
    'providers' => [
        'OpenAI'         => ['shared' => true,  'domains' => ['api.openai.com', 'chatgpt.com', 'chat.openai.com', 'auth.openai.com']],
        'Anthropic'      => ['shared' => false, 'domains' => ['api.anthropic.com', 'claude.ai', 'console.anthropic.com', 'statsig.anthropic.com', 'mcp-proxy.anthropic.com']],
        'Google Gemini'  => ['shared' => true,  'domains' => ['generativelanguage.googleapis.com', 'gemini.google.com', 'aistudio.google.com', 'aiplatform.googleapis.com']],
        'GitHub Copilot' => ['shared' => true,  'domains' => ['api.githubcopilot.com', 'copilot-proxy.githubusercontent.com']],
        'Cursor'         => ['shared' => false, 'domains' => ['api2.cursor.sh', 'api.cursor.sh']],
        'Codeium'        => ['shared' => false, 'domains' => ['server.codeium.com']],
        'Mistral'        => ['shared' => true,  'domains' => ['api.mistral.ai']],
        'Cohere'         => ['shared' => true,  'domains' => ['api.cohere.com']],
        'Groq'           => ['shared' => true,  'domains' => ['api.groq.com']],
        'Perplexity'     => ['shared' => true,  'domains' => ['api.perplexity.ai', 'www.perplexity.ai']],
        'xAI'            => ['shared' => true,  'domains' => ['api.x.ai']],
        'DeepSeek'       => ['shared' => true,  'domains' => ['api.deepseek.com']],
        'OpenRouter'     => ['shared' => true,  'domains' => ['openrouter.ai']],
        'Together'       => ['shared' => true,  'domains' => ['api.together.xyz']],
        'Fireworks'      => ['shared' => true,  'domains' => ['api.fireworks.ai']],
        'Hugging Face'   => ['shared' => true,  'domains' => ['huggingface.co', 'api-inference.huggingface.co']],
        'Replicate'      => ['shared' => true,  'domains' => ['api.replicate.com']],
        'Cerebras'       => ['shared' => true,  'domains' => ['api.cerebras.ai']],
        'NVIDIA NIM'     => ['shared' => true,  'domains' => ['integrate.api.nvidia.com']],
        'GitHub Models'  => ['shared' => true,  'domains' => ['models.github.ai', 'models.inference.ai.azure.com']],
        'Moonshot (Kimi)' => ['shared' => true, 'domains' => ['api.moonshot.ai', 'api.moonshot.cn']],
        'Alibaba Qwen'   => ['shared' => true,  'domains' => ['dashscope.aliyuncs.com', 'dashscope-intl.aliyuncs.com']],
        'Zhipu (GLM)'    => ['shared' => true,  'domains' => ['open.bigmodel.cn', 'api.z.ai']],
        'AI21'           => ['shared' => true,  'domains' => ['api.ai21.com']],
        'Voyage AI'      => ['shared' => true,  'domains' => ['api.voyageai.com']],
        'ElevenLabs'     => ['shared' => true,  'domains' => ['api.elevenlabs.io']],
        'Stability AI'   => ['shared' => true,  'domains' => ['api.stability.ai']],
        'Windsurf'       => ['shared' => false, 'domains' => ['server.self-serve.windsurf.com']],
        'Poe'            => ['shared' => true,  'domains' => ['poe.com']],
        'Grok'           => ['shared' => true,  'domains' => ['grok.com']],
        'DeepSeek Chat'  => ['shared' => true,  'domains' => ['chat.deepseek.com']],
        'Mistral Le Chat' => ['shared' => true, 'domains' => ['chat.mistral.ai']],
        'AWS Bedrock'    => ['shared' => true,  'domains' => ['bedrock-runtime.us-east-1.amazonaws.com', 'bedrock-runtime.eu-central-1.amazonaws.com']],
    ],

    // AI browser extensions: matched on the extension name/description (manifest only — never page or account data)
    'ai_extension_regex' => '#claude|chatgpt|\bgpt\b|openai|anthropic|gemini|copilot|perplexity|\bAI\b|\bLLM\b|grok|deepseek|ollama|mistral|sider|monica|merlin|maxai#i',
    'browser_profiles' => [
        'Chrome'  => 'Library/Application Support/Google/Chrome',
        'Brave'   => 'Library/Application Support/BraveSoftware/Brave-Browser',
        'Edge'    => 'Library/Application Support/Microsoft Edge',
        'Arc'     => 'Library/Application Support/Arc/User Data',
        'Chromium' => 'Library/Application Support/Chromium',
    ],

    // "Protect" presets: Claude Code permissions.deny rules per area (written to ~/.claude/settings.json).
    // Claude Code rule syntax: Tool(path-glob); "~/" means the home directory.
    'deny_rules' => [
        'ssh'      => ['Read(~/.ssh/**)', 'Edit(~/.ssh/**)', 'Write(~/.ssh/**)'],
        'cloud'    => ['Read(~/.aws/**)', 'Read(~/.kube/**)', 'Read(~/.azure/**)', 'Read(~/.config/gcloud/**)', 'Read(~/.config/gh/**)', 'Read(~/.docker/config.json)'],
        'secrets'  => ['Read(**/.env)', 'Read(**/.env.*)', 'Read(**/*.pem)', 'Read(~/.npmrc)', 'Read(~/.netrc)', 'Read(~/.git-credentials)'],
        'keychain' => ['Read(~/Library/Keychains/**)'],
        'gpg'      => ['Read(~/.gnupg/**)'],
        'browser'  => ['Read(~/Library/Application Support/Google/Chrome/**)', 'Read(~/Library/Cookies/**)'],
        'messages' => ['Read(~/Library/Messages/**)', 'Read(~/Library/Mail/**)'],
        'destructive' => ['Bash(rm -rf:*)', 'Bash(rm -fr:*)', 'Bash(sudo:*)', 'Bash(git push --force:*)', 'Bash(git push -f:*)', 'Bash(git reset --hard:*)', 'Bash(dd:*)', 'Bash(mkfs:*)', 'Bash(chmod -R 777:*)'],
    ],
    // Protect rows that are not file areas
    'deny_meta' => [
        'destructive' => ['label' => 'Destructive shell commands (rm -rf, sudo, force-push…)', 'sev' => 'high', 'icon' => '💥'],
    ],
    // One-click protection levels = sets of the presets above
    'protection_presets' => [
        'developer' => ['label' => 'Developer', 'desc' => 'Keys only: SSH, keychain, GPG.', 'keys' => ['ssh', 'keychain', 'gpg']],
        'balanced'  => ['label' => 'Balanced',  'desc' => 'Keys, cloud credentials, .env files and destructive commands.', 'keys' => ['ssh', 'cloud', 'secrets', 'keychain', 'gpg', 'destructive']],
        'strict'    => ['label' => 'Strict',    'desc' => 'Everything in Balanced plus browser data and messages/mail.', 'keys' => ['ssh', 'cloud', 'secrets', 'keychain', 'gpg', 'browser', 'messages', 'destructive']],
    ],


    // Estimated pricing per model (USD per 1M tokens): regex on model id => [input, output, cache-read multiplier, cache-write multiplier].
    // Rates from Anthropic's public price list (cached 2026-06-24) — an ESTIMATE; edit here if prices change. Unlisted models show tokens only.
    'pricing' => [
        '#fable-5|mythos-5#'          => [10, 50, 0.025, 1.25],
        '#opus-(5|4-[5-9])#'          => [5, 25, 0.1, 1.25],
        '#opus-4(-1)?-2\d{7}#'        => [15, 75, 0.1, 1.25],
        '#sonnet-5#'                  => [2, 10, 0.1, 1.25],
        '#sonnet-(3|4)#'              => [3, 15, 0.1, 1.25],
        '#haiku-4#'                   => [1, 5, 0.1, 1.25],
    ],

    // Logo mapping (candidate list; the first existing file is used). Otherwise the UI draws a letter badge.
    'icons' => [
        'claude-desktop' => ['img/tools/claude-desktop.png', 'img/brands/claude.svg'],
        'claude-code'    => ['img/brands/claude.svg'],
        'chatgpt'        => ['img/tools/chatgpt.png', 'img/brands/openai.svg'],
        'codex'          => ['img/brands/openai.svg'],
        'cursor'         => ['img/tools/cursor.png'],
        'windsurf'       => ['img/tools/windsurf.png'],
        'copilot'        => ['img/brands/githubcopilot.svg'],
        'gemini-app'     => ['img/tools/gemini-app.png', 'img/brands/googlegemini.svg'],
        'gemini-cli'     => ['img/brands/googlegemini.svg'],
        'perplexity'     => ['img/tools/perplexity.png', 'img/brands/perplexity.svg'],
        'ollama'         => ['img/tools/ollama.png', 'img/brands/ollama.svg'],
        'lmstudio'       => ['img/tools/lmstudio.png'],
        'jan'            => ['img/tools/jan.png'],
        'antigravity' => ['img/tools/antigravity.png'],
        'zed' => ['img/tools/zed.png'],
        'kiro' => ['img/tools/kiro.png'],
        'warp' => ['img/tools/warp.png'],
        'dia' => ['img/tools/dia.png'],
        'gpt4all' => ['img/tools/gpt4all.png'],
        'msty' => ['img/tools/msty.png'],
        'anythingllm' => ['img/tools/anythingllm.png'],
        'opencode' => ['img/tools/opencode.png'],
        'goose' => ['img/tools/goose.png'],
        // providers
        'p:OpenAI'         => ['img/brands/openai.svg'],
        'p:Anthropic'      => ['img/brands/anthropic.svg'],
        'p:Google Gemini'  => ['img/brands/googlegemini.svg'],
        'p:GitHub Copilot' => ['img/brands/githubcopilot.svg'],
        'p:Perplexity'     => ['img/brands/perplexity.svg'],
        'p:Hugging Face'   => ['img/brands/huggingface.svg'],
        'p:Local Ollama'   => ['img/brands/ollama.svg'],
    ],

    // Local model servers (loopback destination port → name)
    'local_ports' => [11434 => 'Local Ollama', 1234 => 'Local LM Studio'],

    // Browsers: shared-IP matches create noise, so they do not count as "likely" evidence.
    'browsers' => '#/(Google Chrome|Safari|Firefox|Arc|Brave Browser|Microsoft Edge|Opera|Vivaldi)\b|Chrome Helper|com\.apple\.WebKit#i',

    // Critical area catalog: key => [name, severity, icon, path-regex|null, group]
    // Entries with a path regex are classified as "observed" from open files (only the file PATH is matched).
    // Entries with null only come from permission sources (settings files, permission database).
    'areas' => [
        'ssh'           => ['SSH keys',                     'critical', '🔑', '#/\.ssh/|id_rsa|id_ed25519|id_ecdsa#i', 'data'],
        'cloud'         => ['Cloud / CLI credentials',        'critical', '☁️', '#/\.aws/|/\.kube/|/\.azure/|/gcloud/|/\.config/gh/|/\.docker/config#i', 'data'],
        'secrets'       => ['.env & secret files',              'critical', '🗝️', '#(^|/)\.env(\.|$)|\.pem$|\.p12$|/\.npmrc$|\.git-credentials|/\.netrc$|secrets?\.(json|ya?ml)$|credentials(\.json)?$#i', 'data'],
        'keychain'      => ['Keychain / password manager', 'critical', '🔐', '#/Keychains/|1Password|Bitwarden|KeePass|/keyrings/|\.password-store|Microsoft/(Credentials|Protect|Vault)/#i', 'data'],
        'wallet'        => ['Crypto wallet',                       'critical', '🪙', '#wallet|/\.bitcoin|electrum|metamask#i', 'data'],
        'gpg'           => ['GPG keys',                     'critical', '🧷', '#/\.gnupg/#i', 'data'],
        'browser'       => ['Browser data (cookies/passwords)',    'high', '🌐', '#Login Data|/Cookies|Web Data|/Local Storage|/\.mozilla/.*(logins\.json|key4\.db|cookies\.sqlite)#i', 'data'],
        'messages'      => ['Messages / email',                     'high', '💬', '#/Library/(Messages|Mail)/|(Application Support|\.config|AppData/Roaming)/(Slack|Signal|Telegram|WhatsApp|discord|Thunderbird)#i', 'data'],
        'docs'          => ['Documents / Desktop / Downloads',  'high', '📄', '#^(?:[A-Za-z]:)?/(?:Users|home)/[^/]+/(Documents|Desktop|Downloads)(/|$)#i', 'data'],
        'db'            => ['Database files',                'high', '🗄️', '#\.(sqlite3?|db|sql)(-wal|-shm)?$|/var/mysql/#i', 'data'],
        'system'        => ['System / shell config',       'high', '⚙️', '#^/etc/|LaunchAgents|LaunchDaemons|/\.(zshrc|zprofile|bash_profile|bashrc|profile)$|/systemd/|^[A-Za-z]:/Windows/|PowerShell/.*profile\.ps1$#i', 'data'],
        'source'        => ['Source code / working folder',        'medium',   '💻', null, 'data'],
        'fulldisk'      => ['Full Disk Access',                    'critical', '💽', null, 'capability'],
        'accessibility' => ['Accessibility (UI control)',   'critical', '🖱️', null, 'capability'],
        'screen'        => ['Screen recording',                         'critical', '🖥️', null, 'capability'],
        'input'         => ['Keyboard / mouse monitoring',                'critical', '⌨️', null, 'capability'],
        'shell'         => ['Command execution (shell)',            'high', '💲', null, 'capability'],
        'automation'    => ['App automation',                 'high', '🤖', null, 'capability'],
        'mcp'           => ['MCP / external tools',                'high', '🔌', null, 'capability'],
        'camera'        => ['Camera',                              'high', '📷', null, 'capability'],
        'mic'           => ['Microphone',                            'high', '🎙️', null, 'capability'],
        'personal'      => ['Contacts / Calendar / Photos',      'medium',   '👤', null, 'capability'],
    ],

    // macOS TCC service → area (only used when scan_system is on)
    'tcc_services' => [
        'kTCCServiceSystemPolicyAllFiles' => 'fulldisk', 'kTCCServiceAccessibility' => 'accessibility',
        'kTCCServiceScreenCapture' => 'screen', 'kTCCServiceListenEvent' => 'input', 'kTCCServicePostEvent' => 'input',
        'kTCCServiceAppleEvents' => 'automation', 'kTCCServiceCamera' => 'camera', 'kTCCServiceMicrophone' => 'mic',
        'kTCCServiceAddressBook' => 'personal', 'kTCCServiceCalendar' => 'personal', 'kTCCServicePhotos' => 'personal',
        'kTCCServiceSystemPolicyDocumentsFolder' => 'docs', 'kTCCServiceSystemPolicyDesktopFolder' => 'docs',
        'kTCCServiceSystemPolicyDownloadsFolder' => 'docs',
    ],
    // TCC "client" (bundle id / path) → tool key. First match wins.
    'tcc_clients' => [
        'claude-code'    => '#/bin/claude$|/\.claude/#i',
        'claude-desktop' => '#anthropic|Claude\.app#i',
        'chatgpt'        => '#com\.openai|ChatGPT|codex#i',
        'cursor'         => '#cursor|todesktop#i',
        'windsurf'       => '#windsurf|codeium#i',
        'ollama'         => '#ollama#i',
        'gemini-app'     => '#google\.gemini|Gemini\.app#i',
        'lmstudio'       => '#lmstudio|LM Studio#i',
        'perplexity'     => '#perplexity|comet#i',
    ],
    // CLI agents (claude, codex, ...) inherit the terminal/editor permissions
    'tcc_hosts' => '#com\.apple\.Terminal|iterm|com\.microsoft\.VSCode|dev\.warp|ghostty|kitty|alacritty#i',
    'cli_tools' => ['claude-code', 'codex', 'gemini-cli', 'aider'],

    // For "theoretical access": area => if one of these paths exists (existence check only, ~ = home directory)
    'area_paths' => [
        'ssh' => ['~/.ssh'], 'cloud' => ['~/.aws', '~/.kube', '~/.azure', '~/.config/gcloud'],
        'gpg' => ['~/.gnupg'], 'keychain' => ['~/Library/Keychains'],
        'browser' => ['~/Library/Application Support/Google/Chrome'], 'messages' => ['~/Library/Messages'],
        'docs' => ['~/Documents', '~/Desktop', '~/Downloads'], 'system' => ['~/.zshrc'],
    ],

    // Paths ignored during the file scan
    'file_ignore' => '#^/(System|usr|dev|sys|run|lib|lib64|bin|sbin|snap|private/var/db|private/var/folders|Library/(Apple|Preferences))|^[A-Za-z]:/Windows|\.app/Contents/|/Library/Caches/|\.(dylib|so|nib|car|icns|pak|framework|lproj)$|/\.cache/|/proc/#i',
];

// macOS: unchanged. Linux/Windows: process regexes, browser profiles and deny rules adapted (see Platform::adapt).
return Platform::adapt($sig);
