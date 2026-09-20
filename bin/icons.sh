#!/bin/bash
# Extracts real icons from installed apps and downloads brand logos (simple-icons) locally.
DIR="$(cd "$(dirname "$0")/.." && pwd)"
app_icon() { # anahtar  uygulama-yolu
  local res icns; res="$2/Contents/Resources"; [ -d "$res" ] || return
  icns=$(ls "$res"/*.icns 2>/dev/null | grep -i -E "appicon|app\.icns|icon\.icns|electron" | head -1)
  [ -z "$icns" ] && icns=$(ls "$res"/*.icns 2>/dev/null | head -1)
  [ -n "$icns" ] && sips -s format png -Z 128 "$icns" --out "$DIR/img/tools/$1.png" >/dev/null 2>&1 && echo "icon: $1"
}
for h in /Applications "$HOME/Applications"; do
  app_icon claude-desktop "$h/Claude.app"; app_icon chatgpt "$h/ChatGPT.app"; app_icon gemini-app "$h/Gemini.app"
  app_icon ollama "$h/Ollama.app"; app_icon cursor "$h/Cursor.app"; app_icon windsurf "$h/Windsurf.app"
  app_icon antigravity "$h/Antigravity.app"; app_icon zed "$h/Zed.app"; app_icon kiro "$h/Kiro.app"; app_icon warp "$h/Warp.app"
  app_icon dia "$h/Dia.app"; app_icon gpt4all "$h/GPT4All.app"; app_icon msty "$h/Msty.app"; app_icon anythingllm "$h/AnythingLLM.app"; app_icon goose "$h/Goose.app"
  app_icon lmstudio "$h/LM Studio.app"; app_icon perplexity "$h/Perplexity.app"; app_icon jan "$h/Jan.app"
done
brand() { # file color
  curl -fsS "https://cdn.jsdelivr.net/npm/simple-icons@13/icons/$1.svg" | sed "s/<svg /<svg fill=\"#$2\" /" > "$DIR/img/brands/$1.svg" && echo "logo: $1"
}
brand claude D97757; brand anthropic D97757; brand openai 111827; brand googlegemini 8E75B2
brand githubcopilot 111827; brand ollama 111827; brand perplexity 22B8CD; brand huggingface FFD21E
brand github 111827; brand google 4285F4; brand cloudflare F38020
